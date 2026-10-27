<?php
/**
 * Núcleo de GoLehighAcres Blocks: se puede cargar desde WordPress o desde cualquier otro sitio PHP (Wakoo, PrimeBase…).
 * Antes de incluirlo, define GLH_DIR (carpeta del plugin, con «/» final) y GLH_URL (su dirección pública, con «/» final).
 */
defined( 'GLH_DIR' ) || exit;
defined( 'GLH_VER' ) || define( 'GLH_VER', '1.2.0' );
defined( 'GLH_URL' ) || define( 'GLH_URL', '' );

require_once GLH_DIR . 'core/compat.php';
require_once GLH_DIR . 'core/class-registry.php';
require_once GLH_DIR . 'core/class-glh.php';
require_once GLH_DIR . 'core/business.php';
require_once GLH_DIR . 'core/blocks.php';
require_once GLH_DIR . 'core/pages.php';
