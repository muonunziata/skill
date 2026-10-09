"""HTML/CSS for every slide and video scene, built on the GoLehighAcres.org identity (see brand.py).

Design rules: one idea per slide, huge Poppins type, the official logo on every frame, the logo's ring colours as the
visual rhythm (progress dots + bottom stripe), coral for calls to action, platform safe zones (TikTok's interface covers the
bottom and right edge; Instagram crops the grid thumbnail to the centre), automatic text fitting, and a visible credit /
AI label whenever the image needs one.
"""
from __future__ import annotations

import html
from dataclasses import dataclass

from .brand import RING, Brand

# width, height, safe-area padding (top, bottom, left, right)
FORMATS = {
    "instagram": dict(w=1080, h=1350, pt=84, pb=120, pl=84, pr=84),
    "tiktok": dict(w=1080, h=1920, pt=230, pb=420, pl=84, pr=150),
    "video": dict(w=1080, h=1920, pt=230, pb=420, pl=84, pr=150),
}

STRINGS = {
    "es": dict(swipe="Desliza", save="Guarda", share="Comparte", follow="Síguenos", source="Fuente", news="Noticia",
               link="Lee la nota completa · enlace en la bio", review="Redactado con ayuda de IA y revisado por nuestro equipo",
               ai_image="Ilustración generada con IA", reads="Más en"),
    "en": dict(swipe="Swipe", save="Save", share="Share", follow="Follow us", source="Source", news="News",
               link="Read the full story · link in bio", review="Written with AI assistance and reviewed by our team",
               ai_image="AI-generated illustration", reads="More at"),
}


def strings(lang: str) -> dict[str, str]:
    return STRINGS["es"] if lang.startswith("es") else STRINGS["en"]


def _hex_rgb(h: str) -> tuple[int, int, int]:
    h = h.lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    return int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)


def mix(h: str, other: str, t: float) -> str:
    a, b = _hex_rgb(h), _hex_rgb(other)
    return "#%02x%02x%02x" % tuple(round(a[i] + (b[i] - a[i]) * t) for i in range(3))


def _theme_vars(theme: str, b: Brand) -> dict[str, str]:
    if theme == "dark":
        return {"bg": b.dark, "bg2": mix(b.dark, "#000000", .5), "fg": "#ffffff", "muted": "rgba(255,255,255,.80)",
                "panel": "rgba(255,255,255,.09)", "accent": mix(b.color, "#ffffff", .55), "on_accent": "#06201a",
                "line": "rgba(255,255,255,.20)"}
    if theme == "brand":
        return {"bg": b.color, "bg2": mix(b.color, b.dark, .78), "fg": "#ffffff", "muted": "rgba(255,255,255,.86)",
                "panel": "rgba(255,255,255,.14)", "accent": "#ffffff", "on_accent": b.color, "line": "rgba(255,255,255,.30)"}
    return {"bg": "#ffffff", "bg2": "#eef6f1", "fg": "#0b1f19", "muted": "rgba(11,31,25,.68)", "panel": "#eef6f1",
            "accent": b.color, "on_accent": "#ffffff", "line": "rgba(11,31,25,.13)"}


FIT_JS = """
() => {
  document.querySelectorAll('[data-fit]').forEach(el => {
    let fs = parseFloat(getComputedStyle(el).fontSize);
    const min = parseFloat(el.dataset.min || '30');
    let guard = 80;
    // tolerance: Poppins' content area is taller than the line box, a real overflow is at least one extra line
    while ((el.scrollHeight > el.clientHeight + fs * 0.3 || el.scrollWidth > el.clientWidth + 1) && fs > min && guard-- > 0) {
      fs -= 2; el.style.fontSize = fs + 'px';
    }
  });
  return document.fonts ? document.fonts.ready.then(() => true) : true;
}
"""


def _ringbar() -> str:
    segs = "".join(f'<i style="background:{c}"></i>' for c in RING)
    return f'<div class="ringbar">{segs}</div>'


def _ringdeco() -> str:
    """The logo's ring, drawn big and cropped by the canvas edge: the visual signature on photo-less slides."""
    stops, n = [], len(RING)
    for i, c in enumerate(RING):
        stops.append(f"{c} {i / n * 100:.2f}% {(i + 1) / n * 100:.2f}%")
    return (f'<div class="ringdeco" style="background:conic-gradient(from 20deg,{",".join(stops)})"></div>')


