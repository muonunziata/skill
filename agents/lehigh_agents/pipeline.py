"""Orchestrates one run: Rastreador → (Redactor → Auditor [→ Redactor fix → Auditor]) per story → WordPress."""
from __future__ import annotations

import json
import logging
import time
from pathlib import Path
from typing import Any

from .agents import Auditor, Rastreador, Redactor
from .imagegen import ImageGenError, ImageGenerator
from .llm import LLM, LLMError
from .net import Fetcher
from .runlog import RunLog
from .schemas import Articulo, Hallazgo
from .settings import Settings
from .store import Store
from .wordpress import WPError, WordPressClient

log = logging.getLogger("lehigh.pipeline")


class Pipeline:
    def __init__(self, settings: Settings, llm: LLM | None = None, wp: WordPressClient | None = None,
                 imagegen: ImageGenerator | None = None, fetcher: Fetcher | None = None, store: Store | None = None,
                 dry_run: bool = False, make_images: bool = True):
        self.s = settings
        self.dry_run = dry_run
        self.llm = llm or LLM(settings)
        self.fetcher = fetcher or Fetcher()
        self.store = store or Store(settings.state_dir)
        self.wp = None if dry_run else (wp or WordPressClient(settings.wp_rest_url, settings.wp_auth_token))
        self.imagegen = imagegen or ImageGenerator(settings)
        self.make_images = make_images

    # ───────────────────────── one run ─────────────────────────
    def run_once(self) -> dict[str, Any]:
        runlog = RunLog()
        s = self.s
        self.llm.usage = {}  # token usage is reported per run; the LLM client lives for the whole `watch` session
        report: dict[str, Any] = {
            "run_id": runlog.run_id, "started_at": int(time.time()), "dry_run": self.dry_run,
            "models": {"rastreador": s.rastreador_model, "redactor": s.redactor_model, "auditor": s.auditor_model,
                       "image": f"{self.imagegen.provider}/{self.imagegen.model}" if self.imagegen.enabled and self.make_images else ""},
            "topic": s.topic, "items": [], "status": "running",
        }
        runlog("pipeline", "run_started", f"Ejecución {runlog.run_id} iniciada" + (" (modo prueba)" if self.dry_run else ""))
        rastreador = Rastreador(s, self.llm, self.fetcher, self.store, runlog)

        try:
            hallazgos = rastreador.run()
        except (LLMError, WPError) as exc:
            runlog("rastreador", "error", f"El rastreador falló: {exc}", level="error")
            hallazgos = []
            report["error"] = str(exc)
        runlog("pipeline", "step", f"{len(hallazgos)} hallazgo(s) para redactar")

        for h in hallazgos:
            report["items"].append(self._process(h, runlog))

        report["finished_at"] = int(time.time())
        report["counts"] = {k: sum(1 for i in report["items"] if i["status"] == k)
                            for k in ("approved", "flagged", "failed")}
        report["counts"]["found"] = len(hallazgos)
        report["status"] = "error" if report.get("error") else "ok"
        report["usage"] = self.llm.usage
        runlog("pipeline", "run_finished", f"Ejecución terminada: {report['counts']}")
        report["events"] = runlog.events
        self._save(report)
        if self.wp is not None:
            self.wp.post_run_report(self._wp_report(report))
        return report

    def _process(self, h: Hallazgo, runlog: RunLog) -> dict[str, Any]:
        item_key = h.url
        emit = runlog.bind(item_key)
        item: dict[str, Any] = {"titulo_fuente": h.titulo_fuente, "url": h.url, "status": "failed"}
        redactor = Redactor(self.s, self.llm, self.imagegen, self.fetcher, emit, self.wp, self.make_images)
        auditor = Auditor(self.s, self.llm, self.fetcher, emit, self.wp)
        art: Articulo | None = None
        posted = False
        try:
            art = redactor.run(h)
            aud = auditor.audit(h, art)
            revisions = 0
            while aud.status != "approved" and revisions < self.s.max_revisions and aud.audit_notes:
                revisions += 1
                art = redactor.revise(h, art, aud.audit_notes)
                aud = auditor.audit(h, art)
            outcome = auditor.review_and_publish(
                h, art, aud, run_id=runlog.run_id, trace=runlog.trace(item_key),
                extra_meta={"lnh_revisions": revisions})
            item.update(status=aud.status, audit_score=aud.audit_score, audit_notes=aud.audit_notes,
                        post_title=art.post_title, featured_image_url=art.featured_image_url,
                        featured_image_ai=art.featured_image_ai, revisions=revisions,
                        posted=outcome["posted"], reason=outcome.get("reason", ""),
                        post=outcome.get("post"), article=art.to_dict(), finding=h.to_dict())
            posted = outcome["posted"]
            self._remember(h, "published" if posted else aud.status)
        except (LLMError, ImageGenError, WPError) as exc:
            emit("pipeline", "error", f"Falló «{h.titulo_fuente}»: {exc}", level="error")
            item["error"] = str(exc)
            self._remember(h, "failed")
            # don't leave the AI image we uploaded for an article that never reached WordPress
            if art is not None and not posted and self.wp is not None and art.featured_media_id:
                if self.wp.delete_media(art.featured_media_id):
                    emit("pipeline", "cleanup", f"Imagen {art.featured_media_id} eliminada de la biblioteca de medios")
        return item

    # ───────────────────────── persistence ─────────────────────────
    def _remember(self, h: Hallazgo, status: str) -> None:
        """Anti-duplicate memory. A dry run must not consume stories: the real run that follows should still see them."""
        if not self.dry_run:
            self.store.remember(h.url, h.titulo_fuente, status)

    def _save(self, report: dict[str, Any]) -> None:
        out = Path(self.s.state_dir) / "runs"
        out.mkdir(parents=True, exist_ok=True)
        (out / f"{report['run_id']}.json").write_text(json.dumps(report, ensure_ascii=False, indent=2, default=str))

    @staticmethod
    def _wp_report(report: dict[str, Any]) -> dict[str, Any]:
        """Compact version of the report for the plugin's Agent Activity screen (no article bodies)."""
        items = [{k: i.get(k) for k in ("titulo_fuente", "url", "status", "audit_score", "post_title", "featured_image_ai",
                                        "revisions", "posted", "error")} | {"post_id": (i.get("post") or {}).get("id")}
                 for i in report["items"]]
        return {k: report.get(k) for k in ("run_id", "started_at", "finished_at", "status", "models", "topic", "counts",
                                           "dry_run", "usage")} | {"items": items, "events": report["events"][-300:]}
