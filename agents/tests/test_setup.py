import argparse
import json
import threading
import urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from types import SimpleNamespace

import pytest
import requests

from lehigh_agents import setup_wizard as sw
from lehigh_agents.setup_wizard import Console, SetupError


@pytest.fixture(autouse=True)
def clean_env(monkeypatch):
    for k in ("GEMINI_API_KEY", "GOOGLE_API_KEY", "ANTHROPIC_API_KEY", "OPENAI_API_KEY", "IMAGE_API_KEY", "WP_REST_URL", "WP_AUTH_TOKEN"):
        monkeypatch.delenv(k, raising=False)


# ───────────────────────── model choice ─────────────────────────
def M(name, actions=("generateContent",)):
    return {"name": "models/" + name, "supported_actions": list(actions)}


def test_picks_newest_stable_flash_and_ignores_unsuitable_models():
    models = [M("gemini-1.5-flash"), M("gemini-2.0-flash"), M("gemini-2.5-flash"), M("gemini-2.5-flash-lite"),
              M("gemini-2.5-flash-image"), M("gemini-3-flash-preview"), M("gemini-2.5-pro"), M("gemini-2.5-flash-live"),
              M("gemma-3-27b"), M("gemini-2.5-flash-tts"), M("embedding-001", ["embedContent"])]
    assert sw.pick_text_model(models) == "gemini-2.5-flash"         # stable beats a newer preview
    assert sw.pick_text_model([M("gemini-3-flash-preview"), M("gemini-2.0-flash-exp")]) == "gemini-3-flash-preview"
    assert sw.pick_text_model([M("gemini-flash-latest")]) == "gemini-flash-latest"
    assert sw.pick_text_model([M("gemini-2.5-flash", ["embedContent"])]) is None   # cannot generate
    assert sw.pick_text_model([]) is None


def test_model_listing_accepts_sdk_objects():
    objs = [SimpleNamespace(name="models/gemini-2.5-flash", supported_actions=["generateContent"]),
            SimpleNamespace(name="models/gemini-2.0-flash", supported_actions=None)]
    assert sw.pick_text_model(objs) == "gemini-2.5-flash"


def test_picks_the_image_model():
    assert sw.pick_image_model([M("gemini-2.5-flash"), M("gemini-2.5-flash-image"), M("gemini-3-pro-image")]) == "gemini-2.5-flash-image"
    assert sw.pick_image_model([M("gemini-2.5-flash")]) is None


# ───────────────────────── .env writing ─────────────────────────
TEMPLATE = "# ─── keys\nGEMINI_API_KEY=\n# comment stays\nWP_AUTH_TOKEN=\nIMAGE_PROVIDER=replicate\n"


def test_render_env_keeps_comments_quotes_special_values_and_appends_unknown_keys():
    out = sw.render_env(TEMPLATE, {"GEMINI_API_KEY": "abc", "WP_AUTH_TOKEN": "bot:abcd efgh ijkl", "IMAGE_PROVIDER": "", "NEW_ONE": "x#y"})
    assert "# ─── keys" in out and "# comment stays" in out
    assert 'WP_AUTH_TOKEN="bot:abcd efgh ijkl"' in out and 'NEW_ONE="x#y"' in out and "IMAGE_PROVIDER=\n" in out
    assert "# added by setup" in out


def test_env_roundtrip_through_dotenv_and_settings(tmp_path, monkeypatch):
    for k in ("GEMINI_API_KEY", "WP_AUTH_TOKEN", "WP_REST_URL", "REDACCTOR_MODEL"):
        monkeypatch.delenv(k, raising=False)
    path = tmp_path / ".env"
    sw.write_env(path, {"GEMINI_API_KEY": "k", "WP_AUTH_TOKEN": "bot:ab cd ef", "WP_REST_URL": "https://s.com/wp-json", "REDACCTOR_MODEL": "gemini-2.5-flash"})
    assert sw.read_env(path)["WP_AUTH_TOKEN"] == "bot:ab cd ef"
    from lehigh_agents.settings import Settings
    s = Settings.from_env(path)
    assert s.wp_auth_token == "bot:ab cd ef" and s.redactor_model == "gemini-2.5-flash"
    assert oct(path.stat().st_mode & 0o777) == "0o600"
    # a second run updates values but keeps what the user wrote in the file
    path.write_text(path.read_text() + "# my note\n")
    sw.write_env(path, {"GEMINI_API_KEY": "new"})
    text = path.read_text()
    assert "GEMINI_API_KEY=new" in text and "# my note" in text and "WP_REST_URL=https://s.com/wp-json" in text


