"""Curator: turns a finding into verified, rights-aware media (photos and videos) and source text.

Nothing the language model proposes is trusted blindly: image URLs are probed, YouTube links are checked
through oEmbed, and openly licensed photos come from Wikimedia Commons with their attribution.
"""
from __future__ import annotations

import json
import re
import struct
from dataclasses import dataclass
from html.parser import HTMLParser
from urllib.parse import quote, urljoin, urlsplit

from .net import FetchError, Fetcher

OPEN_LICENSES = {"cc0", "pd", "cc-by", "cc-by-sa"}


@dataclass
class MediaItem:
    id: str
    type: str  # image | video
    url: str
    thumb: str = ""
    credit: str = ""
    license: str = "unknown"  # cc0 | pd | cc-by | cc-by-sa | youtube | publisher
    license_url: str = ""
    caption: str = ""
    alt: str = ""
    source_page: str = ""
    width: int = 0
    height: int = 0
    origin: str = ""  # commons | youtube | publisher
    safe_to_publish: bool = False


# --------------------------------------------------------------------------- page parsing
@dataclass
class PageInfo:
    title: str = ""
    description: str = ""
    og_image: str = ""
    site_name: str = ""
    published: str = ""
    canonical: str = ""
    text: str = ""


class _PageParser(HTMLParser):
    SKIP = {"script", "style", "noscript", "nav", "footer", "aside", "header", "form", "svg", "iframe"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.meta: dict[str, str] = {}
        self.title = ""
        self.canonical = ""
        self._in_title = False
        self._skip = 0
        self.chunks: list[str] = []

    def handle_starttag(self, tag, attrs):
        a = {k: (v or "") for k, v in attrs}
        if tag == "title":
            self._in_title = True
        elif tag == "meta":
            key = (a.get("property") or a.get("name") or "").lower()
            if key and a.get("content"):
                self.meta.setdefault(key, a["content"])
        elif tag == "link" and a.get("rel", "").lower() == "canonical":
            self.canonical = a.get("href", "")
        if tag in self.SKIP:
            self._skip += 1

    def handle_endtag(self, tag):
        if tag == "title":
            self._in_title = False
        if tag in self.SKIP and self._skip:
            self._skip -= 1
        if tag in {"p", "br", "li", "h1", "h2", "h3", "div"}:
            self.chunks.append("\n")

    def handle_data(self, data):
        if self._in_title:
            self.title += data
        elif not self._skip and data.strip():
            self.chunks.append(data)


def parse_page(html: str, base_url: str) -> PageInfo:
    p = _PageParser()
    try:
        p.feed(html)
    except Exception:  # malformed HTML must never stop a cycle
        pass
    m = p.meta
    img = m.get("og:image") or m.get("twitter:image") or ""
    if img:
        img = urljoin(base_url, img)
        if urlsplit(img).scheme not in ("http", "https"):
            img = ""
    text = re.sub(r"[ \t]+", " ", "".join(p.chunks))
    text = re.sub(r"\n\s*\n+", "\n", text).strip()
    return PageInfo(
        title=(m.get("og:title") or p.title).strip(),
        description=(m.get("og:description") or m.get("description") or "").strip(),
        og_image=img,
        site_name=m.get("og:site_name", "").strip(),
        published=(m.get("article:published_time") or m.get("og:published_time") or m.get("date") or "").strip(),
        canonical=urljoin(base_url, p.canonical) if p.canonical else "",
        text=text[:60_000],
    )


# --------------------------------------------------------------------------- images
def image_size(data: bytes) -> tuple[int, int]:
    """Best-effort (width, height) for PNG, GIF, JPEG and WebP; (0, 0) if unknown."""
    try:
        if data[:8] == b"\x89PNG\r\n\x1a\n":
            return struct.unpack(">II", data[16:24])
        if data[:6] in (b"GIF87a", b"GIF89a"):
            return struct.unpack("<HH", data[6:10])
        if data[:2] == b"\xff\xd8":
            i = 2
            while i + 9 < len(data):
                if data[i] != 0xFF:
                    i += 1
                    continue
                marker = data[i + 1]
                if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
                    i += 2
                    continue
                length = struct.unpack(">H", data[i + 2 : i + 4])[0]
                if 0xC0 <= marker <= 0xCF and marker not in (0xC4, 0xC8, 0xCC):
                    h, w = struct.unpack(">HH", data[i + 5 : i + 9])
                    return w, h
                i += 2 + length
        if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
            if data[12:16] == b"VP8X":
                w = int.from_bytes(data[24:27], "little") + 1
                h = int.from_bytes(data[27:30], "little") + 1
                return w, h
    except (struct.error, IndexError):
        pass
    return 0, 0


# --------------------------------------------------------------------------- Wikimedia Commons
def classify_license(short_name: str) -> str:
    s = (short_name or "").lower().replace("-", " ").strip()
    if "cc0" in s or "public domain dedication" in s:
        return "cc0"
    if s.startswith("public domain") or s in {"pd", "pd usgov"} or s.startswith("pd "):
        return "pd"
    if s.startswith("cc by sa"):
        return "cc-by-sa"
    if s.startswith("cc by") and "nc" not in s and "nd" not in s:
        return "cc-by"
    return "other"


def _strip_tags(value: str) -> str:
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", value or "")).strip()


def commons_search(fetcher: Fetcher, query: str, limit: int = 4) -> list[MediaItem]:
    url = (
        "https://commons.wikimedia.org/w/api.php?action=query&format=json&generator=search"
        f"&gsrsearch={quote('filetype:bitmap ' + query)}&gsrnamespace=6&gsrlimit={int(limit) * 2}"
        "&prop=imageinfo&iiprop=url|extmetadata|size|mime&iiurlwidth=1280"
    )
    try:
        data = json.loads(fetcher.get(url, accept="application/json").text())
    except (FetchError, ValueError):
        return []
    out: list[MediaItem] = []
    for page in (data.get("query", {}).get("pages", {}) or {}).values():
        info = (page.get("imageinfo") or [{}])[0]
        if info.get("mime") not in ("image/jpeg", "image/png", "image/webp"):
            continue
        meta = info.get("extmetadata", {})
        lic = classify_license(meta.get("LicenseShortName", {}).get("value", ""))
        if lic not in OPEN_LICENSES or info.get("width", 0) < 800:
            continue
        artist = _strip_tags(meta.get("Artist", {}).get("value", "")) or "Unknown author"
        title = page.get("title", "").removeprefix("File:")
        out.append(
            MediaItem(
                id="",
                type="image",
                url=info.get("thumburl") or info.get("url", ""),
                thumb=info.get("thumburl", ""),
                credit=f"{artist} / Wikimedia Commons ({meta.get('LicenseShortName', {}).get('value', lic)})",
                license=lic,
                license_url=meta.get("LicenseUrl", {}).get("value", ""),
                caption=_strip_tags(meta.get("ImageDescription", {}).get("value", ""))[:200] or title,
                alt=title.rsplit(".", 1)[0].replace("_", " "),
                source_page=info.get("descriptionurl", ""),
                width=info.get("thumbwidth") or info.get("width", 0),
                height=info.get("thumbheight") or info.get("height", 0),
                origin="commons",
                safe_to_publish=True,
            )
        )
        if len(out) >= limit:
            break
    return out


# --------------------------------------------------------------------------- video
_YT = re.compile(r"(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})")


def youtube_id(url: str) -> str:
    m = _YT.search(url or "")
    return m.group(1) if m else ""


def validate_youtube(fetcher: Fetcher, url: str) -> MediaItem | None:
    vid = youtube_id(url)
    if not vid:
        return None
    watch = f"https://www.youtube.com/watch?v={vid}"
    try:
        info = json.loads(fetcher.get(f"https://www.youtube.com/oembed?format=json&url={quote(watch, safe='')}",
                                      accept="application/json").text())
    except (FetchError, ValueError):
        return None  # missing, private, or embedding disabled
    return MediaItem(
        id="",
        type="video",
        url=watch,
        thumb=info.get("thumbnail_url", ""),
        credit=f"{info.get('author_name', 'YouTube')} on YouTube",
        license="youtube",
        caption=info.get("title", ""),
        alt=info.get("title", ""),
        source_page=watch,
        width=info.get("width", 0),
        height=info.get("height", 0),
        origin="youtube",
        safe_to_publish=True,
    )
