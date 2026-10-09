"""In-memory activity log for one run; feeds the WordPress 'Agent Activity' screens and per-post traces."""
from __future__ import annotations

import logging
import time
import uuid
from typing import Any

log = logging.getLogger("lehigh.run")


class RunLog:
    def __init__(self, run_id: str | None = None):
        self.run_id = run_id or time.strftime("%Y%m%d-%H%M%S-") + uuid.uuid4().hex[:6]
        self.events: list[dict[str, Any]] = []
        self.started = time.time()
        self.listener = None   # optional callable(event) used by the worker to report live progress

    def __call__(self, agent: str, type_: str, message: str, item: str = "", level: str = "info", **data: Any) -> None:
        self.events.append({"t": round(time.time() - self.started, 2), "ts": int(time.time()), "agent": agent,
                            "type": type_, "level": level, "message": message[:400], "item": item, "data": data})
        if self.listener is not None:
            try:
                self.listener(self.events[-1])
            except Exception:  # noqa: BLE001 - progress reporting must never break a run
                pass
        log.log({"error": 40, "warn": 30}.get(level, 20), "[%s] %s", agent, message)

    def bind(self, item: str):
        def emit(agent: str, type_: str, message: str, **data: Any) -> None:
            self(agent, type_, message, item=item, **data)

        return emit

    def trace(self, item: str) -> list[dict[str, Any]]:
        """Run-level events plus those tagged with `item`, compacted for storage in post meta."""
        keep = ({"agent", "type", "message", "ts", "level"})
        return [{k: v for k, v in e.items() if k in keep} for e in self.events if e["item"] in ("", item)]

    def queries(self) -> list[str]:
        return [q for e in self.events for q in e["data"].get("queries", [])]
