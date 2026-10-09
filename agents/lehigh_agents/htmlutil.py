"""Small HTML helpers shared by the Redactor (sanitising) and the Auditor (checks)."""
from __future__ import annotations

import html as _html
import re

_BLOCK_TAGS = re.compile(r"<\s*(script|style|iframe|object|embed|form|link|meta)\b.*?(?:</\s*\1\s*>|/?>)", re.I | re.S)
_EVENT_ATTR = re.compile(r"""\s+on[a-z]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)""", re.I)
_JS_URL = re.compile(r"""(href|src)\s*=\s*(["'])\s*javascript:[^"']*\2""", re.I)


_SHORTCODE = re.compile(r"(?<!\[)\[(/?[A-Za-z][\w-]*)([^\[\]]*)\](?!\])")


def neutralize_shortcodes(html: str) -> str:
    """Page text can contain WordPress shortcodes ([gallery], [plugin_tag …]); never let them execute."""
    return _SHORTCODE.sub(lambda m: f"&#91;{m.group(1)}{m.group(2)}&#93;", html)


def sanitize_html(html: str) -> str:
    html = _BLOCK_TAGS.sub("", html)
    html = _EVENT_ATTR.sub("", html)
    html = _JS_URL.sub(r"\1=\2#\2", html)
    return neutralize_shortcodes(html)


def unsafe_html_problems(html: str) -> list[str]:
    problems = []
    if re.search(r"<\s*script\b", html, re.I):
        problems.append("contains <script>")
    if re.search(r"<\s*(iframe|object|embed|form)\b", html, re.I):
        problems.append("contains iframe/object/embed/form")
    if _EVENT_ATTR.search(html):
        problems.append("contains inline event handlers (onclick=…)")
    if re.search(r"javascript\s*:", html, re.I):
        problems.append("contains a javascript: URL")
    return problems


def strip_tags(html: str) -> str:
    text = re.sub(r"<!--.*?-->", " ", html, flags=re.S)
    text = re.sub(r"<[^>]+>", " ", text)
    return re.sub(r"\s+", " ", _html.unescape(text)).strip()


def extract_urls(html: str) -> list[str]:
    urls = re.findall(r"""(?:href|src)\s*=\s*["'](https?://[^"'\s>]+)["']""", html, re.I)
    seen: list[str] = []
    for u in urls:
        if u not in seen:
            seen.append(u)
    return seen


def word_count(html: str) -> int:
    return len(strip_tags(html).split())


def slugify(text: str, limit: int = 60) -> str:
    import unicodedata

    s = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode().lower()
    return re.sub(r"[^a-z0-9]+", "-", s).strip("-")[:limit].strip("-") or "articulo"
