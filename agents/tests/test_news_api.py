import json
import threading
import time
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlsplit

import pytest

from conftest import FakeFetcher, FakeLLM, article_page
from lehigh_agents.agents import Rastreador
from lehigh_agents.llm import Grounded
from lehigh_agents.news_api import MediastackClient, NewsAPIError
from lehigh_agents.settings import Settings
from lehigh_agents.store import Store


class _API(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_GET(self):
        srv = self.server
        q = parse_qs(urlsplit(self.path).query)
        srv.requests.append((self.path, {k: v[0] for k, v in q.items()}))
        body = srv.reply(q)
        d = json.dumps(body).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(d)))
        self.end_headers()
        self.wfile.write(d)


@pytest.fixture
def api():
    srv = HTTPServer(("127.0.0.1", 0), _API)
    srv.requests = []
    srv.reply = lambda q: {"pagination": {}, "data": [
        {"title": "Lee County approves road", "url": "https://wink.example/news/road", "source": "WINK", "published_at": "2026-10-08T10:00:00+00:00", "description": "d"},
        {"title": "Javascript link", "url": "javascript:alert(1)", "source": "x", "published_at": ""},
        {"title": "", "url": "https://x.example/untitled", "source": "x"},
    ]}
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    yield srv
    srv.shutdown()


class Http:
    """Routes requests.get to the fake API regardless of scheme, recording which scheme was asked for."""
    def __init__(self, port, schemes):
        self.port, self.schemes = port, schemes

    def get(self, url, params=None, timeout=None):
        import requests
        self.schemes.append(urlsplit(url).scheme)
        return requests.get(f"http://127.0.0.1:{self.port}/v1/news", params=params, timeout=5)


@pytest.fixture
def cfg(settings, monkeypatch):
    monkeypatch.setenv("MEDIASTACK_API_KEY", "MS-KEY")
    monkeypatch.setenv("FRESHNESS_DAYS", "30")
    return Settings.from_env("/x")


def client(cfg, api, tmp_path, schemes=None):
    return MediastackClient(cfg, Store(tmp_path), http=Http(api.server_port, schemes if schemes is not None else []))


def test_disabled_without_key_and_returns_nothing(settings, tmp_path, api):
    c = MediastackClient(settings, Store(tmp_path), http=Http(api.server_port, []))
    assert not c.enabled and c.fetch() == [] and api.requests == []


def test_fetch_sends_the_right_query_and_filters_bad_rows(cfg, api, tmp_path):
    schemes = []
    events = []
    items = client(cfg, api, tmp_path, schemes).fetch(lambda *a, **k: events.append(a))
    assert [i.url for i in items] == ["https://wink.example/news/road"] and items[0].published == "2026-10-08"
    q = api.requests[0][1]
    assert q["access_key"] == "MS-KEY" and q["countries"] == "us" and q["languages"] == "en,es" and q["sort"] == "published_desc"
    assert "," in q["date"] and q["keywords"] == "Lehigh Acres" and schemes == ["https"]


def test_rate_limits_by_interval_and_by_month(cfg, api, tmp_path):
    c = client(cfg, api, tmp_path)
    assert c.fetch()                       # first call goes through
    assert c.fetch() == [] and len(api.requests) == 1          # too soon: skipped, not an error
    c.store.db.execute("UPDATE api_usage SET ts = ts - 9*3600")
    assert c.fetch() and len(api.requests) == 2               # interval passed
    c.store.db.execute("UPDATE api_usage SET ts = ts - 9*3600")
    c.s = Settings.from_env("/x").__class__(**{**c.s.__dict__, "mediastack_monthly_limit": 2})
    assert c.fetch() == [] and len(api.requests) == 2          # monthly cap reached
    assert "mensual" in c.allowance()


def test_https_fallback_is_reported_and_only_in_auto_mode(cfg, api, tmp_path):
    state = {"n": 0}

    def reply(q):
        state["n"] += 1
        return {"error": {"code": "https_access_restricted", "message": "plan"}} if state["n"] == 1 else {"data": [
            {"title": "T", "url": "https://a.example/x", "source": "S", "published_at": "2026-10-08"}]}
    api.reply = reply
    schemes, events = [], []
    items = client(cfg, api, tmp_path, schemes).fetch(lambda *a, **k: events.append((a, k)))
    assert schemes == ["https", "http"] and len(items) == 1
    assert any("sin cifrar" in a[1] for a, k in events)
    # forced HTTPS never downgrades
    state["n"] = 0
    strict = Settings.from_env("/x").__class__(**{**cfg.__dict__, "mediastack_https": "true"})
    schemes2 = []
    with pytest.raises(NewsAPIError, match="https_access_restricted"):
        MediastackClient(strict, Store(tmp_path / "s2"), http=Http(api.server_port, schemes2)).fetch()
    assert schemes2 == ["https"]


def test_api_errors_become_newsapi_errors_and_still_count(cfg, api, tmp_path):
    api.reply = lambda q: {"error": {"code": "invalid_access_key", "message": "bad key"}}
    c = client(cfg, api, tmp_path)
    with pytest.raises(NewsAPIError, match="invalid_access_key"):
        c.fetch()
    assert c.store.api_calls_since("mediastack", 0) == 1


def test_researcher_reads_mediastack_urls_through_the_same_verification(cfg, api, tmp_path):
    url = "https://wink.example/news/road"
    fetch = FakeFetcher({url: article_page()})
    grounded = Grounded("n", [], [])                      # Google found nothing; only Mediastack has candidates
    llm = FakeLLM(cfg, {"rastreador": [[{"titulo_fuente": "Road", "url": url, "resumen_hechos": "Lee County approved a road.", "fecha": time.strftime("%Y-%m-%d")}]]}, grounded)
    events = []
    out = Rastreador(cfg, llm, fetch, Store(tmp_path), lambda *a, **k: events.append(a), news_api=client(cfg, api, tmp_path)).run()
    assert [h.url for h in out] == [url] and url in fetch.calls and any("Mediastack" in e[2] for e in events)


def test_researcher_survives_a_broken_mediastack(cfg, api, tmp_path):
    api.reply = lambda q: {"error": {"code": "usage_limit_reached", "message": "no more"}}
    fetch = FakeFetcher({"https://g.example/a": article_page()})
    grounded = Grounded("n", [], [{"uri": "https://g.example/a", "title": "g", "domain": "g"}])
    llm = FakeLLM(cfg, {"rastreador": [[{"titulo_fuente": "A", "url": "https://g.example/a", "resumen_hechos": "x", "fecha": time.strftime("%Y-%m-%d")}]]}, grounded)
    out = Rastreador(cfg, llm, fetch, Store(tmp_path), lambda *a, **k: None, news_api=client(cfg, api, tmp_path)).run()
    assert len(out) == 1
