# Herramientas de prueba (opcionales)

Sirven para comprobar cambios sin abrir WordPress. Necesitan **Node 18+** con `playwright` (`npm i -D playwright && npx playwright install chromium`) y **PHP 8+**.

## 1. Páginas HTML (`smoke-pages.mjs`)

```
PAGES=../paginas node smoke-pages.mjs
```

Comprueba en un navegador real: menú desplegable de categorías, perfil de negocio (ventana emergente, galería, enlaces a Google Maps, teclado), pared de videos (arrastre, click real que abre el reproductor, cursor de flechas y «GO!») y que no haya scroll horizontal en celular.

## 2. Plugin GoLehighAcres Blocks

```
php ../golehighacres-blocks/tests/core-test.php
node ../golehighacres-blocks/tests/wp-e2e.mjs http://localhost:8080 usuario contraseña
```

- `core-test.php`: carga el núcleo **sin WordPress**, dibuja los 12 bloques y las plantillas y comprueba el saneado.
- `wp-e2e.mjs`: prueba de punta a punta en un **WordPress real** (de prueba, nunca el sitio en vivo): activa el plugin, crea la página Explore desde la plantilla, edita en el editor con vista previa en vivo, publica y revisa la página en escritorio y celular.

## 3. Publicar una versión

```
dev/release.sh 1.2.1 "Qué cambió"
```

El módulo antiguo para VeloxForge (1.1.1) y su banco de pruebas están en `legacy/`.
