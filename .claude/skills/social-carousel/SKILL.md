---
name: social-carousel
description: Design or improve Instagram and TikTok carousels for a GoLehighAcres.org article - slide structure, copy limits, captions, hashtags, safe zones, and how to render them. Use when asked to write carousel content, change the slide templates, or debug a carousel render.
---

# Social carousels (Instagram 4:5 + TikTok photo carousel 9:16)

Always apply the `brand-guide` skill. Code: `agents/lehigh_agents/social/` - `plan.py` (content contract), `templates.py` (HTML/CSS per slide kind), `render.py` (Chromium via Playwright), `designer.py` (orchestration), prompts in `prompts.py` (`SOCIAL_SYSTEM`, `SOCIAL`).

## Content contract (validated by `plan.validate_plan`)
- 5 to `SOCIAL_MAX_SLIDES` (default 8) slides. **First = `cover`, last = `cta`, second to last = `source`**, and at least 3 content slides (`point`, `stat`, `quote`) between.
- Limits (clipped automatically): headline 70 chars (cover 80), body 180, `stat` 14 (the figure exactly as in the article), `stat_label` 50, hook 90, Instagram caption 900, TikTok caption 300, alt text 125.
- One idea per slide, short sentences, mobile reading. Use `stat` only if the article has a relevant figure; use `quote` only for a verbatim attributed quote.
- **Every figure must exist in the article** - `semantic_problems` rejects invented numbers; the designer retries once, then fails with `SocialError`. Never bypass it.
- Attribute ("según <fuente>"), no clickbait, no graphic details for crime/accidents/minors, no private names.
- Hashtags: 5-12, `#GoLehighAcres` and `#LehighAcres` are always appended.

## Render
```bash
cd agents
python main.py social --post <WP post id> --no-video --formats instagram,tiktok --dry-run --out /tmp/kit
```
`--dry-run` keeps everything local. Without a browser: `python -m playwright install chromium` (or set `CHROMIUM_PATH`). Output: `instagram-01.png`..., `tiktok-01.png`..., `captions.txt`, `plan.json`.

## Editing templates
1. Change `templates.py` (CSS lives in `CSS`; sizes via `FORMATS`; text auto-fits through `FIT_JS`).
2. Re-render a sample (see `tests/test_social.py::test_every_slide_kind_renders_at_exact_size`) and look at the PNGs - check long headlines, all 3 themes (`light`, `dark`, `brand`), and both formats.
3. Keep: logo plate header, counter, ring dots + stripe, safe zones, AI label on cover/source, photo only on the cover.
4. `python -m pytest agents/tests/test_social.py -q`.
