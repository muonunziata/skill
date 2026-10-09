"""Settings loaded from the environment (.env). Single source of truth for keys and models."""
from __future__ import annotations

import os
import re
from dataclasses import dataclass
from pathlib import Path

from dotenv import load_dotenv

DEFAULT_MODEL = "gemini-1.5-flash"


def _bool(value: str | None, default: bool = False) -> bool:
    if value is None or value.strip() == "":
        return default
    return value.strip().lower() in {"1", "true", "yes", "on", "si", "sí"}


def _int(value: str | None, default: int, lo: int, hi: int) -> int:
    try:
        n = int(str(value).strip())
    except (TypeError, ValueError):
        return default
    return max(lo, min(hi, n))


def _list(value: str | None, default: list[str]) -> list[str]:
    items = [p.strip() for p in (value or "").split(",") if p.strip()]
    return items or default


def _hex(value: str | None, default: str) -> str:
    v = (value or "").strip()
    return v if re.fullmatch(r"#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})", v) else default


def _json_dict(value: str | None) -> dict:
    import json

    if not value or not value.strip():
        return {}
    try:
        data = json.loads(value)
    except ValueError:
        return {}
    return data if isinstance(data, dict) else {}


def _image_provider(e) -> str:
    """Explicit IMAGE_PROVIDER, else inferred; empty string = image generation disabled."""
    explicit = (e("IMAGE_PROVIDER") or "").strip().lower()
    if explicit in {"none", "off", "disabled", "false"}:
        return ""
    has_key = bool((e("IMAGE_API_KEY") or "").strip())
    if explicit:
        # .env.example ships IMAGE_PROVIDER=replicate with an empty key and documents "leave IMAGE_API_KEY empty to
        # disable", so a named provider without credentials means "off" (Gemini can reuse its text-model key).
        gemini_key = explicit == "gemini" and bool((e("GEMINI_API_KEY") or e("GOOGLE_API_KEY") or "").strip())
        return explicit if (has_key or gemini_key) else ""
    endpoint = (e("IMAGE_API_ENDPOINT") or "").lower()
    if not has_key:
        return ""
    if "replicate" in endpoint or not endpoint:
        return "replicate"
    return "openai"  # any other endpoint is assumed OpenAI-compatible


