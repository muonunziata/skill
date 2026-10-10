"""Agent 1 - El Rastreador: finds recent local news with Google Search grounding and structures it as JSON.

Flow: grounded search (Gemini + Google Search) → open every cited page → build findings strictly from the pages
actually read, so URLs and facts are real rather than recalled.
"""
from __future__ import annotations

import datetime as dt
import logging
from urllib.parse import urlsplit

from .. import prompts
from ..llm import LLM, LLMError, provider_for
from ..media import parse_page
from ..net import FetchError, Fetcher
from ..news_api import NewsAPIError
from ..schemas import Hallazgo, clean_list, normalize_date, validate_hallazgos
from ..settings import Settings
from ..store import Store, canonical_url, similarity

log = logging.getLogger("lehigh.rastreador")

NOT_ARTICLES = {"facebook.com", "x.com", "twitter.com", "instagram.com", "tiktok.com", "youtube.com", "youtu.be",
                "reddit.com", "pinterest.com", "linkedin.com"}
MAX_PAGES = 12
MIN_PAGE_CHARS = 400


def _domain(url: str) -> str:
    return (urlsplit(url).hostname or "").lower().removeprefix("www.")


class Rastreador:
    name = "rastreador"

    def __init__(self, settings: Settings, llm: LLM, fetcher: Fetcher, store: Store, events, news_api=None, search_api=None):
        self.s, self.llm, self.fetcher, self.store, self.events = settings, llm, fetcher, store, events
        self.news_api = news_api
        self.search_api = search_api   # Tavily (optional)

    def _ev(self, type_: str, message: str, **kw) -> None:
        self.events(self.name, type_, message, **kw)

    # ───────────────────────── public ─────────────────────────
    def run(self) -> list[Hallazgo]:
        s = self.s
        today = dt.date.today().isoformat()
        self._ev("step", f"Buscando novedades de {s.topic} ({', '.join(s.focus)})")
        sources: list[dict[str, str]] = []
        if self._uses_gemini_search():
            grounded = self.llm.grounded_search(
                s.rastreador_model, prompts.RASTREADOR_SEARCH_SYSTEM,
                prompts.RASTREADOR_SEARCH.format(today=today, days=s.freshness_days, topic=s.topic, focus=", ".join(s.focus)),
            )
            for q in grounded.queries:
                self._ev("search", f"Búsqueda en Google: {q}", queries=[q])
            sources += list(grounded.sources)
        sources += self._tavily_sources()
        sources += self._extra_sources()
        if not sources:
            self._ev("warn", "La búsqueda no devolvió fuentes citables", level="warn")
            return []

        pages = self._read_sources(sources)
        self._ev("step", f"{len(pages)} página(s) leídas de {len(sources)} fuente(s) candidatas")
        if not pages:
            return []

        items: list[dict] = []
        for batch in self._batches(pages):
            listing = "\n\n".join(
                f"[P{i}] URL: {p['url']}\nTítulo: {p['title']}\nPublicada: {p['published'] or 'desconocida'}\n"
                f"Texto: {p['text'][:batch.chars]}" for i, p in enumerate(batch.pages, 1)
            )
            raw = self.llm.json(
                s.rastreador_model, prompts.RASTREADOR_STRUCTURE_SYSTEM,
                prompts.RASTREADOR_STRUCTURE.format(topic=s.topic, today=today, days=s.freshness_days, pages=listing,
                                                    n=s.max_items * 2, language=prompts.LANG.get(s.language, s.language)),
                validate=validate_hallazgos, max_tokens=8192, label="rastreador",
            )
            items += raw.get("hallazgos", []) if isinstance(raw, dict) else raw
        return self._to_hallazgos(items, pages)

    # ───────────────────────── search mode ─────────────────────────
    def _uses_gemini_search(self) -> bool:
        """Gemini + Google Search grounding, unless the user chose Tavily (or the model is not a Gemini one)."""
        s = self.s
        if s.search_mode == "tavily" or provider_for(s.rastreador_model) != "gemini":
            return False
        if s.search_mode == "auto" and self.search_api is not None and self.search_api.enabled and not s.gemini_api_key:
            return False
        return True

    def _tavily_sources(self) -> list[dict[str, str]]:
        if self.search_api is None or not self.search_api.enabled:
            return []
        hits = self.search_api.find(self._ev)
        return [{"uri": h.url, "title": h.title, "domain": h.title[:40]} for h in hits]

    def _batches(self, pages: list[dict[str, str]]):
        """Split the pages so one prompt fits the engine (OpenCode takes ~20k characters per request)."""
        from types import SimpleNamespace

        limit = self.llm.max_prompt_chars(self.s.rastreador_model)
        if not limit:
            return [SimpleNamespace(pages=pages, chars=4500)]
        per_page = 2500
        fit = max(1, limit // (per_page + 300))
        return [SimpleNamespace(pages=pages[i:i + fit], chars=per_page) for i in range(0, len(pages), fit)]

    # ───────────────────────── steps ─────────────────────────
    def _extra_sources(self) -> list[dict[str, str]]:
        """Optional Mediastack candidates; they are read like any other page, so they are verified the same way."""
        if self.news_api is None or not self.news_api.enabled:
            return []
        try:
            items = self.news_api.fetch(self._ev)
        except NewsAPIError as exc:
            self._ev("warn", f"{exc}", level="warn")
            return []
        return [{"uri": i.url, "title": i.source, "domain": i.source} for i in items[:8]]

    def _read_sources(self, sources: list[dict[str, str]]) -> list[dict[str, str]]:
        pages: list[dict[str, str]] = []
        seen: set[str] = set()
        for src in sources[: MAX_PAGES + 8]:
            if len(pages) >= MAX_PAGES:
                break
            try:
                res = self.fetcher.get(src["uri"], accept="text/html,application/xhtml+xml")
            except FetchError as exc:
                self._ev("fetch_failed", f"No se pudo abrir {src.get('domain') or src['uri'][:60]}: {exc}", level="warn")
                continue
            final = res.url
            domain = _domain(final)
            key = canonical_url(final)
            if key in seen or domain in NOT_ARTICLES:
                continue
            seen.add(key)
            info = parse_page(res.text(), final)
            if len(info.text) < MIN_PAGE_CHARS:
                self._ev("skipped", f"Página con poco texto, descartada: {domain}")
                continue
            self._ev("fetch", f"Leyendo {domain}: {info.title[:80]}", urls=[final])
            pages.append({"url": final, "title": info.title, "published": info.published, "text": info.text,
                          "site": info.site_name or domain})
        return pages

    def _to_hallazgos(self, items: list[dict], pages: list[dict[str, str]]) -> list[Hallazgo]:
        by_url = {canonical_url(p["url"]): p for p in pages}
        cutoff = dt.date.today() - dt.timedelta(days=self.s.freshness_days + 2)
        out: list[Hallazgo] = []
        for it in items:
            url = it["url"].strip()
            page = by_url.get(canonical_url(url))
            title = it["titulo_fuente"].strip()
            if page is None:
                self._ev("skipped", f"URL no proviene de las páginas leídas, descartada: {title}", level="warn")
                continue
            fecha = normalize_date(it.get("fecha")) or normalize_date(page["published"])
            if fecha and dt.date.fromisoformat(fecha) < cutoff:
                self._ev("skipped", f"Demasiado antigua ({fecha}): {title}")
                continue
            reason = self.store.is_duplicate(page["url"], title)
            if not reason:
                for prev in out:
                    if similarity(prev.titulo_fuente, title) >= 0.8:
                        reason = "ya incluida en esta ejecución"
                        break
            if reason:
                self._ev("skipped", f"Duplicada ({reason}): {title}")
                continue
            out.append(Hallazgo(
                titulo_fuente=title, url=page["url"], resumen_hechos=it["resumen_hechos"].strip(), fecha=fecha,
                palabras_clave=clean_list(it.get("palabras_clave"), 8), source_text=page["text"][:12000],
                fuente=page["site"],
            ))
            self._ev("finding", f"Hallazgo: {title}", urls=[page["url"]])
            if len(out) >= self.s.max_items:
                break
        return out
