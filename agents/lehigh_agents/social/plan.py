"""The Social Designer's content plan: what each carousel slide and video scene says, plus captions and hashtags."""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

from ..checks import unsupported_numbers
from ..htmlutil import strip_tags

SLIDE_KINDS = ("cover", "point", "stat", "quote", "source", "cta")
LIMITS = {"headline": 70, "body": 180, "stat": 14, "stat_label": 50, "narration": 200, "text": 60, "hook": 90,
          "instagram_caption": 900, "tiktok_caption": 300, "alt_text": 125}


@dataclass
class Slide:
    kind: str
    headline: str
    body: str = ""
    stat: str = ""
    stat_label: str = ""


@dataclass
class Scene:
    narration: str
    text: str


@dataclass
class SocialPlan:
    hook: str
    slides: list[Slide]
    scenes: list[Scene]
    instagram_caption: str
    tiktok_caption: str
    hashtags: list[str]
    alt_text: str = ""

    def all_text(self) -> str:
        parts = [self.hook, self.instagram_caption, self.tiktok_caption]
        for s in self.slides:
            parts += [s.headline, s.body, s.stat, s.stat_label]
        for sc in self.scenes:
            parts += [sc.narration, sc.text]
        return "\n".join(p for p in parts if p)


def clip(text: Any, limit: int) -> str:
    t = re.sub(r"\s+", " ", strip_tags(str(text or ""))).strip()
    if len(t) <= limit:
        return t
    cut = t[:limit].rsplit(" ", 1)[0].rstrip(" ,;:–-")
    return cut + "…"


def normalize_hashtags(tags: Any, extra: list[str] | None = None, limit: int = 12) -> list[str]:
    if isinstance(tags, str):
        tags = re.split(r"[\s,]+", tags)
    out: list[str] = []
    for raw in list(tags or []) + list(extra or []):
        t = re.sub(r"[^\w]", "", str(raw).lstrip("#"), flags=re.UNICODE)
        if t and ("#" + t).lower() not in {x.lower() for x in out} and not t.isdigit():
            out.append("#" + t)
    return out[:limit]


def validate_plan(data: Any, max_slides: int = 8) -> str | None:
    """Structural problems (the language model is asked to repair them once)."""
    if not isinstance(data, dict):
        return "expected a JSON object"
    slides = data.get("slides")
    if not isinstance(slides, list) or not 5 <= len(slides) <= max_slides:
        return f"'slides' must have between 5 and {max_slides} items"
    for i, s in enumerate(slides):
        if not isinstance(s, dict) or s.get("kind") not in SLIDE_KINDS or not str(s.get("headline", "")).strip():
            return f"slide {i + 1}: needs a valid 'kind' ({', '.join(SLIDE_KINDS)}) and a 'headline'"
        if s["kind"] == "stat" and not str(s.get("stat", "")).strip():
            return f"slide {i + 1}: kind 'stat' needs a 'stat' value"
    kinds = [s["kind"] for s in slides]
    if kinds[0] != "cover" or kinds[-1] != "cta" or kinds[-2] != "source":
        return "slides must start with 'cover', end with 'cta' and have 'source' as the second to last"
    if sum(k in ("point", "stat", "quote") for k in kinds) < 3:
        return "slides need at least 3 content slides (point, stat or quote) between cover and source"
    scenes = data.get("scenes")
    if not isinstance(scenes, list) or not 3 <= len(scenes) <= 9:
        return "'scenes' must have between 3 and 9 items"
    for i, sc in enumerate(scenes):
        if not isinstance(sc, dict) or not str(sc.get("narration", "")).strip():
            return f"scene {i + 1}: needs a 'narration'"
    for key in ("hook", "instagram_caption", "tiktok_caption"):
        if not str(data.get(key, "")).strip():
            return f"'{key}' is required"
    return None


def parse_plan(data: dict[str, Any]) -> SocialPlan:
    """Clip every field to its limit so a long answer can never overflow a slide."""
    slides = []
    for s in data["slides"]:
        kind = s["kind"]
        slides.append(Slide(
            kind=kind,
            headline=clip(s.get("headline"), LIMITS["headline"] + (10 if kind == "cover" else 0)),
            body=clip(s.get("body"), LIMITS["body"]),
            stat=clip(s.get("stat"), LIMITS["stat"]) if kind == "stat" else "",
            stat_label=clip(s.get("stat_label"), LIMITS["stat_label"]) if kind == "stat" else "",
        ))
    scenes = [Scene(narration=clip(sc.get("narration"), LIMITS["narration"]), text=clip(sc.get("text"), LIMITS["text"]))
              for sc in data["scenes"]]
    return SocialPlan(
        hook=clip(data.get("hook"), LIMITS["hook"]),
        slides=slides,
        scenes=scenes,
        instagram_caption=str(data.get("instagram_caption", "")).strip()[: LIMITS["instagram_caption"]],
        tiktok_caption=str(data.get("tiktok_caption", "")).strip()[: LIMITS["tiktok_caption"]],
        hashtags=normalize_hashtags(data.get("hashtags")),
        alt_text=clip(data.get("alt_text"), LIMITS["alt_text"]),
    )


def semantic_problems(plan: SocialPlan, evidence: str) -> str | None:
    """Figures in the social copy that the article does not contain (same guard the Auditor uses)."""
    bad = unsupported_numbers(plan.all_text(), evidence)
    if bad:
        return "estas cifras no aparecen en el artículo y deben eliminarse o corregirse: " + ", ".join(bad)
    return None


def estimated_seconds(text: str) -> float:
    """Spoken Spanish/English runs at roughly 2.6 words per second."""
    return max(1.5, len(text.split()) / 2.6)
