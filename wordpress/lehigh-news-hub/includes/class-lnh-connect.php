<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-click credentials for the agents: a dedicated Author account plus an Application Password, returned as a
 * ready-to-use .env block. The password is shown once in the response to the admin who asked for it; it is never stored
 * in plain text (WordPress keeps only its hash).
 */
final class LNH_Connect {

	const USER_META = 'lnh_agent_user';
	/** sha256 of the agents' connection key (the key itself is never stored). */
	const KEY_META = 'lnh_agent_key_hash';
	const KEY_PREFIX = 'lnh_';

	public static function init(): void {
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_key' ), 25 );
	}

	/**
	 * Connection key: a second way to authenticate the agents' account, for sites where Application Passwords are not
	 * available (no HTTPS, or disabled by a security plugin). It only works on REST API requests and only for the agents' own
	 * Author account, so it can do no more than an Application Password could.
	 *
	 * @param int|false $user_id User determined so far.
	 * @return int|false
	 */
	public static function authenticate_key( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		$token = '';
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) && preg_match( '/^Bearer\s+(' . self::KEY_PREFIX . '[A-Za-z0-9]{20,100})$/', trim( (string) wp_unslash( $_SERVER[ $h ] ) ), $m ) ) { // phpcs:ignore WordPress.Security
				$token = $m[1];
				break;
			}
		}
		if ( '' === $token && ! empty( $_SERVER['HTTP_X_LEHIGH_KEY'] ) ) { // Hosts that strip the Authorization header.
			$candidate = trim( (string) wp_unslash( $_SERVER['HTTP_X_LEHIGH_KEY'] ) ); // phpcs:ignore WordPress.Security
			$token     = preg_match( '/^' . self::KEY_PREFIX . '[A-Za-z0-9]{20,100}$/', $candidate ) ? $candidate : '';
		}
		if ( '' === $token ) {
			return $user_id;
		}
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security
		if ( false === strpos( $uri, '/' . rest_get_url_prefix() . '/' ) && ! isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $user_id; // REST API only.
		}
		$agent = self::agent_user();
		$hash  = $agent ? (string) get_user_meta( $agent->ID, self::KEY_META, true ) : '';
		if ( '' !== $hash && hash_equals( $hash, hash( 'sha256', $token ) ) ) {
			return (int) $agent->ID;
		}
		return $user_id;
	}

	/** @return WP_User|null The account created earlier by this feature. */
	public static function agent_user(): ?WP_User {
		$users = get_users( array( 'meta_key' => self::USER_META, 'meta_value' => '1', 'number' => 1 ) );
		return $users ? $users[0] : null;
	}

	/** @return true|WP_Error */
	public static function can_generate() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'create_users' ) ) {
			return new WP_Error( 'lnh_forbidden', __( 'Only administrators can create the agents’ credentials.', 'lehigh-news-hub' ) );
		}
		return true;
	}

	/**
	 * Create (or reuse) the agents' account and a fresh Application Password.
	 *
	 * @return array{user:string,password:string,rest_url:string,env:string,created_user:bool,mode:string}|WP_Error
	 */
	public static function generate() {
		$ok = self::can_generate();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$user    = self::agent_user();
		$created = false;
		if ( ! $user ) {
			$login = 'lehigh-agents';
			$n     = 1;
			while ( username_exists( $login ) ) {
				$login = 'lehigh-agents-' . ( ++$n );
			}
			$host  = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$host  = preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host ) ? $host : 'example.com';
			$email = 'lehigh-agents@' . $host;
			$n     = 1;
			while ( email_exists( $email ) ) {
				$email = 'lehigh-agents+' . ( ++$n ) . '@' . $host;
			}
			$id = wp_insert_user(
				array(
					'user_login'           => $login,
					'user_pass'            => wp_generate_password( 40 ),
					'user_email'           => $email,
					'display_name'         => 'Lehigh Agents',
					'role'                 => 'author',
					'show_admin_bar_front' => 'false',
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			update_user_meta( $id, self::USER_META, '1' );
			$user    = get_userdata( $id );
			$created = true;
		}
		$rest = untrailingslashit( rest_url() );
		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			// No Application Passwords here (plain HTTP, or a security plugin): use the plugin's own connection key.
			$key = self::KEY_PREFIX . wp_generate_password( 40, false, false );
			update_user_meta( $user->ID, self::KEY_META, hash( 'sha256', $key ) ); // Replaces (revokes) any earlier key.
			return array(
				'user'         => $user->user_login,
				'password'     => $key,
				'rest_url'     => $rest,
				'env'          => 'WP_REST_URL=' . $rest . "\n" . 'WP_AUTH_TOKEN=' . $key . "\n",
				'created_user' => $created,
				'mode'         => 'key',
			);
		}
		// WordPress requires every Application Password name of a user to be unique.
		$base  = sprintf( /* translators: %s: date and time */ __( 'Lehigh agents – %s', 'lehigh-news-hub' ), wp_date( 'Y-m-d H:i' ) );
		$taken = wp_list_pluck( WP_Application_Passwords::get_user_application_passwords( $user->ID ), 'name' );
		$name  = $base;
		for ( $i = 2; in_array( $name, $taken, true ); $i++ ) {
			$name = $base . ' (' . $i . ')';
		}
		$made = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => $name ) );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		$password = WP_Application_Passwords::chunk_password( $made[0] );
		return array(
			'user'         => $user->user_login,
			'password'     => $password,
			'rest_url'     => $rest,
			'env'          => self::env_block( $rest, $user->user_login, $password ),
			'created_user' => $created,
			'mode'         => 'app_password',
		);
	}

	public static function env_block( string $rest_url, string $user, string $password ): string {
		return 'WP_REST_URL=' . $rest_url . "\n" . 'WP_AUTH_TOKEN="' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $user . ':' . $password ) . "\"\n";
	}
}
