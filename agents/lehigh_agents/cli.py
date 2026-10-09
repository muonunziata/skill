from __future__ import annotations

import argparse
import json
import logging
import signal
import sys
import threading

from .imagegen import ImageGenerator
from .llm import LLM, provider_for
from .settings import Settings
from .wordpress import WPError, WordPressClient


def _check_social(s: Settings) -> None:
    """Agent 4 needs Chromium and ffmpeg; their absence is a warning (the other agents keep working), not a failure."""
    if not s.social_enabled:
        print("Agente 4 (kit social): desactivado (SOCIAL_ENABLED=false)")
        return
    from .social.render import SlideRenderer, SocialRenderError
    from .social.video import VideoError, ffmpeg_path

    notes = []
    try:
        with SlideRenderer(s.chromium_path):
            notes.append("navegador ✓")
    except SocialRenderError as exc:
        notes.append("navegador ✗ (ejecuta `python -m playwright install chromium` o define CHROMIUM_PATH)")
        log_detail = str(exc)[:160]
        notes.append(log_detail)
    try:
        ffmpeg_path()
        notes.append("ffmpeg ✓")
    except VideoError:
        notes.append("ffmpeg ✗ (pip install imageio-ffmpeg): sin video")
    voice = f"voz {s.social_voice}" if s.social_voice != "none" else "sin voz"
    print(f"Agente 4 (kit social): {', '.join(notes)} · formatos {','.join(s.social_formats)} · tema {s.social_theme} · {voice}")


def _check(s: Settings) -> int:
    ok = True
    print(f"Tema: {s.topic}\nModelos: rastreador={s.rastreador_model} · redactor={s.redactor_model} · auditor={s.auditor_model}")
    for p in s.problems():
        ok = False
        print(f"  ✗ {p}")
    models = LLM(s).available_models().get("gemini")
    if models is not None:
        for role, m in (("rastreador", s.rastreador_model), ("redactor", s.redactor_model), ("auditor", s.auditor_model)):
            if provider_for(m) == "gemini" and m not in models:
                ok = False
                print(f"  ✗ El modelo '{m}' ({role}) no está disponible en la API de Gemini. "
                      "Actualiza el .env (p. ej. gemini-2.5-flash).")
    ig = ImageGenerator(s)
    print(f"Imágenes IA: {'%s / %s' % (ig.provider, ig.model) if ig.enabled else 'desactivadas (sin IMAGE_API_KEY)'}")
    _check_social(s)
    if s.wp_rest_url and s.wp_auth_token:
        try:
            wp = WordPressClient(s.wp_rest_url, s.wp_auth_token)
            me = wp.whoami()
            print(f"  ✓ WordPress {wp.root}: autenticado como {me.get('name')} ({', '.join(me.get('roles', []))})")
            hub = wp.hub_ping()
            print("  ✓ Plugin Lehigh News Hub activo (v%s)" % hub.get("version") if hub else
                  "  ! Plugin Lehigh News Hub no detectado: los borradores se crean igual, sin panel de agentes.")
        except WPError as exc:
            ok = False
            print(f"  ✗ WordPress: {exc}")
    print("Todo listo." if ok else "Hay problemas que corregir antes de ejecutar.")
    return 0 if ok else 1


