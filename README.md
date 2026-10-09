# Lehigh News Hub

Una redacción automática para noticias locales de **Lehigh Acres, Florida**: tres agentes de IA investigan, redactan y auditan, y un plugin de WordPress te muestra **todo el trabajo de los agentes** para que tú decidas qué se publica.

```
 ┌────────────────────┐   ┌────────────────────┐   ┌────────────────────┐
 │ 1 · RASTREADOR     │ → │ 2 · REDACTOR       │ → │ 3 · AUDITOR        │
 │ Gemini + Google    │   │ Gemini / Claude /  │   │ Gemini             │
 │ Search, lee las    │   │ GPT: artículo SEO  │   │ verifica hechos,   │
 │ páginas reales     │   │ + prompt + imagen  │   │ ortografía, enlaces│
 └────────────────────┘   └────────────────────┘   └─────────┬──────────┘
                                                             │ aprobado → POST (borrador)
                                                             ▼
                              ┌──────────────────────────────────────────┐
                              │ WordPress · plugin «Lehigh News Hub»     │
                              │ cola de revisión · actividad de agentes  │
                              │ publicar / programar / rechazar          │
                              │ shortcode [lehigh_news] configurable     │
                              └──────────────────────────────────────────┘
```

## Qué hace cada pieza

| Pieza | Qué hace |
|---|---|
| **Agente 1 – Rastreador** | Busca novedades con Google Search (Gemini), **abre cada página citada** y construye los hallazgos solo con lo que leyó (`titulo_fuente`, `url`, `resumen_hechos`, `fecha`, `palabras_clave`). Descarta duplicados, noticias viejas y URLs que no leyó. |
| **Agente 2 – Redactor** | Escribe el artículo en bloques Gutenberg optimizado para SEO (`post_title`, `post_content`, `excerpt`, `meta_description`, `suggested_tags`, `featured_image_url`). Resuelve marcadores de fotos (Wikimedia Commons, licencia abierta) y videos (YouTube verificado). Escribe un **prompt fotorrealista en inglés** y genera la imagen destacada (Flux/Replicate, Nano Banana/Gemini u OpenAI). Su modelo se cambia con `REDACCTOR_MODEL`. |
| **Agente 3 – Auditor** | Compara el artículo con los hechos del Rastreador y con el texto original; revisa ortografía, **enlaces rotos**, HTML inseguro, cifras inventadas y copia literal. Devuelve `audit_score` (0-100), `audit_notes` y `status` (`approved`/`flagged`). Si es `approved`, **publica el borrador en WordPress** con sus metadatos de auditoría. |
| **Plugin de WordPress** | Cola de revisión con puntuaciones, pantalla de revisión por artículo, **actividad en vivo de los agentes** (búsquedas, páginas leídas, prompts, imágenes, auditorías, tokens), publicar/programar/rechazar, publicación automática opcional, avisos por correo, aviso de IA y fuente en el sitio, y el shortcode `[lehigh_news]` con constructor visual y presets. Interfaz en inglés y español. |

## Puesta en marcha (10 minutos)

### 1 · Instala el plugin
WordPress → *Plugins → Añadir nuevo → Subir plugin* → `lehigh-news-hub.zip` → Activar. Aparece el menú **News Hub**.

### 2 · Crea el acceso de los agentes
*Usuarios → tu perfil → Contraseñas de aplicación* → crea una (p. ej. «agentes»).
Recomendado: una cuenta con rol **Autor** o **Editor** solo para los agentes (el plugin además filtra el HTML de los agentes con `wp_kses_post`).

### 3 · Configura y arranca los agentes
```bash
cd agents
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env        # rellena GEMINI_API_KEY, WP_REST_URL, WP_AUTH_TOKEN…
python main.py check        # valida claves, modelos y conexión con WordPress
python main.py run --dry-run  # prueba completa sin escribir en WordPress
python main.py run            # una ejecución real
python main.py watch          # ejecuciones continuas (cada LOOP_INTERVAL_MINUTES)
```
Para dejarlo corriendo: `agents/Dockerfile` + `docker-compose.yml`, o `agents/deploy/lehigh-agents.service` (systemd).

