"""Local SQLite memory so the researcher never reports the same story twice."""
from __future__ import annotations

import re
import sqlite3
import time
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

_TRACKING = re.compile(r"^(utm_|fbclid|gclid|mc_|ref$|ref_|cmpid|igshid|ocid)", re.I)
_STOP = {"the", "a", "an", "of", "in", "on", "for", "to", "and", "at", "is", "are", "with", "by", "from"}


def canonical_url(url: str) -> str:
    parts = urlsplit(url.strip())
    host = parts.netloc.lower().removeprefix("www.")
    query = urlencode(sorted((k, v) for k, v in parse_qsl(parts.query) if not _TRACKING.match(k)))
    path = parts.path.rstrip("/") or "/"
    return urlunsplit((parts.scheme.lower(), host, path, query, ""))


def title_tokens(title: str) -> set[str]:
    return {w for w in re.findall(r"[a-z0-9áéíóúñü]+", title.lower()) if w not in _STOP and len(w) > 1}


def similarity(a: str, b: str) -> float:
    ta, tb = title_tokens(a), title_tokens(b)
    if not ta or not tb:
        return 0.0
    return len(ta & tb) / len(ta | tb)


class Store:
    def __init__(self, state_dir: str | Path):
        self.dir = Path(state_dir)
        self.dir.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(self.dir / "agents.sqlite3")
        self.db.execute(
            "CREATE TABLE IF NOT EXISTS seen (url TEXT PRIMARY KEY, title TEXT, status TEXT, ts REAL)"
        )
        self.db.execute("CREATE TABLE IF NOT EXISTS counters (day TEXT, kind TEXT, n INTEGER, PRIMARY KEY(day, kind))")
        self.db.commit()

    def close(self) -> None:
        self.db.close()

    RETRY_FAILED_AFTER = 6 * 3600  # a story that failed (model/API error) may be retried after this many seconds

    def is_duplicate(self, url: str, title: str, threshold: float = 0.8) -> str | None:
        """Return a reason string if the story is already known, else None."""
        cu = canonical_url(url)
        now = time.time()
        row = self.db.execute("SELECT status, ts FROM seen WHERE url=?", (cu,)).fetchone()
        if row and not (row[0] == "failed" and now - row[1] > self.RETRY_FAILED_AFTER):
            return "same URL already processed"
        for known, status in self.db.execute("SELECT title, status FROM seen ORDER BY ts DESC LIMIT 500"):
            if status != "failed" and similarity(title, known or "") >= threshold:
                return f"near-duplicate of '{known}'"
        return None

    def remember(self, url: str, title: str, status: str) -> None:
        self.db.execute(
            "INSERT INTO seen(url,title,status,ts) VALUES(?,?,?,?) "
            "ON CONFLICT(url) DO UPDATE SET status=excluded.status, ts=excluded.ts",
            (canonical_url(url), title, status, time.time()),
        )
        self.db.commit()

    def recent_titles(self, limit: int = 40) -> list[str]:
        return [t for (t,) in self.db.execute("SELECT title FROM seen ORDER BY ts DESC LIMIT ?", (limit,)) if t]

    def count_today(self, kind: str) -> int:
        row = self.db.execute(
            "SELECT n FROM counters WHERE day=? AND kind=?", (time.strftime("%Y-%m-%d"), kind)
        ).fetchone()
        return row[0] if row else 0

    def bump(self, kind: str, n: int = 1) -> None:
        day = time.strftime("%Y-%m-%d")
        self.db.execute(
            "INSERT INTO counters(day,kind,n) VALUES(?,?,?) ON CONFLICT(day,kind) DO UPDATE SET n=n+excluded.n",
            (day, kind, n),
        )
        self.db.commit()
