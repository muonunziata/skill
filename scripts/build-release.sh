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
( cd wordpress && zip -rq ../dist/lehigh-news-hub.zip lehigh-news-hub -x '*.DS_Store' )

# 2. complete project
zip -rq "dist/lehigh-news-hub-complete-${version}.zip" . \
  -x '.git/*' 'dist/*' '*/__pycache__/*' '*.pyc' '*/.pytest_cache/*' 'agents/.env' 'agents/state/*' \
     'agents/.venv/*' '*.egg-info/*' '*.DS_Store' 'node_modules/*'
( cd dist && sha256sum *.zip > SHA256SUMS.txt )
ls -la dist
