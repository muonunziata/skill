<?php
defined( 'ABSPATH' ) || exit;

/**
 * Perfil de negocio: logo, descripción, galería, datos de contacto, horario, etiquetas, redes y enlaces a Google Maps.
 * Un mismo diseño sirve para (1) el bloque «Perfil de negocio» (página propia del negocio, con datos estructurados para buscadores)
 * y (2) la ventana emergente del directorio (que lo construye glh.js con las mismas clases).
 */
class GLH_Business {

	/** Rótulos editables (los mismos controles se usan en el bloque y en el directorio). */
	public static function label_controls( $group = 'Textos del perfil' ) {
		$t = array(
			'lbl_maps'    => array( 'Botón «Google Maps»', 'Open in Google Maps' ),
			'lbl_dir'     => array( 'Botón «Cómo llegar»', 'Get directions' ),
			'lbl_call'    => array( 'Botón «Llamar»', 'Call' ),
			'lbl_web'     => array( 'Botón «Sitio web»', 'Website' ),
			'lbl_address' => array( 'Rótulo «Dirección»', 'Address' ),
			'lbl_phone'   => array( 'Rótulo «Teléfono»', 'Phone' ),
			'lbl_email'   => array( 'Rótulo «Correo»', 'Email' ),
			'lbl_hours'   => array( 'Rótulo «Horario»', 'Hours' ),
			'lbl_found'   => array( 'Insignia de fundador', 'Founding business' ),
			'lbl_photo'   => array( 'Palabra «Foto»', 'Photo' ),
			'lbl_close'   => array( 'Rótulo «Cerrar»', 'Close' ),
		);
		$o = array();
		foreach ( $t as $id => $d ) {
			$o[] = GLH::t( $id, $d[0], $d[1], 'text', $group );
		}
		return $o;
	}

	public static function labels( array $s ) {
		$o = array();
		foreach ( array( 'lbl_maps' => 'Open in Google Maps', 'lbl_dir' => 'Get directions', 'lbl_call' => 'Call', 'lbl_web' => 'Website', 'lbl_address' => 'Address', 'lbl_phone' => 'Phone', 'lbl_email' => 'Email', 'lbl_hours' => 'Hours', 'lbl_found' => 'Founding business', 'lbl_photo' => 'Photo', 'lbl_close' => 'Close' ) as $k => $d ) {
			$o[ substr( $k, 4 ) ] = GLH::v( $s, $k, $d );
		}
		return $o;
	}

	/** Campos del perfil (sin la galería) que se reutilizan en el directorio. */
	public static function profile_fields() {
		return array(
			GLH::c( 'logo', 'Logo', 'image', 'content' ),
			GLH::c( 'desc', 'Descripción', 'textarea', 'content' ),
			GLH::t( 'address', 'Dirección completa (para Google Maps)', '' ),
			GLH::c( 'maps_url', 'Enlace de Google Maps propio (opcional)', 'url', 'content' ),
			GLH::t( 'phone', 'Teléfono', '' ),
			GLH::t( 'email', 'Correo', '' ),
			GLH::c( 'web', 'Sitio web', 'url', 'content' ),
			GLH::c( 'hours', 'Horario (una línea por día: Lun – Vie|8:00 – 17:00)', 'textarea', 'content' ),
			GLH::t( 'tags', 'Etiquetas (separadas por coma)', '' ),
			GLH::c( 'rating', 'Puntuación (0–5)', 'number', 'content', array( 'min' => 0, 'max' => 5 ) ),
			GLH::c( 'reviews', 'Número de opiniones', 'number', 'content', array( 'min' => 0, 'max' => 1000000 ) ),
			GLH::c( 'founding', 'Negocio fundador', 'switch', 'content' ),
			GLH::c( 'facebook', 'Facebook', 'url', 'content' ),
			GLH::c( 'instagram', 'Instagram', 'url', 'content' ),
		);
	}

