"""`python main.py setup`: guided, mostly automatic configuration of the agents.

What it automates
  * validates your Gemini key and picks the best available Gemini model for the three agents,
  * finds your WordPress site, confirms the Lehigh News Hub plugin is active and obtains an Application Password
    through WordPress' own browser approval screen (you click "Approve"; nothing to copy),
  * optionally configures AI image generation,
  * writes a ready-to-use .env (owner-only permissions) and runs the same checks as `main.py check`.

Everything that needs a person is a question with a sensible default; `--non-interactive` takes values from flags
and the existing .env instead.
"""
from __future__ import annotations

import getpass
import http.server
import os
import re
import secrets
import socket
import threading
import time
import urllib.parse
import webbrowser
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

import requests

from .wordpress import normalize_root

APP_NAME = "Lehigh News Hub agents"


def app_name() -> str:
    """WordPress requires every Application Password name of a user to be unique, so each authorisation gets its own."""
    import platform

    return f"{APP_NAME} ({platform.node() or 'pc'}, {time.strftime('%Y-%m-%d %H:%M')})"
APP_ID = "5f6d0f0e-7c3a-4a53-9a2e-1b1c2d3e4f50"  # fixed, so WordPress recognises repeat authorisations

# Names that look like text models but are not suitable for the agents.
_EXCLUDE = ("lite", "image", "tts", "live", "audio", "embedding", "vision", "robotics", "computer", "exp", "thinking",
            "learnlm", "gemma", "aqa", "customtools", "native")


# ───────────────────────── model selection ─────────────────────────
def _model_rows(models) -> list[tuple[str, tuple[str, ...]]]:
    """Normalise SDK model objects / dicts to (bare name, supported actions)."""
    rows = []
    for m in models:
        name = m.get("name") if isinstance(m, dict) else getattr(m, "name", "")
        actions = m.get("supported_actions") if isinstance(m, dict) else getattr(m, "supported_actions", None)
        rows.append((str(name or "").removeprefix("models/"), tuple(actions or ())))
    return rows


def pick_text_model(models) -> str | None:
    """Best stable Gemini Flash model that can generate content (newest version wins; previews only as a last resort)."""
    best: tuple | None = None
    for name, actions in _model_rows(models):
        if not name.startswith("gemini-") or "flash" not in name or any(x in name for x in _EXCLUDE):
            continue
        if actions and "generateContent" not in actions:
            continue
        m = re.match(r"gemini-(\d+)(?:\.(\d+))?-flash", name)
        major, minor = (int(m.group(1)), int(m.group(2) or 0)) if m else (0, 0)  # gemini-flash-latest -> lowest
        key = ("preview" not in name and "-0" not in name[-4:], major, minor, -len(name))
        if best is None or key > best[0]:
            best = (key, name)
    return best[1] if best else None


def pick_image_model(models) -> str | None:
    best: tuple | None = None
    for name, actions in _model_rows(models):
        if not name.startswith("gemini-") or "image" not in name or "flash" not in name:
            continue
        if any(x in name for x in ("preview", "exp")) and best is not None:
            continue
        m = re.match(r"gemini-(\d+)(?:\.(\d+))?", name)
        key = ("preview" not in name, int(m.group(1)) if m else 0, int(m.group(2) or 0) if m else 0)
        if best is None or key > best[0]:
            best = (key, name)
    return best[1] if best else None


# ───────────────────────── .env handling ─────────────────────────
def _quote(value: str) -> str:
    if value == "" or not re.search(r"[\s#'\"\\]", value):
        return value
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


def read_env(path: Path) -> dict[str, str]:
    from dotenv import dotenv_values

    return {k: v or "" for k, v in dotenv_values(path).items()} if path.is_file() else {}


def render_env(template: str, values: dict[str, str]) -> str:
    """Fill `values` into the template, keeping its comments; unknown keys are appended."""
    seen: set[str] = set()
    out = []
    for line in template.splitlines():
        m = re.match(r"^\s*([A-Z][A-Z0-9_]*)\s*=", line)
        if m and m.group(1) in values:
            out.append(f"{m.group(1)}={_quote(values[m.group(1)])}")
            seen.add(m.group(1))
        else:
            out.append(line)
    extra = [k for k in values if k not in seen]
    if extra:
        out += ["", "# added by setup"] + [f"{k}={_quote(values[k])}" for k in extra]
    return "\n".join(out) + "\n"


