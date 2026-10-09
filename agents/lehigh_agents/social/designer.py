"""Agent 4 - El Diseñador Social.

Takes an article that already passed the Auditor and produces, on the GoLehighAcres.org brand:
  * an Instagram carousel (1080x1350 PNG slides) and a TikTok photo carousel (1080x1920 PNG slides),
  * a vertical 9:16 video (MP4, optional narration),
  * captions + hashtags + alt text, ready to copy.
Files are saved to state/social/<slug>/, uploaded to the WordPress media library, and summarised in the post meta `lnh_social`
(the plugin shows it on the review screen). Publishing to the networks is left to a person (or to a webhook, e.g. Make/Zapier).
"""
from __future__ import annotations

import hashlib
import hmac
import json
import logging
import re
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

import requests

from .. import prompts
from ..htmlutil import slugify
from ..llm import LLM
from ..settings import Settings
from . import plan as planmod
from .brand import Brand, load_brand
from .render import SlideRenderer, SocialRenderError, prepare_photo
from .templates import FORMATS, scene_html, slide_html, strings
from .video import VideoError, build_video
from .voice import Narrator, VoiceError, build_track, wav_info

log = logging.getLogger("lehigh.social")

FOCUS = ["50% 40%", "35% 55%", "65% 45%", "50% 60%", "40% 35%", "60% 55%", "50% 50%", "45% 65%", "55% 35%"]
OUTRO_SECONDS = 2.6
MIN_SCENE = 2.4
MAX_SCENE = 14.0


class SocialError(Exception):
    pass


@dataclass
class ArticleContext:
    title: str
    excerpt: str
    body: str                     # plain text of the article
    fuente: str = ""
    url: str = ""
    keywords: list[str] = field(default_factory=list)
    language: str = "es"
    photo: bytes = b""            # featured image (AI or archive); empty = brand-only design
    photo_ai: bool = False
    photo_credit: str = ""
    post_id: int = 0
    post_link: str = ""
    evidence: str = ""            # text the figures are checked against (article + finding facts)


@dataclass
class SocialKit:
    slug: str
    folder: str
    plan: dict[str, Any]
    files: dict[str, list[str]]                      # "instagram"|"tiktok"|"video" -> file names (relative to folder)
    uploaded: dict[str, list[dict[str, Any]]] = field(default_factory=dict)  # same keys -> [{id,url,name}]
    ai_image: bool = False
    voice: str = ""
    seconds: float = 0.0
    model: str = ""
    warnings: list[str] = field(default_factory=list)

    def to_meta(self) -> dict[str, Any]:
        """Compact JSON stored in the `lnh_social` post meta and rendered by the plugin."""
        p = self.plan
        return {
            "version": 1, "generated_at": int(time.time()), "model": self.model, "ai_image": self.ai_image,
            "voice": self.voice, "seconds": round(self.seconds, 1), "hook": p["hook"],
            "instagram": {"caption": p["instagram_caption"], "slides": self.uploaded.get("instagram", [])},
            "tiktok": {"caption": p["tiktok_caption"], "slides": self.uploaded.get("tiktok", [])},
            "video": (self.uploaded.get("video") or [None])[0],
            "hashtags": p["hashtags"], "alt_text": p["alt_text"], "warnings": self.warnings,
        }


def article_context(h, art, s: Settings, post: dict | None = None, photo: bytes = b"") -> ArticleContext:
    from ..htmlutil import strip_tags

    return ArticleContext(
        title=art.post_title, excerpt=art.excerpt, body=strip_tags(art.post_content), fuente=h.fuente or "la fuente",
        url=h.url, keywords=list(h.palabras_clave), language=s.language, photo=photo or art.featured_image_bytes,
        photo_ai=bool(art.featured_image_ai), photo_credit=art.featured_image_credit,
        post_id=int((post or {}).get("id") or 0), post_link=(post or {}).get("link", ""),
        evidence=f"{h.resumen_hechos}\n{h.source_text}\n{strip_tags(art.post_content)}")


