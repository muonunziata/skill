# GoLehighAcres.org news system - guide for Claude

Local-news automation for **Lehigh Acres, Florida**: four Python agents (`agents/`) + a WordPress plugin (`wordpress/lehigh-news-hub/`). Start with the `lehigh-news-hub` skill for the architecture and the `brand-guide` skill before touching anything visual.

## Rules
- **Brand**: all designs (slides, video, plugin UI, templates) use the official GoLehighAcres.org logos in `brand/` (copies in `agents/lehigh_agents/social/assets/` must stay byte-identical), brand green `#1b6a55`, coral `#ff5757`, Poppins. Never redraw or recolour the logos.
- **No invented facts**: agents only use what they read; figures must appear in the source. Don't weaken `checks.py` or the social `semantic_problems` guard to make something pass.
- **Human in the loop**: drafts only; nothing is posted to Instagram/TikTok automatically.
- **Secrets** live in `agents/.env` (git-ignored). Never print or commit them.
- Python code is typed, small modules, errors are specific (`LLMError`, `WPError`, `SocialError`...); a failing optional agent (social) must never fail the article.
- User-facing text of the agents is Spanish; the plugin UI is English with a generated Spanish translation (`wordpress/tools/translations_es.py` -> `build_i18n.py`).

## Commands
```bash
cd agents && pip install -r requirements-dev.txt && python -m playwright install chromium
python -m pytest -q                                   # ~90 tests; browser/ffmpeg tests skip if unavailable
python main.py check | setup | run [--dry-run] | watch | social --post ID
php wordpress/tests/run.php /path/to/throwaway-wordpress   # in-WP tests (plugin must be active)
python3 wordpress/tools/build_i18n.py                  # after adding/changing plugin strings
scripts/build-release.sh                               # dist/*.zip (plugin + complete project)
scripts/sync-claude.sh                                 # after editing plugins/lehigh-news-hub/{skills,agents}
claude plugin validate .                               # marketplace + plugin manifest
```

## Claude Code pieces in this repo
- `plugins/lehigh-news-hub/` - the plugin (source of truth): skills `lehigh-news-hub`, `brand-guide`, `social-carousel`, `social-video`, `news-audit`, `check-setup`, `run-pipeline`, `social-kit`; subagents `news-researcher`, `news-writer`, `news-auditor`, `social-designer`.
- `.claude-plugin/marketplace.json` - marketplace `golehighacres-tools` (`/plugin marketplace add muonunziata/skill`, then `/plugin install lehigh-news-hub --marketplace muonunziata/skill`).
- `.claude/skills`, `.claude/agents` - synced copies so the same tools load in project/cloud sessions (CI fails if they drift).
- `.github/workflows/ci.yml` (tests, PHP lint, i18n and sync checks, plugin validation) and `agents.yml` (optional scheduled/manual pipeline run, enabled with the repo variable `AGENTS_ENABLED=true`).
