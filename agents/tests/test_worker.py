import threading
import time

import pytest

from lehigh_agents.control import Control, Desired
from lehigh_agents.worker import Worker
from lehigh_agents.wordpress import WordPressClient


class FakeControl:
    """In-memory stand-in for the plugin: the test flips `desired` like an editor pressing the buttons."""

    def __init__(self, desired=None):
        self.desired = desired or Desired(available=True, state="paused", interval_minutes=60)
        self.calls = []
        self.lock = threading.Lock()

    def sync(self, status, message="", next_run_at=0, last_run_at=0, handled_run_now=0, agent="", handled_update=0, update_note=""):
        with self.lock:
            self.calls.append({"status": status, "message": message, "handled": handled_run_now, "agent": agent})
            return self.desired

    def statuses(self):
        return [c["status"] for c in self.calls]


class FakePipeline:
    def __init__(self, stories=1, per_story=0.0, hook=None):
        self.runs, self.stories, self.per_story, self.hook = 0, stories, per_story, hook
        self.processed = 0

    def run_once(self, should_stop=None, on_event=None):
        self.runs += 1
        for i in range(self.stories):
            if should_stop and should_stop():
                return {"stopped_early": True}
            if on_event:
                on_event({"agent": "redactor", "message": f"Escribiendo noticia {i + 1}"})
            time.sleep(self.per_story)
            self.processed += 1
            if self.hook:
                self.hook(i)
        return {}


def start(worker):
    t = threading.Thread(target=worker.run, daemon=True)
    t.start()
    return t


def wait_for(cond, timeout=5.0):
    end = time.time() + timeout
    while time.time() < end:
        if cond():
            return True
        time.sleep(0.01)
    return False


def make(pipe, ctl, interval=60):
    stop = threading.Event()
    return Worker(pipe, ctl, interval, stop, tick=0.02, heartbeat=0.02), stop


def test_paused_hub_means_no_work_and_start_begins_immediately():
    ctl, pipe = FakeControl(), FakePipeline()
    w, stop = make(pipe, ctl)
    t = start(w)
    time.sleep(0.3)
    assert pipe.runs == 0 and set(ctl.statuses()) == {"idle"}          # connected and reporting, but idle
    ctl.desired = Desired(available=True, state="running", interval_minutes=60)
    assert wait_for(lambda: pipe.runs == 1)                              # Start working -> runs right away
    time.sleep(0.2)
    assert pipe.runs == 1                                                # and then waits for the interval
    stop.set()
    t.join(3)
    assert ctl.statuses()[-1] == "stopped"                               # the hub learns we left


def test_run_now_runs_once_and_is_confirmed():
    ctl, pipe = FakeControl(Desired(True, "running", 0, 60)), FakePipeline()
    w, stop = make(pipe, ctl)
    t = start(w)
    assert wait_for(lambda: pipe.runs == 1)
    ctl.desired = Desired(True, "running", 1234, 60)
    assert wait_for(lambda: pipe.runs == 2)
    time.sleep(0.2)
    assert pipe.runs == 2                                                # the same request is not run again
    assert any(c["handled"] == 1234 for c in ctl.calls)                  # ...and the hub is told it was taken
    stop.set()
    t.join(3)


def test_pause_finishes_the_article_in_progress_then_stops():
    ctl = FakeControl(Desired(True, "running", 0, 60))
    pipe = FakePipeline(stories=5, per_story=0.15)
    w, stop = make(pipe, ctl)
    t = start(w)
    assert wait_for(lambda: pipe.processed >= 1)
    ctl.desired = Desired(True, "paused", 0, 60)
    assert wait_for(lambda: "idle" in ctl.statuses()[-3:] and not any(th.name == "lehigh-heartbeat" for th in threading.enumerate()))
    done = pipe.processed
    assert 1 <= done < 5                                                 # stopped between stories, never mid-article
    time.sleep(0.4)
    assert pipe.processed == done and pipe.runs == 1
    ctl.desired = Desired(True, "running", 0, 60)                        # Start again: a fresh cycle
    assert wait_for(lambda: pipe.runs == 2)
    stop.set()
    t.join(3)


def test_live_progress_reaches_the_hub():
    ctl = FakeControl(Desired(True, "running", 0, 60))
    w, stop = make(FakePipeline(stories=1, per_story=0.2), ctl)
    t = start(w)
    assert wait_for(lambda: any(c["status"] == "working" and "Escribiendo noticia 1" in c["message"] and c["agent"] == "redactor"
                                for c in ctl.calls))                      # the hub also learns WHICH agent is working
    stop.set()
    t.join(3)


def test_cycle_repeats_after_the_interval_and_the_hub_can_override_it():
    for hub_minutes, early, late in ((0, 61, None), (5, 120, 200)):       # 0 = use the local setting (1 minute)
        ctl, pipe = FakeControl(Desired(True, "running", 0, hub_minutes)), FakePipeline()
        clock = [1000.0]
        stop = threading.Event()
        w = Worker(pipe, ctl, 1, stop, tick=0.02, heartbeat=0.02, clock=lambda: clock[0])
        t = start(w)
        assert wait_for(lambda: pipe.runs == 1 and w._next_run > 0)
        clock[0] += early
        time.sleep(0.2)
        assert pipe.runs == (2 if hub_minutes == 0 else 1)                # local 1 min elapsed / plugin's 5 min has not
        if late:
            clock[0] += late
            assert wait_for(lambda: pipe.runs == 2)                       # 320 s > 5 min
        stop.set()
        t.join(3)


