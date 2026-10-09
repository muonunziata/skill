#!/usr/bin/env python3
"""Installer with a live progress meter (standard library only, so it runs before anything is installed).

    python instalar.py            create .venv, install the dependencies and the browser, with a progress bar

While a step is running it shows: overall percentage, the current step, elapsed time, a spinner that keeps moving and how long ago
the last output arrived - so you can tell "slow download" from "stuck". A full log is written to instalacion.log.
"""
from __future__ import annotations

import os
import queue
import re
import subprocess
import sys
import threading
import time
import venv
from pathlib import Path

ROOT = Path(__file__).resolve().parent
LOG = ROOT / "instalacion.log"
IS_WIN = os.name == "nt"
VENV_PY = ROOT / ".venv" / ("Scripts/python.exe" if IS_WIN else "bin/python")

# (key, label, share of the whole bar)
STEPS = [("venv", "Creando el entorno de Python", 0.04), ("pip", "Actualizando pip", 0.04),
         ("deps", "Instalando librerias", 0.47), ("browser", "Descargando el navegador (Chromium)", 0.40),
         ("check", "Comprobando la instalacion", 0.05)]
PIP_EXPECTED = 38        # "Collecting ..." lines of a normal install of requirements.txt (measured)
BROWSER_DOWNLOADS = 3    # playwright downloads Chromium, ffmpeg and the headless shell
SLOW_AFTER, VERY_SLOW_AFTER = 45, 180   # seconds without any output


# ───────────────────────── progress estimators (pure functions, unit-tested) ─────────────────────────
class PipProgress:
    """0..1 from pip's output: counts the packages it resolves, then jumps when it starts/finishes installing."""

    def __init__(self, expected: int = PIP_EXPECTED):
        self.expected, self.collected, self.stage = max(1, expected), 0, 0.0

    def feed(self, text: str) -> float:
        for line in re.split(r"[\r\n]+", text):
            if line.startswith("Collecting "):
                self.collected += 1
            elif line.startswith("Installing collected packages"):
                self.stage = 0.93
            elif line.startswith("Successfully installed") or "Requirement already satisfied" in line and self.collected == 0:
                self.stage = 1.0 if line.startswith("Successfully") else max(self.stage, 0.5)
        return self.value

    @property
    def value(self) -> float:
        return max(self.stage, min(0.9, self.collected / self.expected))


class BrowserProgress:
    """0..1 from playwright's download bars (``|■■■   |  45% of 110.2 MiB``): each of the ~3 downloads counts for a third."""

    PCT = re.compile(r"(\d{1,3})%")

    def __init__(self, downloads: int = BROWSER_DOWNLOADS):
        self.downloads, self.done, self.pct, self.done_flag = max(1, downloads), 0, 0.0, False

    def feed(self, text: str) -> float:
        for chunk in re.split(r"[\r\n]+", text):
            low = chunk.lower()
            if "downloading" in low and "%" not in low and self.pct > 0.9:   # a new download starts
                self.done, self.pct = min(self.done + 1, self.downloads - 1), 0.0
            m = self.PCT.search(chunk)
            if m:
                p = min(100, int(m.group(1))) / 100
                if p < self.pct - 0.5:                                     # the bar restarted: next file
                    self.done = min(self.done + 1, self.downloads - 1)
                self.pct = p
            if "downloaded to" in low:
                self.done_flag = True
        return self.value

    @property
    def value(self) -> float:
        return 1.0 if self.done_flag and self.done >= self.downloads - 1 and self.pct >= 1 else min(0.97, (self.done + self.pct) / self.downloads)


def fmt_time(seconds: float) -> str:
    s = int(seconds)
    return f"{s // 60:02d}:{s % 60:02d}"


def render_bar(fraction: float, width: int = 30, unicode_ok: bool = True) -> str:
    fraction = max(0.0, min(1.0, fraction))
    filled = int(round(fraction * width))
    full, empty = ("█", "░") if unicode_ok else ("#", "-")
    return "[" + full * filled + empty * (width - filled) + f"] {int(fraction * 100):3d}%"


def stall_note(idle: float) -> str:
    if idle >= VERY_SLOW_AFTER:
        return f" · SIN MOVIMIENTO {int(idle)} s: probablemente la red; puedes cancelar con Ctrl+C y reintentar"
    if idle >= SLOW_AFTER:
        return f" · lento: sin novedades hace {int(idle)} s (sigue vivo, puede ser la descarga)"
    return ""


# ───────────────────────── runner ─────────────────────────
class Meter:
    def __init__(self, out=None):
        self.out = out or sys.stdout
        self.tty = bool(getattr(self.out, "isatty", lambda: False)())
        try:
            "█░".encode(getattr(self.out, "encoding", None) or "ascii")
            self.unicode_ok = True
        except (UnicodeEncodeError, LookupError):
            self.unicode_ok = False
        self.t0 = time.time()
        self.base = 0.0                 # share of the bar completed by finished steps
        self._last_plain = 0.0
        self._spin = 0

    def show(self, label: str, step_fraction: float, share: float, idle: float, final: bool = False) -> None:
        total = self.base + share * step_fraction
        spin = "|/-\\"[self._spin % 4]
        self._spin += 1
        line = f"{render_bar(total, 28, self.unicode_ok)} {spin} {label} · {fmt_time(time.time() - self.t0)}{stall_note(idle)}"
        if self.tty:
            self.out.write("\r" + line.ljust(118)[:118] + ("\n" if final else ""))
            self.out.flush()
        elif final or time.time() - self._last_plain > 5:     # redirected output: one line every few seconds
            self.out.write(line + "\n")
            self.out.flush()
            self._last_plain = time.time()

    def say(self, text: str) -> None:
        if self.tty:
            self.out.write("\r" + " " * 118 + "\r")
        self.out.write(text + "\n")
        self.out.flush()