def write_env(path: Path, values: dict[str, str], template_path: Path | None = None) -> None:
    # Always UTF-8 (python-dotenv reads UTF-8; the platform default on Windows is not).
    template = template_path.read_text(encoding="utf-8") if template_path and template_path.is_file() else ""
    existing = path.read_text(encoding="utf-8") if path.is_file() else ""
    # Re-running keeps the user's file (and their comments) as the base.
    base = existing or template
    data = render_env(base, values).encode("utf-8")
    # Create the file owner-only from the start (it contains secrets); no window where it is world-readable.
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "wb") as fh:
        fh.write(data)
    try:
        path.chmod(0o600)  # also tightens a pre-existing file
    except OSError:
        pass


# ───────────────────────── Gemini ─────────────────────────
class SetupError(Exception):
    pass


def list_gemini_models(api_key: str):
    from google import genai
    from google.genai import errors

    try:
        return list(genai.Client(api_key=api_key).models.list())
    except errors.APIError as exc:
        raise SetupError(f"Google rechazó la clave de Gemini ({exc.code}): {exc.message}") from exc
    except Exception as exc:  # noqa: BLE001 - network problems etc.
        raise SetupError(f"No se pudo contactar con la API de Gemini: {exc}") from exc


# ───────────────────────── WordPress discovery + authorisation ─────────────────────────
@dataclass
class SiteInfo:
    site: str
    rest_root: str
    name: str
    has_plugin: bool
    authorize_url: str


def normalize_site(value: str) -> str:
    value = value.strip()
    if not value:
        raise SetupError("Falta la dirección del sitio.")
    if not re.match(r"^https?://", value, re.I):
        value = "https://" + value
    return value.rstrip("/")


def discover_site(site: str, http=requests, timeout: float = 20) -> SiteInfo:
    """Read the REST index: confirms WordPress, the plugin (namespace lnh/v1) and the approval-screen URL."""
    root = normalize_root(site)
    try:
        r = http.get(root + "/", timeout=timeout, headers={"Accept": "application/json"})
        data = r.json()
    except (requests.RequestException, ValueError) as exc:
        raise SetupError(f"No se pudo leer la API REST de {site} ({exc}). ¿La dirección es correcta y el sitio está en línea?") from exc
    if not isinstance(data, dict) or "namespaces" not in data:
        raise SetupError(f"{root} no responde como una API REST de WordPress.")
    ap = (data.get("authentication") or {}).get("application-passwords") or {}
    authorize = (ap.get("endpoints") or {}).get("authorization", "")
    return SiteInfo(site=site, rest_root=root, name=str(data.get("name", "")), has_plugin="lnh/v1" in data["namespaces"],
                    authorize_url=authorize)


def _free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def is_local_site(site: str) -> bool:
    """WordPress only accepts an http:// return address for sites running in its 'local' environment."""
    host = (urllib.parse.urlsplit(site).hostname or "").lower()
    return host in ("localhost", "127.0.0.1", "::1") or host.endswith((".local", ".test", ".localhost", ".ddev.site"))


def authorize_on_screen(info: SiteInfo, open_url: Callable[[str], object], ask: Callable[..., str],
                        say: Callable[[str], None] = print) -> tuple[str, str]:
    """Production sites: WordPress rejects http:// return URLs, so it shows the new password on screen instead."""
    params = urllib.parse.urlencode({"app_name": app_name(), "app_id": APP_ID})
    url = info.authorize_url + ("&" if "?" in info.authorize_url else "?") + params
    say("Abriendo WordPress en el navegador. Inicia sesión, pulsa «Sí, aprobar esta conexión» y copia la contraseña que te muestra.")
    say(f"Si no se abre solo, entra a:\n  {url}")
    threading.Thread(target=lambda: open_url(url), daemon=True).start()
    user = ask("Usuario de WordPress con el que iniciaste sesión", "")
    password = ask("Contraseña que muestra WordPress (se ve una sola vez)", "", secret=True)
    if not user or not password:
        raise SetupError("Faltó el usuario o la contraseña.")
    return user, password


