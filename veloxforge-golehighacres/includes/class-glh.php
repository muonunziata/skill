<?php
defined( 'ABSPATH' ) || exit;

/** Núcleo del módulo: registro en VeloxForge, ayudantes de controles y de marcado, y carga de recursos. */
class GLH {

	private static $assets = false;

	public static function init() {
		add_action( 'veloxforge_register_modules', array( __CLASS__, 'register_module' ) );
	}

	public static function register_module() {
		VF_Modules::register( 'golehighacres', array(
			'name' => 'GoLehighAcres',
			'desc' => 'Secciones de GoLehighAcres.org como bloques editables del constructor: cabecera, portada, carrusel 3D, cifras, directorio con buscador, recorrido, negocios fundadores, cita, cinta y pared de videos.',
			'icon' => '🏡',
			'boot' => array( __CLASS__, 'boot' ),
		) );
	}

	public static function boot() {
		add_action( 'veloxforge_builder_register_widgets', array( 'GLH_Widgets', 'register' ) );
		add_filter( 'vf_builder_presets', array( 'GLH_Presets', 'add' ) );
	}

	/* ---------- Recursos: solo se cargan cuando la página usa un bloque del módulo ---------- */

	public static function need() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		wp_enqueue_style( 'glh', GLH_URL . 'assets/glh.css', array(), GLH_VER );
		if ( apply_filters( 'glh_google_fonts', true ) ) {
			wp_enqueue_style( 'glh-fonts', 'https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
		}
		wp_enqueue_script( 'glh', GLH_URL . 'assets/glh.js', array(), GLH_VER, true );
	}

	/* ---------- Controles ---------- */

	public static function c( $id, $label, $type, $tab, array $x = array() ) {
		return VF_Builder_Widgets::c( $id, $label, $type, $tab, $x );
	}

	public static function v( array $s, $id, $d = '' ) {
		return VF_Builder_Widgets::val( $s, $id, $d );
	}

	/** Texto de contenido. */
	public static function t( $id, $label, $default = '', $type = 'text', $group = 'Contenido' ) {
		return self::c( $id, $label, $type, 'content', array( 'default' => $default, 'group' => $group ) );
	}

	/** Color que define una variable CSS del bloque (los hijos la heredan). */
	public static function col( $id, $label, $var, $group = 'Colores' ) {
		return self::c( $id, $label, 'color', 'style', array( 'group' => $group, 'css' => array( array( '', $var ) ) ) );
	}

	/** Color de un elemento concreto. */
	public static function ecol( $id, $label, $sel, $prop = 'color', $group = 'Colores' ) {
		return self::c( $id, $label, 'color', 'style', array( 'group' => $group, 'css' => array( array( $sel, $prop ) ) ) );
	}

	public static function num( $id, $label, $tab, $default, $min, $max, array $x = array() ) {
		return self::c( $id, $label, 'number', $tab, array_merge( array( 'default' => $default, 'min' => $min, 'max' => $max ), $x ) );
	}

	public static function sw( $id, $label, $default = 1, $group = 'Comportamiento' ) {
		return self::c( $id, $label, 'switch', 'content', array( 'default' => $default, 'group' => $group ) );
	}

	/** Tamaño de letra responsive de un elemento. */
	public static function size( $id, $label, $sel, $min = 8, $max = 200 ) {
		return self::c( $id, $label, 'number', 'style', array( 'group' => 'Tipografía', 'responsive' => true, 'min' => $min, 'max' => $max, 'css' => array( array( $sel, 'font-size', 'px' ) ) ) );
	}

	public static function ratios() {
		return array( '4/3' => '4:3', '3/4' => '3:4', '1/1' => '1:1', '16/9' => '16:9', '9/16' => '9:16', '3/2' => '3:2', '4/5' => '4:5', '21/9' => '21:9' );
	}

	/* ---------- Marcado ---------- */

	public static function url( $u ) {
		return esc_url( $u, array( 'http', 'https', 'tel', 'mailto' ) );
	}

	public static function img_url( $img, $size = 'large' ) {
		if ( ! is_array( $img ) ) {
			return '';
		}
		if ( ! empty( $img['id'] ) ) {
			$u = wp_get_attachment_image_url( (int) $img['id'], $size );
			if ( $u ) {
				return $u;
			}
		}
		return ! empty( $img['url'] ) ? $img['url'] : '';
	}

	public static function ratio( $r, $d = '4/3' ) {
		$r = (string) $r;
		return isset( self::ratios()[ $r ] ) ? $r : $d;
	}

	/**
	 * Foto del bloque o, si aún no hay imagen, un recuadro de color numerado («FOTO 07») para saber cuál va en cada sitio.
	 * $fill = ocupa todo el contenedor (sin proporción propia).
	 */
	public static function photo( $img, $n, $k, $ratio = '4/3', $alt = '', $prefix = 'FOTO', $fill = false ) {
		$url   = self::img_url( $img );
		$style = $fill ? '' : ' style="aspect-ratio:' . esc_attr( str_replace( '/', ' / ', self::ratio( $ratio ) ) ) . '"';
		$k     = ( ( (int) $k - 1 ) % 6 ) + 1;
		if ( $url !== '' ) {
			return '<div class="glh-ph glh-has' . ( $fill ? ' glh-fill' : '' ) . '"' . $style . '><img src="' . esc_url( $url, array( 'http', 'https' ) ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async"></div>';
		}
		return '<div class="glh-ph glh-k' . (int) $k . ( $fill ? ' glh-fill' : '' ) . '"' . $style . '><span class="glh-no">' . esc_html( $prefix . ' ' . str_pad( (string) (int) $n, 2, '0', STR_PAD_LEFT ) ) . '</span>' . ( $fill ? '' : '<span class="glh-sz">' . esc_html( str_replace( '/', ':', self::ratio( $ratio ) ) ) . '</span>' ) . '</div>';
	}

	public static function json( $data ) {
		return esc_attr( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) );
	}

	/** Atributo de revelado al hacer scroll (la animación es opcional y respeta «reducir movimiento»). */
	public static function rise() {
		return ' data-glh-rise';
	}
}
