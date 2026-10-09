# Herramientas de prueba (opcionales)

Sirven para comprobar cambios sin abrir WordPress. Necesitan **Node 18+** con `playwright` (`npm i -D playwright && npx playwright install chromium`) y **PHP 8+**.

## 1. Páginas HTML (`smoke-pages.mjs`)

```
PAGES=../paginas node smoke-pages.mjs
```

Comprueba en un navegador real: menú desplegable de categorías, perfil de negocio (ventana emergente, galería, enlaces a Google Maps, teclado), pared de videos (arrastre, click real que abre el reproductor, cursor de flechas y «GO!») y que no haya scroll horizontal en celular.

## 2. Plugin de VeloxForge (`harness-veloxforge.php`)

Carga las clases **reales** de VeloxForge (registro de bloques, generador de CSS y renderizador) sobre funciones de WordPress simuladas. Valida los valores por defecto de cada control, que todas las secciones de la biblioteca se sanean y se renderizan, y escribe el HTML y el CSS resultantes.

```
VF_SRC=/ruta/a/veloxforge  GLH_SRC=../veloxforge-golehighacres  OUT_DIR=./out  php harness-veloxforge.php
```

`VF_SRC` es la carpeta del plugin VeloxForge (la que contiene `modules/builder`). Para verlo en el navegador, une `front.css` de VeloxForge + `assets/glh.css` + el `.css` generado + el `.html` generado + `assets/glh.js` en una sola página (el script `ensamblar.py` lo hace).

Nota: es una simulación. No sustituye una prueba dentro de un WordPress real con el editor visual abierto.