class SocialDesigner:
    name = "social"

    def __init__(self, settings: Settings, llm: LLM, fetcher=None, events=None, wp=None, narrator: Narrator | None = None,
                 renderer_factory=None, http=None):
        self.s, self.llm, self.fetcher, self.wp = settings, llm, fetcher, wp
        self.events = events or (lambda *a, **k: None)
        self.narrator = narrator or Narrator(settings)
        self.renderer_factory = renderer_factory or (lambda: SlideRenderer(settings.chromium_path))
        self.http = http or requests

    def _ev(self, type_: str, message: str, **kw) -> None:
        self.events(self.name, type_, message, **kw)

    # ───────────────────────── plan ─────────────────────────
    def make_plan(self, ctx: ArticleContext) -> planmod.SocialPlan:
        s = self.s
        system = prompts.SOCIAL_SYSTEM.format(
            topic=s.topic, language=prompts.LANG.get(ctx.language, ctx.language), tone="cercano, claro y responsable",
            fuente=ctx.fuente)
        base = prompts.SOCIAL.format(
            title=ctx.title, excerpt=ctx.excerpt, fuente=ctx.fuente, url=ctx.url, keywords=", ".join(ctx.keywords),
            body=ctx.body[:3500], max_slides=s.social_max_slides, seconds=s.social_video_seconds)
        data = self.llm.json(s.social_model, system, base, validate=lambda d: planmod.validate_plan(d, s.social_max_slides),
                             max_tokens=4096, label="social")
        plan = planmod.parse_plan(data)
        problem = planmod.semantic_problems(plan, ctx.evidence)
        if problem:
            self._ev("warn", "El plan social tenía datos no respaldados; se pide corrección", level="warn")
            data = self.llm.json(
                s.social_model, system, base + "\n\n" + prompts.SOCIAL_REPAIR.format(problems=problem),
                validate=lambda d: planmod.validate_plan(d, s.social_max_slides), max_tokens=4096, label="social-repair")
            plan = planmod.parse_plan(data)
            problem = planmod.semantic_problems(plan, ctx.evidence)
            if problem:
                raise SocialError("el plan social contiene " + problem)
        plan.hashtags = planmod.normalize_hashtags(plan.hashtags, ["LehighAcres", "GoLehighAcres"])
        return plan

    # ───────────────────────── run ─────────────────────────
    def run(self, ctx: ArticleContext, out_root: Path | None = None, upload: bool | None = None, video: bool | None = None,
            formats: list[str] | None = None) -> SocialKit:
        s = self.s
        formats = formats or s.social_formats
        want_video = s.social_video if video is None else video
        want_upload = (s.social_upload if upload is None else upload) and self.wp is not None
        t0 = time.time()
        plan = self.make_plan(ctx)
        self._ev("plan", f"Plan social: {len(plan.slides)} láminas, {len(plan.scenes)} escenas", count=len(plan.slides))

        slug = slugify(ctx.title)[:60] or "articulo"
        folder = Path(out_root or Path(s.state_dir) / "social") / f"{time.strftime('%Y%m%d-%H%M%S')}-{slug}"
        folder.mkdir(parents=True, exist_ok=True)
        brand = load_brand(s, self.fetcher)
        photo_uri = self._photo(ctx)
        t = strings(ctx.language)
        label = t["ai_image"] if (ctx.photo_ai and photo_uri) else ""
        credit_text = "" if ctx.photo_ai else (ctx.photo_credit if photo_uri else "")
        files: dict[str, list[str]] = {}
        warnings: list[str] = []

        try:
            with self.renderer_factory() as r:
                for fmt in formats:
                    names = []
                    for i, sl in enumerate(plan.slides):
                        html = slide_html(sl, index=i, total=len(plan.slides), fmt=fmt, brand=brand, theme=s.social_theme,
                                          photo=photo_uri, focus=FOCUS[i % len(FOCUS)], credit=credit_text, ai_label=label,
                                          lang=ctx.language, source_name=ctx.fuente)
                        f = FORMATS[fmt]
                        name = f"{fmt}-{i + 1:02d}.png"
                        (folder / name).write_bytes(r.png(html, f["w"], f["h"]))
                        names.append(name)
                    files[fmt] = names
                    self._ev("render", f"{len(names)} láminas de {fmt} dibujadas", count=len(names))
                scene_pngs = []
                durations: list[float] = []
                voice_name, segments = "", []
                if want_video:
                    scene_pngs, durations, segments, voice_name = self._scenes(plan, ctx, brand, photo_uri, label, credit_text, r,
                                                                               warnings)
        except SocialRenderError as exc:
            raise SocialError(str(exc)) from exc
        except Exception as exc:  # noqa: BLE001 - Playwright/OS errors while drawing: the article itself is unaffected
            raise SocialError(f"falló el dibujo de las láminas: {str(exc)[:200]}") from exc

        seconds = 0.0
        if want_video and scene_pngs:
            try:
                audio = None
                if any(segments):
                    try:
                        audio = build_track(segments, durations)
                    except VoiceError as exc:
                        warnings.append(f"Sin narración: {exc}")
                        voice_name = ""
                (folder / "video.mp4").write_bytes(build_video(scene_pngs, durations, audio))
                files["video"] = ["video.mp4"]
                seconds = sum(durations)
                self._ev("video", f"Video vertical de {seconds:.0f}s" + (f" con voz ({voice_name})" if audio else " sin voz"),
                         seconds=round(seconds))
            except VideoError as exc:
                warnings.append(f"Sin video: {exc}")
                self._ev("warn", f"No se pudo crear el video: {exc}", level="warn")

        (folder / "captions.txt").write_text(self._captions(plan), encoding="utf-8")
        (folder / "plan.json").write_text(json.dumps(self._plan_dict(plan), ensure_ascii=False, indent=2), encoding="utf-8")
        kit = SocialKit(slug=slug, folder=str(folder), plan=self._plan_dict(plan), files=files, ai_image=bool(label),
                        voice=voice_name, seconds=seconds, model=s.social_model, warnings=warnings)
        if want_upload:
            self._upload(kit, ctx, folder)
        if ctx.post_id and self.wp is not None and want_upload:
            self._attach(kit, ctx)
        if want_upload:   # a dry run must not trigger external automations
            self._webhook(kit, ctx)
        self._ev("done", f"Kit social listo en {folder} ({round(time.time() - t0)}s)")
        return kit

    # ───────────────────────── pieces ─────────────────────────
    @staticmethod
    def _plan_dict(plan: planmod.SocialPlan) -> dict[str, Any]:
        return {"hook": plan.hook, "slides": [vars(x) for x in plan.slides], "scenes": [vars(x) for x in plan.scenes],
                "instagram_caption": plan.instagram_caption, "tiktok_caption": plan.tiktok_caption,
                "hashtags": plan.hashtags, "alt_text": plan.alt_text}

    @staticmethod
    def _captions(plan: planmod.SocialPlan) -> str:
        tags = " ".join(plan.hashtags)
        return (f"INSTAGRAM\n{plan.instagram_caption}\n\n{tags}\n\nTIKTOK\n{plan.tiktok_caption}\n\n{tags}\n\n"
                f"TEXTO ALTERNATIVO\n{plan.alt_text}\n")

    def _photo(self, ctx: ArticleContext) -> str:
        if not ctx.photo:
            return ""
        try:
            return prepare_photo(ctx.photo)[0]
        except SocialRenderError as exc:
            self._ev("warn", f"La foto no se pudo usar en el diseño: {exc}", level="warn")
            return ""

    def _scenes(self, plan, ctx, brand: Brand, photo_uri, label, credit_text, renderer, warnings):
        s = self.s
        total = len(plan.scenes)
        pngs, durations, segments = [], [], []
        voice_ok = self.narrator.enabled
        voice_name = f"{self.narrator.provider}/{self.narrator.model}" if voice_ok else ""
        for i, sc in enumerate(plan.scenes):
            html = scene_html(sc.text or plan.slides[min(i, len(plan.slides) - 1)].headline, index=i, total=total, brand=brand,
                              theme=s.social_theme, photo=photo_uri, focus=FOCUS[i % len(FOCUS)], credit=credit_text,
                              ai_label=label, lang=ctx.language)
            pngs.append(renderer.png(html, 1080, 1920))
            seg, dur = None, planmod.estimated_seconds(sc.narration)
            if voice_ok:
                try:
                    seg = self.narrator.speak(sc.narration)
                    dur = wav_info(seg)[1]
                except VoiceError as exc:
                    warnings.append(f"Sin narración: {exc}")
                    self._ev("warn", f"La voz falló y se omite: {exc}", level="warn")
                    voice_ok, seg, voice_name = False, None, ""
                    segments = [None] * len(segments)
            segments.append(seg)
            durations.append(round(min(MAX_SCENE, max(MIN_SCENE, dur + 0.5)), 2))
        outro = scene_html("Más noticias de Lehigh Acres" if ctx.language.startswith("es") else "More Lehigh Acres news",
                           index=total, total=total + 1, brand=brand, theme=s.social_theme, lang=ctx.language, outro=True)
        pngs.append(renderer.png(outro, 1080, 1920))
        durations.append(OUTRO_SECONDS)
        segments.append(None)
        return pngs, durations, segments, voice_name

    def _upload(self, kit: SocialKit, ctx: ArticleContext, folder: Path) -> None:
        alt = kit.plan["alt_text"] or ctx.title
        for key, names in kit.files.items():
            for n in names:
                data = (folder / n).read_bytes()
                mime = "video/mp4" if n.endswith(".mp4") else "image/png"
                try:
                    up = self.wp.upload_media(data, f"{slugify(ctx.title)[:40]}-{n}", mime,
                                              alt=alt if key != "video" else "", title=f"{ctx.title} · {n}", timeout=180)
                except Exception as exc:  # noqa: BLE001 - WPError, network, anything: the local files still exist
                    hint = " (si es por tamaño, sube upload_max_filesize en tu hosting)" if n.endswith(".mp4") else ""
                    kit.warnings.append(f"No se pudo subir {n} a WordPress: {str(exc)[:150]}{hint} · archivo local: {folder / n}")
                    self._ev("warn", f"Subida fallida de {n}: {exc}", level="warn")
                    continue
                kit.uploaded.setdefault(key, []).append({"id": up["id"], "url": up["source_url"], "name": n})
        self._ev("upload", "Kit subido a la biblioteca de medios de WordPress",
                 count=sum(len(v) for v in kit.uploaded.values()))

    def _attach(self, kit: SocialKit, ctx: ArticleContext) -> None:
        if not kit.uploaded:   # never replace a previous good kit with an empty one
            kit.warnings.append("Ningún archivo se subió: el kit anterior (si lo hay) se conserva en el borrador.")
            return
        try:
            self.wp.update_post(ctx.post_id, {"meta": {"lnh_social": json.dumps(kit.to_meta(), ensure_ascii=False)}})
            self._ev("attached", f"Kit social vinculado al borrador {ctx.post_id}", post_id=ctx.post_id)
        except Exception as exc:  # noqa: BLE001
            kit.warnings.append(f"No se pudo vincular el kit al borrador: {str(exc)[:150]}")
            self._ev("warn", f"No se pudo guardar lnh_social: {exc}", level="warn")

    def _webhook(self, kit: SocialKit, ctx: ArticleContext) -> None:
        url = self.s.social_webhook_url
        if not url:
            return
        body = json.dumps({"event": "social_kit_ready", "title": ctx.title, "post_id": ctx.post_id, "post_link": ctx.post_link,
                           "kit": kit.to_meta()}, ensure_ascii=False).encode()
        headers = {"Content-Type": "application/json", "User-Agent": "lehigh-agents-social"}
        if self.s.social_webhook_secret:
            sig = hmac.new(self.s.social_webhook_secret.encode(), body, hashlib.sha256).hexdigest()
            headers["X-Lehigh-Signature"] = "sha256=" + sig
        try:
            r = self.http.post(url, data=body, headers=headers, timeout=20)
            self._ev("webhook", f"Webhook notificado (HTTP {r.status_code})")
        except Exception as exc:  # noqa: BLE001
            kit.warnings.append(f"Webhook falló: {str(exc)[:120]}")
            self._ev("warn", f"Webhook falló: {exc}", level="warn")
