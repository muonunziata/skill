"""Choosing a Gemini model that the API actually serves. Google retires model names every few months, so the agents
never depend on one fixed name: `auto` (or any retired name) resolves to the newest available Flash model."""
from __future__ import annotations

import re

AUTO = ("", "auto", "gemini-auto")
FALLBACK_ALIAS = "gemini-flash-latest"   # Google's own moving alias, used when the model list cannot be read

# Names that look like text models but are not suitable for the agents.
_EXCLUDE = ("lite", "image", "tts", "live", "audio", "embedding", "vision", "robotics", "computer", "exp", "thinking",
            "learnlm", "gemma", "aqa", "customtools", "native")


def _model_rows(models) -> list[tuple[str, tuple[str, ...]]]:
    """Normalise SDK model objects / dicts to (bare name, supported actions)."""
    rows = []
    for m in models:
        name = m.get("name") if isinstance(m, dict) else getattr(m, "name", "")
        actions = m.get("supported_actions") if isinstance(m, dict) else getattr(m, "supported_actions", None)
        rows.append((str(name or "").removeprefix("models/"), tuple(actions or ())))
    return rows


def rank_text_models(models, include_lite: bool = False) -> list[str]:
    """Gemini Flash models that can generate content, best first (newest stable version wins; previews and, unless asked,
    the cheaper `lite` variants come last). `include_lite` adds them as fallbacks, e.g. when a quota is exhausted."""
    ranked: list[tuple] = []
    exclude = tuple(x for x in _EXCLUDE if include_lite is False or x != "lite")
    for name, actions in _model_rows(models):
        if not name.startswith("gemini-") or "flash" not in name or any(x in name for x in exclude):
            continue
        if actions and "generateContent" not in actions:
            continue
        m = re.match(r"gemini-(\d+)(?:\.(\d+))?-flash", name)
        major, minor = (int(m.group(1)), int(m.group(2) or 0)) if m else (0, 0)  # gemini-flash-latest -> lowest
        key = ("lite" not in name, "preview" not in name and "-0" not in name[-4:], major, minor, -len(name))
        ranked.append((key, name))
    return [n for _, n in sorted(ranked, reverse=True)]


def pick_text_model(models) -> str | None:
    """Best stable Gemini Flash model that can generate content."""
    ranked = rank_text_models(models)
    return ranked[0] if ranked else None


def pick_image_model(models) -> str | None:
    best: tuple | None = None
    for name, actions in _model_rows(models):
        if not name.startswith("gemini-") or "image" not in name or "flash" not in name:
            continue
        if any(x in name for x in ("preview", "exp")) and best is not None:
            continue
        m = re.match(r"gemini-(\d+)(?:\.(\d+))?", name)
        key = ("preview" not in name, int(m.group(1)) if m else 0, int(m.group(2) or 0) if m else 0)
        if best is None or key > best[0]:
            best = (key, name)
    return best[1] if best else None
