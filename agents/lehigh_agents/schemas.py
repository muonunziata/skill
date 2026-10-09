"""Data contracts between the three agents (field names follow the project specification)."""
from __future__ import annotations

import datetime as dt
import re
from dataclasses import asdict, dataclass, field
from typing import Any
from urllib.parse import urlsplit


# ───────────────────────────── Agent 1 output ─────────────────────────────
@dataclass
class Hallazgo:
    titulo_fuente: str
    url: str
    resumen_hechos: str
    fecha: str  # ISO yyyy-mm-dd, "" if unknown
    palabras_clave: list[str]
    # internal evidence, never sent to WordPress as-is
    source_text: str = ""
    fuente: str = ""  # publisher / domain

    def to_dict(self) -> dict[str, Any]:
        d = asdict(self)
        d.pop("source_text")
        return d


# ───────────────────────────── Agent 2 output ─────────────────────────────
@dataclass
class Articulo:
    post_title: str
    post_content: str
    excerpt: str
    meta_description: str
    suggested_tags: list[str]
    featured_image_url: str = ""
    # filled by the redactor's media step
    featured_image_credit: str = ""
    media_todo: list[str] = field(default_factory=list)  # placeholders that could not be resolved
    model: str = ""
    # AI-generated featured image details (empty when the image comes from an open-licence archive)
    featured_image_ai: bool = False
    featured_image_prompt: str = ""
    featured_image_alt: str = ""
    featured_image_caption: str = ""
    featured_media_id: int = 0
    image_provider: str = ""
    image_model: str = ""

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


# ───────────────────────────── Agent 3 output ─────────────────────────────
@dataclass
class Auditoria:
    audit_score: int
    audit_notes: list[str]
    status: str  # "approved" | "flagged"
    checks: dict[str, Any] = field(default_factory=dict)
    model: str = ""

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


# ───────────────────────────── helpers / validators ─────────────────────────────
_DATE = re.compile(r"(\d{4})-(\d{1,2})-(\d{1,2})")
_DMY = re.compile(r"(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})")


def normalize_date(value: Any) -> str:
    """Return yyyy-mm-dd or '' (never raises)."""
    s = str(value or "").strip()
    for rx, order in ((_DATE, "ymd"), (_DMY, "dmy")):
        m = rx.search(s)
        if not m:
            continue
        a, b, c = (int(x) for x in m.groups())
        y, mo, d = (a, b, c) if order == "ymd" else (c, b, a)
        try:
            return dt.date(y, mo, d).isoformat()
        except ValueError:
            continue
    return ""


def _is_http(url: Any) -> bool:
    return isinstance(url, str) and urlsplit(url).scheme in ("http", "https") and bool(urlsplit(url).netloc)


def clean_list(value: Any, limit: int = 12) -> list[str]:
    if isinstance(value, str):
        value = [v for v in re.split(r"[,;\n]", value)]
    if not isinstance(value, list):
        return []
    out: list[str] = []
    for v in value:
        s = str(v).strip()
        if s and s.lower() not in {x.lower() for x in out}:
            out.append(s)
    return out[:limit]


def validate_hallazgos(data: Any) -> str | None:
    items = data.get("hallazgos") if isinstance(data, dict) else data
    if not isinstance(items, list):
        return "expected a JSON array (or an object with a 'hallazgos' array)"
    for i, it in enumerate(items):
        if not isinstance(it, dict):
            return f"item {i} is not an object"
        for key in ("titulo_fuente", "url", "resumen_hechos"):
            if not isinstance(it.get(key), str) or not it[key].strip():
                return f"item {i}: '{key}' is required"
        if not _is_http(it["url"]):
            return f"item {i}: 'url' must be an http(s) URL"
    return None


def validate_articulo(data: Any) -> str | None:
    if not isinstance(data, dict):
        return "expected a JSON object"
    for key in ("post_title", "post_content", "excerpt", "meta_description"):
        if not isinstance(data.get(key), str) or not data[key].strip():
            return f"'{key}' is required"
    if len(data["post_title"]) > 160:
        return "'post_title' must be at most 160 characters"
    if not isinstance(data.get("suggested_tags", []), (list, str)):
        return "'suggested_tags' must be a list"
    return None


def validate_auditoria(data: Any) -> str | None:
    if not isinstance(data, dict):
        return "expected a JSON object"
    try:
        score = float(data.get("audit_score"))
    except (TypeError, ValueError):
        return "'audit_score' must be a number between 0 and 100"
    if not 0 <= score <= 100:
        return "'audit_score' must be between 0 and 100"
    if data.get("status") not in ("approved", "flagged"):
        return "'status' must be 'approved' or 'flagged'"
    if not isinstance(data.get("audit_notes", []), list):
        return "'audit_notes' must be a list of strings"
    return None
