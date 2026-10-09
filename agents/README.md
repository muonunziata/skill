# Agentes (Python)

Rastreador → Redactor → Auditor → WordPress, y después el Diseñador Social (Agente 4). Ver el [README principal](../README.md) para el panorama completo.

```bash
./install.sh                 # (install.bat en Windows) entorno + dependencias + asistente
python main.py setup         # asistente guiado: crea/actualiza el .env automáticamente
python main.py check | run [--dry-run] [--no-images] [--no-social] | watch [--no-control]
python main.py social --post 123 [--no-video] [--formats instagram,tiktok] [--dry-run] [--out DIR]
```

`install.sh` / `install.bat` ejecutan `instalar.py` (solo librería estándar): crea el entorno, instala las librerías y Chromium con un **medidor de progreso en vivo** (porcentaje, paso, tiempo, girito y aviso «sin novedades hace N s»; registro en `instalacion.log`).

`setup` valida la clave de Gemini, elige el mejor modelo disponible, localiza el sitio y obtiene la contraseña de aplicación: reutiliza la del botón «Generar credenciales» del plugin si ya está en el `.env`, o usa la pantalla de aprobación de WordPress (automática en sitios locales; en sitios públicos WordPress muestra la contraseña y se pega en el asistente). Módulo: `setup_wizard.py`.

## Módulos

| Módulo | Responsabilidad |
|---|---|
| `settings.py` | `.env` → `Settings` (claves, modelos, comportamiento). `REDACCTOR_MODEL` (o `REDACTOR_MODEL`) cambia el modelo del Agente 2. |
| `llm.py` | Router de modelos: Gemini (`google-genai`), Claude (`anthropic`), OpenAI. `json()` valida y reintenta una vez; `grounded_search()` usa Google Search. |
| `agents/rastreador.py` | Agente 1 · búsqueda → lectura de páginas → hallazgos JSON. |
| `agents/redactor.py` | Agente 2 · artículo Gutenberg + multimedia + prompt de imagen + `featured_image_url`. |
| `agents/auditor.py` | Agente 3 · `audit_score`, `audit_notes`, `status` y POST a WordPress. |
| `social/` | Agente 4 · Diseñador Social: `plan.py` (contrato del contenido), `templates.py` (HTML/CSS de láminas y escenas), `render.py` (Chromium/Playwright), `video.py` (ffmpeg), `voice.py` (narración opcional), `designer.py` (orquesta, sube a WordPress y vincula el kit al borrador), `brand.py` + `assets/` (logos y tipografía de GoLehighAcres.org). |
| `news_api.py` | Fuente extra opcional: Mediastack (con límite de consultas para respetar el plan gratuito). |
| `imagegen.py` | Replicate/Flux, Gemini (Nano Banana) y APIs compatibles con OpenAI. |
| `wordpress.py` | Cliente REST (entradas, medios, etiquetas, informe de ejecución). |
| `checks.py` | Comprobaciones deterministas: enlaces, HTML, cifras, copia literal. |
| `control.py` · `worker.py` | Botón **Iniciar / Pausar** del plugin: `watch` es un trabajador que cada ~15 s hace `POST /lnh/v1/control/sync` (reporta su estado y progreso en vivo, recibe *running/paused*, «ejecutar ahora» e intervalo). Pausar detiene entre noticias, nunca a mitad de un artículo. Sin plugin o con `--no-control` funciona como planificador simple. |
| `updater.py` | Botón «Actualizar los agentes» del plugin: `worker` descarga el código (`GET /lnh/v1/agents/package`), valida el zip (sin rutas peligrosas, estructura esperada), lo copia sin tocar `.env`, `.venv`, `state` ni logs, reinstala librerías si cambió `requirements.txt` y se reinicia. |
| `pipeline.py` | Orquesta una ejecución, el bucle de corrección y la limpieza. |
| `net.py` | Descargas con protección SSRF. |
| `store.py` | Memoria SQLite anti-duplicados. |

## Contratos JSON

```jsonc
// Agente 1
{"titulo_fuente": "…", "url": "…", "resumen_hechos": "…", "fecha": "AAAA-MM-DD", "palabras_clave": ["…"]}
// Agente 2
{"post_title": "…", "post_content": "<!-- wp:paragraph -->…", "excerpt": "…", "meta_description": "…",
 "suggested_tags": ["…"], "featured_image_url": "https://…/wp-content/uploads/…"}
// Agente 3
{"audit_score": 92, "audit_notes": ["…"], "status": "approved"}
```

## Imagen destacada con IA
1. El Redactor escribe el artículo y, a partir de él, un prompt fotorrealista en inglés (receta: sujeto, escenario del suroeste de Florida, luz, cámara/lente, composición, señales de realismo, exclusiones).
2. Si el prompt incumple las reglas de seguridad (menores, víctimas, violencia, marcas, idioma), se sustituye por una escena neutra.
3. `ImageGenerator.generate(prompt)` llama a la API configurada (`IMAGE_API_KEY`, `IMAGE_API_ENDPOINT`).
4. Como las URL de los proveedores caducan (y Gemini/OpenAI devuelven bytes), la imagen se **sube a la biblioteca de medios de WordPress** y su URL permanente se asigna a `featured_image_url` y a `featured_media`.
5. Si el Auditor marca el artículo y no se enviará, la imagen subida se elimina.

## Agente 4 · Diseñador Social (Instagram y TikTok)
Se ejecuta tras cada artículo **aprobado** y enviado a WordPress (o con `python main.py social --post ID` sobre uno existente) y **nunca puede hacer fallar el artículo**.

1. **Plan** (`SOCIAL_MODEL`, por defecto el modelo del Redactor): `hook`, 5–8 láminas (`cover` → `point/stat/quote` → `source` → `cta`), 4–7 escenas de video, pies de foto de Instagram y TikTok, hashtags y texto alternativo. Se valida la estructura y se rechazan **cifras que no estén en el artículo** (una corrección automática; si persiste, no se genera).
2. **Diseño** sobre la marca GoLehighAcres.org: logos oficiales (`brand/`), verde `#1b6a55`, coral `#ff5757`, Poppins, anillo de colores del logo como ritmo (puntos de progreso y franja inferior). Instagram 1080×1350 (4:5), TikTok y video 1080×1920 (9:16) con zonas seguras de la interfaz de TikTok. Tres temas: `light` (por defecto), `dark`, `brand`. La imagen generada con IA se **etiqueta** en la portada.
3. **Video** vertical con zoom suave y fundidos (ffmpeg), cierre de marca y **voz opcional** (`SOCIAL_VOICE=gemini|openai`).
4. **Salida**: `state/social/<fecha>-<slug>/` (`instagram-01.png`…, `tiktok-01.png`…, `video.mp4`, `captions.txt`, `plan.json`), subida a la biblioteca de medios y tarjeta **«Kit social»** en la pantalla de revisión del plugin (vista previa, descarga y copiar texto). Opcional: `SOCIAL_WEBHOOK_URL` avisa a Make/Zapier/n8n con firma HMAC (`X-Lehigh-Signature`).

**No publica en Instagram ni TikTok**: ambas redes exigen apps y permisos aprobados por cada cuenta y, además, la decisión final es humana. Descarga los archivos y súbelos desde las apps o Meta Business Suite (o conéctalo con el webhook).

Requisitos: `playwright` (Chromium: `python -m playwright install chromium`, lo hace `install.sh`), `Pillow` y `imageio-ffmpeg`. Sin navegador, el resto de agentes sigue funcionando y el kit se omite con un aviso.
