<?php
defined( 'GLH_DIR' ) || exit;

/** Ayudantes de controles y de marcado, y aviso de recursos. No depende de ningún constructor. */
class GLH {

	private static $assets = false;
	private static $on_need = array();

	/* ---------- Recursos: solo se cargan cuando la página usa un bloque ---------- */

	/** El adaptador del sitio (WordPress, Wakoo, PrimeBase…) registra aquí cómo cargar los recursos. */
	public static function on_need( callable $cb ) {
		self::$on_need[] = $cb;
		if ( self::$assets ) {
			call_user_func( $cb, self::assets() );
		}
	}

	public static function need() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		foreach ( self::$on_need as $cb ) {
			call_user_func( $cb, self::assets() );
		}
	}

	public static function needed() {
		return self::$assets;
	}

	/** Recursos que necesitan los bloques; la versión cambia solo cuando cambia el archivo (evita ver una versión vieja en caché). */
	public static function assets() {
		$v = function ( $f ) {
			return GLH_VER . '.' . (int) @filemtime( GLH_DIR . $f );
		};
		return array(
			'css'   => array( 'glh' => array( 'file' => 'assets/glh.css', 'ver' => $v( 'assets/glh.css' ) ) ),
			'js'    => array( 'glh' => array( 'file' => 'assets/glh.js', 'ver' => $v( 'assets/glh.js' ) ) ),
			'fonts' => apply_filters( 'glh_google_fonts', true ) ? 'https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&display=swap' : '',
		);
	}

	/** Etiquetas <link>/<script> para sitios sin WordPress. $base = URL pública de la carpeta del plugin. */
	public static function asset_tags( $base ) {
		$a = self::assets();
		$h = $a['fonts'] !== '' ? '<link rel="stylesheet" href="' . esc_url( $a['fonts'] ) . '">' : '';
		foreach ( $a['css'] as $k => $f ) {
			$h .= '<link rel="stylesheet" id="' . esc_attr( $k ) . '-css" href="' . esc_url( rtrim( $base, '/' ) . '/' . $f['file'] . '?ver=' . $f['ver'] ) . '">';
		}
		foreach ( $a['js'] as $k => $f ) {
			$h .= '<script defer id="' . esc_attr( $k ) . '-js" src="' . esc_url( rtrim( $base, '/' ) . '/' . $f['file'] . '?ver=' . $f['ver'] ) . '"></script>';
		}
		return $h;
	}

	/* ---------- Controles ---------- */

	public static function c( $id, $label, $type, $tab, array $x = array() ) {
		return GLH_Registry::c( $id, $label, $type, $tab, $x );
	}

	public static function v( array $s, $id, $d = '' ) {
		return GLH_Registry::val( $s, $id, $d );
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
	public static function photo( $img, $n, $k, $ratio = '4/3', $alt = '', $prefix = 'FOTO', $fill = false, $focus = null ) {
		$url   = self::img_url( $img );
		$style = $fill ? '' : ' style="aspect-ratio:' . esc_attr( str_replace( '/', ' / ', self::ratio( $ratio ) ) ) . '"';
		$k     = ( ( (int) $k - 1 ) % 6 ) + 1;
		if ( $url !== '' ) {
			$fa = '';
			if ( is_array( $focus ) && count( $focus ) === 2 ) {
				$fa = ' class="glh-focus" data-fx="' . esc_attr( max( 0, min( 1, (float) $focus[0] ) ) ) . '" data-fy="' . esc_attr( max( 0, min( 1, (float) $focus[1] ) ) ) . '"';
			}
			return '<div class="glh-ph glh-has' . ( $fill ? ' glh-fill' : '' ) . '"' . $style . '><img' . $fa . ' src="' . esc_url( $url, array( 'http', 'https' ) ) . '" alt="' . esc_attr( $alt ) . '" loading="' . ( $fa !== '' ? 'eager' : 'lazy' ) . '" decoding="async"></div>';
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
