# Lehigh News Hub

Una redacción automática para noticias locales de **Lehigh Acres, Florida** (marca **GoLehighAcres.org**): tres agentes de IA investigan, redactan y auditan, un cuarto diseña los carruseles y videos de Instagram y TikTok, y un plugin de WordPress te muestra **todo el trabajo de los agentes** para que tú decidas qué se publica.

```
 ┌────────────────────┐   ┌────────────────────┐   ┌────────────────────┐
 │ 1 · RASTREADOR     │ → │ 2 · REDACTOR       │ → │ 3 · AUDITOR        │
 │ Gemini + Google    │   │ Gemini / Claude /  │   │ Gemini             │
 │ Search, lee las    │   │ GPT: artículo SEO  │   │ verifica hechos,   │
 │ páginas reales     │   │ + prompt + imagen  │   │ ortografía, enlaces│
 └────────────────────┘   └────────────────────┘   └─────────┬──────────┘
                                                             │ aprobado → POST (borrador)
                              ┌──────────────────────────────▼───────────┐   ┌────────────────────────┐
                              │ WordPress · plugin «Lehigh News Hub»     │ → │ 4 · DISEÑADOR SOCIAL   │
                              │ cola de revisión · actividad de agentes  │   │ carruseles IG/TikTok + │
                              │ publicar / programar / rechazar          │ ← │ video 9:16 con la marca│
                              │ shortcode [lehigh_news] configurable     │   │ GoLehighAcres.org      │
                              └──────────────────────────────────────────┘   └────────────────────────┘
                                                         «Kit social» en cada artículo aprobado
```

## Qué hace cada pieza

| Pieza | Qué hace |
|---|---|
| **Agente 1 – Rastreador** | Busca novedades con Google Search (Gemini), **abre cada página citada** y construye los hallazgos solo con lo que leyó (`titulo_fuente`, `url`, `resumen_hechos`, `fecha`, `palabras_clave`). Descarta duplicados, noticias viejas y URLs que no leyó. |
| **Agente 2 – Redactor** | Escribe el artículo en bloques Gutenberg optimizado para SEO (`post_title`, `post_content`, `excerpt`, `meta_description`, `suggested_tags`, `featured_image_url`). Resuelve marcadores de fotos (Wikimedia Commons, licencia abierta) y videos (YouTube verificado). Escribe un **prompt fotorrealista en inglés** y genera la imagen destacada (Flux/Replicate, Nano Banana/Gemini u OpenAI). Su modelo se cambia con `REDACCTOR_MODEL`. |
| **Agente 3 – Auditor** | Compara el artículo con los hechos del Rastreador y con el texto original; revisa ortografía, **enlaces rotos**, HTML inseguro, cifras inventadas y copia literal. Devuelve `audit_score` (0-100), `audit_notes` y `status` (`approved`/`flagged`). Si es `approved`, **publica el borrador en WordPress** con sus metadatos de auditoría. |
| **Agente 4 – Diseñador Social** | Convierte cada artículo aprobado en un **carrusel de Instagram (4:5)**, un **carrusel de TikTok (9:16)** y un **video vertical** (voz opcional) con pies de foto y hashtags, **siempre sobre la marca GoLehighAcres.org** (logos oficiales en `brand/`, verde `#1b6a55`, coral `#ff5757`, Poppins). Etiqueta las imágenes de IA y no inventa cifras. Aparece en el plugin como tarjeta **«Kit social»** (vista previa, descargas, copiar texto). No publica en las redes: lo haces tú. |
| **Plugin de WordPress** | Cola de revisión con puntuaciones, pantalla de revisión por artículo, **actividad en vivo de los agentes** (búsquedas, páginas leídas, prompts, imágenes, auditorías, tokens), publicar/programar/rechazar, publicación automática opcional, avisos por correo, aviso de IA y fuente en el sitio, y el shortcode `[lehigh_news]` con constructor visual y presets. Interfaz en inglés y español. |

## Puesta en marcha (10 minutos)

### 1 · Instala el plugin
WordPress → *Plugins → Añadir nuevo → Subir plugin* → `lehigh-news-hub.zip` → Activar.

Al activarlo, el plugin **crea automáticamente lo que el sitio necesita** y te lleva al panel **News Hub**:

| Página creada | Para qué sirve |
|---|---|
| **Noticias de Lehigh Acres** (`/noticias/` o `/news/`) | Listado de artículos con el shortcode `[lehigh_news]`, destacada + paginación |
| **Cómo trabajamos** (`/como-trabajamos/`) | Política editorial y transparencia: qué hace cada agente, fuentes, imágenes con IA, decisión humana |
| **Correcciones y contacto** (`/correcciones/`) | Cómo avisar de errores y cómo los corregimos; correo público opcional (*Ajustes → Transparencia*) |

Además crea la categoría **Noticias** (categoría por defecto de los artículos de los agentes). Las páginas salen en español si el idioma del sitio/usuario es español y en inglés en otro caso. Nunca se duplican al reactivar, nunca se sobrescriben tus ediciones y, si borras una a propósito, no se vuelve a crear sola; en *News Hub → Ajustes → Páginas* hay un botón para recrear las que falten. Añádelas a tu menú desde *Apariencia → Menús*.

