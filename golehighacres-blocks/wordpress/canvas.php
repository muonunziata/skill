<?php
/** Pantalla completa: solo los bloques, sin cabecera ni pie del tema (los bloques traen su propia cabecera). */
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
wp_body_open();
echo GLH_WP::render_post( get_queried_object_id() ); // phpcs:ignore WordPress.Security.EscapeOutput -- marcado ya escapado por cada bloque
wp_footer();
?>
</body>
</html>
