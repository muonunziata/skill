"""Optional extra news source: Mediastack (APILayer).

The Researcher already discovers stories with Google Search. Mediastack adds structured results from thousands of
publishers; its URLs go through the same "open and read the real page" step, so nothing here is trusted blindly.

The free plan is tiny (100 requests per month) and, according to third-party reports, may be HTTP-only, so:
  * calls are rate-limited (MEDIASTACK_MIN_HOURS) and capped per month (MEDIASTACK_MONTHLY_LIMIT),
  * HTTPS is tried first and HTTP is used only if the plan rejects HTTPS (never silently: it is reported),
  * every problem is reported as an event and never stops the run.
"""
from __future__ import annotations

import datetime as dt
import logging
import time
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlsplit

import requests

from .settings import Settings
from .store import Store

log = logging.getLogger("lehigh.mediastack")

BASE = "api.mediastack.com/v1/news"


class NewsAPIError(Exception):
    pass


@dataclass
class NewsItem:
    title: str
    url: str
    source: str
    published: str
    description: str = ""


class MediastackClient:
    provider = "mediastack"

    def __init__(self, settings: Settings, store: Store, http: Any = requests, base: str = BASE):
        self.s, self.store, self.http, self.base = settings, store, http, base

    @property
    def enabled(self) -> bool:
        return bool(self.s.mediastack_api_key)

    # ───────────────────────── budget ─────────────────────────
    def _month_start(self) -> float:
        now = dt.datetime.now(dt.timezone.utc)
        return dt.datetime(now.year, now.month, 1, tzinfo=dt.timezone.utc).timestamp()

    def allowance(self) -> str | None:
        """Why a call must NOT be made now (None = go ahead)."""
        last = self.store.last_api_call(self.provider)
        if last and time.time() - last < self.s.mediastack_min_hours * 3600:
            return f"última consulta hace menos de {self.s.mediastack_min_hours} h"
        used = self.store.api_calls_since(self.provider, self._month_start())
        if used >= self.s.mediastack_monthly_limit:
            return f"límite mensual alcanzado ({used}/{self.s.mediastack_monthly_limit})"
        return None

    # ───────────────────────── request ─────────────────────────
    def _get(self, scheme: str, params: dict[str, Any]) -> dict[str, Any]:
        try:
            r = self.http.get(f"{scheme}://{self.base}", params=params, timeout=20)
            data = r.json()
        except (requests.RequestException, ValueError) as exc:
            raise NewsAPIError(f"no se pudo consultar Mediastack: {exc}") from exc
        return data if isinstance(data, dict) else {}

    def fetch(self, say=lambda *a, **k: None) -> list[NewsItem]:
        """Latest matching articles, newest first. Returns [] when disabled, throttled or on error."""
        if not self.enabled:
            return []
        why = self.allowance()
        if why:
            say("skipped", f"Mediastack omitido: {why}")
            return []
        s = self.s
        since = (dt.date.today() - dt.timedelta(days=s.freshness_days)).isoformat()
        params = {
            "access_key": s.mediastack_api_key,
            "keywords": s.topic.split(",")[0].strip(),
            "countries": s.mediastack_countries,
            "languages": s.mediastack_languages,
            "date": f"{since},{dt.date.today().isoformat()}",
            "sort": "published_desc",
            "limit": s.mediastack_limit,
        }
        scheme = "http" if s.mediastack_https == "false" else "https"
        data = self._get(scheme, params)
        err = data.get("error")
        if err and err.get("code") == "https_access_restricted" and s.mediastack_https == "auto":
            say("warn", "Tu plan de Mediastack no admite HTTPS: se usa HTTP (la clave viaja sin cifrar). Considera un plan de pago.", level="warn")
            data = self._get("http", params)
            err = data.get("error")
        self.store.log_api_call(self.provider)  # a failed call still counts against the plan
        if err:
            raise NewsAPIError(f"Mediastack: {err.get('code', 'error')} – {str(err.get('message', ''))[:160]}")
        items: list[NewsItem] = []
        for row in data.get("data") or []:
            url = str(row.get("url") or "")
            if urlsplit(url).scheme not in ("http", "https") or not row.get("title"):
                continue
            items.append(NewsItem(title=str(row["title"]).strip(), url=url, source=str(row.get("source") or ""),
                                  published=str(row.get("published_at") or "")[:10], description=str(row.get("description") or "")))
        say("search", f"Mediastack: {len(items)} noticia(s) candidata(s)", queries=[f"mediastack:{params['keywords']}"])
        return items
