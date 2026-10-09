---
name: brand-guide
description: GoLehighAcres.org brand rules - official logos, colours, Poppins type, ring-colour motif, tone. Use whenever creating or editing ANY visual or copy for GoLehighAcres.org - Instagram/TikTok slides, video frames, WordPress plugin UI, thumbnails, covers, banners, templates, captions.
---

# GoLehighAcres.org brand guide (always apply)

The logos supplied by the owner are the source of truth. Never redraw, recolour, stretch, outline or add effects to them.

## Assets
| File | Use |
|---|---|
| `brand/golehighacres-logo-horizontal.png` | Default: header of every slide / frame, WordPress admin header. |
| `brand/golehighacres-logo-vertical.png` | Closing slide (CTA) and video outro. |
| `brand/golehighacres-symbol.png` | Square avatars, favicons, tiny placements. |
| `brand/golehighacres-wordmark-plain.png` | Text-only contexts. |
Copies live in `agents/lehigh_agents/social/assets/` (a test enforces they are identical; change both or neither).
On photos or dark backgrounds the horizontal logo sits on a white rounded plate - never place it directly on a busy photo.

## Colour
| Token | Hex | Use |
|---|---|---|
| Brand green (the arrow) | `#1b6a55` | Primary: numbers, rules, buttons, headings accents |
| Coral (the "GO") | `#ff5757` | Calls to action, tags, swipe hint - sparingly, one coral element per area |
| Deep green | `#0f3a30` | Dark theme background, video backdrop |
| Ring palette | `#2c6e50 #66a785 #a3cf99 #e4a927 #cb7738 #734698 #5c6fb4 #2858a2 #2f89c8` | Rhythm only: progress dots, bottom stripe, soft decorative rings. Never for body text. |
| Ink / white | `#0b1f19` / `#ffffff` | Text / default light-theme background |
Text contrast must be >= 4.5:1. White text only on photos with the dark shade overlay or on green.

## Type
**Poppins** (SIL OFL, embedded as base64 `@font-face` so renders look identical everywhere): 800 for covers and big numbers, 700 headlines, 500-600 body. Sentence case; short lines; one idea per slide.

## Layout rules
- Instagram carousel 1080x1350 (4:5), TikTok / video 1080x1920 (9:16). TikTok safe zone: keep text out of the top ~230 px, bottom ~420 px and right ~150 px.
- Order: cover (hook) -> content slides -> source -> CTA. Counter "01/06" top right, progress dots and ring stripe at the bottom.
- AI-generated imagery is always labelled ("Ilustración generada con IA"). Photo credits stay visible.
- Customisation is allowed only through `.env` (`BRAND_COLOR`, `BRAND_ACCENT`, `BRAND_DARK`, `BRAND_LOGO`, ...), defaults are the values above.

## Voice
Local, clear, neighbourly and responsible: Spanish by default (neutral Latin American), no clickbait, no sensationalism, attribute every claim ("según WINK News"), no rumours. Name the place (Lehigh Acres, Lee County) early.
