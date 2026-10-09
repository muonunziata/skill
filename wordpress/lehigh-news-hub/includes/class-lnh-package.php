<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Download my agents": a ZIP with the agents and a ready-made .env (site address, a fresh Application Password and the
 * Gemini key the administrator pastes), plus double-click launchers. It is built on the fly and never stored: the Gemini key
 * is only in the request and in the downloaded file.
 */
final class LNH_Package {

	const FOLDER = 'golehighacres-agents';
	/** `auto`: the agents pick the newest Gemini Flash the API serves and follow Google's model retirements by themselves. */
	const MODEL  = 'auto';

	/** Files and folders of the agents' source that must not ship in the package. */
	const SKIP = array( '.env', 'state', '.venv', '__pycache__', 'tests', '.pytest_cache', 'node_modules', '.git' );

	public static function init(): void {
		add_action( 'admin_post_lnh_agents_package', array( __CLASS__, 'handle' ) );
	}

	/** The agents' source is added to the plugin archive by scripts/build-release.sh. */
	public static function bundle_dir(): string {
		return LNH_DIR . 'agents-bundle';
	}

	public static function available(): bool {
		return is_file( self::bundle_dir() . '/main.py' ) && is_dir( self::bundle_dir() . '/lehigh_agents' ) && class_exists( 'ZipArchive' );
	}

	/** Version of the bundled agents ('' when this copy of the plugin does not include them). */
	public static function bundle_version(): string {
		$f = self::bundle_dir() . '/lehigh_agents/__init__.py';
		if ( is_readable( $f ) && preg_match( '/__version__\s*=\s*"([0-9][0-9A-Za-z.\-]*)"/', (string) file_get_contents( $f ), $m ) ) {
			return $m[1];
		}
		return '';
	}

	// ---------------------------------------------------------------- .env

	/** One .env line, quoted only when needed. */
	public static function env_line( string $key, string $value ): string {
		if ( '' !== $value && ! preg_match( '/^[A-Za-z0-9_.\/:@+,\-]+$/', $value ) ) {
			$value = '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
		}
		return $key . '=' . $value;
	}

