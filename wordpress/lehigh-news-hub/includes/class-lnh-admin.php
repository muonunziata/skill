<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screens: dashboard, review queue, article review, agent activity, shortcode builder, settings.
 */
final class LNH_Admin {

	const CAP_REVIEW = 'edit_others_posts';
	const CAP_ADMIN  = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_lnh_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_lnh_save_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_lnh_preset', array( __CLASS__, 'handle_preset' ) );
		add_action( 'wp_ajax_lnh_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_filter_query' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LNH_FILE ), array( __CLASS__, 'action_links' ) );
	}

	// ---------------------------------------------------------------- menu.

	public static function menu(): void {
		$counts = current_user_can( self::CAP_REVIEW ) ? LNH_Queue::counts() : array( 'review' => 0 ); // Skip the queries for users who cannot see the menu.
		$badge  = $counts['review'] > 0 ? ' <span class="awaiting-mod">' . (int) $counts['review'] . '</span>' : '';
		add_menu_page( __( 'News Hub', 'lehigh-news-hub' ), __( 'News Hub', 'lehigh-news-hub' ) . $badge, self::CAP_REVIEW, 'lnh', array( __CLASS__, 'page_dashboard' ), 'dashicons-megaphone', 26 );
		add_submenu_page( 'lnh', __( 'Dashboard', 'lehigh-news-hub' ), __( 'Dashboard', 'lehigh-news-hub' ), self::CAP_REVIEW, 'lnh', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'lnh', __( 'Review queue', 'lehigh-news-hub' ), __( 'Review queue', 'lehigh-news-hub' ) . $badge, self::CAP_REVIEW, 'lnh-queue', array( __CLASS__, 'page_queue' ) );
		add_submenu_page( 'lnh', __( 'Agent activity', 'lehigh-news-hub' ), __( 'Agent activity', 'lehigh-news-hub' ), self::CAP_REVIEW, 'lnh-activity', array( __CLASS__, 'page_activity' ) );
		add_submenu_page( 'lnh', __( 'Shortcode builder', 'lehigh-news-hub' ), __( 'Shortcode builder', 'lehigh-news-hub' ), self::CAP_REVIEW, 'lnh-builder', array( __CLASS__, 'page_builder' ) );
		add_submenu_page( 'lnh', __( 'Settings', 'lehigh-news-hub' ), __( 'Settings', 'lehigh-news-hub' ), self::CAP_ADMIN, 'lnh-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( '', __( 'Connect your agents', 'lehigh-news-hub' ), '', self::CAP_ADMIN, 'lnh-connect', array( __CLASS__, 'page_connect' ) );
		// Hidden: single article review (reached from the queue).
		add_submenu_page( '', __( 'Review article', 'lehigh-news-hub' ), '', self::CAP_REVIEW, 'lnh-review', array( __CLASS__, 'page_review' ) );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=lnh-settings' ) ) . '">' . esc_html__( 'Settings', 'lehigh-news-hub' ) . '</a>' );
		return $links;
	}

	public static function is_hub_screen( string $hook ): bool {
		return false !== strpos( $hook, 'lnh' );
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		$is_hub = self::is_hub_screen( $hook );
		$is_edit = $screen && 'post' === $screen->base && 'post' === $screen->post_type;
		if ( ! $is_hub && ! $is_edit && ! ( $screen && 'edit-post' === $screen->id ) ) {
			return;
		}
		wp_enqueue_style( 'lnh-admin', LNH_URL . 'assets/css/admin.css', array(), LNH_VERSION );
		if ( ! $is_hub ) {
			return;
		}
		wp_enqueue_script( 'lnh-admin', LNH_URL . 'assets/js/admin.js', array(), LNH_VERSION, true );
		wp_localize_script(
			'lnh-admin',
			'LNH',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'lnh_preview' ),
				'feedUrl'   => esc_url_raw( rest_url( 'lnh/v1/feed' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'controlUrl' => esc_url_raw( rest_url( 'lnh/v1/control' ) ),
				'agents'    => array_map(
					function ( $a ) {
						return array( 'label' => $a['label'], 'icon' => $a['icon'] );
					},
					self::agents()
				),
				'events'    => self::event_labels(),
				'i18n'      => array(
					'ago'         => __( '%s ago', 'lehigh-news-hub' ),
					'copied'      => __( 'Copied!', 'lehigh-news-hub' ),
					'confirmReject' => __( 'Reject the selected article(s)? They move to the Rejected tab and can be restored.', 'lehigh-news-hub' ),
					'confirmDelete' => __( 'Delete permanently? This cannot be undone.', 'lehigh-news-hub' ),
					'noEvents'    => __( 'No activity yet.', 'lehigh-news-hub' ),
					'live'        => __( 'Live', 'lehigh-news-hub' ),
					'paused'      => __( 'Paused', 'lehigh-news-hub' ),
					'previewFail' => __( 'Could not render the preview.', 'lehigh-news-hub' ),
					'nextIn'      => __( 'next cycle in %s', 'lehigh-news-hub' ),
					'connectedAgo' => __( 'Agents connected · last seen %s', 'lehigh-news-hub' ),
				),
			)
		);
		if ( 'lnh-builder' === ( $_GET['page'] ?? '' ) || false !== strpos( $hook, 'lnh-builder' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_style( 'lnh-front', LNH_URL . 'assets/css/front.css', array(), LNH_VERSION );
		}
	}

	// ---------------------------------------------------------------- view plumbing.

	public static function view( string $name, array $vars = array() ): void {
		$file = LNH_DIR . 'views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $file;
	}

	public static function agents(): array {
		return array(
			'rastreador' => array( 'label' => __( 'Researcher', 'lehigh-news-hub' ), 'icon' => '🔎', 'role' => __( 'Searches the web and reads the sources', 'lehigh-news-hub' ) ),
			'redactor'   => array( 'label' => __( 'Writer', 'lehigh-news-hub' ), 'icon' => '✍️', 'role' => __( 'Writes the article and its featured image', 'lehigh-news-hub' ) ),
			'auditor'    => array( 'label' => __( 'Auditor', 'lehigh-news-hub' ), 'icon' => '🛡️', 'role' => __( 'Fact-checks, then decides if it can be sent', 'lehigh-news-hub' ) ),
			'social'     => array( 'label' => __( 'Social designer', 'lehigh-news-hub' ), 'icon' => '🎨', 'role' => __( 'Designs Instagram and TikTok carousels and videos', 'lehigh-news-hub' ) ),
			'pipeline'   => array( 'label' => __( 'Coordinator', 'lehigh-news-hub' ), 'icon' => '⚙️', 'role' => __( 'Runs the agents in order', 'lehigh-news-hub' ) ),
		);
	}

	public static function event_label( string $type ): string {
		return self::event_labels()[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) );
	}

	/** Event type => translated label (also handed to the live-feed script). */
	public static function event_labels(): array {
		return array(
			'run_started' => __( 'Run started', 'lehigh-news-hub' ),
			'run_finished' => __( 'Run finished', 'lehigh-news-hub' ),
			'step'        => __( 'Working', 'lehigh-news-hub' ),
			'search'      => __( 'Web search', 'lehigh-news-hub' ),
			'fetch'       => __( 'Read a page', 'lehigh-news-hub' ),
			'finding'     => __( 'Story found', 'lehigh-news-hub' ),
			'skipped'     => __( 'Skipped', 'lehigh-news-hub' ),
			'draft'       => __( 'Draft written', 'lehigh-news-hub' ),
			'media'       => __( 'Media', 'lehigh-news-hub' ),
			'image_prompt' => __( 'Image prompt', 'lehigh-news-hub' ),
			'image'       => __( 'Image generated', 'lehigh-news-hub' ),
			'image_ready' => __( 'Image attached', 'lehigh-news-hub' ),
			'check'       => __( 'Automatic check', 'lehigh-news-hub' ),
			'audit'       => __( 'Audit result', 'lehigh-news-hub' ),
			'submitted'   => __( 'Sent to WordPress', 'lehigh-news-hub' ),
			'hold'        => __( 'Held back', 'lehigh-news-hub' ),
			'cleanup'     => __( 'Cleanup', 'lehigh-news-hub' ),
			'dry_run'     => __( 'Test mode', 'lehigh-news-hub' ),
			'warn'        => __( 'Warning', 'lehigh-news-hub' ),
			'error'       => __( 'Error', 'lehigh-news-hub' ),
			'fetch_failed' => __( 'Page unavailable', 'lehigh-news-hub' ),
			'validation'  => __( 'Retry', 'lehigh-news-hub' ),
			'plan'        => __( 'Social plan', 'lehigh-news-hub' ),
			'render'      => __( 'Slides drawn', 'lehigh-news-hub' ),
			'video'       => __( 'Video made', 'lehigh-news-hub' ),
			'upload'      => __( 'Kit uploaded', 'lehigh-news-hub' ),
			'attached'    => __( 'Kit attached', 'lehigh-news-hub' ),
			'webhook'     => __( 'Webhook sent', 'lehigh-news-hub' ),
			'done'        => __( 'Kit ready', 'lehigh-news-hub' ),
		);
	}

	/** Agent system health from the last run. */
	public static function health(): array {
		if ( ! LNH_Control::is_running() ) { // A paused system is not "late": somebody asked it to stop.
			$last = LNH_Runs::last();
			return array( 'state' => 'paused', 'label' => __( 'The agents are paused', 'lehigh-news-hub' ), 'last' => $last ? (int) ( $last['finished_at'] ?: $last['started_at'] ) : 0 );
		}
		$last     = LNH_Runs::last();
		$interval = max( 5, (int) LNH_Settings::get( 'expected_interval' ) );
		if ( ! $last ) {
			return array( 'state' => 'never', 'label' => __( 'Waiting for the first run', 'lehigh-news-hub' ), 'last' => 0 );
		}
		$ts  = (int) ( $last['finished_at'] ?: $last['started_at'] );
		$age = time() - $ts;
		if ( 'error' === $last['status'] ) {
			return array( 'state' => 'error', 'label' => __( 'The last run reported an error', 'lehigh-news-hub' ), 'last' => $ts );
		}
		if ( $age > 2 * $interval * MINUTE_IN_SECONDS ) {
			return array( 'state' => 'late', 'label' => __( 'Agents have not reported for a while', 'lehigh-news-hub' ), 'last' => $ts );
		}
		return array( 'state' => 'ok', 'label' => __( 'Agents are running normally', 'lehigh-news-hub' ), 'last' => $ts );
	}

	private static function notice(): array {
		$code = isset( $_GET['lnh_notice'] ) ? sanitize_key( wp_unslash( $_GET['lnh_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n    = isset( $_GET['lnh_n'] ) ? absint( $_GET['lnh_n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			return array();
		}
		$msgs = array(
			/* translators: %d: number of articles */
			'published' => sprintf( _n( '%d article published.', '%d articles published.', $n, 'lehigh-news-hub' ), $n ),
			/* translators: %d: number of articles */
			'scheduled' => sprintf( _n( '%d article scheduled.', '%d articles scheduled.', $n, 'lehigh-news-hub' ), $n ),
			/* translators: %d: number of articles */
			'rejected'  => sprintf( _n( '%d article rejected.', '%d articles rejected.', $n, 'lehigh-news-hub' ), $n ),
			/* translators: %d: number of articles */
			'restored'  => sprintf( _n( '%d article moved back to review.', '%d articles moved back to review.', $n, 'lehigh-news-hub' ), $n ),
			/* translators: %d: number of articles */
			'deleted'   => sprintf( _n( '%d article deleted.', '%d articles deleted.', $n, 'lehigh-news-hub' ), $n ),
			'saved'     => __( 'Settings saved.', 'lehigh-news-hub' ),
			'preset'    => __( 'Preset saved.', 'lehigh-news-hub' ),
			/* translators: %d: number of pages */
			'pages'     => sprintf( _n( '%d page created.', '%d pages created.', $n, 'lehigh-news-hub' ), $n ),
			'preset_deleted' => __( 'Preset deleted.', 'lehigh-news-hub' ),
			'agents_started' => __( 'The agents will start working as soon as they connect.', 'lehigh-news-hub' ),
			'agents_paused'  => __( 'The agents are paused. They finish the article in progress and then stop.', 'lehigh-news-hub' ),
			'agents_run_now' => __( 'A run was requested: the agents will start within seconds.', 'lehigh-news-hub' ),
			'pkg_key'         => __( 'Paste your Gemini key first (it looks like AIza… and is free at aistudio.google.com/apikey).', 'lehigh-news-hub' ),
			'pkg_rejected'    => __( 'Google rejected that Gemini key. Copy it again from aistudio.google.com/apikey.', 'lehigh-news-hub' ),
			'pkg_unavailable' => __( 'This copy of the plugin does not include the agents, or the zip extension is missing on this server. Download the agents from the project page instead.', 'lehigh-news-hub' ),
			'pkg_error'       => __( 'The agents package could not be created. Check that Application Passwords are available on this site.', 'lehigh-news-hub' ),
			'error'     => __( 'Something went wrong. Nothing was changed.', 'lehigh-news-hub' ),
			'none'      => __( 'Select at least one article first.', 'lehigh-news-hub' ),
		);
		if ( ! isset( $msgs[ $code ] ) ) {
			return array();
		}
		return array( 'type' => ( in_array( $code, array( 'error', 'none' ), true ) || 0 === strpos( $code, 'pkg_' ) ) ? 'error' : 'success', 'message' => $msgs[ $code ] );
	}

	private static function open( string $title, string $sub = '' ): void {
		echo '<div class="wrap lnh-admin"><img class="lnh-brandlogo" src="' . esc_url( LNH_URL . 'assets/img/golehighacres-logo.png' ) . '" alt="GoLehighAcres.org" width="274" height="34">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( '' !== $sub ) {
			echo '<p class="lnh-sub">' . esc_html( $sub ) . '</p>';
		}
		echo '<hr class="wp-header-end">';
		$n = self::notice();
		if ( $n ) {
			echo '<div class="notice notice-' . esc_attr( $n['type'] ) . ' is-dismissible"><p>' . esc_html( $n['message'] ) . '</p></div>';
		}
	}

	private static function close(): void {
		echo '</div>';
	}

	// ---------------------------------------------------------------- pages.

	public static function page_dashboard(): void {
		self::open( __( 'News Hub', 'lehigh-news-hub' ), __( 'Your AI newsroom at a glance.', 'lehigh-news-hub' ) );
		$week = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
			'meta_query' => array( array( 'key' => 'lnh_audit_status', 'compare' => 'EXISTS' ) ),
			'date_query' => array( array( 'after' => '7 days ago' ) ) ) );
		$scores = self::avg_score( 30 );
		self::view( 'dashboard', array(
			'control'   => LNH_Control::status(),
			'counts'    => LNH_Queue::counts(),
			'health'    => self::health(),
			'stats'     => LNH_Runs::agent_stats( 7 ),
			'waiting'   => LNH_Queue::query( 'review', array( 'per_page' => 5 ) )->posts,
			'runs'      => LNH_Runs::recent( 5 ),
			'published7' => (int) $week->found_posts,
			'avg'       => $scores,
			'welcome'   => (array) get_option( LNH_Pages::WELCOME, array() ),
			'pages'     => LNH_Pages::status(),
			'rest_url'  => untrailingslashit( rest_url() ),
			'profile'   => admin_url( 'profile.php#application-passwords-section' ),
		) );
		self::close();
	}

	private static function avg_score( int $days ): int {
		global $wpdb;
		// post_date (local, always set) rather than post_date_gmt: drafts and pending articles have a zero GMT date.
		$since = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$avg = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(CAST(pm.meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'lnh_audit_score' AND p.post_type = 'post' AND p.post_date >= %s",
			$since
		) );
		return (int) round( (float) $avg );
	}

	public static function page_queue(): void {
		self::open( __( 'Review queue', 'lehigh-news-hub' ), __( 'Articles written and audited by the agents. You decide what gets published.', 'lehigh-news-hub' ) );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'review'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( LNH_Queue::TABS[ $tab ] ) ? $tab : 'review';
		$get = function ( $k ) {
			return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		};
		$opts  = array( 's' => $get( 's' ), 'min_score' => absint( $get( 'min_score' ) ), 'orderby' => $get( 'orderby' ), 'order' => $get( 'order' ), 'paged' => max( 1, absint( $get( 'paged' ) ) ) );
		$query = LNH_Queue::query( $tab, $opts );
		self::view( 'queue', array( 'tab' => $tab, 'tabs' => LNH_Queue::tab_labels(), 'counts' => LNH_Queue::counts(), 'query' => $query, 'opts' => $opts ) );
		self::close();
	}

	public static function page_review(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'post' !== $post->post_type || ! LNH_Meta::is_agent_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			self::open( __( 'Review article', 'lehigh-news-hub' ) );
			echo '<p>' . esc_html__( 'Article not found.', 'lehigh-news-hub' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=lnh-queue' ) ) . '">' . esc_html__( 'Back to the queue', 'lehigh-news-hub' ) . '</a></p>';
			self::close();
			return;
		}
		self::open( __( 'Review article', 'lehigh-news-hub' ) );
		self::view( 'review', array( 'post' => $post, 'audit' => LNH_Meta::audit( $post_id ) ) );
		self::close();
	}

	public static function page_activity(): void {
		$run_id = isset( $_GET['run'] ) ? sanitize_text_field( wp_unslash( $_GET['run'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::open( __( 'Agent activity', 'lehigh-news-hub' ), __( 'Everything the Researcher, Writer and Auditor did, step by step.', 'lehigh-news-hub' ) );
		if ( '' !== $run_id ) {
			self::view( 'run', array( 'run' => LNH_Runs::get( $run_id ) ) );
		} else {
			self::view( 'activity', array(
				'control' => LNH_Control::status(),
				'health' => self::health(),
				'stats'  => LNH_Runs::agent_stats( 7 ),
				'runs'   => LNH_Runs::recent( 20 ),
				'feed'   => LNH_Runs::feed( '', 60 ),
				'funnel' => self::funnel(),
			) );
		}
		self::close();
	}

	/** Pipeline funnel over the last 7 days, from run reports plus real post states. */
	private static function funnel(): array {
		$f = array( 'found' => 0, 'written' => 0, 'approved' => 0, 'sent' => 0, 'published' => 0 );
		$since = time() - 7 * DAY_IN_SECONDS;
		foreach ( LNH_Runs::recent( 200 ) as $run ) {
			if ( $run['started_at'] < $since || ! empty( $run['dry_run'] ) ) {
				continue;
			}
			$f['found'] += (int) ( $run['counts']['found'] ?? 0 );
			foreach ( $run['items'] as $it ) {
				if ( 'failed' !== $it['status'] ) {
					++$f['written'];
				}
				if ( 'approved' === $it['status'] ) {
					++$f['approved'];
				}
				if ( $it['posted'] ) {
					++$f['sent'];
				}
			}
		}
		$pub = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
			'meta_query' => array( array( 'key' => 'lnh_audit_status', 'compare' => 'EXISTS' ) ),
			'date_query' => array( array( 'after' => '7 days ago' ) ) ) );
		$f['published'] = (int) $pub->found_posts;
		return $f;
	}

	public static function page_builder(): void {
		self::open( __( 'Shortcode builder', 'lehigh-news-hub' ), __( 'Design a list of the latest articles, preview it live and copy the shortcode.', 'lehigh-news-hub' ) );
		self::view( 'builder', array(
			'schema'     => LNH_Shortcode::schema(),
			'presets'    => LNH_Settings::presets(),
			'categories' => get_categories( array( 'hide_empty' => false ) ),
			'initial'    => LNH_Shortcode::render( array( 'cache' => 0 ) ),
		) );
		self::close();
	}

	/** One-click credentials. The POST is handled here so the one-time password is rendered in the same response. */
	public static function page_connect(): void {
		self::open( __( 'Connect your agents', 'lehigh-news-hub' ) );
		$result = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'lnh_connect' );
			$result = LNH_Connect::generate();
		}
		self::view( 'connect', array( 'package' => LNH_Package::available(), 'result' => $result, 'available' => wp_is_application_passwords_available(), 'existing' => LNH_Connect::agent_user() ) );
		self::close();
	}

	public static function page_settings(): void {
		$schema = LNH_Settings::schema();
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab    = isset( $schema[ $tab ] ) ? $tab : 'general';
		self::open( __( 'News Hub settings', 'lehigh-news-hub' ) );
		self::view( 'settings', array( 'schema' => $schema, 'tab' => $tab, 'values' => LNH_Settings::all() ) );
		self::close();
	}

	// ---------------------------------------------------------------- form handlers.

	private static function back( string $fallback, array $args ): void {
		$ref = isset( $_POST['_back'] ) ? esc_url_raw( wp_unslash( $_POST['_back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url = $ref && 0 === strpos( $ref, admin_url() ) ? $ref : admin_url( $fallback );
		wp_safe_redirect( add_query_arg( $args, remove_query_arg( array( 'lnh_notice', 'lnh_n' ), $url ) ) );
		exit;
	}

	public static function handle_action(): void {
		check_admin_referer( 'lnh_action' );
		if ( ! current_user_can( self::CAP_REVIEW ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'lehigh-news-hub' ), 403 );
		}
		$do  = isset( $_POST['lnh_do'] ) ? sanitize_key( wp_unslash( $_POST['lnh_do'] ) ) : '';
		if ( 'bulk' === $do ) {
			$do = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		}
		$ids = array();
		if ( isset( $_POST['post'] ) ) {
			$ids = array_filter( array_map( 'absint', (array) wp_unslash( $_POST['post'] ) ) );
		}
		if ( ! $ids ) {
			self::back( 'admin.php?page=lnh-queue', array( 'lnh_notice' => 'none' ) );
		}
		$reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$schedule = 0;
		if ( ! empty( $_POST['schedule'] ) ) {
			$local = sanitize_text_field( wp_unslash( $_POST['schedule'] ) );
			$ts    = strtotime( get_gmt_from_date( str_replace( 'T', ' ', $local ) . ':00' ) . ' UTC' );
			$schedule = $ts ? (int) $ts : 0;
		}
		$done = 0;
		$code = 'error';
		foreach ( $ids as $id ) {
			switch ( $do ) {
				case 'publish':
					$ok   = LNH_Queue::publish( $id, $schedule );
					$code = $schedule > time() ? 'scheduled' : 'published';
					break;
				case 'reject':
					$ok   = LNH_Queue::reject( $id, $reason );
					$code = 'rejected';
					break;
				case 'restore':
					$ok   = LNH_Queue::restore( $id );
					$code = 'restored';
					break;
				case 'delete':
					$ok   = LNH_Queue::delete( $id );
					$code = 'deleted';
					break;
				default:
					$ok = new WP_Error( 'lnh_unknown', 'unknown action' );
			}
			if ( true === $ok ) {
				++$done;
			}
		}
		// After deciding on a single article from its review screen, go back to the queue.
		$single = 1 === count( $ids ) && isset( $_POST['from_review'] );
		if ( $single && $done ) {
			wp_safe_redirect( add_query_arg( array( 'lnh_notice' => $code, 'lnh_n' => $done ), admin_url( 'admin.php?page=lnh-queue' ) ) );
			exit;
		}
		self::back( 'admin.php?page=lnh-queue', $done ? array( 'lnh_notice' => $code, 'lnh_n' => $done ) : array( 'lnh_notice' => 'error' ) );
	}

	public static function handle_settings(): void {
		check_admin_referer( 'lnh_save_settings' );
		if ( ! current_user_can( self::CAP_ADMIN ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'lehigh-news-hub' ), 403 );
		}
		$tab = isset( $_POST['lnh_tab'] ) ? sanitize_key( wp_unslash( $_POST['lnh_tab'] ) ) : 'general';
		LNH_Settings::save_tab( $tab, $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field by the schema.
		wp_safe_redirect( add_query_arg( array( 'page' => 'lnh-settings', 'tab' => $tab, 'lnh_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_preset(): void {
		check_admin_referer( 'lnh_preset' );
		if ( ! current_user_can( self::CAP_REVIEW ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'lehigh-news-hub' ), 403 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'save';
		if ( 'delete' === $op ) {
			LNH_Settings::delete_preset( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
			$code = 'preset_deleted';
		} else {
			$atts = self::posted_atts();
			unset( $atts['preset'] );
			$schema = LNH_Shortcode::schema();
			$norm   = LNH_Util::normalize_atts( $atts, $schema );
			$diff   = array();
			foreach ( $norm as $k => $v ) { // Store only what differs from the defaults, so settings changes still apply.
				if ( 'preset' !== $k && (string) $v !== (string) $schema[ $k ]['default'] ) {
					$diff[ $k ] = $v;
				}
			}
			$code = LNH_Settings::save_preset( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), $diff ) ? 'preset' : 'error';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'lnh-builder', 'lnh_notice' => $code ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Shortcode attributes from a POSTed builder form (prefixed a_). */
	private static function posted_atts(): array {
		$out = array();
		foreach ( array_keys( LNH_Shortcode::schema() ) as $key ) {
			if ( isset( $_POST[ 'a_' . $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$val         = wp_unslash( $_POST[ 'a_' . $key ] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
				$out[ $key ] = is_array( $val ) ? implode( ',', array_map( 'sanitize_text_field', $val ) ) : sanitize_text_field( $val );
			}
		}
		return $out;
	}

	public static function ajax_preview(): void {
		check_ajax_referer( 'lnh_preview', 'nonce' );
		if ( ! current_user_can( self::CAP_REVIEW ) ) {
			wp_send_json_error( null, 403 );
		}
		$atts = self::posted_atts();
		unset( $atts['preset'] );
		$atts['cache'] = 0;
		wp_send_json_success(
			array(
				'html'      => LNH_Shortcode::render( $atts ),
				'shortcode' => LNH_Shortcode::to_string( array_diff_key( $atts, array( 'cache' => 1 ) ) ),
			)
		);
	}

	// ---------------------------------------------------------------- post editor / posts list integration.

	public static function metabox(): void {
		global $post;
		if ( $post && LNH_Meta::is_agent_post( (int) $post->ID ) ) {
			add_meta_box( 'lnh-review', __( 'AI agents – audit', 'lehigh-news-hub' ), array( __CLASS__, 'metabox_render' ), 'post', 'side', 'high' );
		}
	}

	public static function metabox_render( WP_Post $post ): void {
		self::view( 'metabox', array( 'post' => $post, 'audit' => LNH_Meta::audit( $post->ID ) ) );
	}

	public static function column( array $cols ): array {
		$cols['lnh_score'] = __( 'AI audit', 'lehigh-news-hub' );
		return $cols;
	}

	public static function column_content( string $col, int $post_id ): void {
		if ( 'lnh_score' !== $col ) {
			return;
		}
		if ( ! LNH_Meta::is_agent_post( $post_id ) ) {
			echo '<span class="description">—</span>';
			return;
		}
		$score = (int) get_post_meta( $post_id, 'lnh_audit_score', true );
		echo self::ring( $score, 34 ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=lnh-review&post=' . $post_id ) ) . '">' . esc_html__( 'Review', 'lehigh-news-hub' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function list_filter( string $post_type ): void {
		if ( 'post' !== $post_type ) {
			return;
		}
		$v = isset( $_GET['lnh_origin'] ) ? sanitize_key( wp_unslash( $_GET['lnh_origin'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="lnh_origin"><option value="">' . esc_html__( 'All origins', 'lehigh-news-hub' ) . '</option><option value="agents"' . selected( $v, 'agents', false ) . '>' . esc_html__( 'Created by AI agents', 'lehigh-news-hub' ) . '</option></select>';
	}

	public static function list_filter_query( WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || 'agents' !== ( $_GET['lnh_origin'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$meta   = (array) $q->get( 'meta_query' );
		$meta[] = array( 'key' => 'lnh_audit_status', 'compare' => 'EXISTS' );
		$q->set( 'meta_query', $meta );
	}

	// ---------------------------------------------------------------- shared view helpers.

	/** Score ring (conic gradient). Output is fully escaped here. */
	public static function ring( int $score, int $size = 44 ): string {
		$cls = LNH_Util::score_class( $score );
		return '<span class="lnh-ring lnh-ring--' . esc_attr( $cls ) . '" style="--v:' . (int) $score . ';--s:' . (int) $size . 'px" title="' . esc_attr( sprintf( /* translators: %d: score */ __( 'Audit score %d/100', 'lehigh-news-hub' ), $score ) ) . '"><b>' . (int) $score . '</b></span>';
	}

	public static function chip( string $status ): string {
		$map = array(
			'approved' => array( 'good', __( 'Approved', 'lehigh-news-hub' ) ),
			'flagged'  => array( 'bad', __( 'Flagged', 'lehigh-news-hub' ) ),
		);
		$m = $map[ $status ] ?? array( 'ok', ucfirst( $status ) );
		return '<span class="lnh-chip lnh-chip--' . esc_attr( $m[0] ) . '">' . esc_html( $m[1] ) . '</span>';
	}

	public static function nonce_form_open( string $do, string $back = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="lnh_action"><input type="hidden" name="lnh_do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'lnh_action' );
		if ( '' !== $back ) {
			echo '<input type="hidden" name="_back" value="' . esc_url( $back ) . '">';
		}
	}
}
