import io
import threading
import time
import zipfile
from types import SimpleNamespace

import pytest

from lehigh_agents import updater
from lehigh_agents.control import Control, Desired
from lehigh_agents.updater import UpdateError, apply_package
from lehigh_agents.worker import Worker
from lehigh_agents.wordpress import WordPressClient

ROOT = "golehighacres-agents"


def make_zip(files, root=ROOT):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        for name, data in files.items():
            info = zipfile.ZipInfo(f"{root}/{name}" if root else name)
            info.external_attr = (0o100755 if name.endswith(".sh") else 0o100644) << 16
            z.writestr(info, data)
    return buf.getvalue()


NEW = {"main.py": "print('new')", "lehigh_agents/__init__.py": '__version__ = "9.9.9"\n', "lehigh_agents/llm.py": "NEW = 1",
       "requirements.txt": "requests\n", "install.sh": "#!/bin/sh\n", ".env": "GEMINI_API_KEY=ATTACKER", "state/db": "x"}


def installed(tmp_path):
    (tmp_path / "lehigh_agents").mkdir()
    (tmp_path / "main.py").write_text("old")
    (tmp_path / "lehigh_agents/__init__.py").write_text('__version__ = "1.0.0"\n')
    (tmp_path / "lehigh_agents/llm.py").write_text("OLD = 1")
    (tmp_path / "requirements.txt").write_text("requests\n")
    (tmp_path / ".env").write_text("GEMINI_API_KEY=MINE\n")
    (tmp_path / "state").mkdir()
    (tmp_path / "state/db").write_text("keep")
    return tmp_path


def test_update_replaces_code_and_never_touches_secrets_or_state(tmp_path):
    root = installed(tmp_path)
    r = apply_package(make_zip(NEW), root)
    assert r.version == "9.9.9" and r.requirements_changed is False and r.files == 5
    assert (root / "lehigh_agents/llm.py").read_text() == "NEW = 1" and (root / "main.py").read_text() == "print('new')"
    assert (root / ".env").read_text() == "GEMINI_API_KEY=MINE\n" and (root / "state/db").read_text() == "keep"   # protected
    assert (root / "install.sh").stat().st_mode & 0o111                                                         # executable kept


def test_changed_requirements_are_reported(tmp_path):
    root = installed(tmp_path)
    assert apply_package(make_zip({**NEW, "requirements.txt": "requests\nnewlib\n"}), root).requirements_changed is True


@pytest.mark.parametrize("files,root_name,msg", [
    ({**NEW, "../evil.py": "x"}, ROOT, "ruta no permitida"),
    ({"/abs.py": "x"}, "", "ruta no permitida"),
    ({"readme.txt": "x"}, ROOT, "no parece"),
    ({k: v for k, v in NEW.items() if k != "main.py"}, ROOT, "no parece"),
    (NEW, "", "estructura"),
])
def test_bad_archives_are_refused_before_anything_is_written(tmp_path, files, root_name, msg):
    root = installed(tmp_path)
    with pytest.raises(UpdateError, match=msg):
        apply_package(make_zip(files, root_name), root)
    assert (root / "main.py").read_text() == "old" and (root / "lehigh_agents/llm.py").read_text() == "OLD = 1"


def test_not_a_zip_and_two_roots_are_refused(tmp_path):
    root = installed(tmp_path)
    with pytest.raises(UpdateError, match="zip"):
        apply_package(b"<html>login</html>", root)
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        z.writestr("a/main.py", "x")
        z.writestr("b/lehigh_agents/__init__.py", "x")
    with pytest.raises(UpdateError, match="única carpeta"):
        apply_package(buf.getvalue(), root)


def test_download_goes_through_the_authenticated_client(settings, wp_server, tmp_path, monkeypatch):
    class WP(WordPressClient):
        def get_bytes(self, path, timeout=120):
            assert path == "/lnh/v1/agents/package"
            return make_zip(NEW)

    root = installed(tmp_path)
    monkeypatch.setattr(updater, "install_requirements", lambda r: pytest.fail("requirements did not change"))
    res = updater.update_from_hub(WP(settings.wp_rest_url, settings.wp_auth_token), root)
    assert res.version == "9.9.9"


def test_get_bytes_reports_http_errors(settings, wp_server):
    wp = WordPressClient(settings.wp_rest_url, settings.wp_auth_token)
    from lehigh_agents.wordpress import WPError

    with pytest.raises(WPError, match="404"):
        wp.get_bytes("/lnh/v1/agents/package")      # the fake WordPress has no such route


# ───────────────────────── the worker side ─────────────────────────
class FakeControl:
    def __init__(self, desired):
        self.desired, self.calls = desired, []

    def sync(self, status, message="", next_run_at=0, last_run_at=0, handled_run_now=0, agent="", handled_update=0, update_note=""):
        self.calls.append({"status": status, "handled_update": handled_update, "note": update_note, "message": message})
        return self.desired


class Pipe:
    runs = 0

    def run_once(self, **kw):
        Pipe.runs += 1


def run_worker(ctl, **kw):
    stop = threading.Event()
    w = Worker(Pipe(), ctl, 60, stop, tick=0.02, heartbeat=0.02, **kw)
    t = threading.Thread(target=w.run, daemon=True)
    t.start()
    return w, stop, t


def test_update_request_downloads_confirms_and_restarts_once():
    ctl = FakeControl(Desired(True, "paused", 0, 60, update=555))
    done, restarts = [], []
    w, stop, t = run_worker(ctl, updater=lambda: done.append(1) or SimpleNamespace(version="9.9.9"), restart=lambda: restarts.append(1))
    t.join(3)
    assert done == [1] and restarts == [1]
    assert any(c["handled_update"] == 555 for c in ctl.calls)             # the hub is told which request was taken
    assert any(c["status"] == "stopped" and "9.9.9" in c["note"] for c in ctl.calls)


def test_a_failed_update_keeps_the_old_agents_running_and_says_why():
    ctl = FakeControl(Desired(True, "running", 0, 60, update=7))
    restarts = []

    def boom():
        raise updater.UpdateError("el archivo descargado no es un zip válido")

    Pipe.runs = 0
    w, stop, t = run_worker(ctl, updater=boom, restart=lambda: restarts.append(1))
    time.sleep(0.4)
    assert restarts == [] and t.is_alive() and Pipe.runs >= 1             # still working
    assert any("falló" in c["note"] for c in ctl.calls)
    assert sum(1 for c in ctl.calls if c["status"] == "idle" and c["handled_update"] == 7) >= 1
    stop.set()
    t.join(3)


def test_old_hub_or_missing_updater_ignores_update_requests():
    ctl = FakeControl(Desired(True, "paused", 0, 60, update=3))
    w, stop, t = run_worker(ctl)                                           # no updater configured
    time.sleep(0.2)
    assert t.is_alive()
    stop.set()
    t.join(3)


def test_sync_advertises_that_it_can_update(settings, wp_server):
    wp_server.control = {"state": "paused", "run_now": 0, "interval_minutes": 60, "update": 12, "agents_latest": "1.5.5"}
    c = Control(WordPressClient(settings.wp_rest_url, settings.wp_auth_token))
    d = c.sync("idle", handled_update=11, update_note="x")
    assert d.update == 12 and d.agents_latest == "1.5.5"
    sent = wp_server.syncs[-1]
    assert sent["can_update"] is True and sent["handled_update"] == 11 and sent["update_note"] == "x"
