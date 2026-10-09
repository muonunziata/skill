---
name: check-setup
description: Diagnose and fix the configuration of the GoLehighAcres.org agents - .env, Gemini models, WordPress connection and plugin, image provider, Mediastack, Chromium/ffmpeg for the social designer. Use when the agents fail to start, a key or model is rejected, WordPress returns 401/403/404, or the social kit cannot render.
allowed-tools: Bash(python main.py check:*), Bash(python main.py setup:*), Bash(python -m playwright:*), Read, Glob, Grep
---

# Check and repair the setup

1. `cd agents && python main.py check`. It validates settings, lists unavailable models, authenticates against WordPress and detects the plugin.
2. Interpret:
   - **Model not available** -> `python main.py setup` re-picks a working Gemini model, or edit `RASTREADOR_MODEL` / `REDACCTOR_MODEL` / `AUDITOR_MODEL` (`gemini-2.5-flash` is a safe default).
   - **WordPress 401/403** -> regenerate credentials in WP: *News Hub -> Panel -> Generate agent credentials*, or `python main.py setup`. App passwords need HTTPS (or a local site).
   - **404 on `/lnh/v1/ping`** -> plugin inactive: drafts still work, but no agent panel.
   - **Social: no browser** -> `python -m playwright install chromium` or `CHROMIUM_PATH`. **No ffmpeg** -> `pip install imageio-ffmpeg` or `FFMPEG_PATH`.
   - **Mediastack `https_access_restricted`** -> expected on the free plan; the client falls back to http (`MEDIASTACK_HTTPS=auto`). Free plan = 100 calls/month; the project throttles itself.
3. Prefer `python main.py setup` (guided, validates keys, writes `.env` with 0600) over hand edits. Never echo secrets; show only whether each is set.
4. Re-run `check` until it prints "Todo listo." and report what changed.
