<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bloques del constructor para GoLehighAcres.
 * Cada bloque trae su contenido en inglés por defecto; todo texto, imagen, color, tamaño y animación se edita desde el panel.
 */
class GLH_Widgets {

	private static function c( $id, $label, $type, $tab, array $x = array() ) {
		return GLH::c( $id, $label, $type, $tab, $x );
	}

	private static function v( array $s, $id, $d = '' ) {
		return GLH::v( $s, $id, $d );
	}

	/** Pone a un bloque su marca de módulo y la categoría común. */
	private static function reg( $slug, $title, $icon, array $controls, $render, array $extra = array() ) {
		VF_Builder_Widgets::register( $slug, array_merge( array(
			'title'    => $title,
			'icon'     => $icon,
			'cat'      => 'GoLehighAcres',
			'js'       => false,
			'controls' => $controls,
			'render'   => function ( $s, $ctx = array() ) use ( $render ) {
				GLH::need();
				return call_user_func( $render, $s, is_array( $ctx ) ? $ctx : array() );
			},
		), $extra ) );
	}

	/** Controles de numeración de fotos (para saber qué foto va en cada recuadro). */
	private static function ph_controls( $start ) {
		return array(
			GLH::num( 'ph_start', 'Primer número de foto', 'content', $start, 1, 999, array( 'group' => 'Fotos marcadas' ) ),
			GLH::t( 'ph_prefix', 'Rótulo de los recuadros vacíos', 'FOTO', 'text', 'Fotos marcadas' ),
		);
	}

	private static function static_attr( array $ctx ) {
		return ! empty( $ctx['editor'] ) ? ' data-glh-static="1"' : '';
	}

