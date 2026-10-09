<?php
/**
 * Plugin Name: VeloxForge · GoLehighAcres
 * Description: Módulo de VeloxForge con las secciones de GoLehighAcres.org como bloques editables del constructor visual: cabecera, portada, carrusel 3D, cifras, directorio con buscador, recorrido, negocios fundadores, cita, cinta y pared de videos.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: GoLehighAcres
 * Text Domain: vf-golehighacres
 */
defined( 'ABSPATH' ) || exit;

define( 'GLH_VER', '1.0.0' );
define( 'GLH_FILE', __FILE__ );
define( 'GLH_DIR', plugin_dir_path( __FILE__ ) );
define( 'GLH_URL', plugin_dir_url( __FILE__ ) );

// VeloxForge carga sus módulos en plugins_loaded (prioridad 20): este add-on se engancha antes.
add_action( 'plugins_loaded', function () {
	if ( ! defined( 'VF_VER' ) || ! class_exists( 'VF_Modules' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-warning"><p><b>VeloxForge · GoLehighAcres</b> necesita el plugin <b>VeloxForge</b> (1.3 o superior) activo.</p></div>';
		} );
		return;
	}
	require_once GLH_DIR . 'includes/class-glh.php';
	require_once GLH_DIR . 'includes/widgets.php';
	require_once GLH_DIR . 'includes/presets.php';
	GLH::init();
}, 10 );

register_activation_hook( __FILE__, function () {
	// Sistema de diseño de la marca, solo si el sitio aún no ha guardado uno propio.
	if ( get_option( 'vf_design', null ) === null ) {
		update_option( 'vf_design', array(
			'colors'  => array(
				array( 'id' => 'primary', 'name' => 'Dorado', 'value' => '#E3A92B' ),
				array( 'id' => 'secondary', 'name' => 'Verde profundo', 'value' => '#0B2F22' ),
				array( 'id' => 'accent', 'name' => 'Coral', 'value' => '#FF5A5F' ),
				array( 'id' => 'text', 'name' => 'Texto', 'value' => '#14181A' ),
				array( 'id' => 'light', 'name' => 'Claro', 'value' => '#F4F7F2' ),
				array( 'id' => 'dark', 'name' => 'Oscuro', 'value' => '#0B2F22' ),
				array( 'id' => 'white', 'name' => 'Blanco', 'value' => '#FFFFFF' ),
			),
			'fonts'   => array(
				'heading' => '"Poppins", "Montserrat", Arial, sans-serif',
				'body'    => 'Arial, "Helvetica Neue", Helvetica, sans-serif',
				'mono'    => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
			),
			'custom'  => array(),
			'space'   => 8,
			'radius'  => 6,
			'classes' => array(),
		), false );
		delete_transient( 'vf_design_css' );
		update_option( 'vf_design_ver', time(), false );
	}
} );
