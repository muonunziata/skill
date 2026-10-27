<?php
defined( 'GLH_DIR' ) || exit;

/**
 * Registro de bloques, ajustes y estilos. No depende de ningún constructor ni de WordPress.
 *
 * Un bloque:  GLH_Registry::register( 'slug', array( 'title', 'icon', 'cat', 'controls' => [...], 'render' => callable( $s, $ctx ): string ) )
 * Un control: id, label, type, tab (content|style), group, default, options, responsive, min, max, css, fields (repeater), title_field.
 * Tipos: text, textarea, number, select, color, url, image, switch, repeater.
 * css: array( array( selector, propiedad, sufijo|literal, es_literal ) ); el selector '' es el contenedor del bloque.
 */
class GLH_Registry {

	const TABLET = '(max-width:1024px)';
	const MOBILE = '(max-width:767px)';

	private static $blocks = array();
	private static $loaded = false;

	/** Valores de envoltorio por defecto de cada bloque: [ ancho acotado, ancho máximo, relleno superior, relleno inferior ]. */
	private static $wrap = array(
		'glh-header'    => array( 0, 1320, 0, 0 ),
		'glh-hero'      => array( 0, 1320, 0, 0 ),
		'glh-carousel'  => array( 0, 1320, 96, 64 ),
		'glh-stats'     => array( 1, 1320, 96, 96 ),
		'glh-directory' => array( 1, 1320, 96, 96 ),
		'glh-business'  => array( 1, 1320, 96, 96 ),
		'glh-journey'   => array( 1, 1320, 96, 96 ),
		'glh-founding'  => array( 0, 1320, 0, 0 ),
		'glh-quote'     => array( 0, 1320, 0, 0 ),
		'glh-marquee'   => array( 0, 1320, 0, 0 ),
		'glh-videowall' => array( 0, 1320, 0, 0 ),
		'glh-pagebg'    => array( 0, 1320, 0, 0 ),
	);

	public static function register( $slug, array $def ) {
		$slug = sanitize_key( $slug );
		if ( $slug === '' ) {
			return;
		}
		self::$blocks[ $slug ] = array_merge( array( 'title' => $slug, 'icon' => '▫️', 'cat' => 'GoLehighAcres', 'controls' => array(), 'render' => null ), $def );
	}

	public static function all() {
		if ( ! self::$loaded ) {
			self::$loaded = true;
			GLH_Widgets::register(); // incluye el perfil de negocio
			do_action( 'glh_register_blocks' );
		}
		return self::$blocks;
	}

	public static function get( $slug ) {
		$a = self::all();
		return isset( $a[ $slug ] ) ? $a[ $slug ] : null;
	}

	/* ---------- Controles ---------- */

	public static function c( $id, $label, $type, $tab, array $extra = array() ) {
		return array_merge( array( 'id' => $id, 'label' => $label, 'type' => $type, 'tab' => $tab ), $extra );
	}

	public static function val( array $s, $id, $default = '' ) {
		return isset( $s[ $id ] ) && $s[ $id ] !== '' && $s[ $id ] !== array() ? $s[ $id ] : $default;
	}