@dataclass(frozen=True)
class Settings:
    gemini_api_key: str
    anthropic_api_key: str
    openai_api_key: str
    wp_rest_url: str
    wp_auth_token: str
    rastreador_model: str
    redactor_model: str
    auditor_model: str
    topic: str
    focus: list[str]
    language: str
    max_items: int
    freshness_days: int
    audit_min_score: int
    post_flagged: bool
    allow_source_images: bool
    interval_minutes: int
    state_dir: Path
    log_level: str
    image_provider: str  # replicate | gemini | openai | "" (disabled)
    image_api_key: str
    image_api_endpoint: str
    image_model: str
    image_aspect_ratio: str
    image_extra: dict
    image_label: bool
    max_revisions: int
    mediastack_api_key: str
    mediastack_min_hours: float
    mediastack_monthly_limit: int
    mediastack_countries: str
    mediastack_languages: str
    mediastack_limit: int
    mediastack_https: str
    social_enabled: bool
    social_formats: list
    social_video: bool
    social_voice: str
    social_voice_model: str
    social_model: str
    social_max_slides: int
    social_video_seconds: int
    social_theme: str
    social_upload: bool
    social_webhook_url: str
    social_webhook_secret: str
    brand_name: str
    brand_handle: str
    brand_color: str
    brand_accent: str
    brand_dark: str
    brand_logo: str
    brand_logo_vertical: str
    brand_font: str
    chromium_path: str

    @classmethod
    def from_env(cls, env_file: str | os.PathLike[str] | None = None) -> "Settings":
        # Real environment variables win over the .env file. Without an explicit path look in the working
        # directory first, then next to the project (agents/.env).
        candidates = [Path(env_file)] if env_file else [Path.cwd() / ".env", Path(__file__).resolve().parent.parent / ".env"]
        for candidate in candidates:
            if candidate.is_file():
                load_dotenv(candidate, override=False)
                break
        e = os.environ.get
        redactor = e("REDACCTOR_MODEL") or e("REDACTOR_MODEL") or DEFAULT_MODEL  # accept the correctly spelled alias too
        return cls(
            gemini_api_key=(e("GEMINI_API_KEY") or e("GOOGLE_API_KEY") or "").strip(),
            anthropic_api_key=(e("ANTHROPIC_API_KEY") or "").strip(),
            openai_api_key=(e("OPENAI_API_KEY") or "").strip(),
            wp_rest_url=(e("WP_REST_URL") or "").strip(),
            wp_auth_token=(e("WP_AUTH_TOKEN") or "").strip(),
            rastreador_model=(e("RASTREADOR_MODEL") or DEFAULT_MODEL).strip(),
            redactor_model=redactor.strip(),
            auditor_model=(e("AUDITOR_MODEL") or DEFAULT_MODEL).strip(),
            topic=(e("SEARCH_TOPIC") or "Lehigh Acres, Florida").strip(),
            focus=_list(e("SEARCH_FOCUS"), ["desarrollo urbano", "bienes raíces", "infraestructura", "comunidad"]),
            language=(e("ARTICLE_LANGUAGE") or "es").strip().lower(),
            max_items=_int(e("MAX_ITEMS_PER_RUN"), 3, 1, 20),
            freshness_days=_int(e("FRESHNESS_DAYS"), 14, 1, 120),
            audit_min_score=_int(e("AUDIT_MIN_SCORE"), 80, 1, 100),
            post_flagged=_bool(e("POST_FLAGGED")),
            allow_source_images=_bool(e("ALLOW_SOURCE_IMAGES")),
            interval_minutes=_int(e("LOOP_INTERVAL_MINUTES"), 60, 1, 24 * 60),
            state_dir=Path(e("STATE_DIR") or "./state"),
            log_level=(e("LOG_LEVEL") or "INFO").upper(),
            image_provider=_image_provider(e),
            image_api_key=(e("IMAGE_API_KEY") or "").strip(),
            image_api_endpoint=(e("IMAGE_API_ENDPOINT") or "").strip(),
            image_model=(e("IMAGE_MODEL") or "").strip(),
            image_aspect_ratio=(e("IMAGE_ASPECT_RATIO") or "16:9").strip(),
            image_extra=_json_dict(e("IMAGE_EXTRA_INPUT")),
            image_label=_bool(e("AI_IMAGE_LABEL"), True),
            max_revisions=_int(e("MAX_REVISIONS"), 1, 0, 3),
            mediastack_api_key=(e("MEDIASTACK_API_KEY") or "").strip(),
            mediastack_min_hours=float(_int(e("MEDIASTACK_MIN_HOURS"), 8, 1, 720)),
            mediastack_monthly_limit=_int(e("MEDIASTACK_MONTHLY_LIMIT"), 100, 1, 1_000_000),
            mediastack_countries=(e("MEDIASTACK_COUNTRIES") or "us").strip().replace(" ", ""),
            mediastack_languages=(e("MEDIASTACK_LANGUAGES") or "en,es").strip().replace(" ", ""),
            mediastack_limit=_int(e("MEDIASTACK_LIMIT"), 25, 1, 100),
            social_enabled=_bool(e("SOCIAL_ENABLED"), True),
            social_formats=[f for f in _list(e("SOCIAL_FORMATS"), ["instagram", "tiktok"]) if f in ("instagram", "tiktok")] or ["instagram", "tiktok"],
            social_video=_bool(e("SOCIAL_VIDEO"), True),
            social_voice=(e("SOCIAL_VOICE") or "none").strip().lower() if (e("SOCIAL_VOICE") or "none").strip().lower() in ("none", "gemini", "openai") else "none",
            social_voice_model=(e("SOCIAL_VOICE_MODEL") or "").strip(),
            social_model=(e("SOCIAL_MODEL") or redactor).strip(),
            social_max_slides=_int(e("SOCIAL_MAX_SLIDES"), 8, 5, 10),
            social_video_seconds=_int(e("SOCIAL_VIDEO_SECONDS"), 30, 12, 60),
            social_theme=(e("SOCIAL_THEME") or "light").strip().lower() if (e("SOCIAL_THEME") or "light").strip().lower() in ("dark", "light", "brand") else "light",
            social_upload=_bool(e("SOCIAL_UPLOAD"), True),
            social_webhook_url=(e("SOCIAL_WEBHOOK_URL") or "").strip(),
            social_webhook_secret=(e("SOCIAL_WEBHOOK_SECRET") or "").strip(),
            brand_name=(e("BRAND_NAME") or "GoLehighAcres.org").strip(),
            brand_handle=(e("BRAND_HANDLE") or "GoLehighAcres.org").strip(),
            brand_color=_hex(e("BRAND_COLOR"), "#1b6a55"),   # the green arrow of the logo
            brand_accent=_hex(e("BRAND_ACCENT"), "#ff5757"),  # the coral "GO"
            brand_dark=_hex(e("BRAND_DARK"), "#0f3a30"),
            brand_logo=(e("BRAND_LOGO") or "").strip(),       # empty = the packaged GoLehighAcres.org logo
            brand_logo_vertical=(e("BRAND_LOGO_VERTICAL") or "").strip(),
            brand_font=(e("BRAND_FONT") or "").strip(),
            chromium_path=(e("CHROMIUM_PATH") or "").strip(),
            mediastack_https=(e("MEDIASTACK_HTTPS") or "auto").strip().lower() if (e("MEDIASTACK_HTTPS") or "auto").strip().lower() in ("auto", "true", "false") else "auto",
        )

    def provider_keys_needed(self) -> dict[str, str]:
        """Which API key each configured model needs -> {model_role: provider}."""
        from .llm import provider_for

        return {
            "rastreador": provider_for(self.rastreador_model),
            "redactor": provider_for(self.redactor_model),
            "auditor": provider_for(self.auditor_model),
        }

    def problems(self, need_wordpress: bool = True) -> list[str]:
        """Human-readable configuration problems (empty list = ready to run)."""
        from .llm import provider_for

        out: list[str] = []
        keys = {"gemini": self.gemini_api_key, "anthropic": self.anthropic_api_key, "openai": self.openai_api_key}
        names = {"gemini": "GEMINI_API_KEY", "anthropic": "ANTHROPIC_API_KEY", "openai": "OPENAI_API_KEY"}
        for role, model in (("RASTREADOR_MODEL", self.rastreador_model), ("REDACCTOR_MODEL", self.redactor_model),
                            ("AUDITOR_MODEL", self.auditor_model)):
            prov = provider_for(model)
            if not keys.get(prov):
                out.append(f"{role}={model} needs {names[prov]}")
        if provider_for(self.rastreador_model) != "gemini":
            out.append("RASTREADOR_MODEL must be a Gemini model (it relies on Google Search grounding)")
        if provider_for(self.auditor_model) != "gemini":
            out.append("AUDITOR_MODEL must be a Gemini model")
        if self.social_enabled and not keys.get(provider_for(self.social_model)):
            out.append(f"SOCIAL_MODEL={self.social_model} needs {names[provider_for(self.social_model)]} (or set SOCIAL_ENABLED=false)")
        if self.image_provider:
            if self.image_provider not in ("replicate", "gemini", "openai"):
                out.append(f"IMAGE_PROVIDER={self.image_provider} is not supported (replicate, gemini or openai)")
            elif not (self.image_api_key or (self.image_provider == "gemini" and self.gemini_api_key)):
                out.append("IMAGE_API_KEY is required for image generation")
        if need_wordpress:
            if not self.wp_rest_url:
                out.append("WP_REST_URL is not set")
            if not self.wp_auth_token:
                out.append("WP_AUTH_TOKEN is not set")
        return out
