<?php
defined( 'GLH_DIR' ) || exit;

/** Plantillas: cada bloque suelto y las páginas completas, listas para insertar y editar. */
class GLH_Pages {

	private static function B( $type, array $s = array() ) {
		return array( 'type' => $type, 'settings' => $s );
	}

	public static function all() {
		$c = 'GoLehighAcres';
		$p = array(
			'page-home' => array( 'title' => 'Página completa: Explore (inicio)', 'icon' => '🏡', 'desc' => 'Portada, carrusel, ciudad, directorio, recorrido, negocios, cita y cinta, con capas de color al 5 % que separan las secciones.', 'blocks' => array(
				self::B( 'glh-pagebg' ),
				self::B( 'glh-hero', GLH_URL !== '' ? array( 'bg' => array( 'id' => 0, 'url' => GLH_URL . 'assets/torre-agua.jpg' ), 'focus_x' => 25.3 ) : array() ),
				self::B( 'glh-carousel', array( 'w_tint' => 'glh-tint-1' ) ),
				self::B( 'glh-stats', array( 'w_tint' => 'glh-tint-2' ) ),
				self::B( 'glh-directory', array( 'w_tint' => 'glh-tint-3' ) ),
				self::B( 'glh-journey', array( 'w_tint' => 'glh-tint-4' ) ),
				self::B( 'glh-founding', array( 'w_bg' => '#0B2F22' ) ),
				self::B( 'glh-quote' ),
				self::B( 'glh-marquee', array( 'w_tint' => 'glh-tint-5' ) ),
			) ),
			'page-news' => array( 'title' => 'Página completa: News & Events (pared de videos)', 'icon' => '🎬', 'desc' => 'Fondo de video y pared de videos a pantalla completa.', 'blocks' => array(
				self::B( 'glh-pagebg' ),
				self::B( 'glh-videowall' ),
			) ),
			'business' => array( 'title' => 'Perfil de negocio de ejemplo', 'icon' => '🏪', 'desc' => 'Página propia de un negocio con todos sus datos.', 'blocks' => array(
				self::B( 'glh-pagebg' ),
				self::B( 'glh-business', array( 'sample' => 1 ) ),
			) ),
		);
		foreach ( $p as $k => $v ) {
			$p[ $k ]['cat'] = $c;
		}
		return apply_filters( 'glh_pages', $p );
	}

	/** Bloques de una plantilla con identificadores nuevos (para que cada copia tenga sus propios estilos). */
	public static function blocks( $key ) {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return array();
		}
		$out = array();
		foreach ( $all[ $key ]['blocks'] as $b ) {
			$b['id'] = substr( md5( $key . count( $out ) . microtime() . mt_rand() ), 0, 8 );
			$out[]   = $b;
		}
		return $out;
	}
}
