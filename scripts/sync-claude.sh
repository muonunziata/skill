#!/usr/bin/env bash
# Keep the project-level Claude Code files (.claude/skills, .claude/agents) identical to the plugin that is the source of truth.
# Why both? Plugins are installed with `/plugin install`; Claude Code on the web / cloud sessions load the project's
# .claude/ folder directly. Run this after editing plugins/lehigh-news-hub/{skills,agents}. `--check` only verifies.
set -euo pipefail
cd "$(dirname "$0")/.."
src=plugins/lehigh-news-hub
if [ "${1:-}" = "--check" ]; then
  diff -r "$src/skills" .claude/skills && diff -r "$src/agents" .claude/agents && echo "✓ .claude/ is in sync with the plugin"
  exit $?
fi
rm -rf .claude/skills .claude/agents
mkdir -p .claude
cp -r "$src/skills" .claude/skills
cp -r "$src/agents" .claude/agents
echo "✓ .claude/skills and .claude/agents updated from $src"
