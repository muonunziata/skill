"""The always-on worker behind `python main.py watch`: runs the pipeline when the hub says "running", idles when "paused".

Without the plugin's control endpoint (or with --no-control) it behaves like a plain scheduler: run, wait LOOP_INTERVAL_MINUTES, repeat.
"""
from __future__ import annotations

import logging
import threading
import time
from typing import Any, Callable

from .control import Control, Desired

log = logging.getLogger("lehigh.worker")


class Worker:
    def __init__(self, pipeline, control: Control | None, interval_minutes: int, stop: threading.Event | None = None,
                 tick: float = 15, heartbeat: float = 10, clock: Callable[[], float] = time.time):
        self.pipeline = pipeline
        self.control = control
        self.interval_minutes = interval_minutes
        self.stop = stop or threading.Event()
        self.tick, self.heartbeat, self.clock = tick, heartbeat, clock
        self._desired = Desired(available=False)
        self._lock = threading.Lock()
        self._handled = 0
        self._next_run = 0.0       # 0 = run as soon as we are allowed to
        self._last_run = 0
        self._message = ""

    # ───────────────────────── plumbing ─────────────────────────
    def _sync(self, status: str, message: str = "") -> Desired:
        if self.control is None:
            return self._desired
        d = self.control.sync(status, message, next_run_at=int(self._next_run), last_run_at=self._last_run,
                              handled_run_now=self._handled)
        with self._lock:
            if d is not None:
                self._desired = d
            return self._desired

    def _interval(self) -> float:
        d = self._desired
        return (d.interval_minutes if d.available and d.interval_minutes else self.interval_minutes) * 60

    def _should_stop(self) -> bool:
        """Checked by the pipeline between stories: Pause never cuts an article in half."""
        with self._lock:
            return self.stop.is_set() or self._desired.paused

    def _on_event(self, event: dict[str, Any]) -> None:
        self._message = f"{event.get('agent', '')}: {event.get('message', '')}"[:200]

    # ───────────────────────── one cycle ─────────────────────────
    def _cycle(self) -> None:
        done = threading.Event()

        def beat() -> None:
            while not done.wait(self.heartbeat):
                self._sync("working", self._message)

        self._message = "Iniciando ejecución…"
        self._sync("working", self._message)          # tells the hub right away that the "Run now" request was taken
        t = threading.Thread(target=beat, daemon=True, name="lehigh-heartbeat")
        t.start()
        try:
            self.pipeline.run_once(should_stop=self._should_stop, on_event=self._on_event)
        except Exception:  # noqa: BLE001 - a bad cycle must not kill the daemon
            log.exception("run failed")
        finally:
            done.set()
            t.join(timeout=5)
        self._last_run = int(self.clock())
        self._next_run = self.clock() + self._interval()
        self._message = ""

    # ───────────────────────── main loop ─────────────────────────
    def run(self) -> None:
        try:
            while not self.stop.is_set():
                d = self._sync("idle", self._message)
                if d.paused:
                    self._next_run = 0                  # "Start working" must begin a run right away
                else:
                    run_now = d.available and d.run_now and d.run_now != self._handled
                    if run_now or self._next_run == 0 or self.clock() >= self._next_run:
                        if run_now:
                            self._handled = d.run_now
                        self._cycle()
                        continue                        # re-read the desired state right after a cycle
                self.stop.wait(self.tick)
        finally:
            if self.control is not None:
                self.control.sync("stopped", "Agentes detenidos")