def run_step(meter: Meter, label: str, share: float, cmd: list[str], estimator=None, env=None, tail: list[str] | None = None) -> int:
    """Run a command, feeding its output to the estimator and redrawing the meter several times a second."""
    q: queue.Queue = queue.Queue()
    try:
        proc = subprocess.Popen(cmd, cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=env)
    except OSError as exc:
        meter.say(f"No se pudo ejecutar {cmd[0]}: {exc}")
        return 1

    def reader() -> None:
        assert proc.stdout is not None
        fd = proc.stdout.fileno()
        while True:
            try:
                data = os.read(fd, 4096)
            except OSError:
                break
            if not data:
                break
            q.put(data)
        q.put(None)

    threading.Thread(target=reader, daemon=True).start()
    last_output, fraction, finished = time.time(), 0.0, False
    with LOG.open("a", encoding="utf-8", errors="replace") as log:
        log.write(f"\n=== {label}: {' '.join(cmd)}\n")
        while not finished:
            try:
                data = q.get(timeout=0.2)
                if data is None:
                    finished = True
                else:
                    text = data.decode("utf-8", "replace")
                    log.write(text)
                    last_output = time.time()
                    if tail is not None:
                        tail.extend(l for l in re.split(r"[\r\n]+", text) if l.strip())
                        del tail[:-15]
                    if estimator:
                        fraction = estimator.feed(text)
            except queue.Empty:
                pass
            if not finished:
                # "step_fraction" is 0 when nothing can be estimated: the spinner + idle timer still prove it is alive
                meter.show(label, fraction if estimator else 0.0, share, time.time() - last_output)
    code = proc.wait()
    meter.show(label, 1.0 if code == 0 else fraction, share, 0, final=False)
    return code


def main() -> int:
    if sys.version_info < (3, 11):
        print("Se necesita Python 3.11 o superior: https://www.python.org/downloads/")
        return 1
    meter = Meter()
    LOG.write_text("", encoding="utf-8")
    meter.say("Instalando los agentes. Es normal que tarde unos minutos; la barra se mueve mientras trabaja.")
    meter.say("Si el contador de tiempo avanza y el girito (| / - \\) se mueve, esta trabajando. Registro: " + str(LOG.name))

    # 1 · venv
    label, share = STEPS[0][1], STEPS[0][2]
    meter.show(label, 0.1, share, 0)
    if not VENV_PY.exists():
        venv.EnvBuilder(with_pip=True, clear=False).create(ROOT / ".venv")
    meter.base += share
    meter.show(label, 1.0, 0, 0, final=False)

    py = str(VENV_PY)
    tail: list[str] = []
    env = {**os.environ, "PIP_DISABLE_PIP_VERSION_CHECK": "1", "PYTHONUNBUFFERED": "1", "PIP_PROGRESS_BAR": "off"}
    # 2 · pip, 3 · dependencies
    for (key, label, share), cmd, est in (
            (STEPS[1], [py, "-m", "pip", "install", "--upgrade", "pip"], None),
            (STEPS[2], [py, "-m", "pip", "install", "-r", "requirements.txt"], PipProgress())):
        code = run_step(meter, label, share, cmd, est, env, tail)
        if code != 0:
            meter.say(f"\nFalló el paso «{label}». Últimas líneas:\n  " + "\n  ".join(tail[-8:]) + f"\nRegistro completo: {LOG}")
            return code
        meter.base += share
    # 4 · browser (best effort: only the Instagram/TikTok designer needs it)
    key, label, share = STEPS[3]
    code = run_step(meter, label, share, [py, "-m", "playwright", "install", "chromium"], BrowserProgress(), env, tail)
    meter.base += share
    if code != 0:
        meter.say("\nNo se pudo instalar el navegador: solo lo necesita el Diseñador social (Instagram/TikTok). Los demas agentes funcionan sin el.\n"
                  f"Para reintentar: {py} -m playwright install chromium   (registro: {LOG.name})")
    # 5 · check
    key, label, share = STEPS[4]
    code = run_step(meter, label, share, [py, "-c", "import lehigh_agents, google.genai, requests, PIL; print('ok')"], None, env, tail)
    meter.base = 1.0
    meter.show("Listo", 1.0, 0, 0, final=True)
    if code != 0:
        meter.say("La comprobacion final fallo:\n  " + "\n  ".join(tail[-6:]) + f"\nRegistro: {LOG}")
        return code
    meter.say("✔ Instalacion terminada." if meter.unicode_ok else "Instalacion terminada.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        print("\nInstalacion cancelada. Puedes volver a abrirla: continua donde se quedo.")
        raise SystemExit(130)