	public static function register() {

		/* ======================= 1. CABECERA ======================= */
		self::reg( 'glh-header', 'Cabecera GoLehighAcres', '🧭', array(
			self::c( 'logo', 'Símbolo del logo (imagen)', 'image', 'content', array( 'group' => 'Marca' ) ),
			GLH::t( 'brand_go', 'Marca: parte en color', 'GO', 'text', 'Marca' ),
			GLH::t( 'brand_name', 'Marca: nombre', 'LehighAcres', 'text', 'Marca' ),
			GLH::t( 'brand_tld', 'Marca: final', '.org', 'text', 'Marca' ),
			self::c( 'home_url', 'Enlace del logo', 'url', 'content', array( 'default' => '#top', 'group' => 'Marca' ) ),
			self::c( 'items', 'Secciones del menú', 'repeater', 'content', array( 'group' => 'Menú', 'title_field' => 'label', 'max' => 12,
				'default' => array(
					array( 'label' => 'Explore', 'url' => '#top', 'active' => 1 ),
					array( 'label' => 'Directory', 'url' => '#directory', 'active' => 0 ),
					array( 'label' => 'Marketplace', 'url' => '#marketplace', 'active' => 0 ),
					array( 'label' => 'Jobs', 'url' => '#jobs', 'active' => 0 ),
					array( 'label' => 'News & Events', 'url' => '#news', 'active' => 0 ),
					array( 'label' => 'The Future', 'url' => '#future', 'active' => 0 ),
					array( 'label' => 'For Businesses', 'url' => '#business', 'active' => 0 ),
				),
				'fields' => array(
					GLH::t( 'label', 'Texto', '' ),
					self::c( 'url', 'Enlace', 'url', 'content' ),
					self::c( 'active', 'Sección actual', 'switch', 'content' ),
				) ) ),
			GLH::sw( 'cta_show', 'Mostrar el botón de WhatsApp', 1, 'Botón' ),
			GLH::t( 'cta_text', 'Texto del botón', 'Join on WhatsApp', 'text', 'Botón' ),
			self::c( 'cta_url', 'Enlace del botón', 'url', 'content', array( 'default' => '#whatsapp', 'group' => 'Botón' ) ),
			GLH::sw( 'sticky', 'Cabecera fija al hacer scroll', 1 ),
			GLH::num( 'height', 'Altura (px)', 'style', 76, 48, 140, array( 'group' => 'Tamaño', 'css' => array( array( '', '--glh-hh', 'px' ) ) ) ),
			GLH::col( 'c_coral', 'Color de «GO» y del botón', '--glh-coral' ),
			GLH::col( 'c_ink', 'Color del texto', '--glh-ink' ),
			GLH::col( 'c_gold', 'Color de la línea del menú', '--glh-gold' ),
			GLH::ecol( 'c_bg', 'Fondo de la cabecera', '.glh-header', 'background-color' ),
			GLH::size( 'brand_size', 'Tamaño del nombre (px)', '.glh-logo', 14, 48 ),
		), function ( $s, $ctx ) {
			$logo = GLH::img_url( self::v( $s, 'logo', array() ), 'medium' );
			$mark = $logo !== ''
				? '<img src="' . esc_url( $logo, array( 'http', 'https' ) ) . '" alt="" height="36">'
				: '<svg viewBox="0 0 44 24" aria-hidden="true" fill="none" stroke-width="5"><path d="M12 3a9 9 0 0 0-9 9" stroke="#2E7D55"/><path d="M3 12a9 9 0 0 0 9 9" stroke="#6BAA85"/><path d="M12 21h3" stroke="#A4D19B"/><path d="M31 3a9 9 0 0 1 9 9" stroke="#7A4A9A"/><path d="M40 12a9 9 0 0 1-9 9" stroke="#2B8ED0"/><path d="M31 3h-3" stroke="#E3A92B"/><path d="M14 12h14" stroke="#0F5A3C" stroke-width="4"/></svg>';
			$nav = '';
			foreach ( array_values( (array) self::v( $s, 'items', array() ) ) as $it ) {
				$nav .= '<li><a href="' . GLH::url( isset( $it['url'] ) ? $it['url'] : '#' ) . '"' . ( ! empty( $it['active'] ) ? ' aria-current="page"' : '' ) . '>' . esc_html( isset( $it['label'] ) ? $it['label'] : '' ) . '</a></li>';
			}
			$cta = self::v( $s, 'cta_text', '' );
			$h   = '<header class="glh glh-header' . ( ! empty( $s['sticky'] ) ? ' is-sticky' : '' ) . '" data-glh="header"' . self::static_attr( $ctx ) . '>';
			$h  .= '<a class="glh-logo" href="' . GLH::url( self::v( $s, 'home_url', '#top' ) ) . '" aria-label="Home">' . $mark . '<span><em>' . esc_html( self::v( $s, 'brand_go', '' ) ) . '</em><b>' . esc_html( self::v( $s, 'brand_name', '' ) ) . '</b><i>' . esc_html( self::v( $s, 'brand_tld', '' ) ) . '</i></span></a>';
			$h  .= '<nav aria-label="Main"><ul class="glh-nav">' . $nav . ( ! empty( $s['cta_show'] ) && $cta !== '' ? '<li class="glh-m-only"><a href="' . GLH::url( self::v( $s, 'cta_url', '#' ) ) . '">' . esc_html( $cta ) . '</a></li>' : '' ) . '</ul></nav>';
			if ( ! empty( $s['cta_show'] ) && $cta !== '' ) {
				$h .= '<a class="glh-cta" href="' . GLH::url( self::v( $s, 'cta_url', '#' ) ) . '">' . esc_html( $cta ) . '</a>';
			}
			return $h . '<button type="button" class="glh-burger" aria-label="Open menu" aria-expanded="false"><span></span></button></header>';
		} );

		/* ======================= 2. PORTADA ======================= */
		self::reg( 'glh-hero', 'Portada a pantalla completa', '🚀', array_merge( array(
			GLH::t( 'eyebrow', 'Rótulo superior', 'Local guide · Lehigh Acres, Florida' ),
			GLH::t( 'title', 'Titular', 'Know the city before you choose where to live.', 'textarea' ),
			GLH::t( 'lead', 'Texto de apoyo', 'Businesses, jobs, events and the projects on the way, all in one place for people arriving and people already here.', 'textarea' ),
			self::c( 'bg', 'Imagen o foto de fondo', 'image', 'content', array( 'group' => 'Fondo' ) ),
			GLH::t( 'btn_text', 'Texto del botón circular (vacío = sin botón)', 'START', 'text', 'Botón' ),
			self::c( 'btn_url', 'Enlace del botón', 'url', 'content', array( 'default' => '#now', 'group' => 'Botón' ) ),
			GLH::num( 'min_h', 'Altura mínima (vh)', 'style', 86, 30, 120, array( 'group' => 'Tamaño', 'responsive' => true, 'css' => array( array( '', '--glh-hero-h', 'vh' ) ) ) ),
			GLH::col( 'c_overlay', 'Capa sobre la foto', '--glh-overlay' ),
			GLH::col( 'c_text', 'Color del texto', '--glh-hero-ink' ),
			GLH::col( 'c_btn', 'Color del botón circular', '--glh-btn-bg' ),
			GLH::size( 'title_size', 'Tamaño del titular (px)', '.glh-hero__title', 24, 160 ),
		), self::ph_controls( 1 ) ), function ( $s, $ctx ) {
			$btn = self::v( $s, 'btn_text', '' );
			$h   = '<section class="glh glh-hero" data-glh="hero">';
			$h  .= '<div class="glh-hero__bg">' . GLH::photo( self::v( $s, 'bg', array() ), (int) self::v( $s, 'ph_start', 1 ), 1, '16/9', '', self::v( $s, 'ph_prefix', 'FOTO' ), true ) . '<span class="glh-hero__shade"></span></div>';
			$h  .= '<div class="glh-hero__in"><p class="glh-label">' . esc_html( self::v( $s, 'eyebrow', '' ) ) . '</p><h1 class="glh-hero__title">' . esc_html( self::v( $s, 'title', '' ) ) . '</h1><p class="glh-hero__lead">' . esc_html( self::v( $s, 'lead', '' ) ) . '</p></div>';
			if ( $btn !== '' ) {
				$h .= '<a class="glh-start" href="' . GLH::url( self::v( $s, 'btn_url', '#' ) ) . '">' . esc_html( $btn ) . '</a>';
			}
			return $h . '</section>';
		} );

		/* ======================= 3. CARRUSEL 3D ======================= */
		$ratios = GLH::ratios();
		self::reg( 'glh-carousel', 'Carrusel 3D a todo el ancho', '🎠', array_merge( array(
			GLH::t( 'label', 'Rótulo', 'Now on GoLehighAcres' ),
			self::c( 'items', 'Fotos del carrusel (vacío = 6 recuadros numerados)', 'repeater', 'content', array( 'group' => 'Fotos', 'max' => 14, 'title_field' => 'alt',
				'fields' => array( self::c( 'image', 'Foto', 'image', 'content' ), GLH::t( 'alt', 'Texto alternativo', '' ) ) ) ),
			self::c( 'ratio', 'Proporción de las fotos', 'select', 'content', array( 'default' => '4/3', 'options' => $ratios, 'group' => 'Fotos' ) ),
			GLH::sw( 'full', 'Ocupar todo el ancho de la pantalla', 1, 'Diseño' ),
			GLH::num( 'card_w', 'Ancho de cada foto (% de la pantalla)', 'content', 36, 12, 70, array( 'group' => 'Diseño' ) ),
			GLH::num( 'perspective', 'Profundidad 3D (px)', 'content', 2200, 600, 5000, array( 'group' => 'Diseño' ) ),
			GLH::sw( 'auto', 'Girar solo', 1 ),
			GLH::num( 'speed', 'Velocidad de giro (grados por segundo)', 'content', 12, 1, 60 ),
			GLH::sw( 'controls', 'Mostrar flechas y botón de pausa', 1 ),
			GLH::t( 'lbl_prev', 'Etiqueta «anterior»', 'Previous', 'text', 'Textos de los botones' ),
			GLH::t( 'lbl_next', 'Etiqueta «siguiente»', 'Next', 'text', 'Textos de los botones' ),
			GLH::t( 'lbl_pause', 'Texto «pausar»', 'Pause', 'text', 'Textos de los botones' ),
			GLH::t( 'lbl_play', 'Texto «girar»', 'Rotate', 'text', 'Textos de los botones' ),
			GLH::col( 'c_ink', 'Color de los botones', '--glh-ink' ),
		), self::ph_controls( 2 ) ), function ( $s, $ctx ) {
			$items = array_values( array_filter( (array) self::v( $s, 'items', array() ), function ( $i ) {
				return is_array( $i ) && GLH::img_url( isset( $i['image'] ) ? $i['image'] : array() ) !== '';
			} ) );
			$n     = $items ? count( $items ) : 6;
			$start = (int) self::v( $s, 'ph_start', 2 );
			$ratio = self::v( $s, 'ratio', '4/3' );
			$cards = '';
			for ( $i = 0; $i < max( 3, $n ); $i++ ) {
				$it     = $items ? $items[ $i % count( $items ) ] : array();
				$cards .= '<div class="glh-cd">' . GLH::photo( isset( $it['image'] ) ? $it['image'] : array(), $start + $i, $i + 1, $ratio, isset( $it['alt'] ) ? $it['alt'] : '', self::v( $s, 'ph_prefix', 'FOTO' ) ) . '</div>';
			}
			$w   = max( 12, min( 70, (float) self::v( $s, 'card_w', 36 ) ) );
			$h   = '<section class="glh glh-carousel' . ( ! empty( $s['full'] ) ? ' is-full' : '' ) . '" data-glh="carousel"' . self::static_attr( $ctx ) . ' data-auto="' . ( ! empty( $s['auto'] ) ? 1 : 0 ) . '" data-speed="' . (float) self::v( $s, 'speed', 12 ) . '" data-pause="' . esc_attr( self::v( $s, 'lbl_pause', 'Pause' ) ) . '" data-play="' . esc_attr( self::v( $s, 'lbl_play', 'Rotate' ) ) . '">';
			$h  .= '<p class="glh-label">' . esc_html( self::v( $s, 'label', '' ) ) . '</p>';
			$h  .= '<div class="glh-car" style="--glh-card-w:' . esc_attr( $w ) . 'vw;--glh-persp:' . (int) self::v( $s, 'perspective', 2200 ) . 'px;--glh-ratio:' . esc_attr( str_replace( '/', ' / ', GLH::ratio( $ratio ) ) ) . '" role="group" aria-roledescription="carousel" aria-label="' . esc_attr( self::v( $s, 'label', '' ) ) . '"><div class="glh-ring">' . $cards . '</div></div>';
			if ( ! empty( $s['controls'] ) ) {
				$h .= '<div class="glh-car-ctl"><button type="button" data-act="prev" aria-label="' . esc_attr( self::v( $s, 'lbl_prev', 'Previous' ) ) . '">←</button><button type="button" data-act="next" aria-label="' . esc_attr( self::v( $s, 'lbl_next', 'Next' ) ) . '">→</button><button type="button" data-act="toggle" aria-pressed="false">' . esc_html( self::v( $s, 'lbl_pause', 'Pause' ) ) . '</button></div>';
			}
			return $h . '</section>';
		}, array( 'js' => false ) );

		/* ======================= 4. CIFRAS ======================= */
		self::reg( 'glh-stats', 'Ciudad: texto y cifras', '🔢', array(
			GLH::t( 'label', 'Rótulo', 'The city' ),
			GLH::t( 'text', 'Texto principal', 'A local guide to Lehigh Acres, Florida: businesses, jobs, events and upcoming projects,', 'textarea' ),
			GLH::t( 'accent', 'Final destacado (en color)', 'in one place.' ),
			self::c( 'items', 'Cifras', 'repeater', 'content', array( 'group' => 'Cifras', 'max' => 6, 'title_field' => 'label',
				'default' => array(
					array( 'num' => 7, 'suffix' => '', 'label' => 'Guide sections' ),
					array( 'num' => 1, 'suffix' => '', 'label' => 'WhatsApp channel' ),
					array( 'num' => 1, 'suffix' => '', 'label' => 'City' ),
				),
				'fields' => array( self::c( 'num', 'Número', 'number', 'content', array( 'min' => 0, 'max' => 1000000000 ) ), GLH::t( 'prefix', 'Prefijo', '' ), GLH::t( 'suffix', 'Sufijo', '' ), GLH::t( 'label', 'Etiqueta', '' ) ) ) ),
			GLH::sw( 'count', 'Animar los números al aparecer', 1 ),
			GLH::col( 'c_accent', 'Color del final destacado', '--glh-coral' ),
			GLH::col( 'c_ink', 'Color del texto', '--glh-ink' ),
			GLH::size( 'text_size', 'Tamaño del texto principal (px)', '.glh-stats__lead', 14, 80 ),
			GLH::size( 'num_size', 'Tamaño de los números (px)', '.glh-stat b', 20, 160 ),
		), function ( $s, $ctx ) {
			$cells = '';
			foreach ( array_values( (array) self::v( $s, 'items', array() ) ) as $it ) {
				$to     = isset( $it['num'] ) && is_numeric( $it['num'] ) ? (float) $it['num'] : 0;
				$cells .= '<div class="glh-stat"><b>' . esc_html( isset( $it['prefix'] ) ? $it['prefix'] : '' ) . ( ! empty( $s['count'] ) ? '<span data-glh-count="' . esc_attr( $to ) . '">' . esc_html( number_format_i18n( $to ) ) . '</span>' : esc_html( number_format_i18n( $to ) ) ) . esc_html( isset( $it['suffix'] ) ? $it['suffix'] : '' ) . '</b><span>' . esc_html( isset( $it['label'] ) ? $it['label'] : '' ) . '</span></div>';
			}
			return '<section class="glh glh-stats" data-glh="stats"><p class="glh-label">' . esc_html( self::v( $s, 'label', '' ) ) . '</p><p class="glh-stats__lead">' . esc_html( self::v( $s, 'text', '' ) ) . ' <span class="glh-accent">' . esc_html( self::v( $s, 'accent', '' ) ) . '</span></p><div class="glh-stats__row">' . $cells . '</div></section>';
		} );

		/* ======================= 5. DIRECTORIO ======================= */
		self::reg( 'glh-directory', 'Directorio con buscador', '🔎', array_merge( array(
			GLH::t( 'label', 'Rótulo', 'Latest additions' ),
			GLH::t( 'title', 'Título', 'Recently added' ),
			GLH::t( 'placeholder', 'Texto del buscador', 'Search a business or category: plumber, restaurant, dentist…' ),
			GLH::t( 'all_label', 'Texto del filtro «todas»', 'All categories' ),
			GLH::t( 'browse_title', 'Título de las tarjetas de categoría', 'Browse by category' ),
			self::c( 'recent', 'Tabla «Recently added»', 'repeater', 'content', array( 'group' => 'Tabla de novedades', 'max' => 12, 'title_field' => 'name',
				'default' => array(
					array( 'name' => 'Sample business 1', 'category' => 'Restaurant', 'area' => 'Lehigh Acres' ),
					array( 'name' => 'Sample business 2', 'category' => 'Services', 'area' => 'Lehigh Acres' ),
					array( 'name' => 'Sample business 3', 'category' => 'Shop', 'area' => 'Lehigh Acres' ),
					array( 'name' => 'Sample business 4', 'category' => 'Health', 'area' => 'Lehigh Acres' ),
				),
				'fields' => array( self::c( 'image', 'Foto', 'image', 'content' ), GLH::t( 'name', 'Negocio', '' ), GLH::t( 'category', 'Categoría', '' ), GLH::t( 'area', 'Zona', '' ), self::c( 'url', 'Enlace', 'url', 'content' ) ) ) ),
			GLH::t( 'recent_note', 'Nota bajo la tabla', 'Sample rows. They will be replaced by real directory listings.' ),
			self::c( 'cats', 'Categorías de negocio', 'repeater', 'content', array( 'group' => 'Categorías', 'max' => 40, 'title_field' => 'name',
				'default' => self::default_cats(),
				'fields' => array( GLH::t( 'name', 'Nombre', '' ), self::c( 'color', 'Color', 'color', 'content' ), self::c( 'types', 'Tipos de negocio (separados por coma)', 'textarea', 'content' ) ) ) ),
			self::c( 'listings', 'Negocios reales (vacío = se generan ejemplos, uno por cada tipo)', 'repeater', 'content', array( 'group' => 'Negocios', 'max' => 200, 'title_field' => 'name',
				'fields' => array( GLH::t( 'name', 'Nombre', '' ), GLH::t( 'category', 'Categoría (igual que arriba)', '' ), GLH::t( 'type', 'Tipo', '' ), GLH::t( 'area', 'Zona', 'Lehigh Acres' ), self::c( 'url', 'Enlace', 'url', 'content' ) ) ) ),
			GLH::t( 'sample_label', 'Etiqueta de los ejemplos (vacío = ocultar)', 'Sample', 'text', 'Negocios' ),
			GLH::t( 'sample_name', 'Prefijo del nombre de ejemplo', 'Sample', 'text', 'Negocios' ),
			GLH::t( 'area_default', 'Zona por defecto', 'Lehigh Acres', 'text', 'Negocios' ),
			GLH::t( 'lbl_none_t', 'Sin resultados: título', 'No businesses found', 'text', 'Mensajes' ),
			GLH::t( 'lbl_none_d', 'Sin resultados: ayuda', 'Try a broader word like “repair”, “health” or “food”, or choose All categories.', 'text', 'Mensajes' ),
			GLH::t( 'lbl_types', 'Palabra «tipos» en las tarjetas', 'types', 'text', 'Mensajes' ),
			GLH::col( 'c_accent', 'Color de énfasis (foco y resaltado)', '--glh-coral' ),
			GLH::col( 'c_ink', 'Color del texto', '--glh-ink' ),
			GLH::size( 'title_size', 'Tamaño del título (px)', '.glh-dir__title', 24, 160 ),
		), self::ph_controls( 8 ) ), function ( $s, $ctx ) {
			$cats = array();
			foreach ( array_values( (array) self::v( $s, 'cats', array() ) ) as $i => $c ) {
				$types = array_values( array_filter( array_map( 'trim', explode( ',', isset( $c['types'] ) ? (string) $c['types'] : '' ) ) ) );
				$cats[] = array( 'n' => isset( $c['name'] ) ? (string) $c['name'] : '', 'c' => ! empty( $c['color'] ) ? (string) $c['color'] : '#2E7D55', 't' => $types );
			}
			$items = array();
			foreach ( array_values( (array) self::v( $s, 'listings', array() ) ) as $l ) {
				if ( ! is_array( $l ) || empty( $l['name'] ) ) {
					continue;
				}
				$ci = false;
				foreach ( $cats as $i => $c ) {
					if ( strcasecmp( $c['n'], isset( $l['category'] ) ? $l['category'] : '' ) === 0 ) {
						$ci = $i;
						break;
					}
				}
				$items[] = array( 'n' => (string) $l['name'], 'k' => $ci === false ? 0 : $ci, 's' => isset( $l['type'] ) ? (string) $l['type'] : '', 'a' => ! empty( $l['area'] ) ? (string) $l['area'] : self::v( $s, 'area_default', '' ), 'u' => isset( $l['url'] ) ? GLH::url( $l['url'] ) : '', 'x' => 0 );
			}
			$sample = self::v( $s, 'sample_name', 'Sample' );
			if ( ! $items ) {
				foreach ( $cats as $i => $c ) {
					foreach ( $c['t'] as $t ) {
						$items[] = array( 'n' => trim( $sample . ' ' . ucwords( $t ) ), 'k' => $i, 's' => $t, 'a' => self::v( $s, 'area_default', '' ), 'u' => '', 'x' => 1 );
					}
				}
			}
			$data = array( 'cats' => $cats, 'items' => $items, 'sample' => self::v( $s, 'sample_label', '' ), 'none' => array( self::v( $s, 'lbl_none_t', '' ), self::v( $s, 'lbl_none_d', '' ) ), 'types' => self::v( $s, 'lbl_types', 'types' ) );
			$start = (int) self::v( $s, 'ph_start', 8 );
			$rows  = '';
			foreach ( array_values( (array) self::v( $s, 'recent', array() ) ) as $i => $r ) {
				$rows .= '<tr><td class="glh-thumb">' . GLH::photo( isset( $r['image'] ) ? $r['image'] : array(), $start + $i, $i + 2, '4/3', isset( $r['name'] ) ? $r['name'] : '', self::v( $s, 'ph_prefix', 'FOTO' ) ) . '</td><td class="glh-tn">' . ( ! empty( $r['url'] ) ? '<a href="' . GLH::url( $r['url'] ) . '">' . esc_html( $r['name'] ) . '</a>' : esc_html( isset( $r['name'] ) ? $r['name'] : '' ) ) . '</td><td>' . esc_html( isset( $r['category'] ) ? $r['category'] : '' ) . '</td><td>' . esc_html( isset( $r['area'] ) ? $r['area'] : '' ) . '</td></tr>';
			}
			$uid   = 'glhq' . substr( md5( wp_json_encode( $s ) ), 0, 6 );
			$count = array();
			foreach ( $items as $it ) {
				$count[ $it['k'] ] = isset( $count[ $it['k'] ] ) ? $count[ $it['k'] ] + 1 : 1;
			}
			$opts  = '<li id="' . $uid . '-o-all" class="glh-dd-opt" role="option" data-id="all" aria-selected="true"><i class="glh-dd-dot"></i><span>' . esc_html( self::v( $s, 'all_label', 'All categories' ) ) . '</span></li><li class="glh-dd-sep" role="presentation"></li>';
			$cards = '';
			foreach ( $cats as $i => $c ) {
				$opts  .= '<li id="' . $uid . '-o-' . (int) $i . '" class="glh-dd-opt" role="option" data-id="' . (int) $i . '" aria-selected="false"><i class="glh-dd-dot" style="--dot:' . esc_attr( $c['c'] ) . '"></i><span>' . esc_html( $c['n'] ) . '</span><small>' . ( isset( $count[ $i ] ) ? (int) $count[ $i ] : 0 ) . '</small></li>';
				$cards .= '<button type="button" class="glh-catcard" data-id="' . (int) $i . '" style="--dot:' . esc_attr( $c['c'] ) . '"><b>' . count( $c['t'] ) . ' ' . esc_html( self::v( $s, 'lbl_types', 'types' ) ) . '</b><h4>' . esc_html( $c['n'] ) . '</h4><p>' . esc_html( implode( ', ', array_slice( $c['t'], 0, 4 ) ) ) . '…</p></button>';
			}
			$dd = '<div class="glh-filterbar"><div class="glh-dd" data-open="false"><button type="button" class="glh-dd-btn" aria-haspopup="listbox" aria-expanded="false" aria-controls="' . $uid . '-l"><i class="glh-dd-dot"></i><span class="glh-dd-label" data-all="' . esc_attr( self::v( $s, 'all_label', 'All categories' ) ) . '">' . esc_html( self::v( $s, 'all_label', 'All categories' ) ) . '</span><svg class="glh-dd-chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><ul class="glh-dd-list" id="' . $uid . '-l" role="listbox" tabindex="-1" hidden>' . $opts . '</ul></div><p class="glh-status" aria-live="polite"></p></div>';
			return '<section class="glh glh-dir" data-glh="directory" data-glh-data="' . GLH::json( $data ) . '"><p class="glh-label">' . esc_html( self::v( $s, 'label', '' ) ) . '</p><h2 class="glh-dir__title" data-glh-rise>' . esc_html( self::v( $s, 'title', '' ) ) . '</h2>'
				. '<form class="glh-search" role="search" onsubmit="return false"><label for="' . $uid . '" class="glh-sr">' . esc_html( self::v( $s, 'placeholder', '' ) ) . '</label><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5 21 21"/></svg><input id="' . $uid . '" type="search" autocomplete="off" placeholder="' . esc_attr( self::v( $s, 'placeholder', '' ) ) . '"><button type="button" class="glh-clear" aria-label="Clear" hidden>&#10005;</button></form>'
				. $dd
				. '<div class="glh-recent"><div class="glh-tbl"><table><tbody>' . $rows . '</tbody></table></div><p class="glh-note">' . esc_html( self::v( $s, 'recent_note', '' ) ) . '</p><h3 class="glh-browse">' . esc_html( self::v( $s, 'browse_title', '' ) ) . '</h3><div class="glh-catgrid">' . $cards . '</div></div><div class="glh-found" hidden></div></section>';
		} );

		/* ======================= 6. RECORRIDO ======================= */
		self::reg( 'glh-journey', 'Recorrido con tarjeta fija', '🧭', array_merge( array(
			GLH::t( 'title', 'Título', 'From discovery' ),
			GLH::t( 'accent', 'Final del título (en color)', 'to decision' ),
			GLH::t( 'lead', 'Texto de apoyo', 'Every stage of the guide is useful on its own, before any housing offer.', 'textarea' ),
			GLH::t( 'card_tag', 'Rótulo de la tarjeta', 'GUIDE' ),
			self::c( 'steps', 'Etapas', 'repeater', 'content', array( 'group' => 'Etapas', 'max' => 10, 'title_field' => 'title',
				'default' => array(
					array( 'title' => 'Discover', 'text' => 'Tourism, events and local businesses show what the city is like.' ),
					array( 'title' => 'Trust', 'text' => 'Verifiable local content and a directory built by the businesses themselves.' ),
					array( 'title' => 'Sign up', 'text' => 'Anyone who wants to can create a profile and receive useful updates.' ),
					array( 'title' => 'Take part', 'text' => 'Memberships, marketplace and jobs for businesses and neighbours.' ),
					array( 'title' => 'Decide', 'text' => 'Anyone who wants to live here can see the available housing projects, always marked as advertising.' ),
				),
				'fields' => array( GLH::t( 'title', 'Título', '' ), self::c( 'text', 'Texto', 'textarea', 'content' ), self::c( 'image', 'Foto de la tarjeta', 'image', 'content' ) ) ) ),
			GLH::t( 'btn_text', 'Texto del botón (vacío = sin botón)', 'Meet the guide', 'text', 'Botón' ),
			self::c( 'btn_url', 'Enlace del botón', 'url', 'content', array( 'default' => '#business', 'group' => 'Botón' ) ),
			GLH::col( 'c_card', 'Color de la tarjeta fija', '--glh-deep' ),
			GLH::col( 'c_accent', 'Color del paso activo y del título', '--glh-coral' ),
			GLH::col( 'c_ink', 'Color del texto', '--glh-ink' ),
			GLH::size( 'title_size', 'Tamaño del título (px)', '.glh-journey__title', 24, 160 ),
		), self::ph_controls( 12 ) ), function ( $s, $ctx ) {
			$steps = array_values( (array) self::v( $s, 'steps', array() ) );
			$start = (int) self::v( $s, 'ph_start', 12 );
			$pre   = self::v( $s, 'ph_prefix', 'FOTO' );
			$cards = '';
			$list  = '';
			foreach ( $steps as $i => $st ) {
				$cards .= '<div class="glh-jc' . ( $i === 0 ? ' on' : '' ) . '" data-i="' . (int) $i . '">' . GLH::photo( isset( $st['image'] ) ? $st['image'] : array(), $start + $i, $i + 1, '4/5', isset( $st['title'] ) ? $st['title'] : '', $pre ) . '</div>';
				$list  .= '<div class="glh-st' . ( $i === 0 ? ' on' : '' ) . '" data-i="' . (int) $i . '"><span class="glh-n">' . str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) . '</span><h3>' . esc_html( isset( $st['title'] ) ? $st['title'] : '' ) . '</h3><p>' . esc_html( isset( $st['text'] ) ? $st['text'] : '' ) . '</p></div>';
			}
			$btn = self::v( $s, 'btn_text', '' );
			return '<section class="glh glh-journey" data-glh="journey" data-total="' . count( $steps ) . '"><h2 class="glh-journey__title" data-glh-rise>' . esc_html( self::v( $s, 'title', '' ) ) . ' <span class="glh-accent">' . esc_html( self::v( $s, 'accent', '' ) ) . '</span></h2><p class="glh-journey__lead" data-glh-rise>' . esc_html( self::v( $s, 'lead', '' ) ) . '</p>'
				. '<div class="glh-jgrid"><div class="glh-jcard"><div class="glh-jcnt"><span data-glh-cnt>01 / ' . str_pad( (string) count( $steps ), 2, '0', STR_PAD_LEFT ) . '</span><span>' . esc_html( self::v( $s, 'card_tag', '' ) ) . '</span></div><div class="glh-jstack">' . $cards . '</div></div>'
				. '<div class="glh-timeline">' . $list . ( $btn !== '' ? '<a class="glh-pill" href="' . GLH::url( self::v( $s, 'btn_url', '#' ) ) . '">' . esc_html( $btn ) . ' <span>→</span></a>' : '' ) . '</div></div></section>';
		} );

