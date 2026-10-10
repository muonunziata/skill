"""Use OpenCode (https://opencode.ai) as the language-model engine: `opencode run -m provider/model --format json "prompt"`.

OpenCode is a coding assistant, so each request runs in an empty temporary folder and the prompt tells the model to answer with
text only. Several models can be listed separated by commas ("opencode/big-pickle,opencode/mimo-v2.6-flash-free"): when one
fails (free models hit their limit now and then) the next one answers.
"""
from __future__ import annotations

import json
import logging
import os
import shutil
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any

log = logging.getLogger("lehigh.opencode")

MAX_ARG_CHARS = 24_000       # Windows limits a whole command line to ~32k characters
COOLDOWN_SECONDS = 600


class OpenCodeError(Exception):
    def __init__(self, message: str, limit: bool = False):
        super().__init__(message)
        self.limit = limit      # True = the model hit a usage limit (try another / wait)


def is_opencode(model: str) -> bool:
    return (model or "").strip().lower().startswith("opencode/")


def split_models(spec: str) -> list[str]:
    return [m.strip() for m in (spec or "").split(",") if m.strip()]


def find_binary(configured: str = "") -> str | None:
    """OPENCODE_PATH, then PATH, then the usual npm / install locations."""
    candidates = [configured, os.environ.get("OPENCODE_PATH", ""), shutil.which("opencode") or "", shutil.which("opencode.cmd") or ""]
    home = Path.home()
    candidates += [str(home / ".opencode/bin/opencode"), str(home / ".opencode/bin/opencode.exe"),
                   str(home / "AppData/Roaming/npm/opencode.cmd"), "/usr/local/bin/opencode", "/opt/homebrew/bin/opencode"]
    for c in candidates:
        if c and Path(c).is_file():
            return c
    return None


def parse_events(stdout: str) -> str:
    """Text answer from `--format json` output (one JSON event per line). Raises OpenCodeError for error events."""
    texts: list[str] = []
    error = ""
    plain: list[str] = []
    for line in stdout.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            ev: Any = json.loads(line)
        except ValueError:
            plain.append(line)       # not JSON: keep it in case the CLI printed the answer as plain text
            continue
        if not isinstance(ev, dict):
            continue
        kind = ev.get("type")
        if kind == "error":
            err = ev.get("error") or {}
            data = err.get("data") or {}
            error = str(data.get("message") or err.get("message") or err.get("name") or "error de OpenCode")
        elif kind == "text":
            part = ev.get("part") or {}
            if isinstance(part, dict) and isinstance(part.get("text"), str):
                texts.append(part["text"])
            elif isinstance(ev.get("text"), str):
                texts.append(ev["text"])
    if error and not texts:
        raise OpenCodeError(error, limit=any(x in error for x in ("429", "FreeUsageLimit", "rate limit", "Rate limit", "quota")))
    answer = "".join(texts).strip() or "\n".join(plain).strip()
    if not answer:
        raise OpenCodeError("OpenCode no devolvió ninguna respuesta")
    return answer


class OpenCodeRunner:
    def __init__(self, binary: str = "", timeout: float = 300, run=subprocess.run, clock=time.time):
        self.binary_hint, self.timeout, self._run, self._clock = binary, timeout, run, clock
        self._cool: dict[str, float] = {}
        self._workdir: str | None = None

    def binary(self) -> str:
        b = find_binary(self.binary_hint)
        if not b:
            raise OpenCodeError("No encuentro OpenCode. Instálalo (https://opencode.ai: `npm i -g opencode-ai`) o define OPENCODE_PATH en el .env.")
        return b

    def _cwd(self) -> str:
        if not self._workdir or not Path(self._workdir).is_dir():
            self._workdir = tempfile.mkdtemp(prefix="lehigh-opencode-")
        return self._workdir

    def run_one(self, model: str, message: str) -> str:
        if len(message) > MAX_ARG_CHARS:
            raise OpenCodeError(f"el mensaje es demasiado largo para OpenCode ({len(message)} caracteres)")
        cmd = [self.binary(), "run", "-m", model, "--format", "json", message]
        try:
            p = self._run(cmd, cwd=self._cwd(), capture_output=True, timeout=self.timeout, stdin=subprocess.DEVNULL)
        except subprocess.TimeoutExpired as exc:
            raise OpenCodeError(f"OpenCode tardó más de {int(self.timeout)} s con {model}") from exc
        except OSError as exc:
            raise OpenCodeError(f"no se pudo ejecutar OpenCode: {exc}") from exc
        out = p.stdout.decode("utf-8", "replace") if isinstance(p.stdout, bytes) else (p.stdout or "")
        err = p.stderr.decode("utf-8", "replace") if isinstance(p.stderr, bytes) else (p.stderr or "")
        try:
            return parse_events(out)
        except OpenCodeError as exc:
            if p.returncode != 0 and not out.strip():
                raise OpenCodeError((err.strip().splitlines() or [str(exc)])[-1][:300]) from exc
            raise

    def complete(self, spec: str, system: str, prompt: str) -> tuple[str, str]:
        """Try each listed model in turn; returns (answer, model used)."""
        message = (f"{system}\n\n{prompt}\n\n"
                   "IMPORTANTE: responde solo con texto. No uses herramientas, no leas ni escribas archivos, no ejecutes comandos.")
        models = split_models(spec)
        if not models:
            raise OpenCodeError("no hay ningún modelo de OpenCode configurado (p. ej. opencode/big-pickle)")
        now = self._clock()
        ordered = [m for m in models if self._cool.get(m, 0) <= now] or models      # all cooling: try anyway
        last: OpenCodeError | None = None
        for m in ordered:
            try:
                return self.run_one(m, message), m
            except OpenCodeError as exc:
                last = exc
                log.warning("OpenCode %s falló: %s", m, exc)
                if exc.limit:
                    self._cool[m] = now + COOLDOWN_SECONDS
        assert last is not None
        raise last
