import datetime as dt
import json
import subprocess
from dataclasses import replace

import pytest

from conftest import FakeFetcher, FakeLLM, article_page
from lehigh_agents.agents import Rastreador
from lehigh_agents.llm import LLM, LLMError, provider_for
from lehigh_agents.opencode import OpenCodeError, OpenCodeRunner, find_binary, is_opencode, parse_events, split_models
from lehigh_agents.runlog import RunLog
from lehigh_agents.search_api import SearchError, TavilyClient
from lehigh_agents.store import Store

TODAY = dt.date.today().isoformat()


def events(*evs):
    return "\n".join(json.dumps(e) for e in evs)


def text_ev(t):
    return {"type": "text", "sessionID": "s", "part": {"type": "text", "text": t}}


# ───────────────────────── OpenCode ─────────────────────────
def test_model_names_and_routing():
    assert is_opencode("opencode/big-pickle") and not is_opencode("gemini-2.5-flash")
    assert provider_for("opencode/big-pickle,opencode/mimo-v2.6-flash-free") == "opencode"
    assert split_models(" opencode/a , opencode/b ,") == ["opencode/a", "opencode/b"]


def test_event_stream_is_turned_into_the_answer():
    out = events({"type": "step_start"}, text_ev('{"ok":'), text_ev(" true}"), {"type": "step_finish"})
    assert parse_events(out) == '{"ok": true}'
    assert parse_events("plain answer without json events") == "plain answer without json events"


def test_error_events_become_errors_and_limits_are_recognised():
    err = {"type": "error", "error": {"name": "APIError", "data": {"message": "FreeUsageLimitError: rate limit", "statusCode": 429}}}
    with pytest.raises(OpenCodeError) as ei:
        parse_events(events(err))
    assert ei.value.limit and "FreeUsageLimit" in str(ei.value)
    with pytest.raises(OpenCodeError, match="ninguna respuesta"):
        parse_events("")
    assert parse_events(events(err, text_ev("sí"))) == "sí"          # an answer wins over a stray error event


class FakeRun:
    def __init__(self, script):
        self.script, self.calls = script, []

    def __call__(self, cmd, **kw):
        self.calls.append((cmd, kw))
        model = cmd[cmd.index("-m") + 1]
        out = self.script[model]
        if isinstance(out, Exception):
            raise out
        return subprocess.CompletedProcess(cmd, 0, out.encode(), b"")


def runner(script, tmp_path):
    exe = tmp_path / "opencode"
    exe.write_text("#!/bin/sh\n")
    exe.chmod(0o755)
    return OpenCodeRunner(str(exe), timeout=5, run=FakeRun(script))


def test_runner_calls_the_cli_in_an_empty_folder_with_json_output(tmp_path):
    r = runner({"opencode/big-pickle": events(text_ev('{"a": 1}'))}, tmp_path)
    text, used = r.complete("opencode/big-pickle", "SISTEMA", "PREGUNTA")
    cmd, kw = r._run.calls[0]
    assert text == '{"a": 1}' and used == "opencode/big-pickle"
    assert cmd[-6:-2] == ["run", "-m", "opencode/big-pickle", "--format"] and cmd[-2] == "json"
    assert "SISTEMA" in cmd[-1] and "PREGUNTA" in cmd[-1] and "No uses herramientas" in cmd[-1]
    assert kw["cwd"] and "lehigh-opencode-" in kw["cwd"] and kw["stdin"] == subprocess.DEVNULL


def test_a_failing_or_limited_model_hands_over_to_the_next_and_cools_down(tmp_path):
    limit = events({"type": "error", "error": {"data": {"message": "FreeUsageLimitError 429"}}})
    clock = [1000.0]
    r = runner({"opencode/big-pickle": limit, "opencode/mimo-v2.6-flash-free": events(text_ev("ok"))}, tmp_path)
    r._clock = lambda: clock[0]
    spec = "opencode/big-pickle,opencode/mimo-v2.6-flash-free"
    assert r.complete(spec, "s", "p") == ("ok", "opencode/mimo-v2.6-flash-free")
    assert r.complete(spec, "s", "p")[1] == "opencode/mimo-v2.6-flash-free"
    models_called = [c[0][c[0].index("-m") + 1] for c in r._run.calls]
    assert models_called == ["opencode/big-pickle", "opencode/mimo-v2.6-flash-free", "opencode/mimo-v2.6-flash-free"]   # no retry storm
    clock[0] += 700
    r.complete(spec, "s", "p")
    again = [c[0][c[0].index("-m") + 1] for c in r._run.calls[3:]]
    assert again == ["opencode/big-pickle", "opencode/mimo-v2.6-flash-free"]                      # cool-down over: it is tried first again


