---
name: check-setup
description: Diagnose and fix the configuration of the GoLehighAcres.org agents - .env, Gemini models, WordPress connection and plugin, image provider, Mediastack, Chromium/ffmpeg for the social designer. Use when the agents fail to start, a key or model is rejected, WordPress returns 401/403/404, or the social kit cannot render.
allowed-tools: Bash(python main.py check:*), Bash(python main.py setup:*), Bash(python -m playwright:*), Read, Glob, Grep
---

# Check and repair the setup

1. `cd agents && python main.py check`. It validates settings, lists unavailable models, authenticates against WordPress and detects the plugin.
2. Interpret:
   - **Model not available** -> since 1.5.4 the default is `auto` (newest served Flash, and a retired pinned name is replaced automatically at run time). Set `RASTREADOR_MODEL` / `REDACCTOR_MODEL` / `AUDITOR_MODEL=auto` in `.env`; `python main.py check` shows the model each one resolves to.
   - **"Application Passwords are not available"** (HTTP site or security plugin) -> not a problem since plugin 1.5.1: *Set up my agents* creates a connection key (`WP_AUTH_TOKEN=lnh_...`, sent as `Authorization: Bearer` and `X-Lehigh-Key`). Regenerating it revokes the old one.
   - **Gemini 429 (quota)** -> the agents space calls (`GEMINI_RPM`, default 8/min), wait when Google gives a retry delay, and fall back to another Flash model (quotas are per model). If every model is out: wait (per-minute) or until tomorrow (daily), enable billing at aistudio.google.com, or reduce usage (`MAX_ITEMS_PER_RUN`, `SOCIAL_ENABLED=false`, longer interval).
   - **OpenCode engine** (`*_MODEL=opencode/big-pickle,opencode/mimo-v2.6-flash-free`, `TAVILY_API_KEY`, `SEARCH_MODE=tavily`): `main.py check` reports whether the `opencode` binary is found (set `OPENCODE_PATH`) and tests Tavily (1 credit). Free OpenCode models answer 429 at their limit: the next listed model is used.
   - **WordPress 401/403** -> regenerate credentials in WP: *News Hub -> Panel -> Generate agent credentials*, or `python main.py setup`. App passwords need HTTPS (or a local site).
   - **404 on `/lnh/v1/ping`** -> plugin inactive: drafts still work, but no agent panel.
   - **Dashboard says "Waiting for the agents to connect"** -> start the worker: double-click `INICIAR.bat` / `INICIAR.command` from the package downloaded in *News Hub -> Set up my agents* (or `./start.sh` = `python main.py watch`); it syncs with `/lnh/v1/control/sync`. They start paused: press *Start working* in News Hub. A 404 there means an old plugin (the worker then runs on its own schedule).
   - **Social: no browser** -> `python -m playwright install chromium` or `CHROMIUM_PATH`. **No ffmpeg** -> `pip install imageio-ffmpeg` or `FFMPEG_PATH`.
   - **Mediastack `https_access_restricted`** -> expected on the free plan; the client falls back to http (`MEDIASTACK_HTTPS=auto`). Free plan = 100 calls/month; the project throttles itself.
3. Prefer `python main.py setup` (guided, validates keys, writes `.env` with 0600) over hand edits. Never echo secrets; show only whether each is set.
4. Re-run `check` until it prints "Todo listo." and report what changed.
