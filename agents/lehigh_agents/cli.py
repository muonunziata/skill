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


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="lehigh-agents", description="Rastreador → Redactor → Auditor → WordPress")
    ap.add_argument("--env-file", help="ruta al archivo .env (por defecto ./.env)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("check", help="valida configuración, modelos y conexión con WordPress")
    for name, hlp in (("run", "una ejecución"), ("watch", "ejecuciones continuas")):
        p = sub.add_parser(name, help=hlp)
        p.add_argument("--dry-run", action="store_true", help="no escribe en WordPress; guarda el resultado en ./state")
        p.add_argument("--no-images", action="store_true", help="no genera imágenes con IA")
    args = ap.parse_args(argv)

    s = Settings.from_env(args.env_file)
    logging.basicConfig(level=getattr(logging, s.log_level, logging.INFO), format="%(asctime)s %(levelname)s %(message)s")
    if args.cmd == "check":
        return _check(s)

    problems = s.problems(need_wordpress=not args.dry_run)
    if problems:
        print("Configuración incompleta:\n  - " + "\n  - ".join(problems), file=sys.stderr)
        return 2

    from .pipeline import Pipeline

    pipe = Pipeline(s, dry_run=args.dry_run, make_images=not args.no_images)
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
