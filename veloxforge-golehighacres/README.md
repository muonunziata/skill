# VeloxForge · GoLehighAcres

**Versión actual: 1.1.1** · historial en `CHANGELOG.md`

Módulo de VeloxForge que convierte cada sección de GoLehighAcres.org en un **bloque editable del constructor visual**. No modifica el código de VeloxForge: se registra con sus propios ganchos (`veloxforge_register_modules`, `veloxforge_builder_register_widgets` y `vf_builder_presets`).

## Instalación

1. Instala y activa **VeloxForge 1.3 o superior**.
2. En WordPress: Plugins → Añadir nuevo → Subir plugin → `veloxforge-golehighacres.zip` → Activar.
3. En VeloxForge → Módulos aparece **GoLehighAcres** (se puede apagar y encender).
4. Al activarlo por primera vez, si el sitio aún no tenía un sistema de diseño guardado, se cargan los colores de la marca y las tipografías (títulos Poppins, texto Arial). Si ya tenías uno, no se toca.

## Actualizar desde una versión anterior

1. **Plugins → Añadir nuevo → Subir plugin** y elige el zip. Cuando WordPress diga que ya está instalado, pulsa **«Reemplazar el actual con el subido»**.
2. Comprueba la versión: en **Plugins** debe decir **1.1.0**, y en **VeloxForge → Módulos** el nombre es «GoLehighAcres · v1.1.0».
3. Vacía las cachés (plugin de caché, CDN/Cloudflare, caché del navegador con Ctrl+Shift+R). Desde la 1.1.0 los archivos se recargan solos cuando cambian.
4. Los bloques que ya estaban en tus páginas se conservan; los controles nuevos aparecen con sus valores por defecto.

## Bloques (categoría «GoLehighAcres» en el constructor)

| Bloque | Qué edita el panel |
|---|---|
| Cabecera | Logo (imagen), nombre, secciones del menú (texto, enlace, sección actual), botón de WhatsApp, fija o no, altura, colores |
| Portada | Rótulo, titular, texto, foto de fondo, botón circular START, altura, capa de color, tamaño del titular |
| Carrusel 3D | Fotos, proporción, ancho, profundidad, giro automático y velocidad, flechas y textos de los botones |
| Ciudad y cifras | Rótulo, frase, final destacado, cifras (número, prefijo, sufijo, etiqueta), animación de conteo |
| Directorio con buscador | Título, tabla de novedades, **18 categorías con sus tipos**, negocios reales, textos, menú desplegable de categorías |
| Recorrido | Título, etapas (título, texto, foto), tarjeta fija, botón |
| Negocios fundadores | Texto, botón, fotos de cada columna; las dos columnas son espejo (interruptor de simetría) |
| Cita | Frase, palabra destacada, autor, foto de fondo, altura |
| Cinta de palabras | Palabras, separador, velocidad |
| Fondo de video de toda la página | Video MP4/WebM, imagen fija, punto focal, color de respaldo y capa de color; se muestra detrás de toda la página y pasa a imagen fija con «reducir movimiento» o ahorro de datos |
| Perfil de negocio | Nombre, categoría, logo, descripción, galería de fotos, dirección, enlace propio de Google Maps, teléfono, correo, sitio web, horario, etiquetas, puntuación, insignia de fundador, redes, datos estructurados LocalBusiness para Google, y todos los textos |
| Pared de videos infinita | Videos (portada, archivo, título, categoría, orientación), filas y columnas, movimiento solo, vista previa, ventana emergente, cursor |

Todos los bloques traen además los controles comunes de VeloxForge: fondo, espacios, bordes, sombra, animación de entrada, visibilidad por dispositivo, condiciones y CSS propio.

## Perfil de negocio

Al hacer click en un negocio del directorio se abre su perfil: ventana emergente centrada en escritorio y pantalla completa en celular, con galería de fotos, logo, descripción, botones **Open in Google Maps** y **Get directions** (usan la dirección del negocio), teléfono, correo, sitio web, horario, etiquetas, puntuación, redes y la insignia de negocio fundador.

