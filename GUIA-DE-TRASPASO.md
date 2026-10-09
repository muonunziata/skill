# GoLehighAcres.org — guía de traspaso

Resumen de todo lo construido, por qué se decidió así y cómo continuarlo en local. Escrito para que cualquiera pueda retomar el trabajo sin haber visto la conversación.

---

## 1. Qué es el proyecto

**GoLehighAcres.org** es un portal local sobre Lehigh Acres (Florida): directorio de comercios, mercado, empleo, noticias y eventos, urbanismo/futuro y un canal de WhatsApp. Nace de la propuesta de inversión `GoLehighAcres_Propuesta_Inversion_Monetizacion.pptx` (tráfico → confianza → datos → monetización → referidos inmobiliarios).

Lo que se construyó en esta sesión:

1. **Dos páginas HTML completas** (diseño y comportamiento): *Explore* (inicio) y *News & Events* (pared de videos).
2. **Un plugin de VeloxForge** que convierte cada sección en un **bloque editable del constructor visual**.
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
veloxforge-golehighacres.zip     plugin listo para instalar en WordPress
veloxforge-golehighacres/        código fuente del plugin + README.md propio
referencias/
  logo-simbolo.png               símbolo del logo (verde + multicolor)
  logo-nombre.png                «GOLehighAcres.org» en su tipografía
dev/
  README.md, smoke-pages.mjs     pruebas automáticas de las páginas
  harness-veloxforge.php         valida el plugin con las clases reales de VeloxForge
  ensamblar.py                   arma páginas abribles a partir de lo que genera el arnés
```

Las páginas se abren con doble clic. Los enlaces del menú son marcadores (`#directory`, `#jobs`…): no saltan entre páginas porque se publicaron por separado.

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

## 5. Plugin de VeloxForge (`veloxforge-golehighacres`)

### 5.1 Qué es VeloxForge (lo que se averiguó leyendo el código)

Plugin de WordPress (PHP 7.4+, v1.3.0) modular: formularios, templates, popups, flotantes y un **constructor visual** independiente de Elementor. El constructor guarda un árbol de contenedores y bloques; cada bloque se registra con `VF_Builder_Widgets::register( slug, [title, icon, cat, controls, render] )` y cada control puede mapear a CSS (`css => [[selector, propiedad, sufijo]]`). El sistema de diseño expone variables `--vfb-c-*` y `--vfb-font-*`. El `veloxforge-hub` es el servidor de licencias/actualizaciones: **no se tocó**.

### 5.2 Cómo se integra (sin modificar VeloxForge)

| Gancho | Para qué |
|---|---|
| `veloxforge_register_modules` | registra el módulo **GoLehighAcres** (se enciende/apaga en VeloxForge → Módulos) |
| `veloxforge_builder_register_widgets` | registra los bloques |
| `vf_builder_presets` | añade secciones y páginas completas a la biblioteca |

El plugin se engancha en `plugins_loaded` con prioridad 10 (VeloxForge carga sus módulos en la 20). Los recursos (`glh.css`, `glh.js`, Poppins de Google Fonts) solo se cargan en páginas que usan un bloque; `glh_google_fonts` (filtro) desactiva la fuente externa.

### 5.3 Bloques

`Cabecera`, `Portada`, `Carrusel 3D`, `Ciudad y cifras`, `Directorio con buscador`, `Perfil de negocio`, `Recorrido`, `Negocios fundadores`, `Cita`, `Cinta de palabras`, `Pared de videos infinita`. Cada uno trae sus textos en inglés por defecto y todo se edita desde el panel, además de los controles comunes de VeloxForge (fondo, espacios, bordes, sombra, animación de entrada, visibilidad por dispositivo, condiciones, CSS propio).