### 2 · Conecta los agentes (casi todo automático)
En WordPress: **News Hub → Panel → «Generar credenciales de los agentes»**. Crea la cuenta «Lehigh Agents» (rol Autor) con su contraseña de aplicación y te muestra dos líneas para copiar o un `.env` para descargar.

### 3 · Instala y configura los agentes con el asistente
```bash
cd agents
./install.sh          # Windows: install.bat
```
Crea el entorno de Python, instala las dependencias y arranca **`python main.py setup`**, que:

* te pide **solo la clave de Gemini** (https://aistudio.google.com/apikey) y la valida;
* **elige automáticamente el mejor modelo Gemini disponible** (así no importa que `gemini-1.5-flash` ya no exista);
* busca tu sitio y confirma que el plugin está activo; para la contraseña de aplicación **reutiliza la del paso 2** si ya está en el `.env`, o abre WordPress para que la apruebes: en un sitio local el retorno es automático, y en un sitio público WordPress te muestra la contraseña en pantalla y la pegas en el asistente (WordPress no permite el retorno automático por `http://` fuera de entornos locales);
* te deja elegir imágenes con IA (Nano Banana reutiliza tu clave de Gemini);
* escribe el `.env` (permisos solo para tu usuario) y ejecuta la comprobación final.

Después:
```bash
python main.py run --dry-run   # prueba completa sin escribir en WordPress
./start.sh                      # funcionamiento continuo, controlado desde WordPress (start.bat en Windows)
```
Para dejarlo corriendo como servicio (siempre conectado al botón de WordPress): `agents/Dockerfile` + `docker-compose.yml`, o `agents/deploy/lehigh-agents.service` (systemd).

> ¿Prefieres hacerlo a mano? `cp .env.example .env`, rellénalo y usa `python main.py check`. Todo el asistente también funciona sin preguntas con `python main.py setup --non-interactive --gemini-key … --site …`.

### 4 · Enciéndelos y pulsa «Iniciar a trabajar»
```bash
./start.sh        # Windows: start.bat
```
Los agentes **se conectan solos a tu WordPress** (con las credenciales del `.env`) y quedan esperando tus órdenes. En **News Hub → Panel** (y en *Actividad de los agentes*) verás una tarjeta de control:

| Botón | Qué hace |
|---|---|
| **▶ Iniciar a trabajar** | Los agentes empiezan de inmediato y repiten el ciclo cada *N* minutos (*Ajustes → Ejecutar cada*). |
| **⏸ Pausar** | Terminan el artículo que tienen en curso (nunca lo cortan a la mitad) y se detienen hasta que vuelvas a iniciar. |
| **⚡ Ejecutar ahora** | Lanza un ciclo ya, sin esperar el intervalo. |

Mientras trabajan verás una **animación**: los cuatro agentes como estaciones de una línea de producción; el que está ocupado se rodea con el anillo de colores del logo, los terminados se marcan con ✓ y por las conexiones viajan «paquetes» de datos (respeta `prefers-reduced-motion`). La tarjeta muestra en vivo si los agentes están conectados, qué están haciendo en este momento («redactor: Escribiendo…») y cuándo es el próximo ciclo. **Hasta que pulses Iniciar, no se hace ninguna llamada de pago**: es el estado por defecto. Los agentes son quienes llaman a WordPress (nunca al revés), así que funcionan detrás de un router o en un computador sin IP pública. WordPress no puede ejecutar Python por sí mismo: el programa `start.sh` / Docker / systemd tiene que estar encendido en algún computador o servidor. Sin el plugin, o con `python main.py watch --no-control`, los agentes trabajan solos cada `LOOP_INTERVAL_MINUTES`.

### 5 · Usa el plugin
* **News Hub → Cola de revisión**: ve las noticias que esperan tu decisión, con su nota de auditoría.
* **Revisar**: vista previa, notas del auditor, fuente, imagen (con el prompt que escribió el Redactor) y la línea de tiempo de lo que hizo cada agente. Botones: *Publicar ahora*, *Programar*, *Rechazar*.
* **Actividad de los agentes**: tarjetas por agente, embudo de 7 días, feed en vivo y detalle de cada ejecución.
* **Kit social** (en la pantalla de revisión): carruseles de Instagram y TikTok, video, pies de foto con hashtags; descarga y publica donde quieras.
* **Constructor de shortcode**: diseña la lista de últimas noticias con vista previa en vivo, cópiala o guárdala como preajuste.

## Variables de entorno (`agents/.env`)

| Variable | Para qué |
|---|---|
| `GEMINI_API_KEY` | Agentes 1 y 3 (y 2 si usa Gemini) |
| `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` | Solo si `REDACCTOR_MODEL` es `claude-*` o `gpt-*` |
| `WP_REST_URL`, `WP_AUTH_TOKEN` | Sitio (`https://tu-sitio.com` o `…/wp-json`) y `usuario:contraseña de aplicación` (o token Bearer) |
| `RASTREADOR_MODEL`, `REDACCTOR_MODEL`, `AUDITOR_MODEL` | Modelos (por defecto `gemini-1.5-flash`) |
| `IMAGE_API_KEY`, `IMAGE_API_ENDPOINT`, `IMAGE_PROVIDER`, `IMAGE_MODEL` | Generación de imágenes (opcional) |
| `MEDIASTACK_API_KEY` | Fuente de noticias extra opcional ([Mediastack](https://mediastack.com/signup), plan gratuito de 100 consultas/mes; el proyecto se limita solo) |
| `SOCIAL_ENABLED`, `SOCIAL_FORMATS`, `SOCIAL_VIDEO`, `SOCIAL_THEME`, `SOCIAL_VOICE`, `SOCIAL_WEBHOOK_URL`, `BRAND_*` | Agente 4 y marca (ver `.env.example`) |
| `AUDIT_MIN_SCORE`, `MAX_REVISIONS`, `POST_FLAGGED`, … | Comportamiento (ver `.env.example`) |

> ⚠️ **Modelos:** Google retiró la familia Gemini 1.5 de la API pública. El valor por defecto sigue siendo `gemini-1.5-flash` como pediste, pero `python main.py check` te avisará si no está disponible; en ese caso pon `gemini-2.5-flash` (o el que prefieras) en las tres variables de modelo.

## Claude Code y GitHub

Todo lo necesario está en este repositorio:

| Qué | Dónde | Cómo se usa |
|---|---|---|
| **Plugin de Claude Code** (skills + subagentes) | `plugins/lehigh-news-hub/` | `/plugin marketplace add muonunziata/skill` y luego `/plugin install lehigh-news-hub --marketplace muonunziata/skill` |
| **Skills** | `lehigh-news-hub` (mapa del proyecto), `brand-guide` (marca), `social-carousel`, `social-video`, `news-audit`, `check-setup`, y de uso manual `run-pipeline` y `social-kit` | `/lehigh-news-hub:social-kit --latest 1` (con plugin) o `/social-kit --latest 1` (en el proyecto) |
| **Subagentes** | `news-researcher`, `news-writer`, `news-auditor`, `social-designer` | Claude los usa solo o con «usa el subagente social-designer…» |
| **Memoria del proyecto** | `CLAUDE.md` | Reglas de marca, anti-alucinación y comandos |
| **Copia para sesiones web/nube** | `.claude/skills`, `.claude/agents` | Las sesiones de Claude Code sobre este repo los cargan solas; `scripts/sync-claude.sh` los mantiene idénticos al plugin (la CI lo comprueba) |
| **CI** | `.github/workflows/ci.yml` | Pruebas Python (con Chromium), sintaxis PHP, traducciones al día, validación del plugin/marketplace |
| **Agentes en GitHub (opcional)** | `.github/workflows/agents.yml` | Se ejecuta a mano desde *Actions* (por defecto en modo prueba) o cada 6 h si defines la variable `AGENTS_ENABLED=true`. Secretos necesarios: `GEMINI_API_KEY`, `WP_REST_URL`, `WP_AUTH_TOKEN` (+ opcionales). |

La marca está documentada en [`brand/BRAND.md`](brand/BRAND.md) y las fuentes de referencia en [`docs/RECURSOS.md`](docs/RECURSOS.md).

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
cd agents && pip install -r requirements-dev.txt && python -m playwright install chromium && python -m pytest

# Plugin dentro de un WordPress de pruebas (¡no en producción!)
php wordpress/tests/run.php /ruta/a/wordpress
```

## Estructura

```
agents/                      Agentes en Python (Gemini · Claude · OpenAI · imágenes)
  lehigh_agents/agents/        rastreador.py · redactor.py · auditor.py
  lehigh_agents/               llm.py · imagegen.py · pipeline.py · wordpress.py · checks.py · net.py …
  lehigh_agents/social/        Agente 4: plan · plantillas · render · video · voz · marca
  tests/                       114 pruebas (las de navegador/ffmpeg se saltan si no están)
wordpress/lehigh-news-hub/   Plugin de WordPress (instalable)
wordpress/tests/run.php      139 comprobaciones dentro de WordPress
wordpress/tools/             Extracción de cadenas y compilación de traducciones
brand/                       Logos oficiales de GoLehighAcres.org y guía de marca
plugins/lehigh-news-hub/     Plugin de Claude Code: skills y subagentes
.claude/ · CLAUDE.md         Los mismos skills/subagentes para sesiones del proyecto (web/nube)
.claude-plugin/             Marketplace de Claude Code
.github/workflows/           CI y ejecución opcional de los agentes
scripts/build-release.sh     Genera los .zip descargables
scripts/sync-claude.sh       Sincroniza .claude/ con el plugin
docs/RECURSOS.md             Fuentes y enlaces de referencia
```

## Costos
Cada ejecución usa Gemini (búsqueda con grounding, lectura estructurada, redacción, auditoría) más, si lo activas, una imagen. El panel de actividad muestra los tokens usados. Ajusta `MAX_ITEMS_PER_RUN` y `LOOP_INTERVAL_MINUTES` para controlar el gasto.