- **Negocios reales:** en el bloque «Directorio con buscador» → «Negocios reales», cada negocio tiene todos esos campos (hasta 6 fotos).
- **Negocios de ejemplo:** usan los textos de «Perfil: datos de ejemplo» y llevan un aviso que se puede ocultar.
- **Página propia de cada negocio:** el bloque «Perfil de negocio» muestra el mismo diseño dentro de una página y añade datos estructurados `LocalBusiness` para buscadores.
- Para desactivar la ventana emergente: interruptor «Abrir el perfil del negocio al hacer click».

## Fondo de video, capas al 5 % y punto focal

- **Fondo de video:** pon el bloque «Fondo de video de toda la página» al principio de la página. Queda fijo a pantalla completa (`object-fit: cover`) detrás de todo. Si el tema del sitio pinta un fondo sólido en un contenedor propio, ese contenedor lo tapará.
- **Capas al 5 %:** añade la clase `glh-tint-1` … `glh-tint-6` en «Clases CSS adicionales» de cualquier contenedor para separar secciones con un color translúcido al 5 % y un borde fino (verde, naranja, azul, morado, dorado, celeste). La página completa *Explore* ya las trae.
- **Zonas de lectura:** con el fondo de video activo, la cabecera, la tabla y los resultados del directorio pasan a paneles blancos translúcidos para que se lean.
- **Punto focal:** «Portada» y «Cita» tienen *Punto focal horizontal/vertical (%)*. Indica dónde está el sujeto de la foto (por ejemplo la torre de agua) y queda **centrado en cualquier pantalla**; si no hay margen para moverla (escritorio ancho), el hueco se rellena con una copia difuminada de la misma foto. En «Portada», «dejar el texto a la izquierda» evita que el titular tape al sujeto centrado.

## Biblioteca

En el panel «Secciones» hay una entrada por bloque y dos **páginas completas**:

- **Explore (inicio):** portada, carrusel, ciudad, directorio, recorrido, negocios fundadores, cita y cinta.
- **News & Events:** pared de videos a pantalla completa.

## Fotos numeradas

Mientras un bloque no tenga imagen muestra un recuadro de color con su número (`FOTO 01` … `FOTO 25`, `VIDEO 01` …). Cada bloque tiene «Primer número de foto» y «Rótulo», así se sabe qué foto va en cada sitio. Al subir la imagen, el recuadro se reemplaza.

## Directorio

- Sin «Negocios reales», se generan negocios de **ejemplo** (uno por tipo) con la etiqueta *Sample*. Se oculta vaciando «Etiqueta de los ejemplos».
- Para publicar negocios reales, agrégalos en «Negocios reales» (nombre, categoría tal como está escrita arriba, tipo, zona, enlace).

## Pared de videos

- La rueda del mouse no se captura por defecto (para no atrapar el scroll de la página). Actívala solo si la pared ocupa toda la pantalla.
- En pantallas táctiles el arrastre vertical hace scroll de la página; el horizontal mueve la pared (salvo que se active la rueda, que también la fija).
- En el editor la pared se muestra como cuadrícula estática para no pelear con la selección de bloques.

## Tipografías

Se cargan Poppins desde Google Fonts. Para desactivarlo (por privacidad): `add_filter( 'glh_google_fonts', '__return_false' );`

## Cambios de la 1.1.0

- Logo real de la marca incluido (antes el símbolo de respaldo salía incompleto en el header).
- Fondo de video, capas al 5 %, punto focal, perfil de negocio y correcciones responsive.
- Versión visible y recarga automática de estilos y scripts.

## Estructura

```
veloxforge-golehighacres.php   arranque y comprobación de VeloxForge
includes/class-glh.php         módulo, controles, fotos marcadas, carga de recursos
includes/business.php          perfil de negocio (bloque, marcado compartido, Google Maps, datos estructurados)
includes/widgets.php           los demás bloques
includes/presets.php           biblioteca de secciones y páginas
assets/glh.css, assets/glh.js  estilos y comportamiento (solo se cargan si la página usa un bloque)
```

## Verificación realizada

Se probó con las clases reales de VeloxForge 1.3.0 (registro de bloques, generador de CSS y renderizador) sobre funciones de WordPress simuladas, y en Chromium: valores por defecto de todos los controles, 12 secciones de la biblioteca, numeración de fotos, directorio, recorrido, carrusel, pared (arrastre, movimiento solo, vista previa, ventana emergente, click real) y cursor. **No se ha probado dentro de un WordPress real con el editor visual abierto**: conviene una pasada en un sitio de pruebas antes de publicar.
