<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers (no database access) so they are easy to test.
 */
final class LNH_Util {

	/** Decode a JSON list stored in post meta; always returns an array. */
	public static function json_list( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	public static function clamp( $value, $min, $max, $default ) {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	public static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on', 'si', 'sí' ), true );
	}

	public static function hex_color( $value, string $default ): string {
		$value = trim( (string) $value );
		return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : $default;
	}

	public static function clip( string $text, int $max ): string {
		$text = trim( $text );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
			return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}
		return strlen( $text ) > $max ? rtrim( substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	public static function host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./', '', strtolower( $host ) ) : '';
	}

	/** CSS class for an audit score. */
	public static function score_class( $score ): string {
		$score = (int) $score;
		if ( $score >= 85 ) {
			return 'good';
		}
		return $score >= 70 ? 'ok' : 'bad';
	}

	/** "7 days", "12 hours", "2 weeks" => unix timestamp in the past; 0 when empty/invalid. */
	public static function parse_since( string $since, ?int $now = null ): int {
		$now = $now ?? time();
		if ( ! preg_match( '/^\s*(\d{1,4})\s*(minute|hour|day|week|month)s?\s*$/i', $since, $m ) ) {
			return 0;
		}
		$unit = array( 'minute' => 60, 'hour' => 3600, 'day' => 86400, 'week' => 604800, 'month' => 2592000 );
		return $now - ( (int) $m[1] ) * $unit[ strtolower( $m[2] ) ];
	}

	/** Replace {placeholders} in a template with escaped-later values. */
	public static function fill( string $template, array $vars ): string {
		$search  = array();
		$replace = array();
		foreach ( $vars as $key => $value ) {
			$search[]  = '{' . $key . '}';
			$replace[] = (string) $value;
		}
		return str_replace( $search, $replace, $template );
	}

	/** First $n words of plain text. */
	public static function words( string $text, int $n ): string {
		$text  = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		$parts = preg_split( '/\s+/u', $text );
		if ( ! $parts || count( $parts ) <= $n ) {
			return $text;
		}
		return rtrim( implode( ' ', array_slice( $parts, 0, $n ) ), ".,;:–-" ) . '…';
	}

	/** CSS aspect-ratio value from "16:9" ("" for auto). */
	public static function ratio( string $ratio ): string {
		return preg_match( '/^(\d{1,2}):(\d{1,2})$/', $ratio, $m ) ? $m[1] . ' / ' . $m[2] : '';
	}

	/**
	 * Normalise shortcode attributes against a schema.
	 * Schema entry: array( 'type' => int|bool|enum|csv|text|color|url, 'default' => mixed, 'min','max','options' ).
	 */
	public static function normalize_atts( array $input, array $schema ): array {
		$out = array();
		foreach ( $schema as $key => $def ) {
			$value = array_key_exists( $key, $input ) && '' !== $input[ $key ] ? $input[ $key ] : $def['default'];
			switch ( $def['type'] ) {
				case 'int':
					$value = self::clamp( $value, $def['min'], $def['max'], $def['default'] );
					break;
				case 'bool':
					$value = self::to_bool( $value ) ? 1 : 0;
					break;
				case 'enum':
					$value = in_array( (string) $value, $def['options'], true ) ? (string) $value : $def['default'];
					break;
				case 'csv':
					$parts = array_filter( array_map( 'sanitize_title', explode( ',', (string) $value ) ) );
					$value = implode( ',', array_unique( $parts ) );
					break;
				case 'ids':
					$parts = array_filter( array_map( 'absint', explode( ',', (string) $value ) ) );
					$value = implode( ',', array_unique( $parts ) );
					break;
				case 'color':
					$value = '' === (string) $value ? '' : self::hex_color( $value, (string) $def['default'] );
					break;
				case 'url':
					$value = esc_url_raw( (string) $value );
					break;
				default:
					$value = sanitize_text_field( (string) $value );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/** Human time like "5 minutes ago" or the date for old items. */
	public static function ago( int $ts ): string {
		if ( $ts <= 0 ) {
			return '—';
		}
		if ( time() - $ts < 7 * DAY_IN_SECONDS ) {
			/* translators: %s: human readable time difference */
			return sprintf( __( '%s ago', 'lehigh-news-hub' ), human_time_diff( $ts ) );
		}
		return wp_date( get_option( 'date_format' ), $ts );
	}
}