- Los colores y tamaños se aplican como **variables CSS** del bloque (`--glh-*`): cambian desde el panel sin tocar código.
- **Fotos marcadas**: mientras no hay imagen, un recuadro de color con su número (`FOTO 01`…). «Primer número de foto» y «Rótulo» son editables por bloque.
- **Directorio**: 18 categorías con sus tipos (editables). Sin «Negocios reales», se generan ejemplos (uno por tipo, con etiqueta *Sample*). Cada negocio real tiene los campos del perfil y hasta 6 fotos.
- **Perfil de negocio** como módulo propio: el mismo diseño de la ventana emergente, en una página, con datos estructurados `LocalBusiness`.
- **Pared de videos**: filas/columnas, movimiento solo, segundos de vista previa, ventana emergente, cursor y colores, todo desde el panel.
- **Biblioteca**: una entrada por bloque + 2 páginas completas (*Explore* y *News & Events*).
- Al activar por primera vez, si el sitio no tenía sistema de diseño guardado, se cargan los colores y tipografías de la marca.

Detalle completo en `veloxforge-golehighacres/README.md`.

### 5.4 Cómo se validó (y qué falta)

- Con las **clases reales** de VeloxForge 1.3.0 sobre funciones de WordPress simuladas: 0 problemas en valores por defecto de controles; las 13 secciones de la biblioteca se sanean, generan CSS y renderizan.
- En Chromium: numeración de fotos, menú desplegable, perfil (negocio real y de ejemplo), bloque independiente, datos estructurados, recorrido, carrusel, pared (arrastre, movimiento solo, vista previa, click real, cursor) y celular.
- **Falta**: probarlo dentro de un **WordPress real con el editor visual abierto** (la pared se muestra como cuadrícula estática en el editor a propósito, para no pelear con la selección de bloques).

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
2. Instala y activa **VeloxForge ≥ 1.3** y luego `veloxforge-golehighacres.zip`.
3. Crea una página con el constructor visual (plantilla *Canvas* si quieres sin tema) y en «Secciones» inserta *Página completa: Explore*; otra con *News & Events*.
4. Cabecera: el bloque *Cabecera* va en una plantilla de tipo cabecera (o dentro de cada página).
5. Directorio real: carga los negocios en «Negocios reales» del bloque *Directorio* (o crea una página por negocio con *Perfil de negocio*).
6. Valida con `dev/harness-veloxforge.php` (ver `dev/README.md`) antes de empaquetar de nuevo.

### 8.3 Volver a empaquetar el plugin

Desde la carpeta que contiene `veloxforge-golehighacres/`:

```
zip -r veloxforge-golehighacres.zip veloxforge-golehighacres
```

---

## 9. Pendientes sugeridos

**Contenido**
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
- Probar el plugin en un WordPress real (editor visual, popups, plantillas, varias páginas a la vez).
- Revisión de accesibilidad con lector de pantalla y de rendimiento (Lighthouse).
- En táctil, la pared solo desplaza horizontalmente por defecto (el scroll vertical es de la página); revisar si se quiere pantalla completa con captura total.

**Legal y transparencia (importante)**
- La propuesta habla de presentar el portal como «guía oficial» y como «caballo de Troya» publicitario. **No se hizo así**: el sitio dice que es un **proyecto independiente, sin vínculo con el gobierno del condado**, y marca los proyectos de vivienda y la publicidad como tal, nombrando a la empresa patrocinadora. Conviene mantener esa transparencia y revisarla con asesoría legal (divulgación publicitaria en EE. UU./Florida).

---

## 10. Referencias de la sesión

- Repositorio: `muonunziata/skill`, rama `claude/skills-installation-gamho8`.
- Páginas publicadas (privadas, solo con tu cuenta): Explore `https://claude.ai/artifact/My8os9Nbb2H7K5PsK9r4A8` · News & Events `https://claude.ai/artifact/TkeW8aFMeuWDpi8gDayWUN`.
- Referencias de diseño analizadas: `imersion.io` (por video; el dominio estaba bloqueado en el entorno) y la pared de videos del segundo video. `bright-avenue.jp` no pudo abrirse: la página editorial es una interpretación de ese estilo.
