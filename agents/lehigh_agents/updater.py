"""Self-update of the agents, triggered by the "Update the agents" button in WordPress.

The worker downloads the agents' code archive from the plugin (`GET /lnh/v1/agents/package`, authenticated like every other call),
checks it, copies it over the installation (never touching .env, .venv, state or logs), reinstalls the libraries if
requirements.txt changed, and restarts itself. Only an administrator pressing the button starts this.
"""
from __future__ import annotations

import io
import logging
import os
import re
import shutil
import subprocess
import sys
import zipfile
from dataclasses import dataclass
from pathlib import Path, PurePosixPath

log = logging.getLogger("lehigh.update")

ROOT = Path(__file__).resolve().parent.parent
PROTECTED = {".env", ".venv", "state", "instalacion.log", "__pycache__", ".git"}
MAX_FILES, MAX_BYTES = 2000, 60_000_000


class UpdateError(Exception):
    pass


@dataclass
class UpdateResult:
    files: int
    version: str
    requirements_changed: bool


def _safe_members(zf: zipfile.ZipFile) -> list[tuple[zipfile.ZipInfo, PurePosixPath]]:
    """Members that are safe to extract, with the archive's single top-level folder stripped. Refuses path tricks."""
    members = [m for m in zf.infolist() if not m.is_dir()]
    if not members or len(members) > MAX_FILES or sum(m.file_size for m in members) > MAX_BYTES:
        raise UpdateError("el archivo de actualización está vacío o es demasiado grande")
    parsed = []
    for m in members:
        p = PurePosixPath(m.filename.replace("\\", "/"))
        if p.is_absolute() or ".." in p.parts or (p.parts and re.match(r"^[A-Za-z]:", p.parts[0])):
            raise UpdateError(f"ruta no permitida en el archivo: {m.filename}")
        if len(p.parts) < 2:
            raise UpdateError("el archivo de actualización no tiene la estructura esperada")
        parsed.append((m, p))
    if len({p.parts[0] for _, p in parsed}) != 1:
        raise UpdateError("el archivo de actualización no tiene una única carpeta raíz")
    return [(m, PurePosixPath(*p.parts[1:])) for m, p in parsed]


def apply_package(data: bytes, root: Path = ROOT) -> UpdateResult:
    """Validate and install the archive. Nothing is written unless the whole archive checks out."""
    try:
        zf = zipfile.ZipFile(io.BytesIO(data))
    except zipfile.BadZipFile as exc:
        raise UpdateError("el archivo descargado no es un zip válido") from exc
    members = _safe_members(zf)
    names = {str(rel) for _, rel in members}
    if "main.py" not in names or "lehigh_agents/__init__.py" not in names:
        raise UpdateError("el archivo no parece ser el de los agentes (falta main.py o lehigh_agents/)")
    version = re.search(r'__version__\s*=\s*"([^"]+)"', zf.read(next(m for m, rel in members if str(rel) == "lehigh_agents/__init__.py")).decode("utf-8", "replace"))
    old_req = (root / "requirements.txt").read_bytes() if (root / "requirements.txt").exists() else b""
    written = 0
    for member, rel in members:
        if rel.parts[0] in PROTECTED or any(part in PROTECTED for part in rel.parts):
            continue
        target = root.joinpath(*rel.parts)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(zf.read(member))
        mode = (member.external_attr >> 16) & 0o777
        if mode & 0o111 and os.name != "nt":
            target.chmod(0o755)
        written += 1
    new_req = (root / "requirements.txt").read_bytes() if (root / "requirements.txt").exists() else b""
    return UpdateResult(files=written, version=version.group(1) if version else "", requirements_changed=old_req != new_req)


def install_requirements(root: Path = ROOT, timeout: int = 1200) -> None:
    p = subprocess.run([sys.executable, "-m", "pip", "install", "-r", "requirements.txt"], cwd=root, capture_output=True, timeout=timeout)
    if p.returncode != 0:
        raise UpdateError("no se pudieron instalar las librerías nuevas: " + p.stderr.decode("utf-8", "replace").strip().splitlines()[-1][:200])


def restart() -> None:
    """Start the new code in place of this process (same arguments)."""
    args = [sys.executable, str(ROOT / "main.py"), *sys.argv[1:]] if Path(sys.argv[0]).name in ("main.py", "__main__.py") else \
        [sys.executable, *sys.argv]
    if os.name == "nt":      # execv on Windows does not replace the console process cleanly
        subprocess.Popen(args, cwd=ROOT)
        os._exit(0)
    os.execv(sys.executable, args)


def update_from_hub(wp, root: Path = ROOT) -> UpdateResult:
    data = wp.get_bytes("/lnh/v1/agents/package")
    result = apply_package(data, root)
    if result.requirements_changed:
        install_requirements(root)
    log.info("agents updated to %s (%d files)", result.version, result.files)
    return result
