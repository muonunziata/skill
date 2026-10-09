"""Agent 3 - El Auditor (Quality & Fact Checker).

Compares the article with Agent 1's facts (and the original source text), checks spelling, links and HTML safety, assigns
`audit_score` / `audit_notes` / `status`, and - when approved - POSTs the draft with its audit metadata to WordPress.
"""
from __future__ import annotations

import datetime as dt
import json
import logging
from typing import Any

from .. import prompts
from ..checks import (check_links, extract_urls, source_overlap, strip_tags, unsafe_html_problems,
                      unsupported_numbers, word_count)
from ..htmlutil import slugify
from ..llm import LLM, LLMError
from ..net import Fetcher
from ..schemas import Articulo, Auditoria, Hallazgo, validate_auditoria
from ..settings import Settings
from ..wordpress import WPError, WordPressClient

log = logging.getLogger("lehigh.auditor")


class Auditor:
    name = "auditor"

    def __init__(self, settings: Settings, llm: LLM, fetcher: Fetcher, events, wp: WordPressClient | None = None):
        self.s, self.llm, self.fetcher, self.events, self.wp = settings, llm, fetcher, events, wp

    def _ev(self, type_: str, message: str, **kw) -> None:
        self.events(self.name, type_, message, **kw)

    # ───────────────────────── audit ─────────────────────────
    def audit(self, h: Hallazgo, art: Articulo) -> Auditoria:
        s = self.s
        notes: list[str] = []
        critical = False
        penalty = 0
        checks: dict[str, Any] = {}
        text = strip_tags(art.post_content)
        evidence = f"{h.resumen_hechos}\n{h.source_text}"

        # 1. links (article + source + featured image + videos)
        urls = extract_urls(art.post_content)
        for u in (h.url, art.featured_image_url):
            if u and u.startswith("http") and u not in urls:
                urls.append(u)
        links = check_links(self.fetcher, urls)
        broken = [r for r in links if r.state == "broken"]
        unver = [r for r in links if r.state == "unverifiable"]
        checks["links"] = {"checked": len(links), "broken": [r.url for r in broken], "unverifiable": [r.url for r in unver]}
        for r in broken:
            notes.append(f"[auto] Enlace roto: {r.url} ({r.detail})")
        penalty += min(30, 10 * len(broken))
        self._ev("check", f"Enlaces: {len(links) - len(broken) - len(unver)} correctos, {len(broken)} rotos, {len(unver)} no verificables")

        # 2. HTML safety
        problems = unsafe_html_problems(art.post_content)
        checks["unsafe_html"] = problems
        if problems:
            critical = True
            penalty += 40
            notes.append("[auto] HTML inseguro: " + "; ".join(problems))

        # 3. figures not present in the evidence
        figs = unsupported_numbers(text, evidence)
        checks["unsupported_numbers"] = figs
        if figs:
            penalty += min(16, 4 * len(figs))
            notes.append("[auto] Cifras que no aparecen en la fuente: " + ", ".join(figs))

        # 4. verbatim copying
        overlap = round(source_overlap(text, h.source_text), 3)
        checks["source_overlap"] = overlap
        if overlap > 0.30:
            critical = True
            penalty += 35
            notes.append(f"[auto] Copia literal excesiva de la fuente ({overlap:.0%} de las secuencias de 8 palabras).")
        elif overlap > 0.15:
            penalty += 15
            notes.append(f"[auto] Demasiada similitud con la fuente ({overlap:.0%}); reescribe con palabras propias.")

        # 5. length and metadata
        words = word_count(art.post_content)
        checks["words"] = words
        if words < 250 or words > 1400:
            penalty += 5
            notes.append(f"[auto] Extensión inusual ({words} palabras).")
        if len(art.meta_description) > 160 or len(art.post_title) > 90:
            penalty += 2
            notes.append("[auto] Titular (>90) o meta descripción (>160) demasiado largos para SEO.")

        # 6. AI image must be labelled
        if art.featured_image_ai:
            checks["ai_image_labelled"] = bool(art.featured_image_caption)
            if not art.featured_image_caption:
                penalty += 10
                notes.append("[auto] La imagen destacada es generada por IA y no lleva etiqueta de divulgación.")

        # 7. model-based fact check + spelling
        llm_score, llm_status, llm_notes, llm_model = 0, "flagged", [], s.auditor_model
        try:
            data = self.llm.json(
                s.auditor_model, prompts.AUDITOR_SYSTEM.format(topic=s.topic),
                prompts.AUDITOR.format(
                    fuente=h.fuente or h.url, url=h.url, fecha=h.fecha or "sin fecha", hechos=h.resumen_hechos,
                    source_text=h.source_text[:6000], title=art.post_title, excerpt=art.excerpt,
                    meta=art.meta_description, body=art.post_content[:9000], checks=json.dumps(checks, ensure_ascii=False),
                    language=prompts.LANG.get(s.language, s.language), min_score=s.audit_min_score),
                validate=validate_auditoria, max_tokens=4096, label="auditor",
            )
            llm_score = int(round(float(data["audit_score"])))
            llm_status = data["status"]
            llm_notes = [str(n).strip() for n in data.get("audit_notes", []) if str(n).strip()]
        except LLMError as exc:
            notes.append(f"[auto] La auditoría con IA no pudo completarse: {exc}")
            llm_score = min(60, 100 - penalty)  # never approve without the model's fact check
            critical = True

        score = max(0, min(100, llm_score - penalty))
        status = "approved" if (score >= s.audit_min_score and llm_status == "approved" and not critical) else "flagged"
        result = Auditoria(audit_score=score, audit_notes=llm_notes + notes, status=status, checks=checks, model=llm_model)
        self._ev("audit", f"Auditoría: {score}/100 → {status.upper()} ({len(result.audit_notes)} nota(s))",
                 score=score, status=status, model=llm_model)
        return result

    # ───────────────────────── publish ─────────────────────────
    def review_and_publish(self, h: Hallazgo, art: Articulo, aud: Auditoria, run_id: str = "",
                           trace: list[dict[str, Any]] | None = None, extra_meta: dict[str, Any] | None = None) -> dict[str, Any]:
        """POST to WordPress: approved → draft, flagged → pending only if POST_FLAGGED=true. Returns the outcome."""
        s = self.s
        payload = self._payload(h, art, aud, run_id, trace or [], extra_meta or {})
        if aud.status == "approved":
            payload["status"] = "draft"
        elif s.post_flagged:
            payload["status"] = "pending"
        else:
            self._ev("hold", "Artículo marcado (flagged): no se envía a WordPress")
            self._discard_media(art)
            return {"posted": False, "reason": "flagged", "payload": payload}
        if self.wp is None:
            self._ev("dry_run", f"Modo prueba: no se envía a WordPress (estado {payload['status']})")
            return {"posted": False, "reason": "dry_run", "payload": payload}
        payload["tags"] = self.wp.resolve_tags(art.suggested_tags)
        if art.featured_media_id:
            payload["featured_media"] = art.featured_media_id
        post = self.wp.create_post(payload)
        self._ev("submitted", f"Enviado a WordPress como {post['status']} (ID {post['id']})", post_id=post["id"],
                 link=post["edit_link"])
        return {"posted": True, "post": post, "payload": payload}

    def _discard_media(self, art: Articulo) -> None:
        """A flagged article is not sent, so remove the AI image we uploaded for it (avoid orphans)."""
        if self.wp is not None and art.featured_media_id and self.wp.delete_media(art.featured_media_id):
            self._ev("cleanup", f"Imagen {art.featured_media_id} eliminada de la biblioteca de medios")
            art.featured_media_id = 0

    def _payload(self, h: Hallazgo, art: Articulo, aud: Auditoria, run_id: str, trace: list[dict[str, Any]],
                 extra: dict[str, Any]) -> dict[str, Any]:
        s = self.s
        meta = {
            "lnh_audit_score": aud.audit_score,
            "lnh_audit_status": aud.status,
            "lnh_audit_notes": json.dumps(aud.audit_notes, ensure_ascii=False),
            "lnh_audit_checks": json.dumps(aud.checks, ensure_ascii=False),
            "lnh_source_title": h.titulo_fuente,
            "lnh_source_url": h.url,
            "lnh_source_name": h.fuente,
            "lnh_source_date": h.fecha,
            "lnh_keywords": json.dumps(h.palabras_clave, ensure_ascii=False),
            "lnh_facts": h.resumen_hechos,
            "lnh_meta_description": art.meta_description,
            "lnh_featured_image_url": art.featured_image_url,
            "lnh_featured_image_ai": bool(art.featured_image_ai),
            "lnh_image_prompt": art.featured_image_prompt,
            "lnh_image_provider": f"{art.image_provider}/{art.image_model}" if art.image_provider else "",
            "lnh_media_todo": json.dumps(art.media_todo, ensure_ascii=False),
            "lnh_agent_models": json.dumps({"rastreador": s.rastreador_model, "redactor": s.redactor_model,
                                            "auditor": s.auditor_model}),
            "lnh_trace": json.dumps(trace, ensure_ascii=False)[:60000],
            "lnh_run_id": run_id,
            "lnh_language": s.language,
            "lnh_generated_at": dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"),
            **extra,
        }
        return {
            "title": art.post_title,
            "content": art.post_content,
            "excerpt": art.excerpt,
            "slug": slugify(art.post_title),
            "meta": meta,
        }
