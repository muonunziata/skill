"""Headless-browser rendering of the HTML slides, plus photo preparation. One browser is reused for a whole kit."""
from __future__ import annotations

import base64
import io
import os
import shutil
import sys
from pathlib import Path

from .templates import FIT_JS


class SocialRenderError(Exception):
    pass


def _playwright_cache_browsers() -> list[str]:
    """Chromium builds already on disk (a Playwright revision different from the installed package still works fine)."""
    roots = [os.environ.get("PLAYWRIGHT_BROWSERS_PATH", ""), str(Path.home() / ".cache/ms-playwright"),
             str(Path.home() / "Library/Caches/ms-playwright"), str(Path.home() / "AppData/Local/ms-playwright")]
    found: list[str] = []
    for root in filter(None, roots):
        base = Path(root)
        if base.is_dir():
            for pattern in ("chromium-*/chrome-linux*/chrome", "chromium-*/chrome-mac*/Chromium.app/Contents/MacOS/Chromium",
                            "chromium-*/chrome-win*/chrome.exe"):
                found += [str(p) for p in sorted(base.glob(pattern), reverse=True)]
    return found


def _candidate_paths() -> list[str]:
    home = Path.home()
    return _playwright_cache_browsers() + [p for p in (
        os.environ.get("CHROMIUM_PATH", ""),
        shutil.which("chromium") or "", shutil.which("chromium-browser") or "", shutil.which("google-chrome") or "",
        shutil.which("google-chrome-stable") or "", shutil.which("chrome") or "", shutil.which("msedge") or "",
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
        "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        str(home / "AppData/Local/Google/Chrome/Application/chrome.exe"),
    ) if p]


def prepare_photo(data: bytes, max_w: int = 1620, max_h: int = 2880) -> tuple[str, int, int]:
    """Orientation-fixed, downscaled JPEG as a data URI (no network needed at render time)."""
    from PIL import Image, ImageOps

    try:
        img = ImageOps.exif_transpose(Image.open(io.BytesIO(data))).convert("RGB")
    except Exception as exc:  # noqa: BLE001 - any decoder failure means "not a usable image"
        raise SocialRenderError(f"la imagen no se puede leer: {exc}") from exc
    img.thumbnail((max_w, max_h))
    buf = io.BytesIO()
    img.save(buf, "JPEG", quality=88, optimize=True)
    return "data:image/jpeg;base64," + base64.b64encode(buf.getvalue()).decode(), img.width, img.height


def logo_data_uri(spec: str, fetcher=None) -> str:
    """BRAND_LOGO may be a local path or an http(s) URL; failures just mean "no logo"."""
    if not spec:
        return ""
    try:
        if spec.startswith(("http://", "https://")):
            if fetcher is None:
                return ""
            data = fetcher.get(spec, max_bytes=2_000_000, accept="image/*").body
        else:
            data = Path(spec).expanduser().read_bytes()
        uri, _, _ = prepare_photo(data, 400, 400)
        # keep transparency for PNG logos
        if data[:8] == b"\x89PNG\r\n\x1a\n":
            return "data:image/png;base64," + base64.b64encode(data).decode()
        return uri
    except Exception:  # noqa: BLE001
        return ""


class SlideRenderer:
    """with SlideRenderer() as r: png = r.png(html, 1080, 1350)"""

    def __init__(self, chromium_path: str = ""):
        self.chromium_path = chromium_path
        self._pw = self._browser = self._page = None

    def __enter__(self) -> "SlideRenderer":
        try:
            from playwright.sync_api import sync_playwright
        except ImportError as exc:
            raise SocialRenderError("falta el paquete 'playwright' (pip install -r requirements.txt)") from exc
        self._pw = sync_playwright().start()
        errors = []
        launch_args = ["--no-sandbox", "--disable-gpu", "--force-color-profile=srgb", "--font-render-hinting=none"]
        attempts: list[dict] = []
        if self.chromium_path:
            attempts.append(dict(executable_path=self.chromium_path))
        attempts.append({})                                  # Playwright's own Chromium (playwright install chromium)
        attempts += [dict(channel="chrome"), dict(channel="msedge")]
        attempts += [dict(executable_path=p) for p in _candidate_paths() if p != self.chromium_path]
        for kw in attempts:
            try:
                self._browser = self._pw.chromium.launch(args=launch_args, **kw)
                break
            except Exception as exc:  # noqa: BLE001
                errors.append(str(exc).splitlines()[0][:120])
        if self._browser is None:
            self._pw.stop()
            raise SocialRenderError(
                "no se encontró un navegador Chromium/Chrome para dibujar las láminas. Ejecuta `python -m playwright install chromium` "
                "o define CHROMIUM_PATH con la ruta de Chrome/Chromium. Detalle: " + "; ".join(errors[-2:]))
        try:
            self._page = self._browser.new_page(viewport={"width": 1080, "height": 1350}, device_scale_factor=1)
        except Exception:
            self.__exit__(None, None, None)
            raise
        return self

    def __exit__(self, *exc) -> None:
        for obj, method in ((self._browser, "close"), (self._pw, "stop")):
            try:
                if obj:
                    getattr(obj, method)()
            except Exception:  # noqa: BLE001
                pass

    def png(self, html: str, width: int, height: int) -> bytes:
        page = self._page
        page.set_viewport_size({"width": width, "height": height})
        page.set_content(html, wait_until="load")
        page.evaluate(FIT_JS)
        return page.screenshot(type="png", clip={"x": 0, "y": 0, "width": width, "height": height})


def playwright_hint() -> str:
    return f"{sys.executable} -m playwright install chromium"