# ───────────────────────── a tiny fake WordPress ─────────────────────────
class _WP(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _json(self, obj, code=200):
        d = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(d)))
        self.end_headers()
        self.wfile.write(d)

    def do_GET(self):
        srv = self.server
        path = urllib.parse.urlsplit(self.path).path
        if path == "/wp-json/":
            ns = ["wp/v2"] + (["lnh/v1"] if srv.plugin else [])
            auth = {"application-passwords": {"endpoints": {"authorization": f"http://127.0.0.1:{srv.server_port}/wp-admin/authorize-application.php"}}} if srv.app_passwords else {}
            return self._json({"name": "Fake Site", "namespaces": ns, "authentication": auth})
        if path == "/wp-json/wp/v2/users/me":
            import base64
            tok = (self.headers.get("Authorization") or "").removeprefix("Basic ")
            if base64.b64decode(tok + "==").decode(errors="ignore") == srv.valid:
                return self._json({"name": "Bot"})
            return self._json({"message": "no"}, 401)
        self._json({}, 404)


@pytest.fixture
def fake_wp():
    srv = HTTPServer(("127.0.0.1", 0), _WP)
    srv.plugin, srv.app_passwords, srv.valid = True, True, "bot:pass word 1234"
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    yield srv
    srv.shutdown()


def approving_browser(user="bot", password="pass word 1234", approve=True, wrong_token_first=False):
    """Behaves like WordPress after the user clicks the button: redirects to success_url (or reject_url)."""
    def open_url(url):
        q = urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)
        success, reject = q["success_url"][0], q["reject_url"][0]
        if wrong_token_first:  # an unrelated local process must not be able to inject credentials
            bad = success.rsplit("/", 2)[0] + "/WRONG/ok?" + urllib.parse.urlencode({"user_login": "evil", "password": "x"})
            requests.get(bad, timeout=5)
        if approve:
            requests.get(success + "?" + urllib.parse.urlencode({"user_login": user, "password": password, "site_url": "x"}), timeout=5)
        else:
            requests.get(reject, timeout=5)
    return open_url


def info_for(srv):
    return sw.discover_site(f"http://127.0.0.1:{srv.server_port}")


def test_discovery_reports_plugin_and_authorisation_endpoint(fake_wp):
    info = info_for(fake_wp)
    assert info.name == "Fake Site" and info.has_plugin and info.authorize_url.endswith("authorize-application.php")
    assert info.rest_root.endswith("/wp-json")
    fake_wp.plugin = fake_wp.app_passwords = False
    info = info_for(fake_wp)
    assert not info.has_plugin and info.authorize_url == ""


def test_discovery_errors_are_friendly():
    with pytest.raises(SetupError, match="API REST"):
        sw.discover_site("http://127.0.0.1:9", timeout=2)
    with pytest.raises(SetupError):
        sw.normalize_site("  ")
    assert sw.normalize_site("tusitio.com/") == "https://tusitio.com"


def test_browser_authorisation_returns_credentials_and_ignores_forged_callbacks(fake_wp):
    info = info_for(fake_wp)
    user, pw = sw.authorize_in_browser(info, open_url=approving_browser(wrong_token_first=True), timeout=20, say=lambda *_: None)
    assert (user, pw) == ("bot", "pass word 1234")
    assert sw.test_wordpress(info, user, pw) == "Bot"


def test_browser_authorisation_rejected_timeout_and_unsupported(fake_wp):
    info = info_for(fake_wp)
    with pytest.raises(SetupError, match="Cancelaste"):
        sw.authorize_in_browser(info, open_url=approving_browser(approve=False), timeout=20, say=lambda *_: None)
    with pytest.raises(SetupError, match="tiempo"):
        sw.authorize_in_browser(info, open_url=lambda u: None, timeout=1, say=lambda *_: None)
    fake_wp.app_passwords = False
    with pytest.raises(SetupError, match="HTTPS"):
        sw.authorize_in_browser(info_for(fake_wp), open_url=lambda u: None, timeout=1, say=lambda *_: None)
    with pytest.raises(SetupError, match="rechazó"):
        sw.test_wordpress(info, "bot", "wrong")


