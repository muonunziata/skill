# GoLehighAcres.org — guía de traspaso

Resumen de todo lo construido, por qué se decidió así y cómo continuarlo en local. Escrito para que cualquiera pueda retomar el trabajo sin haber visto la conversación.

---

## 1. Qué es el proyecto

**GoLehighAcres.org** es un portal local sobre Lehigh Acres (Florida): directorio de comercios, mercado, empleo, noticias y eventos, urbanismo/futuro y un canal de WhatsApp. Nace de la propuesta de inversión `GoLehighAcres_Propuesta_Inversion_Monetizacion.pptx` (tráfico → confianza → datos → monetización → referidos inmobiliarios).

Lo que se construyó en esta sesión:

1. **Dos páginas HTML completas** (diseño y comportamiento): *Explore* (inicio) y *News & Events* (pared de videos).
2. **Un plugin de WordPress independiente, «GoLehighAcres Blocks»**, que convierte cada sección en un **bloque editable con su propio editor**. Desde la 1.2.0 ya no necesita VeloxForge; el módulo anterior para VeloxForge (1.1.1) queda en `legacy/`.
3. Versiones previas en español (referencia).

Todo el sitio quedó **en inglés**, con fondo blanco y la identidad del logo.

---

## 2. Contenido del zip

```
GUIA-DE-TRASPASO.md              este documento
LEEME.txt                        resumen de una pantalla
paginas/
  golehighacres-home.html        Explore (inicio)                    ← versión vigente
  golehighacres-menu.html        News & Events (pared de videos)     ← versión vigente
  golehighacres-immersion.html   primera página inmersiva (español)  ← solo referencia
  golehighacres-feature.html     versión editorial (español)         ← solo referencia
  golehighacres.html             primera página de la propuesta      ← solo referencia
golehighacres-blocks-1.2.0.zip   plugin listo para instalar en WordPress (no necesita VeloxForge)
golehighacres-blocks/            código fuente del plugin + README.md y CHANGELOG.md propios
  media/                         (dentro de paginas/) video de fondo y su imagen fija
    fondo-azul.mp4               animado, 1280x720, 1,4 MB, sin audio
    fondo-azul-poster.jpg        imagen fija del fondo
    torre-agua.jpg               PROVISIONAL (recorte de tu captura, 584 px); reemplazar por el original
referencias/
  logo-simbolo.png               símbolo del logo (verde + multicolor)
  logo-nombre.png                «GOLehighAcres.org» en su tipografía
dev/
  README.md, smoke-pages.mjs     pruebas automáticas de las páginas
  release.sh                     publica una versión nueva del plugin
  ensamblar.py                   arma páginas abribles (herramienta antigua)
legacy/                          módulo antiguo para VeloxForge (1.1.1), su zip y su banco de pruebas
```

Las páginas se abren con doble clic (cada una busca su video en `paginas/media/`; si mueves una página, mueve también esa carpeta). Los enlaces del menú son marcadores (`#directory`, `#jobs`…): no saltan entre páginas porque se publicaron por separado.

---

## 3. Identidad de marca

| Elemento | Valor |
|---|---|
| Tinta (texto) | `#14181A` |
| Gris de apoyo | `#66706A` |
| Línea fina | `#E3E7E0` |
| Dorado (marco, acentos, cursor) | `#E3A92B` |
| **Coral** («GO» del logo, botón de WhatsApp, énfasis) | `#FF5A5F` |
| Verde profundo (sección oscura, tarjeta fija) | `#0B2F22` |
| Paleta del logo para recuadros y categorías | `#2E7D55` `#CC7A3A` `#2B5BA8` `#7A4A9A` `#C99A22` `#2B8ED0` (+ `#5C6FB6` `#A4D19B` `#0F5A3C`) |
| Títulos y negritas | **Poppins** 600/700 (la más parecida a la del logo; no es idéntica) |
| Texto normal | **Arial** (con Helvetica/Arimo de respaldo) |
| Fondo | Blanco puro |

El nombre se escribe como texto: `GO` en coral + `LehighAcres` + `.org`, junto al símbolo del logo (imagen en `referencias/`).

