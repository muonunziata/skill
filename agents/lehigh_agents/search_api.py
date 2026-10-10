"""Tavily web search (https://tavily.com): finds news pages so the Rastreador does not depend on Gemini's Google Search.

Free plan: 1,000 credits a month, no card. A basic search costs 1 credit; the client keeps its own monthly counter
(TAVILY_MONTHLY_LIMIT) so the agents never run the account dry. Results are only *candidates*: the Rastreador still opens
every page and builds its findings from what it actually read.
"""
from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlsplit

import requests

from .store import Store

log = logging.getLogger("lehigh.tavily")
ENDPOINT = "https://api.tavily.com/search"


class SearchError(Exception):
    pass


@dataclass
class SearchHit:
    url: str
    title: str
    snippet: str
    published: str


def month_start(now: float | None = None) -> float:
    t = time.gmtime(now or time.time())
    return time.mktime((t.tm_year, t.tm_mon, 1, 0, 0, 0, 0, 0, 0)) - time.timezone


class TavilyClient:
    provider = "tavily"

    def __init__(self, settings, store: Store | None = None, http: Any = None):
        self.s, self.store, self.http = settings, store, http or requests

    @property
    def enabled(self) -> bool:
        return bool(self.s.tavily_api_key)

    def budget_left(self) -> int:
        if self.store is None:
            return self.s.tavily_monthly_limit
        used = self.store.api_calls_since(self.provider, month_start())
        return max(0, self.s.tavily_monthly_limit - used)

    def queries(self) -> list[str]:
        """A few distinct queries from the configured topic and focus (each costs one credit)."""
        topic = self.s.topic
        base = [f"{topic} {f}" for f in self.s.focus] or [topic]
        return [f"{topic} noticias recientes"] + base[: max(0, self.s.tavily_queries - 1)] if self.s.tavily_queries > 1 \
            else [f"{topic} noticias recientes"]

    def search(self, query: str) -> list[SearchHit]:
        if not self.enabled:
            return []
        try:
            r = self.http.post(ENDPOINT, json={
                "query": query, "topic": "news", "days": max(1, self.s.freshness_days), "max_results": 8,
                "search_depth": "basic", "include_answer": False, "include_raw_content": False},
                headers={"Authorization": f"Bearer {self.s.tavily_api_key}", "Content-Type": "application/json"}, timeout=30)
        except requests.RequestException as exc:
            raise SearchError(f"No se pudo contactar con Tavily: {exc}") from exc
        if self.store is not None:
            self.store.log_api_call(self.provider)
        if r.status_code in (401, 403):
            raise SearchError("Tavily rechazó la clave (TAVILY_API_KEY). Cópiala de nuevo desde https://app.tavily.com")
        if r.status_code == 429 or r.status_code == 432 or r.status_code == 433:
            raise SearchError("Tavily: se agotó el plan gratuito de este mes (1.000 créditos) o el límite por minuto")
        if r.status_code >= 400:
            raise SearchError(f"Tavily respondió HTTP {r.status_code}: {r.text[:160]}")
        try:
            rows = r.json().get("results", [])
        except ValueError as exc:
            raise SearchError("Tavily respondió algo que no es JSON") from exc
        hits = []
        for row in rows if isinstance(rows, list) else []:
            url = str(row.get("url") or "")
            if urlsplit(url).scheme in ("http", "https") and urlsplit(url).netloc:
                hits.append(SearchHit(url, str(row.get("title") or ""), str(row.get("content") or "")[:300],
                                      str(row.get("published_date") or "")))
        return hits

    def find(self, emit) -> list[SearchHit]:
        """Run this run's queries within the monthly budget; errors become warnings, never crashes."""
        out: list[SearchHit] = []
        seen: set[str] = set()
        for q in self.queries():
            if self.budget_left() <= 0:
                emit("warn", f"Tavily: límite mensual propio alcanzado ({self.s.tavily_monthly_limit}); sin más búsquedas este mes", level="warn")
                break
            try:
                hits = self.search(q)
            except SearchError as exc:
                emit("warn", str(exc), level="warn")
                break
            emit("search", f"Búsqueda en Tavily: {q} ({len(hits)} resultados)", queries=[q])
            for h in hits:
                if h.url not in seen:
                    seen.add(h.url)
                    out.append(h)
        return out
