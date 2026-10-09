# GoLehighAcres.org – brand kit

Official logos supplied by the owner. Everything the project designs (Instagram/TikTok slides, video frames, the WordPress plugin UI) is built on them. Do not redraw, recolour, stretch or add effects.

| File | Use |
|---|---|
| `golehighacres-logo-horizontal.png` | Header of every slide and video frame, WordPress admin header |
| `golehighacres-logo-vertical.png` | Closing (CTA) slide and video outro |
| `golehighacres-symbol.png` | Avatars, favicons, small placements |
| `golehighacres-wordmark-plain.png` | Text-only contexts |

Colours: brand green `#1b6a55` · coral `#ff5757` · deep green `#0f3a30` · ring palette `#2c6e50 #66a785 #a3cf99 #e4a927 #cb7738 #734698 #5c6fb4 #2858a2 #2f89c8` (rhythm only) · ink `#0b1f19`.
Type: Poppins (SIL Open Font License, bundled in `agents/lehigh_agents/social/assets/fonts/`).

The agents ship a copy of these files in `agents/lehigh_agents/social/assets/` (a test checks both copies are identical) and the plugin ships a resized header logo in `wordpress/lehigh-news-hub/assets/img/`. Override through `.env` only when needed: `BRAND_LOGO`, `BRAND_LOGO_VERTICAL`, `BRAND_COLOR`, `BRAND_ACCENT`, `BRAND_DARK`, `BRAND_FONT`. The full rules for Claude are in `plugins/lehigh-news-hub/skills/brand-guide/SKILL.md`.