def _social(s: Settings, args: argparse.Namespace) -> int:
    from pathlib import Path

    from .htmlutil import strip_tags
    from .net import Fetcher
    from .social import ArticleContext, SocialDesigner, SocialError

    if not s.wp_rest_url or not s.wp_auth_token:
        print("Se necesita WP_REST_URL y WP_AUTH_TOKEN para leer el artículo (ejecuta `setup`).", file=sys.stderr)
        return 2
    if not s.gemini_api_key and s.social_model.startswith("gemini"):
        print("Falta GEMINI_API_KEY para el modelo social.", file=sys.stderr)
        return 2
    wp = WordPressClient(s.wp_rest_url, s.wp_auth_token)
    try:
        posts = [wp.get_post(args.post)] if args.post else wp.latest_posts(args.latest or 1)
    except WPError as exc:
        print(f"WordPress: {exc}", file=sys.stderr)
        return 1
    if not posts:
        print("No hay artículos de los agentes todavía. Ejecuta `run` primero o indica --post ID.", file=sys.stderr)
        return 1
    fetcher = Fetcher()
    llm = LLM(s)
    formats = [f.strip() for f in (args.formats or "").split(",") if f.strip() in ("instagram", "tiktok")] or None
    failed = 0
    for p in posts:
        meta = p.get("meta") or {}
        title = strip_tags((p.get("title") or {}).get("raw") or (p.get("title") or {}).get("rendered") or "")
        body = strip_tags((p.get("content") or {}).get("raw") or "")
        photo, img = b"", meta.get("lnh_featured_image_url", "")
        if img.startswith("http"):
            try:
                photo = fetcher.get(img, max_bytes=8_000_000, accept="image/*").body
            except Exception:  # noqa: BLE001 - design without a photo
                photo = b""
        kws = []
        try:
            kws = json.loads(meta.get("lnh_keywords") or "[]")
        except ValueError:
            pass
        ctx = ArticleContext(
            title=title, excerpt=strip_tags((p.get("excerpt") or {}).get("raw") or ""), body=body,
            fuente=meta.get("lnh_source_name") or "la fuente", url=meta.get("lnh_source_url", ""), keywords=kws,
            language=meta.get("lnh_language") or s.language, photo=photo, photo_ai=bool(meta.get("lnh_featured_image_ai")),
            post_id=int(p["id"]), post_link=p.get("link", ""), evidence=f"{meta.get('lnh_facts', '')}\n{body}")
        designer = SocialDesigner(s, llm, fetcher, lambda a, t, m, **k: print(f"  [{a}] {m}"), wp)
        print(f"▶ {title} (ID {p['id']})")
        try:
            kit = designer.run(ctx, out_root=Path(args.out) if args.out else None, upload=not args.dry_run,
                               video=False if args.no_video else None, formats=formats)
        except SocialError as exc:
            failed += 1
            print(f"  ✗ {exc}", file=sys.stderr)
            continue
        print(f"  ✓ {kit.folder}\n    " + " · ".join(f"{k}: {len(v)}" for k, v in kit.files.items()))
        for w in kit.warnings:
            print(f"    ! {w}")
    return 1 if failed else 0


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="lehigh-agents", description="Rastreador → Redactor → Auditor → WordPress")
    ap.add_argument("--env-file", help="ruta al archivo .env (por defecto ./.env)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("check", help="valida configuración, modelos y conexión con WordPress")
    sp = sub.add_parser("setup", help="asistente de configuración guiado (crea el .env automáticamente)")
    sp.add_argument("--output", default=".env", help="archivo .env a crear o actualizar (por defecto ./.env)")
    sp.add_argument("--site", help="dirección del sitio WordPress")
    sp.add_argument("--gemini-key")
    sp.add_argument("--wp-user")
    sp.add_argument("--wp-password", help="contraseña de aplicación")
    sp.add_argument("--redactor-model", help="modelo del Agente 2 (gemini-*, claude-*, gpt-*)")
    sp.add_argument("--anthropic-key")
    sp.add_argument("--openai-key")
    sp.add_argument("--image-provider", choices=["none", "gemini", "replicate", "openai"])
    sp.add_argument("--image-key")
    sp.add_argument("--mediastack-key", help="clave de Mediastack (fuente de noticias opcional)")
    sp.add_argument("--non-interactive", action="store_true", help="no preguntar: usar solo flags y el .env existente")
    sp.add_argument("--no-check", action="store_true", help="omitir la comprobación final")
    sp.add_argument("--timeout", type=int, default=300, help="segundos de espera de la aprobación en el navegador")
    for name, hlp in (("run", "una ejecución"), ("watch", "ejecuciones continuas")):
        p = sub.add_parser(name, help=hlp)
        p.add_argument("--dry-run", action="store_true", help="no escribe en WordPress; guarda el resultado en ./state")
        p.add_argument("--no-images", action="store_true", help="no genera imágenes con IA")
        p.add_argument("--no-social", action="store_true", help="no diseña el kit de Instagram/TikTok (Agente 4)")
    so = sub.add_parser("social", help="Agente 4: diseña carruseles y video para Instagram/TikTok de un artículo ya creado")
    so.add_argument("--post", type=int, help="ID del borrador/entrada de WordPress")
    so.add_argument("--latest", type=int, metavar="N", help="los N artículos más recientes de los agentes (por defecto 1)")
    so.add_argument("--no-video", action="store_true", help="solo carruseles")
    so.add_argument("--formats", help="instagram,tiktok (por defecto el de SOCIAL_FORMATS)")
    so.add_argument("--dry-run", action="store_true", help="solo archivos locales: no sube nada a WordPress")
    so.add_argument("--out", help="carpeta de salida (por defecto ./state/social)")
    args = ap.parse_args(argv)

    if args.cmd == "setup":
        from .setup_wizard import run_wizard

        return run_wizard(args)

    s = Settings.from_env(args.env_file)
    logging.basicConfig(level=getattr(logging, s.log_level, logging.INFO), format="%(asctime)s %(levelname)s %(message)s")
    if args.cmd == "check":
        return _check(s)

    if args.cmd == "social":
        return _social(s, args)

    problems = s.problems(need_wordpress=not args.dry_run)
    if problems:
        print("Configuración incompleta:\n  - " + "\n  - ".join(problems), file=sys.stderr)
        return 2

    from .pipeline import Pipeline

    pipe = Pipeline(s, dry_run=args.dry_run, make_images=not args.no_images, make_social=not args.no_social)
    if args.cmd == "run":
        report = pipe.run_once()
        print(json.dumps({k: report[k] for k in ("run_id", "status", "counts")}, ensure_ascii=False))
        for it in report["items"]:
            print(f"- [{it['status']}] {it['titulo_fuente']} ({it.get('audit_score', '-')}/100)"
                  + (f" → {it['post']['edit_link']}" if it.get("post") else ""))
        return 0 if report["status"] == "ok" else 1

    stop = threading.Event()
    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, lambda *_: stop.set())
    while not stop.is_set():
        try:
            pipe.run_once()
        except Exception:  # noqa: BLE001 - a bad cycle must not kill the daemon
            logging.exception("run failed")
        stop.wait(s.interval_minutes * 60)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