def test_missing_binary_timeout_and_oversized_prompts_have_clear_errors(tmp_path, monkeypatch):
    monkeypatch.setattr("lehigh_agents.opencode.find_binary", lambda hint="": None)
    monkeypatch.setenv("OPENCODE_AUTO_INSTALL", "false")
    with pytest.raises(OpenCodeError, match="npm install -g opencode-ai"):
        OpenCodeRunner().complete("opencode/big-pickle", "s", "p")
    r = runner({"opencode/big-pickle": subprocess.TimeoutExpired("x", 5)}, tmp_path)
    monkeypatch.setattr("lehigh_agents.opencode.find_binary", lambda hint="": str(tmp_path / "opencode"))
    with pytest.raises(OpenCodeError, match="tardó"):
        r.complete("opencode/big-pickle", "s", "p")
    with pytest.raises(OpenCodeError, match="demasiado largo"):
        r.run_one("opencode/big-pickle", "x" * 30000)


def test_llm_router_uses_opencode_and_reports_usage(settings, tmp_path):
    llm = LLM(settings, sleep=lambda s: None)
    llm._clients["opencode"] = runner({"opencode/big-pickle": events(text_ev('{"hallazgos": []}'))}, tmp_path)
    assert llm.json("opencode/big-pickle", "sys", "prompt") == {"hallazgos": []}
    assert llm.usage["opencode/big-pickle"]["calls"] == 1
    assert llm.max_prompt_chars("opencode/big-pickle") < 24000 and llm.max_prompt_chars("gemini-2.5-flash") == 0
    llm._clients["opencode"] = runner({"opencode/big-pickle": subprocess.TimeoutExpired("x", 1)}, tmp_path)
    with pytest.raises(LLMError, match="OpenCode"):
        llm.json("opencode/big-pickle", "sys", "prompt")


# ───────────────────────── Tavily ─────────────────────────
class Resp:
    def __init__(self, status, body):
        self.status_code, self._body, self.text = status, body, json.dumps(body)

    def json(self):
        return self._body


class Http:
    def __init__(self, resp):
        self.resp, self.calls = resp, []

    def post(self, url, json=None, headers=None, timeout=None):
        self.calls.append((url, json, headers))
        return self.resp


def tav(settings, tmp_path, resp, **over):
    s = replace(settings, tavily_api_key="tvly-abcdefghijklmnop", **over)
    return TavilyClient(s, Store(tmp_path / "st"), Http(resp)), s


def test_tavily_search_sends_a_news_query_and_keeps_only_http_urls(settings, tmp_path):
    body = {"results": [{"url": "https://wink.example/a", "title": "A", "content": "x" * 500, "published_date": "2026-10-01"},
                        {"url": "javascript:alert(1)", "title": "bad"}, {"url": "ftp://x/y", "title": "bad"}]}
    t, _ = tav(settings, tmp_path, Resp(200, body))
    hits = t.search("lehigh acres")
    url, payload, headers = t.http.calls[0]
    assert [h.url for h in hits] == ["https://wink.example/a"] and len(hits[0].snippet) == 300
    assert payload["topic"] == "news" and payload["query"] == "lehigh acres" and headers["Authorization"] == "Bearer tvly-abcdefghijklmnop"


@pytest.mark.parametrize("status,msg", [(401, "rechazó la clave"), (429, "se agotó"), (500, "HTTP 500")])
def test_tavily_errors_are_explained(settings, tmp_path, status, msg):
    t, _ = tav(settings, tmp_path, Resp(status, {"detail": "x"}))
    with pytest.raises(SearchError, match=msg):
        t.search("q")


def test_tavily_respects_its_own_monthly_budget_and_never_raises_in_find(settings, tmp_path):
    t, s = tav(settings, tmp_path, Resp(200, {"results": [{"url": "https://a.example/x", "title": "t"}]}), tavily_monthly_limit=2, tavily_queries=3)
    seen = []
    hits = t.find(lambda ty, m, **k: seen.append((ty, m)))
    assert len(hits) == 1 and len(t.http.calls) == 2                      # 3 queries wanted, budget of 2 credits
    assert any("límite mensual" in m for _, m in seen)
    bad, _ = tav(settings, tmp_path / "b", Resp(401, {}))
    out = []
    assert bad.find(lambda ty, m, **k: out.append(m)) == [] and any("rechazó" in m for m in out)
    assert not TavilyClient(replace(settings, tavily_api_key=""), None).enabled