	public static function photo_fields( $n = 6 ) {
		$o = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$o[] = GLH::c( 'photo' . $i, 'Foto ' . $i . ' de la galería', 'image', 'content' );
		}
		return $o;
	}

	/** Une los campos sueltos de un negocio en un perfil normalizado. */
	public static function normalize( array $r, array $extra = array() ) {
		$gal = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$u = GLH::img_url( isset( $r[ 'photo' . $i ] ) ? $r[ 'photo' . $i ] : array() );
			if ( $u !== '' ) {
				$gal[] = array( 'src' => $u, 'alt' => '' );
			}
		}
		if ( ! empty( $r['gallery'] ) && is_array( $r['gallery'] ) ) {
			foreach ( $r['gallery'] as $g ) {
				$u = GLH::img_url( isset( $g['image'] ) ? $g['image'] : array() );
				if ( $u !== '' ) {
					$gal[] = array( 'src' => $u, 'alt' => isset( $g['alt'] ) ? (string) $g['alt'] : '' );
				}
			}
		}
		$hours = array();
		foreach ( preg_split( '/\r\n|\r|\n/', isset( $r['hours'] ) ? (string) $r['hours'] : '' ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( $p[0] !== '' ) {
				$hours[] = array( $p[0], isset( $p[1] ) ? $p[1] : '' );
			}
		}
		$social = array();
		foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram' ) as $k => $label ) {
			if ( ! empty( $r[ $k ] ) ) {
				$social[] = array( $label, GLH::url( $r[ $k ] ) );
			}
		}
		if ( ! empty( $r['social'] ) && is_array( $r['social'] ) ) {
			foreach ( $r['social'] as $x ) {
				if ( ! empty( $x['url'] ) ) {
					$social[] = array( isset( $x['name'] ) && $x['name'] !== '' ? (string) $x['name'] : 'Link', GLH::url( $x['url'] ) );
				}
			}
		}
		return array_merge( array(
			'name'    => isset( $r['name'] ) ? (string) $r['name'] : '',
			'cat'     => isset( $r['category'] ) ? (string) $r['category'] : ( isset( $r['cat'] ) ? (string) $r['cat'] : '' ),
			'color'   => isset( $r['color'] ) && VF_Builder_Widgets::color_ok( (string) $r['color'] ) ? (string) $r['color'] : '#2E7D55',
			'logo'    => GLH::img_url( isset( $r['logo'] ) ? $r['logo'] : array(), 'medium' ),
			'desc'    => isset( $r['desc'] ) ? (string) $r['desc'] : '',
			'address' => isset( $r['address'] ) ? (string) $r['address'] : '',
			'maps'    => ! empty( $r['maps_url'] ) ? GLH::url( $r['maps_url'] ) : '',
			'phone'   => isset( $r['phone'] ) ? (string) $r['phone'] : '',
			'email'   => isset( $r['email'] ) ? (string) $r['email'] : '',
			'web'     => ! empty( $r['web'] ) ? GLH::url( $r['web'] ) : '',
			'hours'   => $hours,
			'tags'    => array_values( array_filter( array_map( 'trim', explode( ',', isset( $r['tags'] ) ? (string) $r['tags'] : '' ) ) ) ),
			'rating'  => isset( $r['rating'] ) && is_numeric( $r['rating'] ) ? max( 0, min( 5, (float) $r['rating'] ) ) : 0,
			'reviews' => isset( $r['reviews'] ) && is_numeric( $r['reviews'] ) ? (int) $r['reviews'] : 0,
			'founding' => ! empty( $r['founding'] ),
			'gallery' => $gal,
			'social'  => $social,
			'sample'  => false,
		), $extra );
	}

	public static function maps_url( array $p ) {
		if ( ! empty( $p['maps'] ) ) {
			return $p['maps'];
		}
		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $p['address'] !== '' ? $p['address'] : trim( $p['name'] . ' Lehigh Acres FL' ) );
	}

	public static function dir_url( array $p ) {
		return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $p['address'] !== '' ? $p['address'] : trim( $p['name'] . ' Lehigh Acres FL' ) );
	}

	private static function pin() {
		return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
	}

	/** Marcado del perfil (idéntico al que construye glh.js para la ventana emergente). $ph = número de recuadros marcados si no hay fotos. */
	public static function html( array $p, array $L, $ph = 3 ) {
		$gal = $p['gallery'];
		$n   = $gal ? count( $gal ) : max( 1, (int) $ph );
		$sl  = '';
		$th  = '';
		for ( $i = 0; $i < $n; $i++ ) {
			$k   = ( $i % 6 ) + 1;
			$src = $gal ? $gal[ $i ]['src'] : '';
			$sl .= '<div class="glh-pf-slide glh-k' . $k . ( $i === 0 ? ' on' : '' ) . '">' . ( $src !== '' ? '<img src="' . esc_url( $src, array( 'http', 'https' ) ) . '" alt="' . esc_attr( $gal[ $i ]['alt'] !== '' ? $gal[ $i ]['alt'] : $p['name'] ) . '" loading="lazy">' : '<b>' . esc_html( strtoupper( $L['photo'] ) . ' ' . ( $i + 1 ) ) . '</b><small>' . esc_html( $p['name'] ) . '</small>' ) . '</div>';
			$th .= '<button type="button" class="glh-pf-th glh-k' . $k . '" aria-label="' . esc_attr( $L['photo'] . ' ' . ( $i + 1 ) ) . '" aria-current="' . ( $i === 0 ? 'true' : 'false' ) . '">' . ( $src !== '' ? '<img src="' . esc_url( $src, array( 'http', 'https' ) ) . '" alt="">' : '' ) . '</button>';
		}
		$ini   = '';
		foreach ( array_slice( preg_split( '/\s+/', trim( preg_replace( '/^Sample\s+/i', '', $p['name'] ) ) ), 0, 2 ) as $w ) {
			$ini .= strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $w, 0, 1 ) : substr( $w, 0, 1 ) );
		}
		$stars = '';
		if ( $p['rating'] > 0 ) {
			$r      = (int) round( $p['rating'] );
			$stars  = '<span class="glh-pf-stars" aria-label="' . esc_attr( $p['rating'] ) . ' / 5">' . str_repeat( '★', $r ) . str_repeat( '☆', 5 - $r ) . '</span> <span>' . esc_html( number_format( $p['rating'], 1 ) . ( $p['reviews'] ? ' (' . $p['reviews'] . ')' : '' ) ) . '</span>';
		}
		$tel  = preg_replace( '/[^+\d]/', '', $p['phone'] );
		$rows = '<div><dt>' . esc_html( $L['address'] ) . '</dt><dd>' . esc_html( $p['address'] ) . '<br><a href="' . esc_url( self::maps_url( $p ) ) . '" target="_blank" rel="noopener">' . esc_html( $L['maps'] ) . ' ↗</a></dd></div>';
		if ( $p['phone'] !== '' ) {
			$rows .= '<div><dt>' . esc_html( $L['phone'] ) . '</dt><dd><a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $p['phone'] ) . '</a></dd></div>';
		}
		if ( $p['email'] !== '' ) {
			$rows .= '<div><dt>' . esc_html( $L['email'] ) . '</dt><dd>' . esc_html( $p['email'] ) . '</dd></div>';
		}
		if ( $p['web'] !== '' ) {
			$rows .= '<div><dt>' . esc_html( $L['web'] ) . '</dt><dd><a href="' . esc_url( $p['web'] ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '#^https?://#', '', $p['web'] ) ) . '</a></dd></div>';
		}
		if ( $p['hours'] ) {
			$h = '';
			foreach ( $p['hours'] as $x ) {
				$h .= '<span>' . esc_html( $x[0] ) . '</span><span>' . esc_html( $x[1] ) . '</span>';
			}
			$rows .= '<div><dt>' . esc_html( $L['hours'] ) . '</dt><dd><div class="glh-pf-hours">' . $h . '</div></dd></div>';
		}
		$tags = '';
		foreach ( $p['tags'] as $t ) {
			$tags .= '<span>' . esc_html( $t ) . '</span>';
		}
		$soc = '';
		foreach ( $p['social'] as $x ) {
			$soc .= '<a href="' . esc_url( $x[1] ) . '" target="_blank" rel="noopener">' . esc_html( $x[0] ) . '</a>';
		}
		return '<div class="glh-pf-gal"><div class="glh-pf-main">' . $sl . ( $n > 1 ? '<button type="button" class="glh-pf-arrow glh-pf-prev" aria-label="‹">‹</button><button type="button" class="glh-pf-arrow glh-pf-next" aria-label="›">›</button><span class="glh-pf-count">1 / ' . $n . '</span>' : '' ) . '</div>' . ( $n > 1 ? '<div class="glh-pf-thumbs">' . $th . '</div>' : '' ) . '</div>'
			. '<div class="glh-pf-info"><div class="glh-pf-head"><div class="glh-pf-logo" style="--dot:' . esc_attr( $p['color'] ) . '">' . ( $p['logo'] !== '' ? '<img src="' . esc_url( $p['logo'], array( 'http', 'https' ) ) . '" alt="">' : esc_html( $ini !== '' ? $ini : 'GO' ) ) . '</div><div><h2 class="glh-pf-name">' . esc_html( $p['name'] ) . '</h2><div class="glh-pf-meta">' . ( $p['cat'] !== '' ? '<span class="glh-pf-cat"><i style="--dot:' . esc_attr( $p['color'] ) . '"></i>' . esc_html( $p['cat'] ) . '</span>' : '' ) . $stars . ( $p['founding'] ? '<span class="glh-pf-badge">' . esc_html( $L['found'] ) . '</span>' : '' ) . '</div></div></div>'
			. ( $p['desc'] !== '' ? '<p class="glh-pf-desc">' . esc_html( $p['desc'] ) . '</p>' : '' )
			. '<div class="glh-pf-actions"><a class="glh-pf-btn main" href="' . esc_url( self::maps_url( $p ) ) . '" target="_blank" rel="noopener">' . self::pin() . ' ' . esc_html( $L['maps'] ) . '</a><a class="glh-pf-btn" href="' . esc_url( self::dir_url( $p ) ) . '" target="_blank" rel="noopener">' . esc_html( $L['dir'] ) . '</a>' . ( $p['phone'] !== '' ? '<a class="glh-pf-btn" href="tel:' . esc_attr( $tel ) . '">' . esc_html( $L['call'] ) . '</a>' : '' ) . ( $p['web'] !== '' ? '<a class="glh-pf-btn" href="' . esc_url( $p['web'] ) . '" target="_blank" rel="noopener">' . esc_html( $L['web'] ) . '</a>' : '' ) . '</div>'
			. '<dl class="glh-pf-list">' . $rows . '</dl>'
			. ( $tags !== '' ? '<div class="glh-pf-tags">' . $tags . '</div>' : '' ) . ( $soc !== '' ? '<div class="glh-pf-social">' . $soc . '</div>' : '' )
			. ( ! empty( $p['note'] ) ? '<p class="glh-pf-note">' . esc_html( $p['note'] ) . '</p>' : '' ) . '</div>';
	}

	/** Datos estructurados LocalBusiness para buscadores. */
	public static function schema( array $p ) {
		if ( $p['name'] === '' ) {
			return '';
		}
		$d = array( '@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $p['name'] );
		if ( $p['desc'] !== '' ) {
			$d['description'] = $p['desc'];
		}
		if ( $p['logo'] !== '' ) {
			$d['logo'] = $p['logo'];
		}
		if ( $p['gallery'] ) {
			$d['image'] = array_map( function ( $g ) {
				return $g['src'];
			}, $p['gallery'] );
		}
		if ( $p['phone'] !== '' ) {
			$d['telephone'] = $p['phone'];
		}
		if ( $p['email'] !== '' ) {
			$d['email'] = $p['email'];
		}
		if ( $p['web'] !== '' ) {
			$d['url'] = $p['web'];
		}
		if ( $p['address'] !== '' ) {
			$d['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $p['address'], 'addressRegion' => 'FL', 'addressCountry' => 'US' );
			$d['hasMap']  = self::maps_url( $p );
		}
		if ( $p['rating'] > 0 && $p['reviews'] > 0 ) {
			$d['aggregateRating'] = array( '@type' => 'AggregateRating', 'ratingValue' => $p['rating'], 'reviewCount' => $p['reviews'] );
		}
		return '<script type="application/ld+json">' . wp_json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	}

	/** Bloque «Perfil de negocio» del constructor. */
	public static function register() {
		VF_Builder_Widgets::register( 'glh-business', array(
			'title'    => 'Perfil de negocio',
			'icon'     => '🏪',
			'cat'      => 'GoLehighAcres',
			'js'       => false,
			'controls' => array_merge(
				array(
					GLH::t( 'name', 'Nombre del negocio', 'Business name' ),
					GLH::t( 'category', 'Categoría', 'Restaurants & Food' ),
					GLH::c( 'color', 'Color de la categoría', 'color', 'content', array( 'default' => '#2E7D55' ) ),
				),
				self::profile_fields(),
				array(
					GLH::c( 'gallery', 'Galería de fotos', 'repeater', 'content', array( 'group' => 'Galería', 'max' => 24, 'title_field' => 'alt',
						'fields' => array( GLH::c( 'image', 'Foto', 'image', 'content' ), GLH::t( 'alt', 'Texto alternativo', '' ) ) ) ),
					GLH::c( 'social', 'Otras redes o enlaces', 'repeater', 'content', array( 'group' => 'Redes', 'max' => 8, 'title_field' => 'name',
						'fields' => array( GLH::t( 'name', 'Nombre (YouTube, TikTok…)', '' ), GLH::c( 'url', 'Enlace', 'url', 'content' ) ) ) ),
					GLH::sw( 'schema', 'Añadir datos estructurados para Google (LocalBusiness)', 1, 'SEO' ),
					GLH::sw( 'sample', 'Es un perfil de ejemplo (muestra el aviso)', 0, 'SEO' ),
					GLH::col( 'c_accent', 'Color de los botones y destacados', '--glh-coral' ),
					GLH::col( 'c_ink', 'Color del texto', '--glh-ink' ),
					GLH::ecol( 'c_bg', 'Fondo del perfil', '.glh-prof', 'background-color' ),
				),
				self::label_controls()
			),
			'render' => function ( $s, $ctx = array() ) {
				GLH::need();
				$p = self::normalize( $s, array( 'name' => GLH::v( $s, 'name', '' ), 'cat' => GLH::v( $s, 'category', '' ) ) );
				if ( ! empty( $s['sample'] ) ) {
					$p['sample'] = true;
					$p['note']   = 'This is a sample profile. Every field is editable for each real business.';
				}
				$h = '<section class="glh glh-business" data-glh="business"' . ( ! empty( $ctx['editor'] ) ? ' data-glh-static="1"' : '' ) . '><div class="glh-prof">' . self::html( $p, self::labels( $s ) ) . '</div>';
				if ( ! empty( $s['schema'] ) && empty( $s['sample'] ) && empty( $ctx['editor'] ) ) {
					$h .= self::schema( $p );
				}
				return $h . '</section>';
			},
		) );
	}
}
