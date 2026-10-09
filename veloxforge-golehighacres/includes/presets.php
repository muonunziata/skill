<?php
defined( 'ABSPATH' ) || exit;

/** Secciones de la biblioteca de VeloxForge: cada bloque suelto y las páginas completas, listas para insertar y editar. */
class GLH_Presets {

	private static function W( $widget, array $s = array() ) {
		return array( 'type' => 'widget', 'widget' => $widget, 'settings' => $s );
	}

	/** Sección de ancho completo, sin relleno ni espacios: el propio bloque decide su diseño. */
	private static function S( array $kids, array $s = array() ) {
		return array( 'type' => 'container', 'settings' => array_merge( array(
			'boxed'   => 'full',
			'gap'     => array( 'desktop' => 0 ),
			'padding' => array( 'desktop' => array( 'top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0, 'unit' => 'px' ) ),
		), $s ), 'children' => $kids );
	}

	/** Sección con ancho máximo y relleno vertical (para bloques de texto). */
	private static function B( array $kids, array $s = array() ) {
		return self::S( $kids, array_merge( array(
			'boxed'   => 'boxed',
			'cw'      => 1320,
			'padding' => array( 'desktop' => array( 'top' => 96, 'right' => 24, 'bottom' => 96, 'left' => 24, 'unit' => 'px' ), 'mobile' => array( 'top' => 56, 'right' => 16, 'bottom' => 56, 'left' => 16, 'unit' => 'px' ) ),
		), $s ) );
	}

	public static function add( array $p ) {
		$add = function ( $id, $title, $cat, $icon, $desc, array $tree ) use ( &$p ) {
			$p[ $id ] = array( 'id' => $id, 'title' => $title, 'cat' => $cat, 'icon' => $icon, 'desc' => $desc, 'tree' => $tree );
		};
		$c = 'GoLehighAcres';

		$add( 'glh-header', 'Cabecera con logo y secciones', $c, '🧭', 'Logo, menú de secciones y botón de WhatsApp.', array( self::S( array( self::W( 'glh-header' ) ) ) ) );
		$add( 'glh-hero', 'Portada a pantalla completa', $c, '🚀', 'Foto de fondo, titular y botón circular START.', array( self::S( array( self::W( 'glh-hero' ) ) ) ) );
		$add( 'glh-carousel', 'Carrusel 3D giratorio', $c, '🎠', 'Fotos en anillo 3D que giran solas, a todo el ancho.', array( self::S( array( self::W( 'glh-carousel' ) ), array( 'padding' => array( 'desktop' => array( 'top' => 96, 'right' => 0, 'bottom' => 64, 'left' => 0, 'unit' => 'px' ) ) ) ) ) );
		$add( 'glh-stats', 'Ciudad: texto y cifras', $c, '🔢', 'Frase principal y tres cifras que se animan.', array( self::B( array( self::W( 'glh-stats' ) ) ) ) );
		$add( 'glh-directory', 'Directorio con buscador y categorías', $c, '🔎', 'Buscador, 18 categorías de negocio, filtros y tabla de novedades.', array( self::B( array( self::W( 'glh-directory' ) ) ) ) );
		$add( 'glh-business', 'Perfil de negocio', $c, '🏪', 'Logo, descripción, galería, contacto, horario y enlace a Google Maps.', array( self::B( array( self::W( 'glh-business', array( 'sample' => 1 ) ) ) ) ) );
		$add( 'glh-journey', 'Recorrido con tarjeta fija', $c, '🧭', 'Etapas con una tarjeta de foto que cambia al avanzar.', array( self::B( array( self::W( 'glh-journey' ) ) ) ) );
		$add( 'glh-founding', 'Negocios fundadores (simétrico)', $c, '🤝', 'Texto centrado y una columna de fotos espejo a cada lado.', array( self::S( array( self::W( 'glh-founding' ) ), array( 'bg_color' => '#0B2F22' ) ) ) );
		$add( 'glh-quote', 'Cita a pantalla completa', $c, '❝', 'Frase grande sobre una foto de fondo.', array( self::S( array( self::W( 'glh-quote' ) ) ) ) );
		$add( 'glh-marquee', 'Cinta de palabras', $c, '〰️', 'Palabras que se desplazan sin fin.', array( self::S( array( self::W( 'glh-marquee' ) ) ) ) );
		$add( 'glh-videowall', 'Pared de videos infinita', $c, '🎬', 'Videos que se arrastran en todas direcciones, con vista previa y reproductor.', array( self::S( array( self::W( 'glh-videowall' ) ) ) ) );

		$add( 'glh-page-home', 'Página completa: Explore (inicio)', $c, '🏡', 'Portada, carrusel, ciudad, directorio, recorrido, negocios, cita y cinta.', array(
			self::S( array( self::W( 'glh-hero' ) ) ),
			self::S( array( self::W( 'glh-carousel' ) ), array( 'padding' => array( 'desktop' => array( 'top' => 96, 'right' => 0, 'bottom' => 64, 'left' => 0, 'unit' => 'px' ) ) ) ),
			self::B( array( self::W( 'glh-stats' ) ) ),
			self::B( array( self::W( 'glh-directory' ) ) ),
			self::B( array( self::W( 'glh-journey' ) ) ),
			self::S( array( self::W( 'glh-founding' ) ), array( 'bg_color' => '#0B2F22' ) ),
			self::S( array( self::W( 'glh-quote' ) ) ),
			self::S( array( self::W( 'glh-marquee' ) ) ),
		) );
		$add( 'glh-page-news', 'Página completa: News & Events (pared de videos)', $c, '🎬', 'Pared de videos a pantalla completa.', array( self::S( array( self::W( 'glh-videowall' ) ) ) ) );

		return $p;
	}
}