def authorize_in_browser(info: SiteInfo, open_url: Callable[[str], object] = webbrowser.open, timeout: int = 300,
                         say: Callable[[str], None] = print, ask: Callable[..., str] | None = None) -> tuple[str, str]:
    """WordPress' standard approval flow. Returns (user_login, application_password).

    Local sites: automatic, through a one-shot callback on 127.0.0.1. Other sites: the password is shown by WordPress and
    pasted here (needs `ask`, i.e. an interactive session).
    """
    if not info.authorize_url:
        raise SetupError("Este sitio no ofrece contraseñas de aplicación (necesitan HTTPS, o están desactivadas).")
    if not is_local_site(info.site):
        if ask is None:
            raise SetupError("En un sitio en producción WordPress no permite el retorno automático. Usa el botón «Generar credenciales» del plugin "
                             "y pasa --wp-user/--wp-password, o ejecuta el asistente en modo interactivo.")
        return authorize_on_screen(info, open_url, ask, say)
    token = secrets.token_urlsafe(16)
    port = _free_port()
    result: dict[str, str] = {}

    class Handler(http.server.BaseHTTPRequestHandler):
        timeout = 5  # browsers open idle speculative connections; never let one block the wait loop

        def log_message(self, *a):  # noqa: D401 - silence
            pass

        def _page(self, title: str, body: str, code: int = 200):
            html = (f"<!doctype html><meta charset=utf-8><title>{title}</title><body style='font:16px system-ui;"
                    f"max-width:32rem;margin:15vh auto;text-align:center'><h1>{title}</h1><p>{body}</p>").encode()
            self.send_response(code)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(html)))
            self.end_headers()
            self.wfile.write(html)

        def do_GET(self):
            u = urllib.parse.urlsplit(self.path)
            q = urllib.parse.parse_qs(u.query)
            if u.path == f"/{token}/ok" and q.get("password") and q.get("user_login"):
                result["user"], result["password"] = q["user_login"][0], q["password"][0]
                self._page("✅ Listo", "Los agentes ya están conectados con WordPress. Puedes cerrar esta pestaña y volver a la terminal.")
            elif u.path == f"/{token}/no":
                result["rejected"] = "1"
                self._page("Autorización cancelada", "No se concedió el acceso. Vuelve a la terminal.")
            else:
                self._page("No encontrado", "Esta dirección no es válida.", 404)

    server = http.server.HTTPServer(("127.0.0.1", port), Handler)
    server.timeout = 1
    params = urllib.parse.urlencode({
        "app_name": app_name(), "app_id": APP_ID,
        "success_url": f"http://127.0.0.1:{port}/{token}/ok", "reject_url": f"http://127.0.0.1:{port}/{token}/no",
    })
    url = info.authorize_url + ("&" if "?" in info.authorize_url else "?") + params
    say("Abriendo WordPress en el navegador. Inicia sesión si hace falta y pulsa «Sí, aprobar esta conexión».")
    say(f"Si no se abre solo, entra a:\n  {url}")
    threading.Thread(target=lambda: open_url(url), daemon=True).start()
    deadline = time.time() + timeout
    try:
        while time.time() < deadline and "password" not in result and "rejected" not in result:
            server.handle_request()
    except KeyboardInterrupt as exc:
        raise SetupError("Interrumpido por el usuario.") from exc
    finally:
        server.server_close()
    if result.get("rejected"):
        raise SetupError("Cancelaste la autorización en WordPress.")
    if "password" not in result:
        raise SetupError("Se agotó el tiempo esperando la aprobación en el navegador.")
    return result["user"], result["password"]


def test_wordpress(info: SiteInfo, user: str, password: str, http=requests, timeout: float = 20) -> str:
    """Verify the credentials; returns the account's display name."""
    r = http.get(info.rest_root + "/wp/v2/users/me", params={"context": "edit"}, auth=(user, password), timeout=timeout)
    if r.status_code != 200:
        raise SetupError(f"WordPress rechazó las credenciales (HTTP {r.status_code}).")
    try:
        return str(r.json().get("name", user))
    except (ValueError, AttributeError) as exc:
        raise SetupError("WordPress respondió algo que no es JSON al comprobar las credenciales.") from exc


# ───────────────────────── console abstraction ─────────────────────────
class Console:
    def __init__(self, interactive: bool = True):
        self.interactive = interactive

    def say(self, text: str = "") -> None:
        print(text)

    def ask(self, prompt: str, default: str = "", secret: bool = False) -> str:
        if not self.interactive:
            return default
        suffix = f" [{default if not secret else '••••' if default else ''}]" if default else ""
        try:
            value = (getpass.getpass(f"{prompt}{suffix}: ") if secret else input(f"{prompt}{suffix}: ")).strip()
        except EOFError:
            return default
        return value or default

    def choose(self, prompt: str, options: list[str], default: int = 1) -> int:
        self.say(prompt)
        for i, o in enumerate(options, 1):
            self.say(f"  {i}. {o}")
        raw = self.ask("Elige una opción", str(default))
        try:
            n = int(raw)
        except ValueError:
            n = default
        return n if 1 <= n <= len(options) else default