		/* ======================= 7. NEGOCIOS FUNDADORES (simétrico) ======================= */
		$ph_fields = array( self::c( 'image', 'Foto', 'image', 'content' ), self::c( 'ratio', 'Proporción', 'select', 'content', array( 'default' => '4/3', 'options' => $ratios ) ) );
		$fd_left   = array( array( 'ratio' => '4/3' ), array( 'ratio' => '3/4' ), array( 'ratio' => '4/3' ), array( 'ratio' => '1/1' ) );
		self::reg( 'glh-founding', 'Negocios fundadores (simétrico)', '🤝', array_merge( array(
			GLH::t( 'label', 'Rótulo', 'Founding businesses' ),
			GLH::t( 'title', 'Título', 'Own a business in Lehigh Acres?', 'textarea' ),
			GLH::t( 'lead', 'Texto', 'The first businesses to join form the foundation of the directory and keep their benefits from day one.', 'textarea' ),
			GLH::t( 'btn_text', 'Texto del botón', 'Join as a founding business', 'text', 'Botón' ),
			self::c( 'btn_url', 'Enlace del botón', 'url', 'content', array( 'default' => '#whatsapp', 'group' => 'Botón' ) ),
			self::c( 'left', 'Fotos de la columna izquierda', 'repeater', 'content', array( 'group' => 'Fotos', 'max' => 8, 'title_field' => 'ratio', 'default' => $fd_left, 'fields' => $ph_fields ) ),
			self::c( 'right', 'Fotos de la columna derecha', 'repeater', 'content', array( 'group' => 'Fotos', 'max' => 8, 'title_field' => 'ratio', 'default' => $fd_left, 'fields' => $ph_fields ) ),
			GLH::sw( 'mirror', 'Forzar simetría: la derecha copia las proporciones de la izquierda', 1, 'Fotos' ),
			GLH::sw( 'parallax', 'Movimiento suave al hacer scroll', 1, 'Fotos' ),
			GLH::col( 'c_bg', 'Color de fondo', '--glh-deep' ),
			GLH::col( 'c_ink', 'Color del texto', '--glh-deep-ink' ),
			GLH::size( 'title_size', 'Tamaño del título (px)', '.glh-founding__title', 24, 160 ),
		), self::ph_controls( 17 ) ), function ( $s, $ctx ) {
			$l     = array_values( (array) self::v( $s, 'left', array() ) );
			$r     = array_values( (array) self::v( $s, 'right', array() ) );
			$start = (int) self::v( $s, 'ph_start', 17 );
			$pre   = self::v( $s, 'ph_prefix', 'FOTO' );
			$col   = function ( array $items, $from, array $ratios_from ) use ( $pre ) {
				$h = '';
				foreach ( $items as $i => $it ) {
					$ra = $ratios_from ? ( isset( $ratios_from[ $i ]['ratio'] ) ? $ratios_from[ $i ]['ratio'] : '4/3' ) : ( isset( $it['ratio'] ) ? $it['ratio'] : '4/3' );
					$h .= GLH::photo( isset( $it['image'] ) ? $it['image'] : array(), $from + $i, $from + $i, $ra, '', $pre );
				}
				return $h;
			};
			$mirror = ! empty( $s['mirror'] );
			$lh     = $col( $l, $start, array() );
			$rh     = $col( $r ? $r : $l, $start + count( $l ), $mirror ? $l : array() );
			$par    = ! empty( $s['parallax'] ) ? ' data-glh-par="0.05"' : '';
			return '<section class="glh glh-founding" data-glh="founding"><div class="glh-fgrid"><div class="glh-fcol"' . $par . '>' . $lh . '</div><div class="glh-fcopy"><p class="glh-label">' . esc_html( self::v( $s, 'label', '' ) ) . '</p><h2 class="glh-founding__title">' . esc_html( self::v( $s, 'title', '' ) ) . '</h2><p class="glh-flead">' . esc_html( self::v( $s, 'lead', '' ) ) . '</p><a class="glh-btn-l" href="' . GLH::url( self::v( $s, 'btn_url', '#' ) ) . '">' . esc_html( self::v( $s, 'btn_text', '' ) ) . '</a></div><div class="glh-fcol"' . $par . '>' . $rh . '</div></div></section>';
		} );

