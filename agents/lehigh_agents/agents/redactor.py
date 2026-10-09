"""Agent 2 - El Redactor Multimedia.

1. Writes the article (Gutenberg HTML, SEO-optimised) from Agent 1's finding - model selectable via REDACCTOR_MODEL.
2. Resolves media placeholders ([[IMAGEN: …]], [[VIDEO: …]]) into real, rights-safe blocks or leaves invisible TODO markers.
3. Writes a detailed English photorealism prompt for the featured image, sends it to the image API, hosts the result
   and assigns its permanent URL to `featured_image_url`.
"""
from __future__ import annotations

import json
import logging
import re
import time
from pathlib import Path
from typing import Any

from .. import prompts
from ..htmlutil import sanitize_html, slugify, strip_tags
from ..imagegen import GeneratedImage, ImageGenError, ImageGenerator
from ..llm import LLM, LLMError
from ..media import commons_search, parse_page, validate_youtube, youtube_id
from ..net import FetchError, Fetcher
from ..schemas import Articulo, Hallazgo, clean_list, validate_articulo
from ..settings import Settings
from ..wordpress import WPError, WordPressClient

log = logging.getLogger("lehigh.redactor")

PLACEHOLDER = re.compile(r"\[\[\s*(IMAGEN|IMAGE|VIDEO)\s*:\s*(.+?)\s*\]\]", re.I | re.S)
AI_LABELS = {"es": "Imagen ilustrativa generada con IA", "en": "AI-generated illustration",
             "pt": "Imagem ilustrativa gerada por IA", "fr": "Illustration générée par IA"}

# Words that must never appear in an image prompt for a news outlet (see prompts.IMAGE_PROMPT hard rules).
UNSAFE_IMAGE_TERMS = re.compile(
    r"\b(child|children|kid|kids|boy|girl|baby|toddler|teen|teenager|minor|student|victim|corpse|dead(?![- ]end)|body bag|blood|"
    r"bloody|injur\w*|wound\w*|crash scene|accident scene|arrest\w*|handcuff\w*|gun|guns|weapon\w*|shooting|stabbing|"
    r"riot|protest\w*|police officer|deputy|firefighter|swat|logo|trademark|celebrity|politician|nude|naked)\b", re.I)
NEUTRAL_ALT = {"es": "Calle residencial de Lehigh Acres, Florida", "en": "Residential street in Lehigh Acres, Florida",
               "pt": "Rua residencial em Lehigh Acres, Flórida", "fr": "Rue résidentielle de Lehigh Acres, Floride"}
REALISM_SUFFIX = ("Photorealistic documentary photograph, natural unretouched colour, true-to-life textures, "
                  "subtle film grain, no text, no logos, no watermark.")


def fallback_image_prompt(h: Hallazgo) -> str:
    """Neutral, always-safe location photograph used when the model's prompt is rejected."""
    theme = ", ".join(k for k in h.palabras_clave[:2] if k.isascii()) or "community life"
    return (
        "Photorealistic editorial photograph of a quiet residential street in Lehigh Acres, Southwest Florida, with "
        "one-storey stucco ranch homes with tile roofs, slash pines and cabbage palms, a drainage canal in the "
        f"background and a few parked cars, evoking the theme of {theme}. Late afternoon golden hour, soft low sun, "
        "long shadows, clear subtropical sky with scattered cumulus. Shot on a Canon EOS R5 with a 35mm lens at f/4, "
        "eye level, wide 16:9 composition with foreground, midground and background, rule of thirds. Documentary "
        "photojournalism style, natural unretouched colour, true-to-life textures, subtle film grain, no HDR look. "
        "No people, no text, no signage, no logos, no watermark."
    )


def validate_image_prompt(data: Any) -> str | None:
    if not isinstance(data, dict):
        return "expected a JSON object"
    p = data.get("image_prompt")
    if not isinstance(p, str) or len(p.strip()) < 150:
        return "'image_prompt' must be a detailed prompt of at least 150 characters"
    if not isinstance(data.get("alt_text"), str) or not data["alt_text"].strip():
        return "'alt_text' is required"
    return None


