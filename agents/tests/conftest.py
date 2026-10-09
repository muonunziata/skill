import base64
import json
import struct
import threading
import zlib
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest

from lehigh_agents.llm import LLM
from lehigh_agents.net import FetchError, FetchResult
from lehigh_agents.settings import Settings


def make_png(w=800, h=450) -> bytes:
    def chunk(t, d):
        c = struct.pack(">I", len(d)) + t + d
        return c + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)

    raw = b"".join(b"\x00" + b"\x80\x90\xa0" * w for _ in range(h))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw, 9)) + chunk(b"IEND", b""))


PNG = make_png()


def article_page(title="Lee County approves new road", n=120):
    body = " ".join(f"<p>The Lee County Commission voted on March {i % 28 + 1} to approve project number {i}. "
                    f"Officials said the road will cost 4.5 million dollars and open in 2027.</p>" for i in range(n // 10))
    return (f'<html><head><title>{title}</title><meta property="og:image" content="/img/hero.jpg">'
            f'<meta property="og:site_name" content="WINK News"><meta property="article:published_time" content="2099-01-01">'
            f"</head><body><article>{body}</article></body></html>")


class FakeFetcher:
    """Serves canned responses; anything else behaves like a 404."""

    def __init__(self, pages=None):
        self.pages = dict(pages or {})
        self.calls = []

    def get(self, url, max_bytes=None, accept="*/*"):
        self.calls.append(url)
        if url in self.pages:
            v = self.pages[url]
            if isinstance(v, FetchError):
                raise v
            if isinstance(v, FetchResult):
                return v
            body = v if isinstance(v, bytes) else v.encode()
            ctype = "image/png" if body[:4] == b"\x89PNG" else ("application/json" if body[:1] in b"{[" else "text/html")
            return FetchResult(url=url, status=200, content_type=ctype, body=body)
        if "/uploads/" in url or "replicate.delivery" in url:  # images we uploaded / generated
            return FetchResult(url, 200, "image/png", PNG)
        if "commons.wikimedia.org" in url:
            return FetchResult(url, 200, "application/json", b'{"query":{"pages":{}}}')
        raise FetchError(f"HTTP 404 for {url}", 404)


class FakeLLM(LLM):
    """Real parsing/validation/retry logic; only the network call is scripted, keyed by agent."""

    def __init__(self, settings, scripts=None, grounded=None):
        super().__init__(settings, sleep=lambda s: None)
        self.scripts = {k: list(v) for k, v in (scripts or {}).items()}
        self.grounded = grounded
        self.calls = []

    def _role(self, system):
        for key, needle in (("rastreador", "Agente Rastreador"), ("redactor", "Redactor Multimedia"),
                            ("imagen", "photo editor"), ("auditor", "Agente Auditor"),
                            ("social", "Diseñador Social")):
            if needle in system:
                return key
        return "other"

    def _call(self, model, system, prompt, max_tokens, want_json=True):
        role = self._role(system)
        self.calls.append((role, model, prompt))
        queue = self.scripts[role]
        item = queue.pop(0) if len(queue) > 1 else queue[0]
        text = item if isinstance(item, str) else json.dumps(item, ensure_ascii=False)
        self._track(model, len(prompt) // 4, len(text) // 4)
        return text

    def grounded_search(self, model, system, prompt):
        return self.grounded


class _WPHandler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _send(self, code, obj):
        data = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _body(self):
        n = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(n) if n else b""

    def _handle(self, method):
        srv = self.server
        body = self._body()
        path = self.path.split("?")[0]
        srv.requests.append({"method": method, "path": path, "auth": self.headers.get("Authorization"),
                             "headers": dict(self.headers), "body": body})
        if not self.headers.get("Authorization"):
            return self._send(401, {"message": "no auth"})
        if path.endswith("/users/me"):
            return self._send(200, {"name": "bot", "roles": ["editor"]})
        if path.endswith("/lnh/v1/ping"):
            return self._send(200, {"version": "1.0.0"})
        if path.endswith("/lnh/v1/runs"):
            return self._send(200, {"ok": True})
        if path.endswith("/wp/v2/media") and method == "POST":
            srv.media_id += 1
            return self._send(201, {"id": srv.media_id, "source_url": f"http://127.0.0.1:{srv.server_port}/uploads/img{srv.media_id}.png"})
        if "/wp/v2/media/" in path:
            if method == "DELETE":
                srv.deleted.append(path)
                return self._send(200, {"deleted": True})
            return self._send(200, {"id": int(path.rsplit("/", 1)[1]), "source_url": f"http://127.0.0.1:{srv.server_port}/uploads/img{path.rsplit('/', 1)[1]}.png"})
        if path.endswith("/wp/v2/tags"):
            if method == "GET":
                return self._send(200, [])
            srv.tag_id += 1
            return self._send(201, {"id": srv.tag_id})
        if method == "GET" and (path.endswith("/wp/v2/posts") or path.rsplit("/", 1)[-1].isdigit() and "/wp/v2/posts/" in path):
            post = {"id": 501, "link": "http://x/?p=501", "title": {"raw": "Aprueban nueva carretera", "rendered": "x"},
                    "content": {"raw": "<p>El condado de Lee aprobó el 4 de marzo un proyecto vial en Lehigh Acres, según WINK News. "
                                      "La obra costará 4.5 millones de dólares y abrirá en 2027.</p>"},
                    "excerpt": {"raw": "Obra aprobada."},
                    "meta": {"lnh_source_url": "https://wink.example/road", "lnh_source_name": "WINK News", "lnh_keywords": '["road"]',
                             "lnh_facts": "Lee County approved a road project.", "lnh_language": "es", "lnh_featured_image_url": ""}}
            return self._send(200, [post] if path.endswith("/wp/v2/posts") else post)
        if "/wp/v2/posts/" in path and method == "POST":
            payload = json.loads(body)
            srv.post_updates.append((int(path.rsplit("/", 1)[1]), payload))
            return self._send(200, {"id": int(path.rsplit("/", 1)[1])})
        if path.endswith("/wp/v2/posts") and method == "POST":
            payload = json.loads(body)
            srv.posts.append(payload)
            return self._send(201, {"id": 500 + len(srv.posts), "link": "http://x/?p=1", "status": payload.get("status")})
        self._send(404, {"message": "not found"})

    do_GET = lambda self: self._handle("GET")  # noqa: E731
    do_POST = lambda self: self._handle("POST")  # noqa: E731
    do_DELETE = lambda self: self._handle("DELETE")  # noqa: E731


@pytest.fixture
def wp_server():
    srv = HTTPServer(("127.0.0.1", 0), _WPHandler)
    srv.requests, srv.posts, srv.deleted, srv.media_id, srv.tag_id = [], [], [], 76, 10
    srv.post_updates = []
    t = threading.Thread(target=srv.serve_forever, daemon=True)
    t.start()
    yield srv
    srv.shutdown()


@pytest.fixture
def settings(tmp_path, monkeypatch, wp_server):
    for k in ("REDACCTOR_MODEL", "REDACTOR_MODEL", "IMAGE_API_KEY", "IMAGE_PROVIDER", "IMAGE_API_ENDPOINT", "IMAGE_MODEL"):
        monkeypatch.delenv(k, raising=False)
    monkeypatch.setenv("GEMINI_API_KEY", "g")
    monkeypatch.setenv("WP_REST_URL", f"http://127.0.0.1:{wp_server.server_port}")
    monkeypatch.setenv("WP_AUTH_TOKEN", "bot:abcd efgh")
    monkeypatch.setenv("STATE_DIR", str(tmp_path / "state"))
    monkeypatch.setenv("FRESHNESS_DAYS", "3650")
    monkeypatch.setenv("SOCIAL_ENABLED", "false")  # Agent 4 has its own tests (needs Chromium)
    for k in ("SOCIAL_VOICE", "SOCIAL_WEBHOOK_URL", "SOCIAL_VIDEO", "SOCIAL_MODEL", "BRAND_LOGO", "CHROMIUM_PATH"):
        monkeypatch.delenv(k, raising=False)
    return Settings.from_env("/nonexistent.env")
