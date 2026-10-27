# GoLehighAcres Blocks

**Versión actual: 1.2.0**

Las secciones de GoLehighAcres.org como bloques editables, con su **propio editor**. No necesita VeloxForge, Elementor ni ningún otro constructor: solo WordPress 6.0+ y PHP 7.4+.

## Instalar o actualizar

1. WordPress → **Plugins → Añadir nuevo → Subir plugin** → elige `golehighacres-blocks-1.2.0.zip` → **Instalar ahora**.
   Si ya estaba instalado, WordPress pregunta: elige **«Reemplazar el actual con el subido»**.
2. **Activar**. Si tenías el módulo antiguo «VeloxForge · GoLehighAcres», se desactiva solo (puedes borrarlo después; VeloxForge ya no hace falta para estas páginas).
3. Si usas un plugin de caché o Cloudflare, vacía la caché.

## Crear y editar páginas

- Menú **GoLehighAcres** → escribe un título, elige una plantilla (*Explore*, *News & Events* o *Perfil de negocio*) → **Crear y editar**.
- En **Páginas**, cualquier página tiene el enlace **«Editar con GoLehighAcres»**.
- En el editor:
  - izquierda: los bloques (clic para editarlo; ↑ ↓ ordenan, ⧉ duplica, ✕ elimina), **Añadir bloque** y **Cargar una plantilla**;
  - dentro de un bloque: pestañas **Contenido**, **Estilo** y **Bloque** (fondo, capa de color al 5 %, ancho, espacios, ancla, ocultar en celular o escritorio, CSS propio);
  - los números con 🖥 💻 📱 tienen un valor para cada tamaño de pantalla;
  - arriba: vista de escritorio, tablet o celular; **Guardar**, **Publicar** y **Ver página**.
  - La vista previa se actualiza sola mientras escribes; nada se publica hasta que guardas.
- **Pantalla completa** (activado por defecto): la página muestra solo los bloques, sin la cabecera ni el pie del tema (los bloques traen su propia cabecera).
- Para que sea la portada del sitio: **Ajustes → Lectura → Una página estática** → elige la página.

## Insertar en otra página

- `[glh_page id="12"]` → todos los bloques de la página 12.
- `[glh_block type="glh-stats"]` → un bloque suelto con su contenido por defecto.

## Bloques

| Bloque | Qué es |
|---|---|
| `glh-pagebg` | Fondo de video de toda la página (incluye el video azul de la marca) |
| `glh-header` | Logo, menú y botón de WhatsApp |
| `glh-hero` | Portada a pantalla completa con punto focal (la torre queda centrada) |
| `glh-carousel` | Carrusel 3D giratorio |
| `glh-stats` | Texto y cifras animadas |
| `glh-directory` | Buscador, categorías y tabla de novedades |
| `glh-business` | Perfil de negocio (galería, contacto, horario, mapa, datos para Google) |
| `glh-journey` | Recorrido con tarjeta fija |
| `glh-founding` | Negocios fundadores (simétrico) |
| `glh-quote` | Cita a pantalla completa |
| `glh-marquee` | Cinta de palabras |
| `glh-videowall` | Pared de videos infinita (News & Events) |

## Estructura

```
golehighacres-blocks.php   arranque en WordPress
core/                      núcleo SIN WordPress: registro de bloques, saneado, estilos y dibujado
  load.php                 cargar el núcleo desde cualquier sistema PHP
  class-registry.php       GLH_Registry: register(), schema(), sanitize_settings(), render(), render_list()
  blocks.php, business.php los 12 bloques
  pages.php                plantillas de página
  compat.php               funciones mínimas (esc_html, esc_url…) cuando no hay WordPress
wordpress/                 adaptador de WordPress: guardado, sitio, editor (admin.js / admin.css), pantalla completa
assets/                    glh.css, glh.js, logo, video de fondo y foto de la torre
tests/                     core-test.php (sin WordPress) y wp-e2e.mjs (WordPress real con navegador)
```

### Usar el núcleo en otro sistema (Wakoo, PrimeBase…)

```php
define( 'GLH_DIR', '/ruta/a/golehighacres-blocks/' );
define( 'GLH_URL', 'https://tu-sitio.com/golehighacres-blocks/' );
require GLH_DIR . 'core/load.php';

echo GLH::asset_tags( GLH_URL );                       // <link> y <script> de los bloques
echo GLH_Registry::render_list( $bloques );            // [ [ 'id' => 'a1', 'type' => 'glh-hero', 'settings' => [ … ] ], … ]
$esquema = GLH_Registry::schema();                     // campos de cada bloque, para construir un editor
$limpio  = GLH_Registry::sanitize_settings( 'glh-hero', $datos_del_formulario );
```

Los datos de una página son una lista JSON `[{ id, type, settings }]`. En WordPress se guarda en el campo `_glh_blocks` de la página.

## Pruebas

```
php tests/core-test.php                                            # núcleo, sin WordPress
node tests/wp-e2e.mjs http://localhost:8080 usuario contraseña     # WordPress real (necesita Playwright)
```

## Para desarrolladores

- Filtros: `glh_post_types` (tipos de contenido con editor, por defecto `page`), `glh_pages` (plantillas), `glh_google_fonts` (false = no cargar Poppins desde Google).
- Acción `glh_register_blocks`: registra bloques propios con `GLH_Registry::register( 'slug', [ 'title', 'icon', 'controls', 'render' ] )`.
- Publicar una versión: `dev/release.sh 1.2.1 "Qué cambió"` (sube el número, anota el cambio y genera el zip en `dist/`).
