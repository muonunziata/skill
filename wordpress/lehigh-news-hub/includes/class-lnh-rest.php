<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API (namespace lnh/v1). Authenticated with the normal WordPress mechanisms:
 * Application Passwords for the agents, cookie + nonce for the admin screens.
 */
final class LNH_Rest {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			'lnh/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => array( __CLASS__, 'can_post' ),
			)
		);
		register_rest_route(
			'lnh/v1',
			'/runs',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_run' ),
					'permission_callback' => array( __CLASS__, 'can_post' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_runs' ),
					'permission_callback' => array( __CLASS__, 'can_review' ),
				),
			)
		);
		register_rest_route(
			'lnh/v1',
			'/control/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'control_sync' ),
				'permission_callback' => array( __CLASS__, 'can_post' ),
			)
		);
		register_rest_route(
			'lnh/v1',
			'/agents/package',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'LNH_Package', 'serve_code' ),
				'permission_callback' => array( __CLASS__, 'can_post' ),
			)
		);
		register_rest_route(
			'lnh/v1',
			'/control',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'control_status' ),
					'permission_callback' => array( __CLASS__, 'can_review' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'control_set' ),
					'permission_callback' => array( __CLASS__, 'can_review' ),
				),
			)
		);
		register_rest_route(
			'lnh/v1',
			'/feed',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'feed' ),
				'permission_callback' => array( __CLASS__, 'can_review' ),
				'args'                => array(
					'agent' => array( 'sanitize_callback' => 'sanitize_key' ),
					'since' => array( 'sanitize_callback' => 'absint' ),
					'limit' => array( 'sanitize_callback' => 'absint', 'default' => 80 ),
				),
			)
		);
	}

	/** Agents authenticate as Author or higher. Contributors must not be able to forge activity logs. */
	public static function can_post(): bool {
		return current_user_can( 'publish_posts' );
	}

	public static function can_review(): bool {
		return current_user_can( 'edit_others_posts' );
	}

	public static function ping(): array {
		return array(
			'ok'      => true,
			'plugin'  => 'lehigh-news-hub',
			'version' => LNH_VERSION,
			'site'    => get_bloginfo( 'name' ),
			'time'    => time(),
			'user'    => wp_get_current_user()->user_login,
		);
	}

	public static function post_run( WP_REST_Request $request ) {
		$run = LNH_Runs::sanitize( (array) $request->get_json_params() );
		if ( ! $run ) {
			return new WP_Error( 'lnh_invalid_run', __( 'The run report is missing run_id.', 'lehigh-news-hub' ), array( 'status' => 400 ) );
		}
		$id = LNH_Runs::store( $run );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'lnh_store_failed', $id->get_error_message(), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'id' => $id ), 201 );
	}

	public static function list_runs( WP_REST_Request $request ): array {
		return array_map(
			function ( $r ) {
				unset( $r['events'] );
				return $r;
			},
			LNH_Runs::recent( 20 )
		);
	}

	public static function feed( WP_REST_Request $request ): array {
		return LNH_Runs::feed( (string) $request['agent'], min( 200, max( 1, (int) $request['limit'] ) ), (int) $request['since'] );
	}

	/** The agents report in and learn whether they should be working. */
	public static function control_sync( WP_REST_Request $request ): array {
		return LNH_Control::sync( (array) $request->get_json_params() );
	}

	public static function control_status(): array {
		return LNH_Control::status();
	}

	/** Start / pause / run now from the admin screens (cookie + nonce) or any editor-level credential. */
	public static function control_set( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$ok   = LNH_Control::apply( sanitize_key( (string) ( $body['action'] ?? $request['action'] ) ) );
		if ( ! $ok ) {
			return new WP_Error( 'lnh_bad_action', __( 'Unknown action. Use start, pause or run_now.', 'lehigh-news-hub' ), array( 'status' => 400 ) );
		}
		return LNH_Control::status();
	}
}
