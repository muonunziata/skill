#!/usr/bin/env bash
# Run the agents continuously (macOS / Linux). Stop with Ctrl+C.
cd "$(dirname "$0")"
exec .venv/bin/python main.py watch "$@"