def test_without_the_plugin_it_is_a_plain_scheduler():
    pipe = FakePipeline()
    stop = threading.Event()
    w = Worker(pipe, None, 60, stop, tick=0.02)
    t = start(w)
    assert wait_for(lambda: pipe.runs == 1)
    stop.set()
    t.join(3)
    ctl = FakeControl(Desired(available=False))                          # old plugin / no endpoint: same behaviour
    pipe2 = FakePipeline()
    w2, stop2 = make(pipe2, ctl)
    t2 = start(w2)
    assert wait_for(lambda: pipe2.runs == 1)
    stop2.set()
    t2.join(3)


def test_a_crashing_cycle_does_not_kill_the_worker():
    class Boom(FakePipeline):
        def run_once(self, **kw):
            self.runs += 1
            raise RuntimeError("boom")

    pipe = Boom()
    ctl = FakeControl(Desired(True, "running", 0, 1))
    clock = [0.0]
    stop = threading.Event()
    w = Worker(pipe, ctl, 1, stop, tick=0.02, heartbeat=0.02, clock=lambda: clock[0])
    t = start(w)
    assert wait_for(lambda: pipe.runs == 1 and w._next_run > 0)      # the cycle has fully ended before time moves on
    clock[0] += 120
    assert wait_for(lambda: pipe.runs == 2)
    stop.set()
    t.join(3)


def test_temporary_hub_outage_keeps_the_last_known_state():
    class Flaky(FakeControl):
        down = False

        def sync(self, *a, **k):
            if self.down:
                return None
            return super().sync(*a, **k)

    ctl = Flaky(Desired(True, "paused", 0, 60))
    pipe = FakePipeline()
    w, stop = make(pipe, ctl)
    t = start(w)
    time.sleep(0.1)
    ctl.down = True
    ctl.desired = Desired(True, "running", 0, 60)
    time.sleep(0.2)
    assert pipe.runs == 0                                                # still paused: we never guess "running" from silence
    ctl.down = False
    assert wait_for(lambda: pipe.runs == 1)
    stop.set()
    t.join(3)


# ───────────────────────── HTTP client against the fake WordPress ─────────────────────────
def test_control_client_talks_to_the_hub(settings, wp_server):
    wp_server.control = {"state": "paused", "run_now": 77, "interval_minutes": 30, "server_time": 5}
    c = Control(WordPressClient(settings.wp_rest_url, settings.wp_auth_token), host="laptop")
    d = c.sync("working", "Auditor: revisando", next_run_at=99, last_run_at=11, handled_run_now=76, agent="auditor")
    assert d == Desired(True, "paused", 77, 30) and d.paused
    sent = wp_server.syncs[-1]
    assert sent["status"] == "working" and sent["host"] == "laptop" and sent["handled_run_now"] == 76 and sent["version"] and sent["agent"] == "auditor"
    assert wp_server.requests[-1]["auth"].startswith("Basic ")


def test_control_client_degrades_gracefully(settings, wp_server):
    c = Control(WordPressClient(settings.wp_rest_url, settings.wp_auth_token))
    wp_server.control = None                                             # plugin without the endpoint -> 404
    assert c.sync("idle") == Desired(available=False) and not c.sync("idle").paused
    wp_server.control = {"state": "weird"}
    assert c.sync("idle").available is False
    dead = Control(WordPressClient("http://127.0.0.1:1", "a:b", timeout=1))
    assert dead.sync("idle") is None                                     # unreachable: caller keeps the last state


def test_pipeline_pause_hook_stops_between_stories(settings, wp_server):
    import datetime as dt

    from conftest import FakeFetcher, FakeLLM, article_page
    from lehigh_agents.llm import Grounded
    from lehigh_agents.pipeline import Pipeline
    from test_agents import ARTICLE_JSON, FACTS, IMG_PROMPT, FakeGen, audit_json

    today = dt.date.today().isoformat()
    urls = ["https://wink.example/a", "https://news.example/b", "https://fox.example/c"]
    fetch = FakeFetcher({u: article_page(t) for u, t in zip(urls, ["Road approved", "Fire downtown", "School cameras"])})
    grounded = Grounded("n", ["q"], [{"uri": u, "title": "x", "domain": "x"} for u in urls])
    items = [{"titulo_fuente": t, "url": u, "resumen_hechos": FACTS, "fecha": today, "palabras_clave": [k]}
             for t, u, k in zip(["Road approved", "Fire downtown", "School cameras"], urls, ["road", "fire", "school"])]
    llm = FakeLLM(settings, {"rastreador": [items], "redactor": [ARTICLE_JSON], "imagen": [{"image_prompt": IMG_PROMPT, "alt_text": "a"}],
                             "auditor": [audit_json(92)]}, grounded)
    pipe = Pipeline(settings, llm=llm, wp=WordPressClient(settings.wp_rest_url, settings.wp_auth_token), imagegen=FakeGen(), fetcher=fetch)
    seen, events = [], []
    report = pipe.run_once(should_stop=lambda: len(seen) >= 1 or seen.append(1) or False, on_event=events.append)
    assert report["stopped_early"] is True and len(report["items"]) == 1 and report["counts"]["found"] == 3
    assert any(e["agent"] == "redactor" for e in events)                 # live progress was delivered
    assert pipe.store.is_duplicate(urls[1], "Fire downtown") is None     # the unprocessed stories are still pending
