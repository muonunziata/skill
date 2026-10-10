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


_PLATFORM_PACKAGES = ("opencode-ai", "opencode-windows-x64", "opencode-windows-x64-baseline", "opencode-linux-x64", "opencode-linux-arm64",
                      "opencode-darwin-arm64", "opencode-darwin-x64")


def _npm_roots() -> list[Path]:
    """Folders where `npm install -g` puts packages (asked to npm itself, plus the usual defaults)."""
    roots: list[Path] = []
    npm = shutil.which("npm") or shutil.which("npm.cmd")
    if npm:
        try:
            out = subprocess.run([npm, "root", "-g"], capture_output=True, timeout=20).stdout.decode("utf-8", "replace").strip()
            if out:
                roots.append(Path(out.splitlines()[-1]))
        except (OSError, subprocess.SubprocessError):
            pass
    home = Path.home()
    appdata = os.environ.get("APPDATA", "")
    roots += [Path(appdata) / "npm" / "node_modules"] if appdata else []
    roots += [home / "AppData/Roaming/npm/node_modules", Path("/usr/local/lib/node_modules"), Path("/usr/lib/node_modules"),
              Path("/opt/homebrew/lib/node_modules"), home / ".npm-global/lib/node_modules"]
    return roots


def search_places(configured: str = "") -> list[str]:
    """Every path tried, so a failure can say where it looked."""
    home = Path.home()
    local = os.environ.get("LOCALAPPDATA", "")
    places = [configured, os.environ.get("OPENCODE_PATH", "")]
    for name in ("opencode", "opencode.exe", "opencode.cmd"):
        places.append(shutil.which(name) or "")
    places += [str(home / ".opencode/bin/opencode"), str(home / ".opencode/bin/opencode.exe"), str(home / ".bun/bin/opencode"),
               str(home / "AppData/Roaming/npm/opencode.cmd"), str(home / "scoop/shims/opencode.exe"),
               str(Path(local) / "Microsoft/WinGet/Links/opencode.exe") if local else "", r"C:\ProgramData\chocolatey\bin\opencode.exe",
               "/usr/local/bin/opencode", "/opt/homebrew/bin/opencode", str(home / ".local/bin/opencode")]
    # The OpenCode *desktop app* (…\\Programs\\@opencode-aidesktop\\OpenCode.exe) is a window, not the command-line tool: look for the
    # command-line binary it may ship with, but never return the GUI executable itself.
    for programs in ([Path(local) / "Programs"] if local else []) + [home / "AppData/Local/Programs"]:
        if programs.is_dir():
            for pattern in ("*opencode*/resources/**/opencode-cli*", "*opencode*/resources/**/opencode.exe", "*opencode*/**/opencode-cli*.exe"):
                places += [str(p) for p in sorted(programs.glob(pattern))[:5]]
    for root in _npm_roots():
        for pkg in _PLATFORM_PACKAGES:
            places += [str(root / pkg / "bin" / "opencode"), str(root / pkg / "bin" / "opencode.exe")]
    return [p for p in places if p]


def find_binary(configured: str = "") -> str | None:
    """OPENCODE_PATH, then PATH, then the usual install and npm locations (Windows, macOS, Linux)."""
    for c in search_places(configured):
        if Path(c).is_file():
            return c
    return None


def launcher(binary: str) -> list[str]:
    """Command that starts `binary`. A Windows .cmd shim is replaced by `node <script>`: cmd.exe would mangle a long multi-line prompt."""
    if binary.lower().endswith((".cmd", ".bat")):
        script = Path(binary).parent / "node_modules" / "opencode-ai" / "bin" / "opencode"
        node = shutil.which("node") or shutil.which("node.exe")
        if script.is_file() and node:
            return [node, str(script)]
    return [binary]


def try_install(run=subprocess.run) -> bool:
    """Install OpenCode with npm when it is missing (one attempt per process). Returns True if npm reported success."""
    npm = shutil.which("npm") or shutil.which("npm.cmd")
    if not npm:
        return False
    log.warning("OpenCode no está instalado: ejecutando `npm install -g opencode-ai` (solo esta vez)…")
    try:
        return run([npm, "install", "-g", "opencode-ai"], capture_output=True, timeout=600).returncode == 0
    except (OSError, subprocess.SubprocessError):
        return False


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
        self._install_tried = False

    def binary(self) -> str:
        b = find_binary(self.binary_hint)
        if not b and not self._install_tried and os.environ.get("OPENCODE_AUTO_INSTALL", "true").lower() not in ("0", "false", "no"):
            self._install_tried = True
            if try_install():
                b = find_binary(self.binary_hint)
        if not b:
            where = ", ".join(dict.fromkeys(str(Path(p).parent) for p in search_places(self.binary_hint)[:14]))
            raise OpenCodeError("No encuentro OpenCode en este computador (busqué en: " + where[:300] + "). Instálalo con `npm install -g opencode-ai` "
                                "(o desde https://opencode.ai) y vuelve a abrir INICIAR; si ya lo tienes en otra carpeta, escribe su ruta completa "
                                "en OPENCODE_PATH dentro del archivo .env.")
        return b

    def _cwd(self) -> str:
        if not self._workdir or not Path(self._workdir).is_dir():
            self._workdir = tempfile.mkdtemp(prefix="lehigh-opencode-")
        return self._workdir

    def run_one(self, model: str, message: str) -> str:
        if len(message) > MAX_ARG_CHARS:
            raise OpenCodeError(f"el mensaje es demasiado largo para OpenCode ({len(message)} caracteres)")
        cmd = [*launcher(self.binary()), "run", "-m", model, "--format", "json", message]
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