	public static function color_ok( $s ) {
		return (bool) preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)|transparent|var\(--glh-[a-z0-9_-]{1,30}\))$/', (string) $s );
	}

	/** Controles de la pestaña «Bloque»: fondo, anchura, relleno, capa de color, ancla, visibilidad, clase y CSS propio. */
	public static function common( $slug ) {
		$w = isset( self::$wrap[ $slug ] ) ? self::$wrap[ $slug ] : array( 0, 1320, 0, 0 );
		$g = 'Bloque';
		return array(
			self::c( 'w_bg', 'Color de fondo del bloque', 'color', 'wrap', array( 'group' => $g ) ),
			self::c( 'w_tint', 'Capa de color al 5 % que separa esta sección', 'select', 'wrap', array( 'group' => $g, 'default' => '', 'options' => array( '' => 'Ninguna', 'glh-tint-1' => 'Verde', 'glh-tint-2' => 'Naranja', 'glh-tint-3' => 'Azul', 'glh-tint-4' => 'Morado', 'glh-tint-5' => 'Dorado', 'glh-tint-6' => 'Celeste' ) ) ),
			self::c( 'w_boxed', 'Contenido con ancho máximo (apagado = ancho completo)', 'switch', 'wrap', array( 'group' => $g, 'default' => $w[0] ) ),
			self::c( 'w_max', 'Ancho máximo (px)', 'number', 'wrap', array( 'group' => $g, 'default' => $w[1], 'min' => 320, 'max' => 2400 ) ),
			self::c( 'w_pt', 'Espacio superior (px)', 'number', 'wrap', array( 'group' => $g, 'default' => $w[2], 'min' => 0, 'max' => 400 ) ),
			self::c( 'w_pb', 'Espacio inferior (px)', 'number', 'wrap', array( 'group' => $g, 'default' => $w[3], 'min' => 0, 'max' => 400 ) ),
			self::c( 'w_anchor', 'ID del ancla (para enlaces #)', 'text', 'wrap', array( 'group' => 'Avanzado' ) ),
			self::c( 'w_hide_d', 'Ocultar en escritorio', 'switch', 'wrap', array( 'group' => 'Avanzado' ) ),
			self::c( 'w_hide_m', 'Ocultar en celular y tablet', 'switch', 'wrap', array( 'group' => 'Avanzado' ) ),
			self::c( 'w_class', 'Clases CSS adicionales', 'text', 'wrap', array( 'group' => 'Avanzado' ) ),
			self::c( 'w_css', 'CSS propio de este bloque (usa «selector»)', 'textarea', 'wrap', array( 'group' => 'Avanzado', 'css_only' => true ) ),
		);
	}

	public static function controls_for( $slug ) {
		$b = self::get( $slug );
		return $b ? array_merge( $b['controls'], self::common( $slug ) ) : array();
	}

	/** Esquema para el editor del administrador (sin funciones). */
	public static function schema() {
		$out = array();
		foreach ( self::all() as $slug => $b ) {
			$out[ $slug ] = array( 'title' => $b['title'], 'icon' => $b['icon'], 'cat' => $b['cat'], 'controls' => self::controls_for( $slug ) );
		}
		return $out;
	}

	public static function defaults( $slug ) {
		$o = array();
		foreach ( self::controls_for( $slug ) as $c ) {
			if ( array_key_exists( 'default', $c ) ) {
				$o[ $c['id'] ] = $c['default'];
			}
		}
		return $o;
	}

	/* ---------- Saneado ---------- */

	private static function num( $v, $c ) {
		if ( $v === '' || $v === null || ! is_numeric( $v ) ) {
			return '';
		}
		$n = (float) $v;
		if ( isset( $c['min'] ) ) {
			$n = max( (float) $c['min'], $n );
		}
		if ( isset( $c['max'] ) ) {
			$n = min( (float) $c['max'], $n );
		}
		return $n;
	}

	public static function clean_css( $css ) {
		$css = preg_replace( '/<\/?[a-z!][^>]*>/i', '', (string) $css );
		$css = str_replace( array( '<', "\0" ), '', $css );
		$css = preg_replace( '/@import[^;]*;?/i', '', $css );
		$css = preg_replace( '/(expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding)/i', '', $css );
		return trim( substr( $css, 0, 30000 ) );
	}

	private static function text( $v, $max ) {
		return is_scalar( $v ) ? substr( trim( strip_tags( (string) $v ) ), 0, $max ) : '';
	}

	private static function one( array $c, $v ) {
		switch ( $c['type'] ) {
			case 'text':
				return self::text( $v, 500 );
			case 'textarea':
				if ( ! is_scalar( $v ) ) {
					return '';
				}
				if ( ! empty( $c['css_only'] ) ) {
					return self::clean_css( $v );
				}
				return substr( trim( strip_tags( (string) $v ) ), 0, 5000 );
			case 'number':
				return self::num( $v, $c );
			case 'select':
				return is_scalar( $v ) && isset( $c['options'][ (string) $v ] ) ? (string) $v : ( isset( $c['default'] ) ? $c['default'] : '' );
			case 'color':
				$s = is_scalar( $v ) ? trim( (string) $v ) : '';
				return self::color_ok( $s ) ? $s : '';
			case 'url':
				$s = is_scalar( $v ) ? trim( (string) $v ) : '';
				if ( $s !== '' && $s[0] === '#' ) {
					return preg_replace( '/[^A-Za-z0-9_\-#]/', '', $s );
				}
				return esc_url_raw( $s, array( 'http', 'https', 'tel', 'mailto' ) );
			case 'image':
				return is_array( $v ) ? array( 'id' => isset( $v['id'] ) ? (int) $v['id'] : 0, 'url' => isset( $v['url'] ) ? esc_url_raw( (string) $v['url'], array( 'http', 'https' ) ) : '' ) : array( 'id' => 0, 'url' => '' );
			case 'switch':
				return empty( $v ) ? 0 : 1;
			case 'repeater':
				$items = array();
				foreach ( is_array( $v ) ? $v : array() as $it ) {
					if ( ! is_array( $it ) || count( $items ) >= ( isset( $c['max'] ) ? (int) $c['max'] : 30 ) ) {
						continue;
					}
					$row = array();
					foreach ( $c['fields'] as $f ) {
						if ( array_key_exists( $f['id'], $it ) ) {
							$row[ $f['id'] ] = self::one( $f, $it[ $f['id'] ] );
						}
					}
					$items[] = $row;
				}
				return $items;
		}
		return '';
	}

	public static function sanitize_value( array $c, $v ) {
		if ( ! empty( $c['responsive'] ) ) {
			$o = array();
			if ( is_array( $v ) ) {
				foreach ( array( 'desktop', 'tablet', 'mobile' ) as $d ) {
					if ( isset( $v[ $d ] ) && $v[ $d ] !== '' ) {
						$o[ $d ] = self::one( $c, $v[ $d ] );
					}
				}
			}
			return $o;
		}
		return self::one( $c, $v );
	}

	public static function sanitize_settings( $slug, $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::controls_for( $slug ) as $c ) {
			if ( array_key_exists( $c['id'], $raw ) ) {
				$out[ $c['id'] ] = self::sanitize_value( $c, $raw[ $c['id'] ] );
			}
		}
		return $out;
	}

	/* ---------- Estilos ---------- */

	private static function css_value( array $c, $v, array $rule ) {
		if ( $v === '' || $v === null || $v === array() ) {
			return '';
		}
		switch ( $c['type'] ) {
			case 'number':
				if ( ! is_numeric( $v ) ) {
					return '';
				}
				if ( ! empty( $rule[3] ) ) {
					return (string) $rule[2];
				}
				if ( isset( $rule[2] ) && strpos( $rule[2], '{v}' ) !== false ) {
					return str_replace( '{v}', (string) (float) $v, $rule[2] );
				}
				return $v . ( isset( $rule[2] ) ? $rule[2] : '' );
			case 'switch':
				return ! empty( $rule[3] ) && $v ? (string) $rule[2] : '';
			case 'image':
				return is_array( $v ) && ! empty( $v['url'] ) ? 'url("' . str_replace( '"', '%22', esc_url_raw( $v['url'] ) ) . '")' : '';
		}
		return is_scalar( $v ) ? preg_replace( '/[;{}<>\\\\]/', '', (string) $v ) : '';
	}

	private static function sel( $base, $suffix ) {
		if ( $suffix === '' ) {
			return $base;
		}
		return $suffix[0] === ':' ? $base . $suffix : $base . ' ' . $suffix;
	}

	/** CSS de las reglas definidas por los controles de un bloque, con puntos de ruptura de tablet y móvil. */
	public static function css_for( $base, array $controls, array $s ) {
		$rules = array();
		foreach ( $controls as $c ) {
			if ( empty( $c['css'] ) || ! isset( $s[ $c['id'] ] ) ) {
				continue;
			}
			$val = $s[ $c['id'] ];
			if ( ! empty( $c['responsive'] ) ) {
				if ( ! is_array( $val ) ) {
					continue;
				}
				$by = array( '' => isset( $val['desktop'] ) ? $val['desktop'] : null, self::TABLET => isset( $val['tablet'] ) ? $val['tablet'] : null, self::MOBILE => isset( $val['mobile'] ) ? $val['mobile'] : null );
			} else {
				$by = array( '' => $val );
			}
			foreach ( $by as $media => $v ) {
				if ( $v === null ) {
					continue;
				}
				foreach ( $c['css'] as $rule ) {
					$out = self::css_value( $c, $v, $rule );
					if ( $out !== '' ) {
						$rules[ $media ][ self::sel( $base, $rule[0] ) ][] = $rule[1] . ':' . $out;
					}
				}
			}
		}
		$css = '';
		foreach ( array( '', self::TABLET, self::MOBILE ) as $media ) {
			if ( empty( $rules[ $media ] ) ) {
				continue;
			}
			$block = '';
			foreach ( $rules[ $media ] as $sel => $decls ) {
				$block .= $sel . '{' . implode( ';', $decls ) . '}';
			}
			$css .= $media === '' ? $block : '@media ' . $media . '{' . $block . '}';
		}
		return $css;
	}

	/** Estilos del envoltorio (fondo, ancho, relleno, visibilidad, CSS propio). */
	private static function wrap_css( $base, array $s ) {
		$css = '';
		$d   = array();
		if ( ! empty( $s['w_bg'] ) ) {
			$d[] = 'background-color:' . $s['w_bg'];
		}
		$pt = (float) self::val( $s, 'w_pt', 0 );
		$pb = (float) self::val( $s, 'w_pb', 0 );
		if ( $pt > 0 ) {
			$d[] = 'padding-top:' . $pt . 'px';
		}
		if ( $pb > 0 ) {
			$d[] = 'padding-bottom:' . $pb . 'px';
		}
		if ( $d ) {
			$css .= $base . '{' . implode( ';', $d ) . '}';
		}
		if ( ! empty( $s['w_boxed'] ) ) {
			$css .= $base . '>.glh-block__in{max-width:' . (int) self::val( $s, 'w_max', 1320 ) . 'px;margin-inline:auto;padding-inline:clamp(16px,4vw,24px)}';
		}
		$m = array();
		if ( $pt > 56 ) {
			$m[] = 'padding-top:56px';
		}
		if ( $pb > 56 ) {
			$m[] = 'padding-bottom:56px';
		}
		if ( $m ) {
			$css .= '@media ' . self::MOBILE . '{' . $base . '{' . implode( ';', $m ) . '}}';
		}
		if ( ! empty( $s['w_hide_d'] ) ) {
			$css .= '@media (min-width:1025px){' . $base . '{display:none!important}}';
		}
		if ( ! empty( $s['w_hide_m'] ) ) {
			$css .= '@media (max-width:1024px){' . $base . '{display:none!important}}';
		}
		if ( ! empty( $s['w_css'] ) ) {
			$css .= str_replace( 'selector', $base, self::clean_css( $s['w_css'] ) );
		}
		return $css;
	}

	/* ---------- Dibujado ---------- */

	/**
	 * HTML de un bloque ya envuelto: <style> propio + <section>. $id identifica la instancia (estable entre visitas).
	 * $settings se sanea aquí, así que puede venir directo del almacenamiento.
	 */
	public static function render( $slug, $settings, $id, array $ctx = array() ) {
		$b = self::get( $slug );
		if ( ! $b || ! is_callable( $b['render'] ) ) {
			return '';
		}
		$s = self::sanitize_settings( $slug, $settings );
		foreach ( self::controls_for( $slug ) as $c ) {
			if ( ! array_key_exists( $c['id'], $s ) && array_key_exists( 'default', $c ) ) {
				$s[ $c['id'] ] = $c['default'];
			}
		}
		$iid   = 'glh-i-' . preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $id ) );
		$base  = '.' . $iid;
		$css   = self::css_for( $base, $b['controls'], $s ) . self::wrap_css( $base, $s );
		try {
			$html = call_user_func( $b['render'], $s, $ctx );
		} catch ( Throwable $e ) {
			return '<!-- glh: error en el bloque ' . esc_html( $slug ) . ' -->';
		}
		$cls = trim( 'glh-block glh-b-' . $slug . ' ' . $iid . ' ' . self::val( $s, 'w_tint', '' ) . ' ' . preg_replace( '/[^A-Za-z0-9_\- ]/', '', (string) self::val( $s, 'w_class', '' ) ) );
		$anc = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) self::val( $s, 'w_anchor', '' ) );
		return ( $css !== '' ? '<style>' . $css . '</style>' : '' ) . '<section class="' . esc_attr( $cls ) . '"' . ( $anc !== '' ? ' id="' . esc_attr( $anc ) . '"' : '' ) . '><div class="glh-block__in">' . $html . '</div></section>';
	}

	/** Dibuja una lista de bloques [ [ 'id', 'type', 'settings' ], … ] en orden. */
	public static function render_list( array $blocks, array $ctx = array() ) {
		$h = '';
		foreach ( $blocks as $i => $bl ) {
			if ( ! is_array( $bl ) || empty( $bl['type'] ) ) {
				continue;
			}
			$h .= self::render( $bl['type'], isset( $bl['settings'] ) ? $bl['settings'] : array(), isset( $bl['id'] ) ? $bl['id'] : 'b' . $i, $ctx );
		}
		return $h;
	}
}
