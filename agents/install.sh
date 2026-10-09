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

# Creates .venv, installs the libraries and the browser, showing a live progress meter (log: instalacion.log)
"$PY" instalar.py || { echo "La instalación falló: revisa instalacion.log" >&2; exit 1; }
# shellcheck disable=SC1091
. .venv/bin/activate

if [ "${1:-}" = "--no-setup" ]; then
  [ -n "${LEHIGH_LAUNCHER:-}" ] || echo "Next: .venv/bin/python main.py setup"
  exit 0
fi
if grep -Eq '^GEMINI_API_KEY=.+' .env 2>/dev/null && grep -Eq '^WP_AUTH_TOKEN=.+' .env 2>/dev/null && [ "$#" -eq 0 ]; then
  echo "✓ .env ya está configurado (clave de Gemini y conexión a WordPress). Para encender los agentes: ./INICIAR.command  (o ./start.sh)"
  echo "  Para volver a configurar: .venv/bin/python main.py setup"
  exit 0
fi
python main.py setup "$@"
