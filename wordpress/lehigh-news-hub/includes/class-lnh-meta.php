<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the audit metadata the agents send with each post (readable and writable through /wp/v2/posts)
 * and keeps SEO plugins in sync.
 */
final class LNH_Meta {

	/** key => type */
	const KEYS = array(
		'lnh_audit_score'        => 'integer',
		'lnh_audit_status'       => 'string',
		'lnh_audit_notes'        => 'string',
		'lnh_audit_checks'       => 'string',
		'lnh_source_title'       => 'string',
		'lnh_source_url'         => 'string',
		'lnh_source_name'        => 'string',
		'lnh_source_date'        => 'string',
		'lnh_keywords'           => 'string',
		'lnh_facts'              => 'string',
		'lnh_meta_description'   => 'string',
		'lnh_featured_image_url' => 'string',
		'lnh_featured_image_ai'  => 'boolean',
		'lnh_featured_image_credit' => 'string',
		'lnh_image_prompt'       => 'string',
		'lnh_image_provider'     => 'string',
		'lnh_media_todo'         => 'string',
		'lnh_agent_models'       => 'string',
		'lnh_trace'              => 'string',
		'lnh_run_id'             => 'string',
		'lnh_generated_at'       => 'string',
		'lnh_revisions'          => 'integer',
		'lnh_language'            => 'string',
		'lnh_social'             => 'string', // JSON written by Agent 4 (Social Designer).
		// Set by the hub itself (editor decisions).
		'lnh_reviewed_by'        => 'integer',
		'lnh_reviewed_at'        => 'integer',
		'lnh_reject_reason'      => 'string',
	);

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'is_protected_meta', array( __CLASS__, 'protect' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'sync_seo' ), 20, 4 );
		add_filter( 'rest_prepare_post', array( __CLASS__, 'hide_internal_meta' ), 10, 2 );
	}

	/**
	 * The audit data (auditor notes, source facts, image prompt, reviewer, agent trace) is internal: registering it for the
	 * REST API would otherwise expose it to every visitor on published articles. Only people who can edit the post see it.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Post.
	 */
	public static function hide_internal_meta( $response, $post ) {
		if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( array_keys( $data['meta'] ) as $key ) {
				if ( 0 === strpos( (string) $key, 'lnh_' ) ) {
					unset( $data['meta'][ $key ] );
				}
			}
			$response->set_data( $data );
		}
		return $response;
	}

	public static function register(): void {
		foreach ( self::KEYS as $key => $type ) {
			register_post_meta(
				'post',
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
					'sanitize_callback' => self::sanitizer( $type, $key ),
				)
			);
		}
	}

	private static function sanitizer( string $type, string $key ) {
		if ( 'integer' === $type ) {
			return 'absint';
		}
		if ( 'boolean' === $type ) {
			return function ( $v ) {
				return LNH_Util::to_bool( $v );
			};
		}
		if ( in_array( $key, array( 'lnh_source_url', 'lnh_featured_image_url' ), true ) ) {
			return 'esc_url_raw';
		}
		if ( 'lnh_social' === $key ) {
			return array( __CLASS__, 'sanitize_social' );
		}
		if ( in_array( $key, array( 'lnh_facts', 'lnh_audit_notes', 'lnh_audit_checks', 'lnh_trace', 'lnh_image_prompt', 'lnh_keywords', 'lnh_media_todo', 'lnh_agent_models', 'lnh_reject_reason' ), true ) ) {
			return 'sanitize_textarea_field'; // Keeps JSON intact; output is always escaped.
		}
		return 'sanitize_text_field';
	}

	/** Meta sanitizer: only a well-formed kit survives; anything else is stored as empty. */
	public static function sanitize_social( $raw ): string {
		$kit = self::normalize_social( LNH_Util::json_list( $raw ) );
		return $kit ? (string) wp_json_encode( $kit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
	}

	/** The social kit of a post (empty array when Agent 4 has not produced one). */
	public static function social( int $post_id ): array {
		return self::normalize_social( LNH_Util::json_list( get_post_meta( $post_id, 'lnh_social', true ) ) );
	}

	/** Whitelist + clip every field of a kit (it is rendered in the admin, so it is treated as untrusted input). */
	public static function normalize_social( array $raw ): array {
		if ( empty( $raw['instagram'] ) && empty( $raw['tiktok'] ) && empty( $raw['video'] ) ) {
			return array();
		}
		$text  = function ( $v, int $max ): string {
			return LNH_Util::clip( sanitize_textarea_field( is_scalar( $v ) ? (string) $v : '' ), $max );
		};
		$file  = function ( $f ) use ( $text ): array {
			$url = is_array( $f ) ? esc_url_raw( (string) ( $f['url'] ?? '' ), array( 'http', 'https' ) ) : '';
			return $url ? array( 'id' => absint( $f['id'] ?? 0 ), 'url' => $url, 'name' => $text( $f['name'] ?? '', 80 ) ) : array();
		};
		$files = function ( $list ) use ( $file ): array {
			$out = array();
			foreach ( array_slice( is_array( $list ) ? $list : array(), 0, 12 ) as $f ) {
				$one = $file( $f );
				if ( $one ) {
					$out[] = $one;
				}
			}
			return $out;
		};
		$net = function ( $n ) use ( $text, $files ): array {
			$n = is_array( $n ) ? $n : array();
			return array( 'caption' => $text( $n['caption'] ?? '', 2200 ), 'slides' => $files( $n['slides'] ?? array() ) );
		};
		$tags = array();
		foreach ( array_slice( is_array( $raw['hashtags'] ?? null ) ? $raw['hashtags'] : array(), 0, 15 ) as $t ) {
			$t = '#' . preg_replace( '/[^\p{L}\p{N}_]/u', '', (string) $t );
			if ( strlen( $t ) > 1 ) {
				$tags[] = $t;
			}
		}
		$warnings = array();
		foreach ( array_slice( is_array( $raw['warnings'] ?? null ) ? $raw['warnings'] : array(), 0, 8 ) as $w ) {
			$warnings[] = $text( $w, 200 );
		}
		return array(
			'generated_at' => absint( $raw['generated_at'] ?? 0 ),
			'model'        => $text( $raw['model'] ?? '', 80 ),
			'voice'        => $text( $raw['voice'] ?? '', 80 ),
			'seconds'      => round( (float) ( $raw['seconds'] ?? 0 ), 1 ),
			'ai_image'     => LNH_Util::to_bool( $raw['ai_image'] ?? false ),
			'hook'         => $text( $raw['hook'] ?? '', 200 ),
			'instagram'    => $net( $raw['instagram'] ?? array() ),
			'tiktok'       => $net( $raw['tiktok'] ?? array() ),
			'video'        => $file( $raw['video'] ?? array() ),
			'hashtags'     => $tags,
			'alt_text'     => $text( $raw['alt_text'] ?? '', 200 ),
			'warnings'     => $warnings,
		);
	}

	/** Hide our keys from the Custom Fields box. */
	public static function protect( $protected, $key ) {
		return ( is_string( $key ) && 0 === strpos( $key, 'lnh_' ) ) ? true : $protected;
	}

	/** Is this post one created by the agents? */
	public static function is_agent_post( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, 'lnh_audit_status', true );
	}

	/** Mirror the meta description into Yoast / Rank Math / AIOSEO when they are active. */
	public static function sync_seo( $post_id, $post, $update, $before ): void {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || ! self::is_agent_post( (int) $post_id ) ) {
			return;
		}
		$desc = (string) get_post_meta( $post_id, 'lnh_meta_description', true );
		if ( '' === $desc ) {
			return;
		}
		$targets = array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description' );
		foreach ( $targets as $key ) {
			if ( '' === (string) get_post_meta( $post_id, $key, true ) ) {
				update_post_meta( $post_id, $key, $desc );
			}
		}
	}

	/** Decoded audit data for views. */
	public static function audit( int $post_id ): array {
		return array(
			'score'   => (int) get_post_meta( $post_id, 'lnh_audit_score', true ),
			'status'  => (string) get_post_meta( $post_id, 'lnh_audit_status', true ),
			'notes'   => LNH_Util::json_list( get_post_meta( $post_id, 'lnh_audit_notes', true ) ),
			'checks'  => LNH_Util::json_list( get_post_meta( $post_id, 'lnh_audit_checks', true ) ),
			'models'  => LNH_Util::json_list( get_post_meta( $post_id, 'lnh_agent_models', true ) ),
			'trace'   => LNH_Util::json_list( get_post_meta( $post_id, 'lnh_trace', true ) ),
			'todo'    => LNH_Util::json_list( get_post_meta( $post_id, 'lnh_media_todo', true ) ),
			'keywords' => LNH_Util::json_list( get_post_meta( $post_id, 'lnh_keywords', true ) ),
		);
	}
}