---

## 4. Páginas HTML

### 4.1 Explore (`golehighacres-home.html`)

Secciones, de arriba hacia abajo (la numeración de fotos está en la sección 7):

1. **Cabecera** fija: logo + secciones (*Explore, Directory, Marketplace, Jobs, News & Events, The Future, For Businesses*) + botón **Join on WhatsApp**. En menos de 1180 px pasa a menú hamburguesa.
2. **Portada** a pantalla completa con foto, titular y botón circular **START**.
3. **Carrusel 3D** a todo el ancho: gira solo, se arrastra, flechas y pausa.
4. **Ciudad y cifras**: frase + 3 cifras que se animan (en celular siempre en una línea).
5. **Directorio** (*Recently added*): buscador, **menú desplegable** de 18 categorías, tabla de novedades, tarjetas por categoría y resultados agrupados por categoría.
6. **Perfil de negocio**: al hacer click en un negocio se abre su perfil (ventana emergente en escritorio, pantalla completa en celular): logo, descripción, galería de fotos, botones **Open in Google Maps** y **Get directions** (con la dirección), teléfono, correo, web, horario, etiquetas, redes y aviso de ejemplo.
7. **Recorrido** («From discovery to decision») con tarjeta fija que cambia al avanzar.
8. **Negocios fundadores**: texto centrado y una columna de fotos espejo a cada lado (simétrico).
9. **Cita** a pantalla completa y **cinta** de palabras que se desplaza.
10. **Pie** con la nota de transparencia.

**Fondo de video y capas (octubre):** el video `fondo_azup.mp4` es un fondo animado de formas azul claro (no es la torre). Va fijo a pantalla completa detrás de toda la página (`.bgv`, `object-fit: cover`: nunca se ve recortado ni con bandas). Cada sección lleva una capa translúcida al **5 %** de un color distinto (`.tint-1` … `.tint-6`) con borde fino para delimitarla; cabecera, tabla y resultados son paneles blancos translúcidos para mantener la lectura. Con «reducir movimiento» o ahorro de datos solo se muestra la imagen fija.

**Héroe con punto focal:** si existe `paginas/media/torre-agua.jpg`, el héroe la usa y centra el punto indicado en `data-fx` / `data-fy` (0–1) en cualquier pantalla; si no hay margen, el hueco se rellena con una copia difuminada. Verificado midiendo la posición de una marca en 1440, 1920, 768 y 390 px.

**Responsive (celular):** botón START fuera del texto, tabla sin corte (la columna «Area» se oculta), pasos del recorrido legibles, tarjeta del recorrido compacta, sin movimiento de columnas, buscador con texto corto, botones a todo el ancho en pantallas muy angostas.

Extras: guía lateral con número y nombre de sección, cursor propio que se vuelve un **«GO!» pequeño y semitransparente** sobre enlaces.

### 4.2 News & Events (`golehighacres-menu.html`)

Inspirada en la pared de videos de la referencia (`Recording_…022053.mp4`):

- **No hay scroll de página**: la pared ocupa toda la pantalla y se mueve **arrastrando en cualquier dirección** (también rueda, trackpad, flechas del teclado y dedo).
- **Infinita**: las filas dan la vuelta; 6 filas × 11 columnas, cada fila simétrica (patrón vertical/horizontal alternado y palíndromo).
- **Movimiento solo** cuando nadie la toca (~2 s), con dirección que cambia suavemente.
- **Cursor** propio: círculo con **flechas arriba/abajo/izquierda/derecha** («puedes ir a todos lados»); sobre un video, un **GO!** pequeño semitransparente.
- **Vista previa**: pasar el mouse sobre un video lo reproduce 10 s **sin sonido**.
- **Ventana emergente** al hacer click: reproduce con sonido y controles (vertical 9:16, horizontal 16:9). Esc, X o click fuera para cerrar.
- Marco dorado y fondo blanco; los recuadros son de color y están numerados.

Para poner videos reales, editar el arreglo `TILES` al inicio del script: `video` (URL del archivo) e `img` (portada) por recuadro. Las categorías y sus colores están en `CATS` / `TAGC`.