		/* ======================= 8. CITA ======================= */
		self::reg( 'glh-quote', 'Cita a pantalla completa', '❝', array_merge( array(
			GLH::t( 'text', 'Frase', 'A city is known by its', 'textarea' ),
			GLH::t( 'accent', 'Palabra destacada', 'moments.' ),
			GLH::t( 'cite', 'Autor o lugar', 'Lehigh Acres, Florida' ),
			self::c( 'bg', 'Foto de fondo', 'image', 'content', array( 'group' => 'Fondo' ) ),
			GLH::num( 'min_h', 'Altura mínima (vh)', 'style', 62, 30, 120, array( 'group' => 'Tamaño', 'responsive' => true, 'css' => array( array( '', '--glh-quote-h', 'vh' ) ) ) ),
			GLH::col( 'c_overlay', 'Capa sobre la foto', '--glh-overlay' ),
			GLH::col( 'c_text', 'Color del texto', '--glh-hero-ink' ),
			GLH::col( 'c_accent', 'Color de la palabra destacada', '--glh-quote-accent' ),
			GLH::size( 'text_size', 'Tamaño de la frase (px)', '.glh-quote__text', 20, 160 ),
		), self::ph_controls( 25 ) ), function ( $s, $ctx ) {
			return '<section class="glh glh-quote" data-glh="quote"><div class="glh-quote__bg">' . GLH::photo( self::v( $s, 'bg', array() ), (int) self::v( $s, 'ph_start', 25 ), 3, '16/9', '', self::v( $s, 'ph_prefix', 'FOTO' ), true ) . '<span class="glh-hero__shade"></span></div><div class="glh-quote__in"><blockquote class="glh-quote__text">' . esc_html( self::v( $s, 'text', '' ) ) . ' <span class="glh-quote__acc">' . esc_html( self::v( $s, 'accent', '' ) ) . '</span></blockquote><cite>' . esc_html( self::v( $s, 'cite', '' ) ) . '</cite></div></section>';
		} );