# ───────────────────────── the wizard ─────────────────────────
def run_wizard(args, console: Console | None = None, *, http=requests, open_url: Callable[[str], object] = webbrowser.open,
               list_models: Callable[[str], list] = list_gemini_models) -> int:
    con = console or Console(interactive=not args.non_interactive)
    env_path = Path(args.output).resolve()
    template = Path(__file__).resolve().parent.parent / ".env.example"
    values = read_env(env_path)
    new: dict[str, str] = {}
    con.say("\n🛠  Asistente de configuración de los agentes de Lehigh News Hub\n")
    if values:
        con.say(f"Se encontró {env_path}; sus valores se usan como predeterminados.\n")

    # 1 · Gemini ------------------------------------------------------------------
    con.say("1/4 · Gemini (búsqueda, redacción y auditoría)")
    key = args.gemini_key or values.get("GEMINI_API_KEY", "") or os.environ.get("GEMINI_API_KEY", "")
    models: list = []
    for attempt in range(3):
        key = key if (attempt == 0 and key) else con.ask("Clave de API de Gemini (https://aistudio.google.com/apikey)", key, secret=True)
        if not key:
            con.say("  ✗ Hace falta una clave de Gemini.")
            if not con.interactive:
                return 2
            continue
        try:
            models = list_models(key)
            break
        except SetupError as exc:
            con.say(f"  ✗ {exc}")
            key = ""
            if not con.interactive:
                return 2
    else:
        con.say("No se pudo validar la clave de Gemini. Revísala y vuelve a ejecutar `python main.py setup`.")
        return 2
    new["GEMINI_API_KEY"] = key
    text_model = pick_text_model(models)
    if not text_model:
        con.say("  ✗ La clave funciona pero no hay modelos Gemini Flash disponibles.")
        return 2
    con.say(f"  ✓ Clave válida. Modelo elegido automáticamente: {text_model}")
    available = {n for n, _ in _model_rows(models)}
    for var in ("RASTREADOR_MODEL", "REDACCTOR_MODEL", "AUDITOR_MODEL"):
        old_model = values.get(var, "")  # keep a previous choice that is still available
        new[var] = old_model if old_model in available else text_model
    old_red = values.get("REDACCTOR_MODEL", "")
    prev_other = old_red if old_red and not old_red.startswith("gemini") else ""
    other = args.redactor_model or ""
    if con.interactive and not other:
        other = con.ask("¿Otro modelo para el Redactor? (p. ej. claude-sonnet-5-5 o gpt-4o-mini; Enter = el mismo)", prev_other)
    elif not other:
        other = prev_other
    if other:
        new["REDACCTOR_MODEL"] = other
        if other.startswith("claude"):
            new["ANTHROPIC_API_KEY"] = args.anthropic_key or values.get("ANTHROPIC_API_KEY", "") or con.ask("  Clave ANTHROPIC_API_KEY", "", secret=True)
        elif other.startswith(("gpt", "o1", "o3", "o4", "chatgpt")):
            new["OPENAI_API_KEY"] = args.openai_key or values.get("OPENAI_API_KEY", "") or con.ask("  Clave OPENAI_API_KEY", "", secret=True)

    # 2 · WordPress -----------------------------------------------------------------
    con.say("\n2/4 · WordPress")
    site = args.site or ""
    if not site:
        old = values.get("WP_REST_URL", "")
        site = con.ask("Dirección de tu sitio (p. ej. https://tusitio.com)", re.sub(r"/wp-json.*$", "", old))
    if not site:
        con.say("  ✗ Hace falta la dirección del sitio.")
        return 2
    try:
        info = discover_site(normalize_site(site), http=http)
    except SetupError as exc:
        con.say(f"  ✗ {exc}")
        return 2
    con.say(f"  ✓ Sitio encontrado: {info.name or info.site}")
    if info.has_plugin:
        con.say("  ✓ El plugin Lehigh News Hub está activo")
    else:
        con.say("  ! No se detecta el plugin Lehigh News Hub. Instálalo y actívalo (lehigh-news-hub.zip); los borradores se crearán igual, pero sin el panel.")
    new["WP_REST_URL"] = info.rest_root

    user, password = args.wp_user or "", args.wp_password or ""
    token_old = values.get("WP_AUTH_TOKEN", "")
    if not (user and password) and ":" in token_old and normalize_root(values.get("WP_REST_URL", "") or "x") == info.rest_root:
        ou, op = token_old.split(":", 1)
        try:
            who = test_wordpress(info, ou, op, http=http)
            con.say(f"  ✓ Las credenciales guardadas siguen funcionando ({who}); se conservan.")
            user, password = ou, op
        except (SetupError, requests.RequestException):
            pass
    if not (user and password):
        local = is_local_site(info.site)
        mode = 1 if (info.authorize_url and (local or not con.interactive)) else 2
        if con.interactive:
            if not local:
                con.say("  Lo más rápido: en WordPress, News Hub → «Generar credenciales de los agentes», y pega aquí el usuario y la contraseña.")
            mode = con.choose("¿Cómo conectamos los agentes?", [
                "Autorizar en el navegador" + (" (automático)" if local else " (WordPress te muestra la contraseña y la pegas aquí)"),
                "Pegar usuario y contraseña de aplicación (p. ej. los del botón «Generar credenciales» del plugin)",
            ], mode)
        if mode == 1:
            try:
                user, password = authorize_in_browser(info, open_url=open_url, say=con.say, timeout=args.timeout,
                                                      ask=con.ask if con.interactive else None)
            except SetupError as exc:
                con.say(f"  ✗ {exc}")
                if not con.interactive:
                    return 2
                mode = 2
        if not (user and password):
            con.say("  Crea una en Usuarios → tu perfil → Contraseñas de aplicación (o usa el botón «Generar credenciales» del plugin).")
            user = con.ask("  Usuario de WordPress", user)
            password = con.ask("  Contraseña de aplicación", password, secret=True)
    try:
        who = test_wordpress(info, user, password, http=http)
    except (SetupError, requests.RequestException) as exc:
        con.say(f"  ✗ {exc}")
        return 2
    con.say(f"  ✓ Conectado como «{who}»")
    new["WP_AUTH_TOKEN"] = f"{user}:{password}"

    # 3 · Images -----------------------------------------------------------------------
    con.say("\n3/4 · Imagen destacada con IA (opcional)")
    provider = args.image_provider
    if provider is None and not con.interactive and (values.get("IMAGE_PROVIDER") or values.get("IMAGE_API_KEY")):
        con.say("  – Se conserva la configuración de imágenes existente.")
        provider = "keep"
    if provider is None and con.interactive:
        n = con.choose("¿Generar la imagen destacada con IA?", [
            "No, usar solo fotos con licencia abierta",
            "Sí, con Nano Banana (Gemini) — reutiliza tu clave de Gemini",
            "Sí, con Flux en Replicate",
            "Sí, con OpenAI (gpt-image-1)",
        ], 1)
        provider = {1: "none", 2: "gemini", 3: "replicate", 4: "openai"}[n]
    provider = provider or "none"
    if provider == "keep":
        pass
    elif provider == "none":
        con.say("  – Sin imágenes con IA (se pueden activar luego en el .env).")
        new["IMAGE_PROVIDER"] = ""
        new["IMAGE_API_KEY"] = ""
    elif provider == "gemini":
        img_model = pick_image_model(models) or "gemini-2.5-flash-image"
        new.update(IMAGE_PROVIDER="gemini", IMAGE_MODEL=img_model, IMAGE_API_KEY="", IMAGE_API_ENDPOINT="")
        con.say(f"  ✓ Nano Banana con el modelo {img_model} (usa tu clave de Gemini)")
    else:
        label = "REPLICATE" if provider == "replicate" else "OPENAI"
        k = args.image_key or values.get("IMAGE_API_KEY", "") or con.ask(f"  Clave de API de {label.title()}", "", secret=True)
        if not k:
            con.say("  ✗ Sin clave no se pueden generar imágenes; se desactivan.")
            new.update(IMAGE_PROVIDER="", IMAGE_API_KEY="")
        else:
            new.update(IMAGE_PROVIDER=provider, IMAGE_API_KEY=k,
                       IMAGE_MODEL="black-forest-labs/flux-1.1-pro" if provider == "replicate" else "gpt-image-1")
            con.say(f"  ✓ {provider} configurado")

    # 4 · Write + check ------------------------------------------------------------------
    con.say("\n4/4 · Guardando la configuración")
    merged = {**values, **new}
    write_env(env_path, merged, template)
    con.say(f"  ✓ {env_path} (permisos solo para tu usuario)")
    if not args.no_check:
        from .cli import _check
        from .settings import Settings

        con.say("\nComprobación final:")
        rc = _check(Settings.from_env(env_path))
        if rc != 0:
            return rc
    con.say("\n🎉 Listo. Siguiente paso, una prueba sin escribir en WordPress:\n    python main.py run --dry-run\n"
            "y para el funcionamiento continuo:\n    python main.py watch")
    return 0