---

## 5. Plugin «GoLehighAcres Blocks» (`golehighacres-blocks`)

### 5.1 Por qué es independiente

Hasta la 1.1.1 era un módulo de **VeloxForge** (un constructor visual para WordPress): los bloques se registraban con `VF_Builder_Widgets::register` y se editaban en el constructor de VeloxForge. En la 1.2.0 se separó por completo: el plugin trae su **propio registro de bloques, su propio editor y su propio guardado**. VeloxForge ya no hace falta para estas páginas.

### 5.2 Cómo está hecho

| Parte | Qué hace |
|---|---|
| `core/` | Núcleo **sin WordPress**: `GLH_Registry` (registrar bloques, esquema de campos, saneado, CSS por escritorio/tablet/celular, dibujado), los 12 bloques, las plantillas y `compat.php` (funciones mínimas cuando no hay WordPress). Se carga con `core/load.php` desde cualquier sistema PHP. |
| `wordpress/` | Adaptador de WordPress: guarda los bloques en el campo `_glh_blocks` de la página (JSON `[{id, type, settings}]`), muestra la página (pantalla completa o dentro del tema), códigos cortos, menú **GoLehighAcres** y el editor (`admin.js` / `admin.css`). |
| `assets/` | `glh.css`, `glh.js`, logo, video azul de fondo y foto de la torre (provisional). |

El editor está en **GoLehighAcres → Páginas** o en el enlace **«Editar con GoLehighAcres»** de cada página. Tiene una lista de bloques, un formulario por bloque (Contenido / Estilo / Bloque), valores por tamaño de pantalla, biblioteca de medios, repetidores y una **vista previa en vivo** que muestra los cambios antes de guardar. Los recursos (`glh.css`, `glh.js`, Poppins) solo se cargan en páginas que usan bloques; el filtro `glh_google_fonts` desactiva la fuente externa.

### 5.3 Bloques

`Fondo de video de toda la página`, `Cabecera`, `Portada`, `Carrusel 3D`, `Ciudad y cifras`, `Directorio con buscador`, `Perfil de negocio`, `Recorrido`, `Negocios fundadores`, `Cita`, `Cinta de palabras`, `Pared de videos infinita`. Cada uno trae sus textos en inglés por defecto y todo se edita desde el panel. La pestaña **Bloque** de cada uno da fondo, capa de color al 5 %, ancho máximo, espacios, ancla, ocultar en celular o escritorio, clases y CSS propio.

- Los colores y tamaños se aplican como **variables CSS** del bloque (`--glh-*`).
- **Fotos marcadas**: mientras no hay imagen, un recuadro de color con su número (`FOTO 01`…).
- **Directorio**: 18 categorías con sus tipos (editables); sin «Negocios reales», se generan ejemplos marcados *Sample*.
- **Perfil de negocio**: el mismo diseño de la ventana emergente, en una página, con datos estructurados `LocalBusiness`.
- **Plantillas**: *Explore* (inicio, con capas de color al 5 % y la torre centrada), *News & Events* y *Perfil de negocio*.

Detalle completo en `golehighacres-blocks/README.md`.

### 5.4 Cómo se validó

- `tests/core-test.php`: el núcleo **sin WordPress** dibuja los 12 bloques y las 3 plantillas y sanea bien (CSS propio sin etiquetas, opciones no válidas descartadas, números acotados).
- `tests/wp-e2e.mjs` en un **WordPress 6.5 real** (de prueba, con SQLite) y Chromium, 19 de 19 comprobaciones:
  - al activarlo desactiva el módulo antiguo;
  - crea *Explore* desde la plantilla;
  - el editor muestra los cambios en la vista previa sin guardar;
  - se editan números por dispositivo y la capa de color, se añade un elemento a un repetidor, y se publica;
  - la página publicada sale a pantalla completa con sus 9 bloques en escritorio y celular, sin scroll horizontal y sin rastro de VeloxForge;
  - los códigos cortos funcionan dentro del tema.
  No quedó ningún aviso de PHP del plugin.