	/** Set the given keys in the template (replace the line, or append it); every other line is kept as it is. */
	public static function build_env( string $template, array $values ): string {
		$lines = preg_split( '/\r\n|\n|\r/', rtrim( $template ) );
		$seen  = array();
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '/^([A-Z0-9_]+)=/', $line, $m ) && array_key_exists( $m[1], $values ) ) {
				$lines[ $i ]    = self::env_line( $m[1], (string) $values[ $m[1] ] );
				$seen[ $m[1] ] = true;
			}
		}
		foreach ( $values as $k => $v ) {
			if ( empty( $seen[ $k ] ) ) {
				$lines[] = self::env_line( $k, (string) $v );
			}
		}
		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	public static function env_values( array $creds, string $gemini_key, bool $ai_images ): array {
		return array(
			'WP_REST_URL'      => $creds['rest_url'],
			'WP_AUTH_TOKEN'    => 'key' === ( $creds['mode'] ?? '' ) ? $creds['password'] : $creds['user'] . ':' . $creds['password'],
			'GEMINI_API_KEY'   => $gemini_key,
			'RASTREADOR_MODEL' => self::MODEL,
			'REDACCTOR_MODEL'  => self::MODEL,
			'AUDITOR_MODEL'    => self::MODEL,
			'ARTICLE_LANGUAGE' => 0 === strpos( determine_locale(), 'es' ) ? 'es' : 'en',
			'IMAGE_PROVIDER'   => $ai_images ? 'gemini' : '',
			'IMAGE_MODEL'      => '', // empty = the agents' default, replaced automatically when Google retires it
		);
	}

	// ---------------------------------------------------------------- key check

	/** @return bool|null true = accepted, false = rejected by Google, null = could not tell (offline, blocked...). */
	public static function key_is_accepted( string $key ): ?bool {
		$res = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1',
			array( 'timeout' => 10, 'headers' => array( 'x-goog-api-key' => $key ) )
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) {
			return true;
		}
		return in_array( $code, array( 400, 401, 403 ), true ) ? false : null;
	}

	// ---------------------------------------------------------------- the archive

	private static function readme( bool $es ): string {
		if ( $es ) {
			return "GoLehighAcres.org · Agentes de noticias\n\n"
				. "1. Windows: doble clic en INICIAR.bat\n   Mac: doble clic en INICIAR.command (la primera vez: clic derecho > Abrir)\n   Linux: bash INICIAR.command\n"
				. "2. La primera vez se prepara todo solo (unos minutos). Cuando diga \"Agentes encendidos\", déjala abierta.\n"
				. "3. En WordPress: News Hub > pulsa \"Iniciar a trabajar\". Desde ahí los pausas o los inicias cuando quieras.\n\n"
				. "Necesitas Python 3.11 o superior (en Windows el instalador lo instala solo si tienes winget; si no, python.org).\n"
				. "El archivo .env contiene la contraseña de conexión de tu sitio y tu clave de Gemini: no lo compartas.\n";
		}
		return "GoLehighAcres.org · News agents\n\n"
			. "1. Windows: double-click INICIAR.bat\n   Mac: double-click INICIAR.command (first time: right-click > Open)\n   Linux: bash INICIAR.command\n"
			. "2. The first run sets everything up (a few minutes). When it says the agents are on, leave the window open.\n"
			. "3. In WordPress: News Hub > press \"Start working\". Pause or start them from there at any time.\n\n"
			. "You need Python 3.11+ (on Windows the installer adds it with winget if available; otherwise python.org).\n"
			. "The .env file holds your site connection password and your Gemini key: do not share it.\n";
	}

	/**
	 * Build the archive in a temporary file.
	 *
	 * @return array{path:string,filename:string,user:string}|WP_Error
	 */
	public static function build( string $gemini_key, bool $ai_images ) {
		if ( ! self::available() ) {
			return new WP_Error( 'lnh_pkg_unavailable', __( 'This copy of the plugin does not include the agents, or the PHP zip extension is missing on this server.', 'lehigh-news-hub' ) );
		}
		$creds = LNH_Connect::generate();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}
		$base     = self::bundle_dir();
		$template = is_readable( $base . '/.env.example' ) ? (string) file_get_contents( $base . '/.env.example' ) : '';
		$env      = self::build_env( $template, self::env_values( $creds, $gemini_key, $ai_images ) );
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp      = wp_tempnam( 'lnh-agents' );
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'lnh_pkg_zip', __( 'Could not create the archive.', 'lehigh-news-hub' ) );
		}
		$unix = defined( 'ZipArchive::OPSYS_UNIX' );
		$add  = function ( string $name, int $mode ) use ( $zip, $unix ) {
			if ( $unix ) {
				$zip->setExternalAttributesName( self::FOLDER . '/' . $name, ZipArchive::OPSYS_UNIX, $mode << 16 );
			}
		};
		self::add_bundle( $zip, $add );
		$zip->addFromString( self::FOLDER . '/.env', $env );
		$add( '.env', 0100600 );
		$zip->addFromString( self::FOLDER . '/LEEME.txt', self::readme( true ) . "\n----\n\n" . self::readme( false ) );
		$add( 'LEEME.txt', 0100644 );
		if ( ! $zip->close() ) {
			return new WP_Error( 'lnh_pkg_zip', __( 'Could not create the archive.', 'lehigh-news-hub' ) );
		}
		return array( 'path' => $tmp, 'filename' => self::FOLDER . '.zip', 'user' => $creds['user'] );
	}

	/** Add every shipped file of the agents (not .env, state, tests...) to the archive, keeping executable bits. */
	private static function add_bundle( ZipArchive $zip, callable $add ): void {
		$base = self::bundle_dir();
		$it = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				function ( $f ) {
					return ! in_array( $f->getFilename(), self::SKIP, true ) && '.pyc' !== substr( $f->getFilename(), -4 );
				}
			)
		);
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $base ) + 1 ) );
			$zip->addFile( $file->getPathname(), self::FOLDER . '/' . $rel );
			$add( $rel, preg_match( '/\.(sh|command)$/', $rel ) ? 0100755 : 0100644 );
		}
	}

	/**
	 * The agents' code only (no .env, no credentials): what a connected worker downloads when an editor presses "Update agents".
	 *
	 * @return string|WP_Error Path of a temporary ZIP file.
	 */
	public static function code_zip() {
		if ( ! self::available() ) {
			return new WP_Error( 'lnh_pkg_unavailable', __( 'This copy of the plugin does not include the agents, or the PHP zip extension is missing on this server.', 'lehigh-news-hub' ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( 'lnh-agents-code' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'lnh_pkg_zip', __( 'Could not create the archive.', 'lehigh-news-hub' ) );
		}
		$unix = defined( 'ZipArchive::OPSYS_UNIX' );
		self::add_bundle(
			$zip,
			function ( string $name, int $mode ) use ( $zip, $unix ) {
				if ( $unix ) {
					$zip->setExternalAttributesName( self::FOLDER . '/' . $name, ZipArchive::OPSYS_UNIX, $mode << 16 );
				}
			}
		);
		return $zip->close() ? $tmp : new WP_Error( 'lnh_pkg_zip', __( 'Could not create the archive.', 'lehigh-news-hub' ) );
	}

	/** REST callback body: stream the code archive to an authenticated agent. */
	public static function serve_code(): void {
		$tmp = self::code_zip();
		if ( is_wp_error( $tmp ) ) {
			status_header( 404 );
			wp_send_json( array( 'code' => $tmp->get_error_code(), 'message' => $tmp->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		header( 'X-Lehigh-Agents-Version: ' . self::bundle_version() );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $tmp );
		exit;
	}

	// ---------------------------------------------------------------- admin-post

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lehigh-news-hub' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lnh_agents_package' );
		$back = admin_url( 'admin.php?page=lnh-connect' );
		$key  = isset( $_POST['gemini_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['gemini_key'] ) ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9._\-]{20,300}$/', $key ) ) {
			wp_safe_redirect( add_query_arg( 'lnh_notice', 'pkg_key', $back ) );
			exit;
		}
		if ( false === self::key_is_accepted( $key ) ) {
			wp_safe_redirect( add_query_arg( 'lnh_notice', 'pkg_rejected', $back ) );
			exit;
		}
		$built = self::build( $key, ! empty( $_POST['ai_images'] ) );
		if ( is_wp_error( $built ) ) {
			wp_safe_redirect( add_query_arg( 'lnh_notice', 'lnh_pkg_unavailable' === $built->get_error_code() ? 'pkg_unavailable' : 'pkg_error', $back ) );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $built['filename'] . '"' );
		header( 'Content-Length: ' . filesize( $built['path'] ) );
		readfile( $built['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $built['path'] );
		exit;
	}
}