# ───────────────────────── the whole wizard ─────────────────────────
class Scripted(Console):
    def __init__(self, answers):
        super().__init__(interactive=True)
        self.answers, self.log = list(answers), []

    def say(self, text=""):
        self.log.append(text)

    def ask(self, prompt, default="", secret=False):
        return self.answers.pop(0) if self.answers else default


def wiz_args(tmp_path, srv, **over):
    base = dict(output=str(tmp_path / ".env"), site=f"http://127.0.0.1:{srv.server_port}", gemini_key="G-KEY", wp_user=None,
                wp_password=None, redactor_model=None, anthropic_key=None, openai_key=None, image_provider="none", image_key=None, mediastack_key=None,
                non_interactive=True, no_check=True, timeout=20)
    base.update(over)
    return argparse.Namespace(**base)


MODELS = [M("gemini-1.5-flash"), M("gemini-2.5-flash"), M("gemini-2.5-flash-image")]


def test_wizard_non_interactive_authorises_in_browser_and_writes_env(tmp_path, fake_wp, capsys):
    rc = sw.run_wizard(wiz_args(tmp_path, fake_wp), open_url=approving_browser(), list_models=lambda k: MODELS)
    env = sw.read_env(tmp_path / ".env")
    assert rc == 0
    assert env["GEMINI_API_KEY"] == "G-KEY" and env["REDACCTOR_MODEL"] == env["AUDITOR_MODEL"] == env["RASTREADOR_MODEL"] == "gemini-2.5-flash"
    assert env["WP_AUTH_TOKEN"] == "bot:pass word 1234" and env["WP_REST_URL"].endswith("/wp-json") and env["IMAGE_PROVIDER"] == ""
    # second run: the stored credentials still work, so no browser approval is requested again
    rc = sw.run_wizard(wiz_args(tmp_path, fake_wp), open_url=lambda u: pytest.fail("browser opened again"), list_models=lambda k: MODELS)
    assert rc == 0


def test_wizard_with_flags_gemini_images_and_missing_plugin_warning(tmp_path, fake_wp):
    fake_wp.plugin = False
    con = Console(interactive=False)
    logs = []
    con.say = logs.append
    rc = sw.run_wizard(wiz_args(tmp_path, fake_wp, wp_user="bot", wp_password="pass word 1234", image_provider="gemini"),
                       con, open_url=lambda u: pytest.fail("no browser needed"), list_models=lambda k: MODELS)
    env = sw.read_env(tmp_path / ".env")
    assert rc == 0 and env["IMAGE_PROVIDER"] == "gemini" and env["IMAGE_MODEL"] == "gemini-2.5-flash-image"
    assert any("No se detecta el plugin" in l for l in logs)


def test_wizard_failures_return_nonzero_and_write_nothing(tmp_path, fake_wp):
    def bad_key(k):
        raise SetupError("Google rechazó la clave")
    assert sw.run_wizard(wiz_args(tmp_path, fake_wp), Console(interactive=False), list_models=bad_key) == 2
    assert sw.run_wizard(wiz_args(tmp_path, fake_wp, wp_user="bot", wp_password="wrong"), Console(interactive=False), list_models=lambda k: MODELS) == 2
    assert sw.run_wizard(wiz_args(tmp_path, fake_wp, site="http://127.0.0.1:9"), Console(interactive=False), list_models=lambda k: MODELS) == 2
    assert sw.run_wizard(wiz_args(tmp_path, fake_wp, gemini_key=None), Console(interactive=False), list_models=lambda k: MODELS) == 2
    assert not (tmp_path / ".env").exists()
    assert sw.run_wizard(wiz_args(tmp_path, fake_wp, wp_user="bot", wp_password="pass word 1234"), Console(interactive=False),
                         list_models=lambda k: [M("gemini-2.5-pro")]) == 2  # key ok but no usable model