- En esa prueba apareció un fallo que también estaba en la 1.1.x: el relleno difuminado tapaba la foto de la portada. Quedó corregido.
- **Falta**: probarlo en el sitio real (su tema, sus plugins de caché).

---

## 6. Línea de tiempo de decisiones

| # | Qué se pidió / decidió |
|---|---|
| 1 | Skills y plugins para diseño inmersivo; se sugirieron *Design* y *Superdesign*; se leyó la propuesta `.pptx` |
| 2 | Primera página inmersiva (3D + scroll) y después una versión editorial |
| 3 | Referencia **imersion.io** (analizada por video): carrusel, globo, tabla, línea de tiempo, columnas, cita, cinta |
| 4 | Fotos como **recuadros numerados** para reemplazarlas luego |
| 5 | Carrusel «Ahora en GoLehighAcres» a todo el ancho; **Founding businesses simétrico**; se quitó el globo |
| 6 | Menú de videos inspirado en la referencia `…022053.mp4`: pared arrastrable, **infinita**, 6 filas simétricas, **sin scroll de página** |
| 7 | Movimiento aleatorio en reposo; cursor propio; **vista previa de 10 s sin sonido**; ventana emergente al click |
| 8 | **Fondo blanco** total; toda la página **en inglés**; logo real; **Poppins + Arial** |
| 9 | Header con las secciones del proyecto; *News & Events* = la pared de videos; *Explore* = el home |
| 10 | Directorio: **buscador + categorías + menú desplegable** (18 categorías de negocio) |
| 11 | Cursor «**GO!**» rojo → pequeño y semitransparente; cursor de la pared con **flechas en 4 direcciones** |
| 12 | Plugin de **VeloxForge** con todo editable |
| 13 | **Perfil de negocio** con logo, descripción, galería, contacto, horario y **Google Maps** |
| 14 | **Video de fondo** de toda la página, **capas al 5 %** por sección y **optimización responsive**; héroe con **punto focal** para centrar la torre de agua |
| 15 | Versiones numeradas (1.1.1, 1.1.2…) con historial y script de publicación |
| 16 | **Separarlo de VeloxForge**: plugin independiente 1.2.0 con editor propio y núcleo sin WordPress |

Errores encontrados y corregidos durante las pruebas: el click sobre un video no abría el reproductor (el foco recentraba la pared; ahora solo recentra con teclado); el botón de WhatsApp se cortaba a 1280 px; un parche duplicó código y se restauró desde el último commit; las cifras de la ciudad se cortaban en celular.

---

## 7. Numeración de fotos (páginas HTML)

| Fotos | Dónde |
|---|---|
| `FOTO 01` | Portada |
| `FOTO 02`–`07` | Carrusel 3D |
| `FOTO 08`–`11` | Miniaturas de «Recently added» |
| `FOTO 12`–`16` | Tarjeta fija del recorrido (una por etapa) |
| `FOTO 17`–`24` | Columnas de negocios fundadores (17–20 izquierda, 21–24 derecha) |
| `FOTO 25` | Fondo de la cita |
| `VIDEO 01`–`66` | Pared de videos (6 filas × 11) |

El plugin repite esta numeración (cada bloque tiene su «Primer número de foto»).

---

## 8. Cómo continuar en local

### 8.1 Solo las páginas HTML

1. Abre `paginas/golehighacres-home.html` y `golehighacres-menu.html` en el navegador.
2. Para cambiar textos y estilos, edita el HTML (CSS en `<style>`, JS al final). Variables de color y tipografía al inicio de `:root`.
3. Para fotos reales: reemplaza los `<div class="ph …">` por `<img>` (o usa el plugin). Para la pared, rellena `TILES`.
4. Pruebas: `cd dev && npm i -D playwright && npx playwright install chromium && PAGES=../paginas node smoke-pages.mjs`.

### 8.2 Con WordPress (recomendado para el sitio final)