CSS = """
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:var(--W);height:var(--H);overflow:hidden;background:var(--bg)}
.s{position:relative;width:var(--W);height:var(--H);overflow:hidden;color:var(--fg);
   font-family:var(--font),Poppins,Inter,"Segoe UI","Helvetica Neue",Arial,"Noto Sans","DejaVu Sans",sans-serif;
   background:linear-gradient(170deg,var(--bg) 0%,var(--bg2) 100%);-webkit-font-smoothing:antialiased}
.bg{position:absolute;inset:0;background-size:cover;background-position:var(--focus,50% 50%);background-repeat:no-repeat}
.bg--soft{opacity:.20;filter:blur(8px) saturate(1.05);transform:scale(1.12)}
.shade{position:absolute;inset:0}
.ringdeco{position:absolute;width:980px;height:980px;border-radius:50%;right:-430px;top:-330px;opacity:.20;
  -webkit-mask:radial-gradient(circle,transparent 54%,#000 55%);mask:radial-gradient(circle,transparent 54%,#000 55%)}
.ringdeco--b{right:auto;left:-520px;top:auto;bottom:-380px;opacity:.13;transform:rotate(160deg)}
.safe{position:absolute;left:var(--pl);right:var(--pr);top:var(--pt);bottom:var(--pb);display:flex;flex-direction:column;min-height:0}
.top{display:flex;align-items:center;justify-content:space-between;gap:24px;flex:none}
.logo{background:#fff;border-radius:22px;padding:14px 26px;display:flex;align-items:center;box-shadow:0 6px 28px rgba(0,0,0,.14),0 0 0 1px rgba(0,0,0,.06)}
.logo img{height:46px;width:auto;display:block}
.count{font-size:28px;font-weight:700;color:var(--muted);font-variant-numeric:tabular-nums;background:var(--panel);padding:10px 20px;border-radius:999px}
.main{flex:1;min-height:0;display:flex;flex-direction:column;justify-content:center;gap:28px;padding:30px 0}
.main--end{justify-content:flex-end}
.tag{align-self:flex-start;background:var(--hot);color:#fff;padding:12px 26px;border-radius:999px;font-weight:700;font-size:26px;letter-spacing:.08em;text-transform:uppercase}
.h{font-weight:800;line-height:1.08;letter-spacing:-.02em;overflow:hidden;max-height:var(--hmax);text-wrap:balance;overflow-wrap:anywhere}
.p{font-size:40px;line-height:1.3;color:var(--muted);overflow:hidden;max-height:var(--pmax);font-weight:500}
.bottom{flex:none;display:flex;align-items:center;justify-content:space-between;gap:24px;min-height:56px}
.dots{display:flex;gap:10px;align-items:center}.dots i{width:16px;height:16px;border-radius:50%;opacity:.38}.dots i.on{width:54px;border-radius:9px;opacity:1}
.hint{font-weight:700;font-size:30px;display:flex;align-items:center;gap:14px;color:var(--fg)}
.hint svg{color:var(--hot)}
.credit{position:absolute;left:var(--pl);right:var(--pr);bottom:calc(var(--pb) - 60px);font-size:21px;color:var(--muted);letter-spacing:.02em}
.credit b{background:var(--panel);border:1px solid var(--line);padding:5px 14px;border-radius:999px;font-weight:600;margin-right:10px}
.ringbar{position:absolute;left:0;right:0;bottom:0;height:16px;display:flex}.ringbar i{flex:1}

/* cover */
.s--cover .shade{background:linear-gradient(180deg,rgba(0,0,0,.42) 0%,rgba(0,0,0,0) 28%,rgba(0,0,0,.30) 52%,rgba(6,24,19,.93) 100%)}
.s--cover .h{font-size:var(--cover-size);color:#fff;text-shadow:0 2px 26px rgba(0,0,0,.35)}
.s--cover .p{color:rgba(255,255,255,.92);font-size:38px}
.s--cover .count{background:rgba(0,0,0,.35);color:#fff}
.s--cover .hint{color:#fff}.s--cover .credit{color:rgba(255,255,255,.86)}.s--cover .credit b{background:rgba(0,0,0,.38);border-color:rgba(255,255,255,.3)}
.s--cover .dots i.on{opacity:1}
/* point */
.num{font-size:200px;font-weight:800;line-height:.9;letter-spacing:-.04em;font-variant-numeric:tabular-nums;color:var(--accent)}
.rule{width:140px;height:12px;border-radius:7px;background:linear-gradient(90deg,var(--hot),var(--accent))}
.s--point .h{font-size:var(--point-size)}
/* stat */
.kicker{font-size:34px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);overflow:hidden;max-height:var(--pmax)}
.big{font-weight:800;line-height:1;letter-spacing:-.04em;font-size:var(--stat-size);max-height:var(--hmax);overflow:hidden;white-space:nowrap;color:var(--accent)}
.statlabel{font-size:44px;font-weight:700}
/* quote */
.qm{font-size:280px;line-height:.62;font-weight:800;color:var(--hot);height:150px}
.s--quote .h{font-weight:700;font-size:var(--point-size)}
.who{font-size:36px;font-weight:700;color:var(--accent)}
/* source */
.card{background:var(--panel);border:1px solid var(--line);border-radius:40px;padding:52px;display:flex;flex-direction:column;gap:24px}
.note{font-size:30px;color:var(--muted);display:flex;gap:14px;align-items:flex-start;line-height:1.3;font-weight:500}
.note::before{content:"";flex:none;width:14px;height:14px;border-radius:50%;background:var(--hot);margin-top:11px}
/* cta */
.s--cta .main{align-items:flex-start}
.s--cta .h{font-size:var(--point-size)}
.vlogo{background:#fff;border-radius:34px;padding:34px 44px;box-shadow:0 10px 40px rgba(0,0,0,.14),0 0 0 1px rgba(0,0,0,.06);align-self:stretch;display:flex;justify-content:center}
.vlogo img{width:100%;max-width:640px;height:auto;display:block}
.chips{display:flex;flex-wrap:wrap;gap:16px}.chips span{padding:16px 34px;border-radius:999px;font-weight:700;font-size:36px;color:#fff}
.chips span:nth-child(1){background:var(--hot)}.chips span:nth-child(2){background:#2858a2}.chips span:nth-child(3){background:var(--brand)}
.link{font-size:32px;font-weight:600;color:var(--muted)}
/* video scenes */
.s--scene .shade{background:linear-gradient(180deg,rgba(0,0,0,.50) 0%,rgba(0,0,0,.04) 26%,rgba(0,0,0,.28) 46%,rgba(6,24,19,.92) 100%)}
.s--scene .h{font-size:var(--scene-size);color:#fff;text-shadow:0 2px 30px rgba(0,0,0,.5)}
.s--scene .credit{color:rgba(255,255,255,.88)}.s--scene .credit b{background:rgba(0,0,0,.38);border-color:rgba(255,255,255,.3)}
.progress{height:10px;border-radius:6px;background:rgba(255,255,255,.28);overflow:hidden;flex:1}.progress i{display:block;height:100%;background:var(--hot);border-radius:6px}
.s--outro .main{justify-content:center;align-items:stretch}
.s--outro .h{color:var(--fg);font-size:84px}
"""