def test_wizard_interactive_paste_path_other_redactor_and_image_choice(tmp_path, fake_wp, monkeypatch):
    # answers in order: gemini key, redactor model, anthropic key, site (given by arg -> skipped), method=2 (paste), user, password, image choice 3 (replicate), key
    answers = ["G-KEY", "claude-sonnet-5-5", "A-KEY", "2", "bot", "pass word 1234", "3", "R-KEY"]
    con = Scripted(answers)
    rc = sw.run_wizard(wiz_args(tmp_path, fake_wp, gemini_key=None, image_provider=None, non_interactive=False),
                       con, open_url=lambda u: pytest.fail("browser should not open"), list_models=lambda k: MODELS)
    env = sw.read_env(tmp_path / ".env")
    assert rc == 0, con.log
    assert env["REDACCTOR_MODEL"] == "claude-sonnet-5-5" and env["ANTHROPIC_API_KEY"] == "A-KEY"
    assert env["IMAGE_PROVIDER"] == "replicate" and env["IMAGE_API_KEY"] == "R-KEY" and env["WP_AUTH_TOKEN"] == "bot:pass word 1234"


# ───────────────────────── production sites (WordPress rejects http:// return URLs) ─────────────────────────
def test_local_site_detection():
    for site in ("http://localhost:8080", "http://127.0.0.1", "https://news.local", "https://x.test", "https://a.ddev.site"):
        assert sw.is_local_site(site), site
    for site in ("https://lehighnews.com", "https://example.com.au", "http://192.0.2.10"):
        assert not sw.is_local_site(site), site


def test_remote_site_uses_the_on_screen_password_flow(fake_wp):
    info = info_for(fake_wp)
    info.site = "https://news.example.com"          # pretend it is a public site
    opened = []
    answers = iter(["editor1", "abcd efgh ijkl"])
    user, pw = sw.authorize_in_browser(info, open_url=opened.append, say=lambda *_: None, ask=lambda *a, **k: next(answers))
    assert (user, pw) == ("editor1", "abcd efgh ijkl")
    time_url = opened[0] if opened else ""
    q = urllib.parse.parse_qs(urllib.parse.urlsplit(time_url).query) if time_url else {}
    assert "success_url" not in q and "reject_url" not in q and q.get("app_name")   # no http:// callback is sent
    with pytest.raises(SetupError, match="producción"):
        sw.authorize_in_browser(info, open_url=opened.append, say=lambda *_: None, ask=None)
    with pytest.raises(SetupError, match="Faltó"):
        sw.authorize_in_browser(info, open_url=opened.append, say=lambda *_: None, ask=lambda *a, **k: "")


def test_wizard_remote_non_interactive_without_credentials_explains_what_to_do(tmp_path, fake_wp, monkeypatch):
    monkeypatch.setattr(sw, "is_local_site", lambda s: False)
    logs = []
    con = Console(interactive=False)
    con.say = logs.append
    rc = sw.run_wizard(wiz_args(tmp_path, fake_wp), con, open_url=lambda u: pytest.fail("no browser"), list_models=lambda k: MODELS)
    assert rc == 2 and any("Generar credenciales" in l for l in logs)


def test_wizard_remote_interactive_paste_flow(tmp_path, fake_wp, monkeypatch):
    monkeypatch.setattr(sw, "is_local_site", lambda s: False)
    # Enter on the redactor question, method 1 (browser, on screen), user, password, image choice 1 (none)
    con = Scripted(["", "1", "bot", "pass word 1234", "1"])
    rc = sw.run_wizard(wiz_args(tmp_path, fake_wp, image_provider=None, non_interactive=False), con, open_url=lambda u: None, list_models=lambda k: MODELS)
    assert rc == 0, con.log
    assert sw.read_env(tmp_path / ".env")["WP_AUTH_TOKEN"] == "bot:pass word 1234"


def test_application_password_names_are_unique_per_authorisation(monkeypatch):
    monkeypatch.setattr(sw.time, "strftime", lambda fmt: "2026-10-09 10:00")
    first = sw.app_name()
    monkeypatch.setattr(sw.time, "strftime", lambda fmt: "2026-10-09 10:01")
    assert first != sw.app_name() and first.startswith(sw.APP_NAME)