# ───────────────────────── the researcher without Gemini ─────────────────────────
def test_rastreador_with_tavily_and_opencode_models_reads_pages_and_never_calls_gemini(settings, tmp_path):
    from lehigh_agents.llm import Grounded

    url = "https://wink.example/road"
    s = replace(settings, tavily_api_key="tvly-abcdefghijklmnop", rastreador_model="opencode/big-pickle", gemini_api_key="")
    t = TavilyClient(s, Store(tmp_path / "st"), Http(Resp(200, {"results": [{"url": url, "title": "Road", "content": "c"}]})))
    llm = FakeLLM(s, {"rastreador": [{"hallazgos": [{"titulo_fuente": "Lee County approves road", "url": url,
                                                       "resumen_hechos": "Lee County approved a road project on March 4.", "fecha": TODAY,
                                                       "palabras_clave": ["road"]}]}]})
    llm.grounded_search = lambda *a, **k: pytest.fail("Gemini grounding must not be used")
    log = RunLog()
    found = Rastreador(s, llm, FakeFetcher({url: article_page("Road approved")}), Store(tmp_path / "s2"), log.bind(""), search_api=t).run()
    assert [h.url for h in found] == [url]
    assert any(e["type"] == "search" and "Tavily" in e["message"] for e in log.events)


def test_big_inputs_are_split_into_batches_for_opencode(settings, tmp_path):
    s = replace(settings, rastreador_model="opencode/big-pickle", tavily_api_key="tvly-abcdefghijklmnop")
    llm = FakeLLM(s, {"rastreador": [[]]})
    r = Rastreador(s, llm, FakeFetcher(), Store(tmp_path / "s"), RunLog().bind(""))
    pages = [{"url": f"https://x.example/{i}", "title": "t", "published": "", "text": "y" * 4000, "site": "x"} for i in range(12)]
    batches = r._batches(pages)
    assert len(batches) > 1 and sum(len(b.pages) for b in batches) == 12
    assert all(len(b.pages) * (b.chars + 300) <= llm.max_prompt_chars(s.rastreador_model) + 300 for b in batches)
    gem = Rastreador(replace(settings, rastreador_model="gemini-2.5-flash"), llm, FakeFetcher(), Store(tmp_path / "s3"), RunLog().bind(""))
    assert len(gem._batches(pages)) == 1                                 # Gemini keeps the single big prompt


def test_find_binary_honours_the_configured_path(tmp_path):
    exe = tmp_path / "oc"
    exe.write_text("x")
    assert find_binary(str(exe)) == str(exe)


def test_windows_cmd_shim_is_replaced_by_node_and_the_script(tmp_path, monkeypatch):
    from lehigh_agents import opencode as oc

    npm_dir = tmp_path / "npm"
    script = npm_dir / "node_modules/opencode-ai/bin/opencode"
    script.parent.mkdir(parents=True)
    script.write_text("#!/usr/bin/env node\n")
    shim = npm_dir / "opencode.cmd"
    shim.write_text("@echo off")
    monkeypatch.setattr(oc.shutil, "which", lambda n: "/usr/bin/node" if n.startswith("node") else None)
    assert oc.launcher(str(shim)) == ["/usr/bin/node", str(script)]
    assert oc.launcher("/usr/local/bin/opencode") == ["/usr/local/bin/opencode"]


def test_missing_opencode_is_installed_once_with_npm_then_found(tmp_path, monkeypatch):
    from lehigh_agents import opencode as oc

    exe = tmp_path / "opencode"
    exe.write_text("#!/bin/sh\n")
    found = {"now": False}
    monkeypatch.setattr(oc, "find_binary", lambda hint="": str(exe) if found["now"] else None)
    installs = []
    monkeypatch.setattr(oc, "try_install", lambda *a, **k: installs.append(1) or found.__setitem__("now", True) or True)
    r = OpenCodeRunner()
    assert r.binary() == str(exe) and installs == [1]
    monkeypatch.setenv("OPENCODE_AUTO_INSTALL", "false")
    found["now"] = False
    r2 = OpenCodeRunner()
    with pytest.raises(OpenCodeError, match="OPENCODE_PATH") as ei:
        r2.binary()
    assert "busqué en" in str(ei.value) and installs == [1]                 # auto-install can be switched off


def test_search_places_cover_windows_mac_linux_and_npm(monkeypatch):
    from lehigh_agents import opencode as oc

    monkeypatch.setattr(oc, "_npm_roots", lambda: [oc.Path("/r/node_modules")])
    places = " ".join(oc.search_places("/custom/oc"))
    assert "/custom/oc" in places and ".opencode" in places and "opencode.cmd" in places and "/r/node_modules/opencode-windows-x64/bin/opencode.exe" in places