		/* ======================= 9. CINTA ======================= */
		self::reg( 'glh-marquee', 'Cinta de palabras', '〰️', array(
			self::c( 'words', 'Palabras (una por línea)', 'textarea', 'content', array( 'default' => "Discover\nExplore\nConnect\nLive" ) ),
			GLH::t( 'sep', 'Separador', '•' ),
			GLH::num( 'speed', 'Segundos por vuelta (más alto = más lento)', 'content', 30, 5, 200 ),
			GLH::sw( 'pause_hover', 'Pausar al pasar el mouse', 1 ),
			GLH::col( 'c_sep', 'Color del separador', '--glh-coral' ),
			GLH::col( 'c_ink', 'Color del texto', '--glh-ink' ),
			GLH::size( 'size', 'Tamaño de letra (px)', '.glh-track', 10, 80 ),
		), function ( $s, $ctx ) {
			$w = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) self::v( $s, 'words', '' ) ) ) ) );
			if ( ! $w ) {
				return '';
			}
			$one = '';
			foreach ( $w as $word ) {
				$one .= '<span>' . esc_html( $word ) . '</span><i aria-hidden="true">' . esc_html( self::v( $s, 'sep', '•' ) ) . '</i>';
			}
			return '<div class="glh glh-marquee' . ( ! empty( $s['pause_hover'] ) ? ' is-pausable' : '' ) . '" data-glh="marquee" aria-hidden="true" style="--glh-dur:' . (int) self::v( $s, 'speed', 30 ) . 's"><div class="glh-track"><div class="glh-run">' . str_repeat( $one, 4 ) . '</div><div class="glh-run">' . str_repeat( $one, 4 ) . '</div></div></div>';
		} );

		/* ======================= 10. PARED DE VIDEOS ======================= */
		self::reg( 'glh-videowall', 'Pared de videos infinita', '🎬', array(
			self::c( 'items', 'Videos (si hay menos que recuadros se repiten; vacío = recuadros numerados)', 'repeater', 'content', array( 'group' => 'Videos', 'max' => 80, 'title_field' => 'title',
				'fields' => array(
					GLH::t( 'title', 'Título', '' ),
					self::c( 'poster', 'Imagen de portada', 'image', 'content' ),
					self::c( 'video', 'Archivo de video (URL .mp4 o .webm)', 'url', 'content' ),
					GLH::t( 'category', 'Categoría (de la lista de abajo)', '' ),
					self::c( 'orient', 'Orientación', 'select', 'content', array( 'default' => 'auto', 'options' => array( 'auto' => 'Automática (alterna)', 'P' => 'Vertical', 'L' => 'Horizontal' ) ) ),
				) ) ),
			self::c( 'cats', 'Categorías y colores (una por línea: Nombre|#color)', 'textarea', 'content', array( 'group' => 'Videos', 'default' => "Events|#CC7A3A\nNews|#5C6FB6\nCommunity|#FF5A5F\nTourism|#A4D19B\nBusinesses|#E3A92B\nJobs|#2B8ED0" ) ),
			GLH::num( 'rows', 'Filas', 'content', 6, 2, 10, array( 'group' => 'Pared' ) ),
			GLH::num( 'cols', 'Columnas (impar para que cada fila sea simétrica)', 'content', 11, 3, 21, array( 'group' => 'Pared' ) ),
			GLH::num( 'height', 'Altura de la pared (vh)', 'style', 100, 40, 200, array( 'group' => 'Tamaño', 'responsive' => true, 'css' => array( array( '', '--glh-wall-h', 'vh' ) ) ) ),
			GLH::num( 'tile_h', 'Altura de cada fila (% de la pared)', 'content', 36, 15, 70, array( 'group' => 'Pared' ) ),
			GLH::num( 'gap', 'Separación (px)', 'content', 16, 0, 60, array( 'group' => 'Pared' ) ),
			GLH::sw( 'drift', 'Movimiento aleatorio cuando nadie la toca', 1, 'Movimiento' ),
			GLH::num( 'drift_speed', 'Velocidad del movimiento aleatorio', 'content', 36, 5, 200, array( 'group' => 'Movimiento' ) ),
			GLH::num( 'idle', 'Segundos de reposo antes de moverse sola', 'content', 2, 0, 30, array( 'group' => 'Movimiento' ) ),
			GLH::sw( 'wheel', 'La rueda del mouse mueve la pared (desactiva el scroll de la página sobre ella)', 0, 'Movimiento' ),
			GLH::num( 'preview', 'Segundos de vista previa al pasar el mouse (sin sonido)', 'content', 10, 0, 60, array( 'group' => 'Reproducción' ) ),
			GLH::sw( 'popup', 'Abrir ventana emergente al hacer click', 1, 'Reproducción' ),
			GLH::sw( 'frame', 'Marco dorado alrededor', 1, 'Diseño' ),
			GLH::t( 'cur_drag', 'Texto del cursor sobre la pared', 'Drag', 'text', 'Cursor' ),
			GLH::t( 'cur_go', 'Texto del cursor sobre un video', 'GO!', 'text', 'Cursor' ),
			GLH::t( 'lbl_prev', 'Rótulo «Vista previa · sin sonido»', 'Preview · no sound', 'text', 'Textos' ),
			GLH::t( 'lbl_nofile', 'Texto del reproductor sin archivo', 'The video plays here, with sound', 'text', 'Textos' ),
			GLH::t( 'lbl_close', 'Rótulo «Cerrar»', 'Close', 'text', 'Textos' ),
			GLH::col( 'c_go', 'Color del cursor «GO!»', '--glh-coral' ),
			GLH::col( 'c_gold', 'Color del marco y bordes', '--glh-gold' ),
			GLH::ecol( 'c_bg', 'Fondo de la pared', '.glh-wall', 'background-color' ),
		), function ( $s, $ctx ) {
			$rows = max( 2, min( 10, (int) self::v( $s, 'rows', 6 ) ) );
			$cols = max( 3, min( 21, (int) self::v( $s, 'cols', 11 ) ) );
			if ( $cols % 2 === 0 ) {
				$cols++; // siempre impar: cada fila queda simétrica
			}
			$cats = array();
			foreach ( preg_split( '/\r\n|\r|\n/', (string) self::v( $s, 'cats', '' ) ) as $line ) {
				$p = array_map( 'trim', explode( '|', $line ) );
				if ( $p[0] !== '' ) {
					$cats[ strtolower( $p[0] ) ] = array( $p[0], isset( $p[1] ) && VF_Builder_Widgets::color_ok( $p[1] ) ? $p[1] : '#E3A92B' );
				}
			}
			$items = array_values( array_filter( (array) self::v( $s, 'items', array() ), 'is_array' ) );
			$tiles = '';
			for ( $r = 0; $r < $rows; $r++ ) {
				for ( $c = 0; $c < $cols; $c++ ) {
					$n  = $r * $cols + $c;
					$it = $items ? $items[ $n % count( $items ) ] : array();
					$o  = isset( $it['orient'] ) ? $it['orient'] : 'auto';
					$k  = $o === 'P' || $o === 'L' ? $o : ( ( $r + $c ) % 2 === 1 ? 'P' : 'L' );
					$ct = isset( $it['category'] ) ? strtolower( trim( $it['category'] ) ) : '';
					if ( ! isset( $cats[ $ct ] ) && $cats ) {
						$keys = array_keys( $cats );
						$ct   = $keys[ $n % count( $keys ) ];
					}
					$cn     = isset( $cats[ $ct ] ) ? $cats[ $ct ][0] : '';
					$cc     = isset( $cats[ $ct ] ) ? $cats[ $ct ][1] : '#E3A92B';
					$poster = GLH::img_url( isset( $it['poster'] ) ? $it['poster'] : array(), 'medium_large' );
					$video  = ! empty( $it['video'] ) ? esc_url( $it['video'], array( 'http', 'https' ) ) : '';
					$title  = isset( $it['title'] ) && $it['title'] !== '' ? $it['title'] : 'Video ' . str_pad( (string) ( $n + 1 ), 2, '0', STR_PAD_LEFT );
					$tiles .= '<button type="button" class="glh-tile glh-t' . $k . ' glh-k' . ( ( ( $r + $c ) % 6 ) + 1 ) . '" data-n="' . (int) $n . '" data-r="' . (int) $r . '" data-video="' . esc_attr( $video ) . '" data-title="' . esc_attr( $title ) . '" data-cat="' . esc_attr( $cn ) . '" data-cc="' . esc_attr( $cc ) . '" aria-label="' . esc_attr( $title ) . '">'
						. ( $poster !== '' ? '<img src="' . esc_url( $poster, array( 'http', 'https' ) ) . '" alt="" loading="lazy" decoding="async">' : '' )
						. '<span class="glh-no">VIDEO ' . str_pad( (string) ( $n + 1 ), 2, '0', STR_PAD_LEFT ) . '</span>'
						. ( $cn !== '' ? '<span class="glh-tag" style="background:' . esc_attr( $cc ) . '">' . esc_html( $cn ) . '</span>' : '' ) . '</button>';
				}
			}
			$cfg = array(
				'rows' => $rows, 'cols' => $cols, 'tile' => (float) self::v( $s, 'tile_h', 36 ), 'gap' => (int) self::v( $s, 'gap', 16 ),
				'drift' => ! empty( $s['drift'] ) ? 1 : 0, 'driftSpeed' => (float) self::v( $s, 'drift_speed', 36 ), 'idle' => (float) self::v( $s, 'idle', 2 ),
				'wheel' => ! empty( $s['wheel'] ) ? 1 : 0, 'preview' => (float) self::v( $s, 'preview', 10 ), 'popup' => ! empty( $s['popup'] ) ? 1 : 0,
				'cur' => array( self::v( $s, 'cur_drag', 'Drag' ), self::v( $s, 'cur_go', 'GO!' ) ),
				'txt' => array( self::v( $s, 'lbl_prev', '' ), self::v( $s, 'lbl_nofile', '' ), self::v( $s, 'lbl_close', 'Close' ) ),
			);
			return '<section class="glh glh-wall' . ( ! empty( $s['frame'] ) ? ' has-frame' : '' ) . '" data-glh="wall"' . self::static_attr( $ctx ) . ' data-glh-cfg="' . GLH::json( $cfg ) . '" aria-label="Video wall. Drag to explore."><div class="glh-wall__stage">' . $tiles . '</div></section>';
		} );
	}

	/** Categorías de negocio típicas de Lehigh Acres, con los tipos de cada una. */
	public static function default_cats() {
		$raw = array(
			array( 'Restaurants & Food', '#2E7D55', 'restaurant, cafe, bakery, food truck, grocery, catering, pizza, bar & grill' ),
			array( 'Home Services', '#CC7A3A', 'plumber, electrician, HVAC, roofing, landscaping, pest control, house cleaning, pool service, handyman, fencing' ),
			array( 'Construction & Contractors', '#2B5BA8', 'general contractor, builder, concrete, painting, flooring, remodeling, windows & doors, solar' ),
			array( 'Real Estate', '#7A4A9A', 'realtor, property management, title company, home inspection, appraiser, mortgage broker' ),
			array( 'Auto & Transport', '#C99A22', 'auto repair, car wash, car dealership, towing, tires, body shop, trucking' ),
			array( 'Health & Medical', '#2B8ED0', 'doctor, dentist, urgent care, pharmacy, chiropractor, therapist, optometrist, home health' ),
			array( 'Beauty & Personal Care', '#5C6FB6', 'hair salon, barber, nail salon, spa, massage, tattoo' ),
			array( 'Retail & Shopping', '#FF5A5F', 'clothing, furniture, hardware store, gift shop, thrift store, convenience store, florist' ),
			array( 'Education & Childcare', '#0F5A3C', 'daycare, school, tutoring, driving school, language classes, music lessons' ),
			array( 'Professional Services', '#2E7D55', 'attorney, accountant, insurance agent, notary, consulting, translation, tax preparation' ),
			array( 'Financial Services', '#CC7A3A', 'bank, credit union, loans, check cashing, investment advisor' ),
			array( 'Technology & Marketing', '#2B5BA8', 'web design, IT support, marketing agency, printing, photography, video production' ),
			array( 'Pets & Animals', '#7A4A9A', 'veterinarian, pet grooming, pet boarding, pet store, farm & feed' ),
			array( 'Fitness & Recreation', '#C99A22', 'gym, yoga, martial arts, sports league, golf, fishing, parks' ),
			array( 'Events & Entertainment', '#2B8ED0', 'event venue, DJ, party rental, wedding services, live music' ),
			array( 'Hotels & Lodging', '#5C6FB6', 'hotel, motel, RV park, vacation rental' ),
			array( 'Churches & Community', '#FF5A5F', 'church, nonprofit, social club, volunteer group, food bank' ),
			array( 'Farm & Garden', '#0F5A3C', 'plant nursery, farm, equipment rental, tree service, irrigation' ),
		);
		$out = array();
		foreach ( $raw as $r ) {
			$out[] = array( 'name' => $r[0], 'color' => $r[1], 'types' => $r[2] );
		}
		return $out;
	}
}
