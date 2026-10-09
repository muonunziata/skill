<?php
defined( 'ABSPATH' ) || exit;

final class LNH_Plugin {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		LNH_Meta::init();
		LNH_Runs::init();
		LNH_Connect::init();
		LNH_Control::init();
		LNH_Package::init();
		LNH_Rest::init();
		LNH_Queue::init();
		LNH_Shortcode::init();
		LNH_Front::init();
		LNH_Pages::init();
		if ( is_admin() ) {
			LNH_Admin::init();
		}
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_filter( 'load_textdomain_mofile', array( __CLASS__, 'spanish_fallback' ), 10, 2 );
	}

	/** Every Spanish locale (es_MX, es_CO, es_AR…) uses the bundled neutral Spanish translation. */
	public static function spanish_fallback( $mofile, $domain ) {
		if ( 'lehigh-news-hub' !== $domain || is_readable( $mofile ) ) {
			return $mofile;
		}
		if ( preg_match( '#lehigh-news-hub-es_[A-Z]{2}\.mo$#', $mofile ) ) {
			$fallback = LNH_DIR . 'languages/lehigh-news-hub-es_ES.mo';
			return is_readable( $fallback ) ? $fallback : $mofile;
		}
		return $mofile;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'lehigh-news-hub', false, dirname( plugin_basename( LNH_FILE ) ) . '/languages' );
	}

	public static function activate(): void {
		if ( false === get_option( LNH_Settings::OPTION ) ) {
			add_option( LNH_Settings::OPTION, LNH_Settings::defaults(), '', false );
		}
		LNH_Pages::on_activate();
		if ( ! wp_next_scheduled( 'lnh_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lnh_cleanup' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'lnh_cleanup' );
	}
}