def _e(text: str) -> str:
    return html.escape(text or "", quote=True)


def _style(fmt: str, brand: Brand, theme: str, focus: str) -> str:
    f = FORMATS[fmt]
    v = _theme_vars(theme, brand)
    k = min(f["h"] / 1350, 1.18)
    vars_ = {
        "--W": f"{f['w']}px", "--H": f"{f['h']}px", "--pt": f"{f['pt']}px", "--pb": f"{f['pb']}px", "--pl": f"{f['pl']}px",
        "--pr": f"{f['pr']}px", "--focus": focus, "--font": f"'{brand.font}'", "--hot": brand.accent, "--brand": brand.color,
        "--hmax": f"{round(f['h'] * .34)}px", "--pmax": f"{round(f['h'] * .2)}px",
        "--cover-size": f"{round(100 * k)}px", "--point-size": f"{round(70 * k)}px",
        "--stat-size": f"{round(240 * min(k, 1.1))}px", "--scene-size": "98px",
        **{f"--{key}": val for key, val in v.items()},
    }
    return ";".join(f"{key}:{val}" for key, val in vars_.items())


def _logo_html(b: Brand) -> str:
    return f'<div class="logo"><img src="{_e(b.logo_h)}" alt="{_e(b.name)}"></div>'


def _credit_html(credit: str, ai_label: str) -> str:
    bits = []
    if ai_label:
        bits.append(f"<b>{_e(ai_label)}</b>")
    if credit and credit != ai_label:
        bits.append(_e(credit))
    return f'<div class="credit">{"".join(bits)}</div>' if bits else ""


def _wrap(brand: Brand, css_vars: str, kind: str, theme: str, inner: str, photo: str, soft: bool, deco: bool) -> str:
    if photo:
        bg = f'<div class="bg {"bg--soft" if soft else ""}" style="background-image:url(\'{photo}\')"></div>'
    else:
        bg = ""
    if deco and not (photo and not soft):
        bg += _ringdeco() + _ringdeco().replace('class="ringdeco"', 'class="ringdeco ringdeco--b"')
    return (f'<!doctype html><html><head><meta charset="utf-8"><style>{brand.font_css}{CSS}</style></head><body>'
            f'<div class="s s--{kind} t--{theme}" style="{css_vars}">{bg}<div class="shade"></div>{inner}{_ringbar()}</div></body></html>')


