#!/usr/bin/env bash
# One-step install for macOS / Linux:  ./install.sh
# Creates a private Python environment, installs the dependencies and starts the guided setup.
#   ./install.sh --no-setup     only install
#   ./install.sh --site https://tusitio.com    any `main.py setup` option is passed through
set -euo pipefail
cd "$(dirname "$0")"

PY="${PYTHON:-python3}"
if ! command -v "$PY" >/dev/null 2>&1; then
  echo "Python 3.11 or newer is required (https://www.python.org/downloads/)." >&2
  exit 1
fi
"$PY" - <<'PYCHECK' || { echo "Python 3.11 or newer is required." >&2; exit 1; }
import sys
sys.exit(0 if sys.version_info >= (3, 11) else 1)
PYCHECK

[ -d .venv ] || "$PY" -m venv .venv
# shellcheck disable=SC1091
. .venv/bin/activate
echo "Installing dependencies…"
python -m pip install --quiet --upgrade pip
python -m pip install --quiet -r requirements.txt
echo "✓ Dependencies installed in ./.venv"
# Agent 4 draws the slides with Chromium. Best effort: without it the other three agents keep working.
echo "Installing the browser used to draw Instagram/TikTok slides (Chromium, ~150 MB)…"
python -m playwright install chromium >/dev/null 2>&1 && echo "✓ Chromium ready" \
  || echo "! Chromium was not installed; run '.venv/bin/python -m playwright install chromium' later or set CHROMIUM_PATH."

if [ "${1:-}" = "--no-setup" ]; then
  echo "Next: .venv/bin/python main.py setup"
  exit 0
fi
python main.py setup "$@"