_EN_WORDS = {"the", "a", "an", "of", "with", "and", "at", "in", "on", "by", "to", "from", "shot", "light", "street", "sky",
             "homes", "behind", "background", "photograph", "natural", "no", "is", "are", "for"}
_ES_WORDS = {"el", "la", "los", "las", "de", "del", "un", "una", "con", "y", "en", "al", "por", "para", "que", "se", "su",
             "calle", "cielo", "casas", "fotografía", "fondo", "luz", "sin"}


def _is_english(text: str) -> bool:
    """Function-word heuristic: robust where an ASCII-ratio test would accept accent-poor Spanish."""
    words = re.findall(r"[a-záéíóúñü]+", text.lower())
    en = sum(w in _EN_WORDS for w in words)
    es = sum(w in _ES_WORDS for w in words)
    return en >= 5 and en > 2 * es


def _comment_text(text: str) -> str:
    """Make `text` safe inside an HTML comment: no `--` run (so no `-->` / `--!>` can close it early) and no `>`."""
    return re.sub(r"-+", "-", text).replace(">", "")


def image_block(url: str, alt: str, caption: str) -> str:
    from html import escape

    cap = f'<figcaption class="wp-element-caption">{escape(caption)}</figcaption>' if caption else ""
    return (
        '<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->\n'
        f'<figure class="wp-block-image size-large"><img src="{escape(url, quote=True)}" alt="{escape(alt, quote=True)}"/>{cap}</figure>\n'
        "<!-- /wp:image -->"
    )


def video_block(url: str) -> str:
    from html import escape

    u = escape(url, quote=True)
    return (
        f'<!-- wp:embed {{"url":"{u}","type":"video","providerNameSlug":"youtube","responsive":true,'
        '"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->\n'
        '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube '
        f'wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">\n{u}\n</div></figure>\n'
        "<!-- /wp:embed -->"
    )