### 4 · Usa el plugin
* **News Hub → Cola de revisión**: ve las noticias que esperan tu decisión, con su nota de auditoría.
* **Revisar**: vista previa, notas del auditor, fuente, imagen (con el prompt que escribió el Redactor) y la línea de tiempo de lo que hizo cada agente. Botones: *Publicar ahora*, *Programar*, *Rechazar*.
* **Actividad de los agentes**: tarjetas por agente, embudo de 7 días, feed en vivo y detalle de cada ejecución.
* **Constructor de shortcode**: diseña la lista de últimas noticias con vista previa en vivo, cópiala o guárdala como preajuste.

## Variables de entorno (`agents/.env`)

| Variable | Para qué |
|---|---|
| `GEMINI_API_KEY` | Agentes 1 y 3 (y 2 si usa Gemini) |
| `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` | Solo si `REDACCTOR_MODEL` es `claude-*` o `gpt-*` |
| `WP_REST_URL`, `WP_AUTH_TOKEN` | Sitio (`https://tu-sitio.com` o `…/wp-json`) y `usuario:contraseña de aplicación` (o token Bearer) |
| `RASTREADOR_MODEL`, `REDACCTOR_MODEL`, `AUDITOR_MODEL` | Modelos (por defecto `gemini-1.5-flash`) |
| `IMAGE_API_KEY`, `IMAGE_API_ENDPOINT`, `IMAGE_PROVIDER`, `IMAGE_MODEL` | Generación de imágenes (opcional) |
| `AUDIT_MIN_SCORE`, `MAX_REVISIONS`, `POST_FLAGGED`, … | Comportamiento (ver `.env.example`) |

> ⚠️ **Modelos:** Google retiró la familia Gemini 1.5 de la API pública. El valor por defecto sigue siendo `gemini-1.5-flash` como pediste, pero `python main.py check` te avisará si no está disponible; en ese caso pon `gemini-2.5-flash` (o el que prefieras) en las tres variables de modelo.

## Seguridad y transparencia

* **Los agentes nunca publican solos**: crean *borradores* (o *pendientes*, si activas `POST_FLAGGED`). La publicación automática es una opción del plugin, desactivada por defecto y nunca aplica a artículos «flagged».
* **URLs de origen no confiable**: todo lo que el modelo o una página web propone (enlaces, imágenes) pasa por un descargador con protección SSRF (solo http/https, solo IPs públicas, redirecciones revalidadas, tamaño y tiempo acotados).
* **Anti-alucinación**: el Rastreador solo acepta URLs que realmente abrió; el Auditor contrasta cifras, nombres y enlaces con la fuente.
* **Imágenes**: solo fotos con licencia abierta (Wikimedia Commons, con crédito) o imágenes generadas. Las imágenes generadas con IA son *ilustrativas*: el prompt prohíbe personas identificables, menores, víctimas, violencia y reconstrucciones de hechos concretos; se **etiquetan siempre** en el pie de foto, en la ficha del artículo y en las tarjetas (insignia «Imagen IA»). No uses estas imágenes como si fueran fotografías del hecho.
* **Contenido de los agentes**: se limpia (scripts, iframes, eventos, shortcodes) antes de enviarse y otra vez en WordPress.
* **Aviso de IA y fuente** visibles en cada artículo (configurables en *Ajustes → Transparencia*).

## Pruebas

```bash
# Agentes (no consumen APIs: usan dobles de prueba)
cd agents && pip install -r requirements.txt && python -m pytest

# Plugin dentro de un WordPress de pruebas (¡no en producción!)
php wordpress/tests/run.php /ruta/a/wordpress
```

## Estructura

```
agents/                      Agentes en Python (Gemini · Claude · OpenAI · imágenes)
  lehigh_agents/agents/        rastreador.py · redactor.py · auditor.py
  lehigh_agents/               llm.py · imagegen.py · pipeline.py · wordpress.py · checks.py · net.py …
  tests/                       36 pruebas
wordpress/lehigh-news-hub/   Plugin de WordPress (instalable)
wordpress/tests/run.php      Pruebas dentro de WordPress
wordpress/tools/             Extracción de cadenas y compilación de traducciones
scripts/build-release.sh     Genera los .zip descargables
```

## Costos
Cada ejecución usa Gemini (búsqueda con grounding, lectura estructurada, redacción, auditoría) más, si lo activas, una imagen. El panel de actividad muestra los tokens usados. Ajusta `MAX_ITEMS_PER_RUN` y `LOOP_INTERVAL_MINUTES` para controlar el gasto.
