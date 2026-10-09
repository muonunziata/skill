"""GoLehighAcres.org brand kit: logos, palette and the Poppins font (SIL OFL), all embedded so renders look identical everywhere.

Identity (taken from the supplied logos):
  * green arrow  #1b6a55  → primary
  * coral "GO"   #ff5757  → accent (tags, calls to action)
  * ring colours (greens, yellow, orange, purple, blues) → decorative rhythm: progress dots and the bottom stripe
  * wordmark: geometric bold sans (Poppins), "GO" in coral + "LehighAcres.org" in black
"""
from __future__ import annotations

import base64
from dataclasses import dataclass
from pathlib import Path

ASSETS = Path(__file__).resolve().parent / "assets"

# Segment colours sampled from the ring of the logo, in the order they appear (green → yellow → orange → purple → blue).
RING = ["#2c6e50", "#66a785", "#a3cf99", "#e4a927", "#cb7738", "#734698", "#5c6fb4", "#2858a2", "#2f89c8"]
FONT_WEIGHTS = (500, 600, 700, 800)


@dataclass
class Brand:
    name: str = "GoLehighAcres.org"
    handle: str = "GoLehighAcres.org"
    color: str = "#1b6a55"
    accent: str = "#ff5757"
    dark: str = "#0f3a30"
    logo_h: str = ""      # horizontal logo, data URI
    logo_v: str = ""      # vertical logo, data URI
    font: str = "Poppins"
    font_css: str = ""    # @font-face rules (data URIs)


def _data_uri(path: Path, mime: str) -> str:
    return f"data:{mime};base64," + base64.b64encode(path.read_bytes()).decode()


def font_face_css() -> str:
    rules = []
    for w in FONT_WEIGHTS:
        f = ASSETS / "fonts" / f"poppins-latin-{w}-normal.woff2"
        if f.is_file():
            rules.append(f"@font-face{{font-family:'Poppins';font-weight:{w};font-style:normal;font-display:block;"
                         f"src:url({_data_uri(f, 'font/woff2')}) format('woff2')}}")
    return "".join(rules)


def _logo(spec: str, packaged: str, fetcher=None) -> str:
    """A custom BRAND_LOGO (path or URL) wins; otherwise the packaged GoLehighAcres.org logo."""
    from .render import logo_data_uri

    if spec:
        uri = logo_data_uri(spec, fetcher)
        if uri:
            return uri
    return _data_uri(ASSETS / packaged, "image/png")


def load_brand(settings, fetcher=None) -> Brand:
    return Brand(
        name=settings.brand_name, handle=settings.brand_handle, color=settings.brand_color, accent=settings.brand_accent,
        dark=settings.brand_dark,
        logo_h=_logo(settings.brand_logo, "golehighacres-logo-horizontal.png", fetcher),
        logo_v=_logo(settings.brand_logo_vertical, "golehighacres-logo-vertical.png", fetcher),
        font=settings.brand_font or "Poppins",
        font_css=font_face_css(),
    )
