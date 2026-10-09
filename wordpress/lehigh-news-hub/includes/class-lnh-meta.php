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
		'lnh_image_prompt'       => 'string',
		'lnh_image_provider'     => 'string',
		'lnh_media_todo'         => 'string',
		'lnh_agent_models'       => 'string',
		'lnh_trace'              => 'string',
		'lnh_run_id'             => 'string',
		'lnh_generated_at'       => 'string',
		'lnh_revisions'          => 'integer',
		'lnh_language'            => 'string',
		// Set by the hub itself (editor decisions).
		'lnh_reviewed_by'        => 'integer',
		'lnh_reviewed_at'        => 'integer',
		'lnh_reject_reason'      => 'string',
	);

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'is_protected_meta', array( __CLASS__, 'protect' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'sync_seo' ), 20, 4 );
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
		if ( in_array( $key, array( 'lnh_facts', 'lnh_audit_notes', 'lnh_audit_checks', 'lnh_trace', 'lnh_image_prompt', 'lnh_keywords', 'lnh_media_todo', 'lnh_agent_models', 'lnh_reject_reason' ), true ) ) {
			return 'sanitize_textarea_field'; // Keeps JSON intact; output is always escaped.
		}
		return 'sanitize_text_field';
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
