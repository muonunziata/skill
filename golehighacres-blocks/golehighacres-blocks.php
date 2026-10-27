<?php
/**
 * Plugin Name: GoLehighAcres Blocks
 * Description: Secciones de GoLehighAcres.org como bloques editables, con su propio editor: cabecera, portada, carrusel 3D, cifras, directorio con buscador, perfil de negocio, recorrido, negocios fundadores, cita, cinta, pared de videos y fondo de video. No necesita VeloxForge ni ningún otro constructor.
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: GoLehighAcres
 * Text Domain: golehighacres-blocks
 */
defined( 'ABSPATH' ) || exit;

// El módulo antiguo «VeloxForge · GoLehighAcres» usa las mismas clases: al activar este se desactiva aquel, y mientras siga activo este no se carga.
const GLH_OLD = 'veloxforge-golehighacres/veloxforge-golehighacres.php';
register_activation_hook( __FILE__, function () {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( GLH_OLD, true );
} );
if ( in_array( GLH_OLD, (array) get_option( 'active_plugins', array() ), true ) || defined( 'GLH_VER' ) || class_exists( 'GLH' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><b>GoLehighAcres Blocks</b>: desactiva el plugin antiguo <b>VeloxForge · GoLehighAcres</b> (este lo sustituye y ya no necesita VeloxForge). Después vuelve a activar GoLehighAcres Blocks.</p></div>';
	} );
	return;
}

define( 'GLH_VER', '1.2.0' );
define( 'GLH_FILE', __FILE__ );
define( 'GLH_DIR', plugin_dir_path( __FILE__ ) );
define( 'GLH_URL', plugin_dir_url( __FILE__ ) );

require_once GLH_DIR . 'core/load.php';
require_once GLH_DIR . 'wordpress/class-wp.php';
add_action( 'init', array( 'GLH_WP', 'init' ) );
