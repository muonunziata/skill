#!/usr/bin/env bash
# Build the downloadable archives into ./dist
#   lehigh-news-hub.zip           installable WordPress plugin (Plugins → Add New → Upload)
#   lehigh-news-hub-complete-<version>.zip  whole project: agents + plugin + docs
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(grep -oP "Version:\s*\K[0-9.]+" wordpress/lehigh-news-hub/lehigh-news-hub.php | head -1)
rm -rf dist && mkdir -p dist

# 1. plugin (single top-level folder, as WordPress expects)
python3 wordpress/tools/build_i18n.py >/dev/null
# The plugin ships the agents (agents-bundle/) so its "Download my agents" button can hand out a ready-to-run package.
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
cp -r wordpress/lehigh-news-hub "$stage/lehigh-news-hub"
mkdir "$stage/lehigh-news-hub/agents-bundle"
( cd agents && tar --exclude='.env' --exclude='state' --exclude='.venv' --exclude='__pycache__' --exclude='tests' \
    --exclude='.pytest_cache' --exclude='*.pyc' --exclude='*.egg-info' -cf - . ) | tar -xf - -C "$stage/lehigh-news-hub/agents-bundle"
( cd "$stage" && zip -rq "$OLDPWD/dist/lehigh-news-hub.zip" lehigh-news-hub -x '*.DS_Store' )

# 2. complete project
zip -rq "dist/lehigh-news-hub-complete-${version}.zip" . \
  -x '.git/*' 'dist/*' '*/__pycache__/*' '*.pyc' '*/.pytest_cache/*' 'agents/.env' 'agents/state/*' \
     'agents/.venv/*' '*.egg-info/*' '*.DS_Store' 'node_modules/*'
( cd dist && sha256sum *.zip > SHA256SUMS.txt )
ls -la dist
