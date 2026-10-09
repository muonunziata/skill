---
name: social-kit
description: Generate the Instagram/TikTok carousels and vertical video for an existing WordPress article made by the agents. Manual only because it uses AI APIs and uploads files to the media library.
disable-model-invocation: true
argument-hint: "[--post ID | --latest N] [--no-video] [--formats instagram,tiktok] [--dry-run]"
allowed-tools: Bash(python main.py social:*), Bash(python main.py check:*), Read, Glob
---

# Make a social kit

Arguments: `$ARGUMENTS` (default: `--latest 1`).

1. `cd agents`; `.env` must have `WP_REST_URL`, `WP_AUTH_TOKEN`, `GEMINI_API_KEY` (or the key for `SOCIAL_MODEL`).
2. If Chromium is missing the command says so: run `python -m playwright install chromium`.
3. Run `python main.py social $ARGUMENTS`. Add `--dry-run` to keep everything local (no upload, post untouched).
4. Report the folder (`state/social/<date>-<slug>/`): slide counts, `video.mp4`, `captions.txt`, warnings. Open a couple of PNGs with Read to sanity-check the design against the `brand-guide` skill (logo, colours, legibility, AI label).
5. Remind the user the kit appears in the **Social kit** card of the article's review screen in WordPress and that publishing to Instagram/TikTok is manual (or via `SOCIAL_WEBHOOK_URL`).
