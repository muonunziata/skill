"""Connection to the plugin's Start / Pause button: the agents call out to WordPress, never the other way round."""
from __future__ import annotations

import logging
import socket
from dataclasses import dataclass
from typing import Any

from . import __version__
from .wordpress import WPError, WordPressClient

log = logging.getLogger("lehigh.control")


@dataclass
class Desired:
    """What the editor asked for. `available` is False when WordPress has no (or an older) Lehigh News Hub plugin."""
    available: bool = False
    state: str = "running"      # running | paused
    run_now: int = 0            # timestamp of a pending "Run now" click, 0 = none
    interval_minutes: int = 0   # 0 = use LOOP_INTERVAL_MINUTES

    @property
    def paused(self) -> bool:
        return self.available and self.state == "paused"


class Control:
    def __init__(self, wp: WordPressClient, host: str | None = None):
        self.wp = wp
        self.host = (host or socket.gethostname() or "agents")[:80]

    def sync(self, status: str, message: str = "", next_run_at: int = 0, last_run_at: int = 0,
             handled_run_now: int = 0, agent: str = "") -> Desired | None:
        """Report our status, receive the desired state. None = temporary problem (keep the last known state)."""
        payload: dict[str, Any] = {"status": status, "message": message[:200], "host": self.host, "version": __version__,
                                   "next_run_at": int(next_run_at), "last_run_at": int(last_run_at),
                                   "handled_run_now": int(handled_run_now), "agent": agent}
        try:
            data = self.wp._req("POST", "/lnh/v1/control/sync", json=payload)
        except WPError as exc:
            if exc.status in (404, 401, 403):   # no plugin / old plugin / not allowed: run on our own schedule
                return Desired(available=False)
            log.info("control sync failed (will retry): %s", exc)
            return None
        if not isinstance(data, dict) or data.get("state") not in ("running", "paused"):
            return Desired(available=False)
        return Desired(available=True, state=data["state"], run_now=int(data.get("run_now") or 0),
                       interval_minutes=int(data.get("interval_minutes") or 0))