class Redactor:
    name = "redactor"

    def __init__(self, settings: Settings, llm: LLM, imagegen: ImageGenerator, fetcher: Fetcher, events,
                 wp: WordPressClient | None = None, make_images: bool = True):
        self.s, self.llm, self.imagegen, self.fetcher, self.events = settings, llm, imagegen, fetcher, events
        self.wp = wp  # None in dry-run: images are stored locally instead of the WordPress media library
        self.make_images = make_images

    def _ev(self, type_: str, message: str, **kw) -> None:
        self.events(self.name, type_, message, **kw)

    # ───────────────────────── 1. article ─────────────────────────
    def run(self, h: Hallazgo) -> Articulo:
        s = self.s
        self._ev("step", f"Redactando con {s.redactor_model}: {h.titulo_fuente}")
        data = self.llm.json(
            s.redactor_model,
            prompts.REDACTOR_SYSTEM.format(topic=s.topic, language=prompts.LANG.get(s.language, s.language)),
            prompts.REDACTOR.format(
                titulo=h.titulo_fuente, fuente=h.fuente or h.url, url=h.url, fecha=h.fecha or "sin fecha",
                keywords=", ".join(h.palabras_clave), hechos=h.resumen_hechos, source_text=h.source_text[:6000],
                topic=s.topic,
            ),
            validate=validate_articulo, max_tokens=8192, label="redactor",
        )
        art = self._build(data)
        self._ev("draft", f"Borrador listo: «{art.post_title}» ({len(strip_tags(art.post_content).split())} palabras)",
                 model=s.redactor_model)
        self._media(h, art)
        return art

    def revise(self, h: Hallazgo, art: Articulo, notes: list[str]) -> Articulo:
        """Fix issues reported by the Auditor; the featured image is kept."""
        self._ev("step", f"Corrigiendo el borrador según {len(notes)} nota(s) del auditor")
        current = json.dumps({"post_title": art.post_title, "post_content": art.post_content, "excerpt": art.excerpt,
                              "meta_description": art.meta_description, "suggested_tags": art.suggested_tags,
                              "featured_image_url": ""}, ensure_ascii=False)
        data = self.llm.json(
            self.s.redactor_model,
            prompts.REDACTOR_SYSTEM.format(topic=self.s.topic, language=prompts.LANG.get(self.s.language, self.s.language)),
            prompts.REDACTOR_REVISE.format(notes="\n".join(f"- {n}" for n in notes), article=current)
            + f"\n\nHECHOS (única fuente válida):\n{h.resumen_hechos}",
            validate=validate_articulo, max_tokens=8192, label="redactor-revision",
        )
        new = self._build(data)
        for field in ("featured_image_url", "featured_image_credit", "featured_image_ai", "featured_image_prompt",
                      "featured_image_alt", "featured_image_caption", "featured_media_id", "image_provider",
                      "image_model"):
            setattr(new, field, getattr(art, field))
        new.media_todo = list(art.media_todo)  # media still to add (incl. "imagen destacada") survives the rewrite
        self._resolve_placeholders(h, new)
        new.media_todo = list(dict.fromkeys(new.media_todo))
        self._ev("draft", f"Revisión lista: «{new.post_title}»", model=self.s.redactor_model)
        return new

    def _build(self, data: dict[str, Any]) -> Articulo:
        return Articulo(
            post_title=data["post_title"].strip(),
            post_content=sanitize_html(data["post_content"].strip()),
            excerpt=strip_tags(data["excerpt"])[:300],
            meta_description=strip_tags(data["meta_description"])[:200],
            suggested_tags=[t.lower() for t in clean_list(data.get("suggested_tags"), 8)],
            featured_image_url="",
            model=self.s.redactor_model,
        )

    # ───────────────────────── 2. media ─────────────────────────
    def _media(self, h: Hallazgo, art: Articulo) -> None:
        self._resolve_placeholders(h, art)
        if self.make_images and self.imagegen.enabled:
            try:
                self._generate_featured(h, art)
            except (ImageGenError, LLMError, WPError, OSError) as exc:
                self._ev("warn", f"No se pudo generar la imagen con IA: {exc}", level="warn")
        if not art.featured_image_url:
            self._archive_featured(h, art)

    def _resolve_placeholders(self, h: Hallazgo, art: Articulo) -> None:
        art.post_content = re.sub(
            r"<!--\s*wp:paragraph\s*-->\s*<p>\s*(\[\[.+?\]\])\s*</p>\s*<!--\s*/wp:paragraph\s*-->|<p>\s*(\[\[.+?\]\])\s*</p>",
            lambda m: m.group(1) or m.group(2), art.post_content, flags=re.S)
        used = 0

        def repl(m: re.Match[str]) -> str:
            nonlocal used
            kind, desc = m.group(1).upper(), re.sub(r"\s+", " ", m.group(2)).strip()
            if kind == "VIDEO":
                url = next((u for u in re.findall(r"https?://\S+", desc) if youtube_id(u)), "")
                item = validate_youtube(self.fetcher, url) if url else None
                if item:
                    self._ev("media", f"Video de YouTube verificado: {item.caption[:60]}")
                    return video_block(item.url)
                art.media_todo.append(f"video: {desc}")
                return f"<!-- lnh-placeholder video: {_comment_text(desc)} -->"
            if used >= 2:
                art.media_todo.append(f"imagen: {desc}")
                return f"<!-- lnh-placeholder imagen: {_comment_text(desc)} -->"
            hit = self._commons(f"{desc} {self.s.topic}") or self._commons(self.s.topic)
            if not hit:
                art.media_todo.append(f"imagen: {desc}")
                return f"<!-- lnh-placeholder imagen: {_comment_text(desc)} -->"
            used += 1
            self._ev("media", f"Foto con licencia abierta (Wikimedia Commons): {hit.alt[:60]}")
            return image_block(hit.url, hit.alt or desc, f"{hit.caption} — {hit.credit}" if hit.caption else hit.credit)

        art.post_content = PLACEHOLDER.sub(repl, art.post_content)

    def _commons(self, query: str):
        try:
            for item in commons_search(self.fetcher, query, limit=2):
                return item
        except Exception as exc:  # noqa: BLE001 - media is optional
            log.info("commons search failed: %s", exc)
        return None

    def _archive_featured(self, h: Hallazgo, art: Articulo) -> None:
        """Fallback featured image: an openly licensed Commons photo (or the source image when explicitly allowed)."""
        hit = self._commons(" ".join(h.palabras_clave[:2] + [self.s.topic]))
        if hit:
            art.featured_image_url = hit.url
            art.featured_image_credit = hit.credit
            art.featured_image_alt = hit.alt
            art.featured_image_caption = hit.credit
            self._ev("media", f"Imagen destacada con licencia abierta: {hit.alt[:60]}")
        elif self.s.allow_source_images:
            try:
                res = self.fetcher.get(h.url, accept="text/html")
                og = parse_page(res.text(), res.url).og_image
            except FetchError:
                og = ""
            if og:
                art.featured_image_url = og
                art.featured_image_credit = f"Imagen: {h.fuente}"
                self._ev("media", "Imagen destacada tomada de la fuente (ALLOW_SOURCE_IMAGES=true)", level="warn")
        if not art.featured_image_url:
            art.media_todo.append("imagen destacada")

    # ───────────────────────── 3. AI featured image ─────────────────────────
    def build_image_prompt(self, h: Hallazgo, art: Articulo) -> tuple[str, str, bool]:
        """Ask the Redactor model for a detailed English photorealism prompt based on the finished article."""
        s = self.s
        data = self.llm.json(
            s.redactor_model, prompts.IMAGE_PROMPT_SYSTEM,
            prompts.IMAGE_PROMPT.format(
                title=art.post_title, excerpt=art.excerpt, body=strip_tags(art.post_content)[:1800],
                keywords=", ".join(h.palabras_clave), language=prompts.LANG.get(s.language, s.language)),
            validate=validate_image_prompt, max_tokens=2048, label="prompt-imagen",
        )
        prompt = re.sub(r"\s+", " ", data["image_prompt"]).strip()
        alt = re.sub(r"\s+", " ", data["alt_text"]).strip()[:140]
        sensitive = bool(data.get("sensitive"))
        if not _is_english(prompt) or UNSAFE_IMAGE_TERMS.search(prompt):
            self._ev("warn", "El prompt de imagen no cumplió las reglas de seguridad; se usa una escena neutra", level="warn")
            # the model's alt text described the scene we just rejected: describe the neutral one instead
            prompt, alt, sensitive = fallback_image_prompt(h), NEUTRAL_ALT.get(s.language, NEUTRAL_ALT["en"]), True
        if "photo" not in prompt.lower():
            prompt = f"{prompt} {REALISM_SUFFIX}"
        return prompt, alt, sensitive

    def _generate_featured(self, h: Hallazgo, art: Articulo) -> None:
        prompt, alt, sensitive = self.build_image_prompt(h, art)
        self._ev("image_prompt", "Prompt de imagen fotorrealista redactado" + (" (tema sensible → escena neutra)" if sensitive else ""),
                 prompt=prompt)
        self._ev("step", f"Generando imagen con {self.imagegen.provider}/{self.imagegen.model}…")
        img = self.imagegen.generate(prompt)
        self._ev("image", f"Imagen generada ({img.width}x{img.height}, {len(img.data) // 1024} KB, {img.seconds}s)",
                 provider=img.provider, model=img.model)
        label = AI_LABELS.get(self.s.language, AI_LABELS["en"]) if self.s.image_label else ""
        url, media_id = self._host(img, art, alt, label)
        art.featured_image_url = url
        art.featured_media_id = media_id
        art.featured_image_ai = True
        art.featured_image_prompt = prompt
        art.featured_image_alt = alt
        art.featured_image_caption = label
        art.featured_image_credit = label
        art.image_provider, art.image_model = img.provider, img.model
        self._ev("image_ready", f"featured_image_url asignada: {url}", url=url)

    def _host(self, img: GeneratedImage, art: Articulo, alt: str, caption: str) -> tuple[str, int]:
        filename = f"{slugify(art.post_title)}-{int(time.time())}.{img.extension}"
        if self.wp is not None:
            up = self.wp.upload_media(img.data, filename, img.mime, alt=alt, caption=caption, title=art.post_title)
            if not up.get("source_url"):
                raise WPError("WordPress did not return the media URL")
            return up["source_url"], int(up["id"])
        out_dir = Path(self.s.state_dir) / "images"
        out_dir.mkdir(parents=True, exist_ok=True)
        path = out_dir / filename
        path.write_bytes(img.data)
        return (img.remote_url or str(path.resolve())), 0