1. Crea un sitio local (LocalWP, wp-env, Docker…).
2. Instala y activa `golehighacres-blocks-1.2.0.zip` (no hace falta VeloxForge).
3. **GoLehighAcres → Páginas**: crea *Explore* desde su plantilla y otra con *News & Events*. Para que *Explore* sea la portada: **Ajustes → Lectura → Una página estática**.
4. Directorio real: carga los negocios en «Negocios reales» del bloque *Directorio* (o crea una página por negocio con la plantilla *Perfil de negocio*).
5. Pruebas: `php golehighacres-blocks/tests/core-test.php` y `node golehighacres-blocks/tests/wp-e2e.mjs URL usuario contraseña` (ver `dev/README.md`).

### 8.3 Versionado y empaquetado del plugin

Cada cambio sube el último número (1.2.0 → 1.2.1 → 1.2.2…) y queda anotado en `golehighacres-blocks/CHANGELOG.md`. Para publicar una versión nueva (desde la raíz del proyecto):

```
dev/release.sh 1.2.1 "Qué cambió"
```

Sube el número en la cabecera del plugin y en `GLH_VER`, añade la nota al historial, ejecuta la prueba del núcleo y genera `dist/golehighacres-blocks-1.2.1.zip` (más una copia sin número, `dist/golehighacres-blocks.zip`). La versión se ve en **Plugins**, en el menú **GoLehighAcres** y en la barra del editor.

> **Versión del plugin: 1.2.0.** Si WordPress sigue mostrando algo viejo, es caché: vacía la del plugin de caché y la de Cloudflare.

## 9. Pendientes sugeridos

**Contenido**
- **Imagen original de la torre de agua** (hoy hay un recorte provisional de 584 px sacado de una captura: queda centrada, pero se ve de baja resolución) (sin recortar, p. ej. `torre-agua.jpg`) en `paginas/media/`, y ajustar `data-fx` al centro de la torre (fracción del ancho de la imagen). Solo se tenía un recorte dentro de una captura, así que el centrado está listo pero sin la foto real.
- Fotos y videos reales; logo en alta; textos finales de cada sección.
- Directorio real (nombres, direcciones, teléfonos, horarios, fotos). Hoy todo es de ejemplo y está marcado *Sample*.
- URL real del canal de WhatsApp (los botones apuntan a `#whatsapp`).

**Estructura**
- Conectar las páginas entre sí (los enlaces del menú son marcadores).
- Páginas de *Marketplace, Jobs, The Future, For Businesses*.
- Decidir si cada negocio tendrá página propia (bloque *Perfil de negocio*) o solo ventana emergente.
- Mapa incrustado en el perfil (en WordPress sí se puede; en las páginas HTML publicadas no).
- Pasar el directorio a un tipo de contenido propio de WordPress si crece (hoy los negocios viven dentro del bloque).

**Calidad**
- Probar el plugin en el sitio real (su tema y sus plugins de caché).
- Adaptadores para **Wakoo** y **PrimeBase**: el núcleo (`core/`) ya no depende de WordPress; falta saber cómo guardan y muestran páginas esos sistemas.
- Revisión de accesibilidad con lector de pantalla y de rendimiento (Lighthouse).
- En táctil, la pared solo desplaza horizontalmente por defecto (el scroll vertical es de la página); revisar si se quiere pantalla completa con captura total.

**Legal y transparencia (importante)**
- La propuesta habla de presentar el portal como «guía oficial» y como «caballo de Troya» publicitario. **No se hizo así**: el sitio dice que es un **proyecto independiente, sin vínculo con el gobierno del condado**, y marca los proyectos de vivienda y la publicidad como tal, nombrando a la empresa patrocinadora. Conviene mantener esa transparencia y revisarla con asesoría legal (divulgación publicitaria en EE. UU./Florida).

---

## 10. Referencias de la sesión

- Repositorio: `muonunziata/skill`, rama `claude/skills-installation-gamho8`.
- Páginas publicadas (privadas, solo con tu cuenta): Explore `https://claude.ai/artifact/My8os9Nbb2H7K5PsK9r4A8` · News & Events `https://claude.ai/artifact/TkeW8aFMeuWDpi8gDayWUN`.
- Referencias de diseño analizadas: `imersion.io` (por video; el dominio estaba bloqueado en el entorno) y la pared de videos del segundo video. `bright-avenue.jp` no pudo abrirse: la página editorial es una interpretación de ese estilo.
