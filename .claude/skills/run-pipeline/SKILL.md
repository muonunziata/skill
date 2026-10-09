---
name: run-pipeline
description: Run the GoLehighAcres.org news pipeline (Rastreador -> Redactor -> Auditor -> Social designer). Manual only because it calls paid APIs and can write drafts to WordPress.
disable-model-invocation: true
argument-hint: "[--dry-run] [--no-images] [--no-social]"
allowed-tools: Bash(python main.py:*), Bash(python -m lehigh_agents:*), Read, Glob
---

# Run the pipeline

Arguments: `$ARGUMENTS` (default: `--dry-run`).

1. `cd agents` and make sure `.env` exists (otherwise run the `check-setup` skill first).
2. Run `python main.py check` - stop and report if it fails.
3. Run `python main.py run $ARGUMENTS`. If the user gave no arguments use `--dry-run` (full run, nothing written to WordPress; results land in `agents/state/runs/<run_id>.json`).
4. Only without `--dry-run` does it create **drafts** in WordPress (never publishes) and spend API quota - confirm with the user before running it for real.
5. Summarise: counts (found/approved/flagged/failed), each story's title, score and notes, the post edit links, any warnings (events with level `warn`/`error`), and where the social kit was saved (`agents/state/social/<date>-<slug>/`).
Do not print keys or the contents of `.env`.