def slide_html(slide, *, index: int, total: int, fmt: str, brand: Brand, theme: str = "light", photo: str = "",
               focus: str = "50% 50%", tag: str = "", credit: str = "", ai_label: str = "", lang: str = "es",
               source_name: str = "") -> str:
    t = strings(lang)
    kind = slide.kind
    if kind == "cover" and not photo and theme == "light":
        theme = "brand"          # white text needs a dark ground: a photo-less cover uses the solid brand green
    css_vars = _style(fmt, brand, theme, focus)
    last = index == total - 1
    dots = "".join(f'<i class="{"on" if i == index else ""}" style="background:{RING[i % len(RING)]}"></i>' for i in range(total))
    hint = "" if last else (f'<div class="hint">{_e(t["swipe"])} <svg width="46" height="28" viewBox="0 0 40 24" fill="none">'
                            '<path d="M2 12h34M26 2l10 10-10 10" stroke="currentColor" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div>')
    head = f'<div class="top">{_logo_html(brand)}<div class="count">{index + 1:02d}/{total:02d}</div></div>'
    foot = f'<div class="bottom"><div class="dots">{dots}</div>{hint}</div>'
    h, b = _e(slide.headline), _e(slide.body)
    pbody = f'<div class="p" data-fit data-min="26">{b}</div>' if slide.body else ""

    if kind == "cover":
        main = (f'<div class="main main--end"><div class="tag">{_e(tag or t["news"])}</div>'
                f'<div class="h" data-fit data-min="54" style="max-height:{round(FORMATS[fmt]["h"] * .42)}px">{h}</div>{pbody}</div>')
    elif kind == "stat":
        main = (f'<div class="main"><div class="kicker" data-fit data-min="22">{h}</div>'
                f'<div class="big" data-fit data-min="80">{_e(slide.stat)}</div>'
                f'<div class="statlabel">{_e(slide.stat_label)}</div><div class="rule"></div>{pbody}</div>')
    elif kind == "quote":
        main = (f'<div class="main"><div class="qm">“</div><div class="h" data-fit data-min="40">{h}</div>'
                f'{"<div class=who>— " + b + "</div>" if slide.body else ""}</div>')
    elif kind == "source":
        who = _e(source_name or slide.body)
        main = (f'<div class="main"><div class="card"><div class="tag">{_e(t["source"])}</div>'
                f'<div class="h" data-fit data-min="40" style="font-size:62px">{h}</div>'
                f'<div class="p" style="color:var(--fg);font-weight:700" data-fit data-min="30">{who}</div>'
                f'<div class="note">{_e(t["review"])}</div></div></div>')
    elif kind == "cta":
        main = (f'<div class="main"><div class="h" data-fit data-min="44">{h}</div>{pbody}'
                f'<div class="chips"><span>{_e(t["save"])}</span><span>{_e(t["share"])}</span><span>{_e(t["follow"])}</span></div>'
                f'<div class="vlogo"><img src="{_e(brand.logo_v)}" alt="{_e(brand.name)}"></div>'
                f'<div class="link">{_e(t["link"])}</div></div>')
    else:  # point
        main = (f'<div class="main"><div class="num">{index:02d}</div><div class="rule"></div>'
                f'<div class="h" data-fit data-min="40">{h}</div>{pbody}</div>')
    inner = f'<div class="safe">{head}{main}{foot}</div>{_credit_html(credit, ai_label) if kind in ("cover", "source") else ""}'
    # interior slides stay clean (legibility first): the photo lives on the cover and in the video scenes
    return _wrap(brand, css_vars, kind, theme, inner, photo if kind == "cover" else "", soft=False,
                 deco=kind != "cover" or not photo)


def scene_html(text: str, *, index: int, total: int, brand: Brand, theme: str = "light", photo: str = "",
               focus: str = "50% 50%", tag: str = "", credit: str = "", ai_label: str = "", lang: str = "es",
               outro: bool = False) -> str:
    t = strings(lang)
    css_vars = _style("video", brand, theme, focus)
    pct = round((index + 1) / total * 100)
    head = f'<div class="top">{_logo_html(brand)}</div>'
    if outro:
        main = (f'<div class="main"><div class="vlogo"><img src="{_e(brand.logo_v)}" alt="{_e(brand.name)}"></div>'
                f'<div class="h" data-fit data-min="48">{_e(text)}</div>'
                f'<div class="chips"><span>{_e(t["follow"])}</span></div><div class="link">{_e(t["link"])}</div></div>')
        inner = f'<div class="safe">{head}{main}<div class="bottom"></div></div>'
        return _wrap(brand, css_vars, "outro", theme, inner, "", False, True)
    label = f'<div class="tag">{_e(tag)}</div>' if index == 0 and tag else ""
    main = f'<div class="main main--end">{label}<div class="h" data-fit data-min="50">{_e(text)}</div></div>'
    foot = f'<div class="bottom"><div class="progress"><i style="width:{pct}%"></i></div></div>'
    inner = f'<div class="safe">{head}{main}{foot}</div>{_credit_html(credit, ai_label) if photo else ""}'
    return _wrap(brand, css_vars, "scene", theme, inner, photo, False, False)
