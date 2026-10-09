#!/usr/bin/env bash
# macOS: double-click (first time: right-click -> Open). Linux: bash INICIAR.command
# Turns the agents on; the first time it also installs everything they need.
cd "$(dirname "$0")" || exit 1
if [ ! -x .venv/bin/python ]; then
  echo "Primera vez: preparando los agentes. Tarda unos minutos y solo ocurre esta vez..."
  LEHIGH_LAUNCHER=1 bash ./install.sh --no-setup || { echo; read -r -p "Algo falló. Pulsa Enter para cerrar."; exit 1; }
fi
echo
echo "Agentes encendidos. Deja esta ventana abierta; para apagarlos, ciérrala (Ctrl+C)."
echo "Se controlan desde WordPress: News Hub → botón «Iniciar a trabajar»."
echo
.venv/bin/python main.py watch
