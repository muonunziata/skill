<?php
/**
 * Funciones mínimas que el núcleo necesita. Dentro de WordPress ya existen y no se redefinen;
 * en cualquier otro sistema (Wakoo, PrimeBase…) estas versiones simples cubren lo necesario.
 */
defined( 'GLH_DIR' ) || exit;

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u, $protocols = null ) {
		$u = trim( (string) $u );
		if ( $u === '' ) {
			return '';
		}
		$ok = is_array( $protocols ) ? $protocols : array( 'http', 'https' );
		if ( $u[0] === '#' || $u[0] === '/' || $u[0] === '?' ) {
			return htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $u, $m ) && ! in_array( strtolower( $m[1] ), $ok, true ) ) {
			return '';
		}
		return htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u, $protocols = null ) {
		return html_entity_decode( esc_url( $u, $protocols ), ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $flags = 0 ) {
		return json_encode( $d, $flags );
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $decimals = 0 ) {
		return number_format( (float) $n, (int) $decimals );
	}
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $id, $size = 'large' ) {
		return false;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
	}
}
