<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-click credentials for the agents: a dedicated Author account plus an Application Password, returned as a
 * ready-to-use .env block. The password is shown once in the response to the admin who asked for it; it is never stored
 * in plain text (WordPress keeps only its hash).
 */
final class LNH_Connect {

	const USER_META = 'lnh_agent_user';

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
		if ( ! wp_is_application_passwords_available() ) {
			return new WP_Error( 'lnh_no_app_passwords', __( 'Application Passwords are not available on this site. They require HTTPS (or a local environment) and must not be disabled by a security plugin.', 'lehigh-news-hub' ) );
		}
		return true;
	}

	/**
	 * Create (or reuse) the agents' account and a fresh Application Password.
	 *
	 * @return array{user:string,password:string,rest_url:string,env:string,created_user:bool}|WP_Error
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
		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error( 'lnh_no_app_passwords_user', __( 'Application Passwords are disabled for the agents’ account.', 'lehigh-news-hub' ) );
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
		$rest     = untrailingslashit( rest_url() );
		return array(
			'user'         => $user->user_login,
			'password'     => $password,
			'rest_url'     => $rest,
			'env'          => self::env_block( $rest, $user->user_login, $password ),
			'created_user' => $created,
		);
	}

	public static function env_block( string $rest_url, string $user, string $password ): string {
		return 'WP_REST_URL=' . $rest_url . "\n" . 'WP_AUTH_TOKEN="' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $user . ':' . $password ) . "\"\n";
	}
}
