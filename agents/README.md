# Agentes (Python)

Rastreador → Redactor → Auditor → WordPress. Ver el [README principal](../README.md) para el panorama completo.

```bash
./install.sh                 # (install.bat en Windows) entorno + dependencias + asistente
python main.py setup         # asistente guiado: crea/actualiza el .env automáticamente
python main.py check | run [--dry-run] [--no-images] | watch
```

`setup` valida la clave de Gemini, elige el mejor modelo disponible, localiza el sitio y obtiene la contraseña de aplicación mediante la pantalla de aprobación de WordPress (o reutiliza la del botón «Generar credenciales» del plugin). Módulo: `setup_wizard.py`.

## Módulos

| Módulo | Responsabilidad |
|---|---|
| `settings.py` | `.env` → `Settings` (claves, modelos, comportamiento). `REDACCTOR_MODEL` (o `REDACTOR_MODEL`) cambia el modelo del Agente 2. |
| `llm.py` | Router de modelos: Gemini (`google-genai`), Claude (`anthropic`), OpenAI. `json()` valida y reintenta una vez; `grounded_search()` usa Google Search. |
| `agents/rastreador.py` | Agente 1 · búsqueda → lectura de páginas → hallazgos JSON. |
| `agents/redactor.py` | Agente 2 · artículo Gutenberg + multimedia + prompt de imagen + `featured_image_url`. |
| `agents/auditor.py` | Agente 3 · `audit_score`, `audit_notes`, `status` y POST a WordPress. |
| `imagegen.py` | Replicate/Flux, Gemini (Nano Banana) y APIs compatibles con OpenAI. |
| `wordpress.py` | Cliente REST (entradas, medios, etiquetas, informe de ejecución). |
| `checks.py` | Comprobaciones deterministas: enlaces, HTML, cifras, copia literal. |
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
