---
name: lehigh-news-hub
description: Map of the GoLehighAcres.org local-news system (Lehigh Acres, Florida) - four Python agents plus a WordPress plugin. Use when working on anything in this repo - the Rastreador/Redactor/Auditor/Social agents, the WordPress plugin "Lehigh News Hub", the [lehigh_news] shortcode, the .env configuration, tests, or releases.
---

# Lehigh News Hub - project map

A news desk for **Lehigh Acres, Florida** that publishes under the **GoLehighAcres.org** brand (see the `brand-guide` skill: every visual must follow it).

## The four agents (Python, `agents/lehigh_agents/`)
| # | Agent | File | Job |
|---|---|---|---|
| 1 | Rastreador (researcher) | `agents/rastreador.py` | Google-Search grounding (+ optional Mediastack, `news_api.py`); **opens every page it cites** and only keeps URLs it actually read. Output `Hallazgo`: `titulo_fuente, url, resumen_hechos, fecha, palabras_clave`. |
| 2 | Redactor (writer) | `agents/redactor.py` | Gutenberg HTML article, SEO fields, media placeholders, English photorealism prompt -> AI featured image (`imagegen.py`) -> `featured_image_url`. |
| 3 | Auditor | `agents/auditor.py` + `checks.py` | Fact check vs. the finding and source text, links, unsafe HTML, invented figures, copy overlap. Approved drafts are POSTed to WordPress (`wordpress.py`). |
| 4 | Social designer | `social/designer.py` | Instagram + TikTok carousels (PNG) and a 9:16 video (MP4) from an approved article, plus captions/hashtags. Never fatal to the article. |

`pipeline.py` runs 1 -> 2 -> 3 per story, then 4 for approved ones. `cli.py` is the entry point: `python main.py check | setup | run | watch | social`.

## WordPress plugin (`wordpress/lehigh-news-hub/`, PHP 7.4+)
Review queue, per-article review screen (audit, source, image prompt, agent trace, **Social kit** card), agent activity feed (CPT `lnh_run`), shortcode `[lehigh_news]` + builder, pages created on activation, REST namespace `lnh/v1`, post meta `lnh_*` (hidden from visitors). Spanish translation is generated: edit `wordpress/tools/translations_es.py` then run `python3 wordpress/tools/build_i18n.py`.

## Non-negotiable design rules
1. **Anti-hallucination**: agents only use facts from pages they read; figures must appear in the source (`checks.unsupported_numbers`). Never relax this to make a test or an article pass.
2. **Human decides**: agents create drafts; nothing is published without the editor unless the editor enabled auto-publish. The social kit is never posted to Instagram/TikTok automatically.
3. **AI transparency**: AI images are labelled (caption in WP, badge on slides). Sensitive stories (crime, minors, accidents) get neutral images and no graphic social copy.
4. **Secrets**: `.env` only (0600). Never print or commit keys, WP passwords or the Mediastack key.
5. **Brand**: logos in `brand/` (copies in `agents/lehigh_agents/social/assets/` must stay byte-identical - a test checks it).

## Commands
```bash
cd agents && python -m pytest -q                       # Python tests (browser/ffmpeg tests skip if missing)
php wordpress/tests/run.php /path/to/test-wordpress    # in-WordPress tests (throw-away site only)
python3 wordpress/tools/build_i18n.py                  # rebuild .pot/.po/.mo
scripts/build-release.sh                               # zips into dist/
scripts/sync-claude.sh                                 # copy plugins/lehigh-news-hub skills+agents into .claude/
```
Related skills: `run-pipeline`, `check-setup`, `social-kit`, `social-carousel`, `social-video`, `news-audit`, `brand-guide`. Subagents: `news-researcher`, `news-writer`, `news-auditor`, `social-designer`.
