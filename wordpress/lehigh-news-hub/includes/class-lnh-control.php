<?php
defined( 'ABSPATH' ) || exit;

/**
 * Remote control of the agents: the Start / Pause button.
 *
 * WordPress cannot run the Python agents itself, so the agents connect to the hub: the worker process (`python main.py watch`)
 * calls POST /lnh/v1/control/sync every few seconds, reports what it is doing, and receives the editor's wishes
 * (running or paused, "run now", how often to run). Nothing is pushed into the agents' machine; they always call out.
 */
final class LNH_Control {

	const STATE_OPTION  = 'lnh_control';
	const WORKER_OPTION = 'lnh_worker';
	/** The worker syncs every ~15 s; this long without news means it is gone. */
	const CONNECTED_WITHIN = 75;
	/** Agents shown in the working animation, in pipeline order. */
	const AGENTS = array( 'rastreador', 'redactor', 'auditor', 'social' );

	public static function init(): void {
		add_action( 'admin_post_lnh_control', array( __CLASS__, 'handle' ) );
	}

	// ---------------------------------------------------------------- desired state (set by people).

	/** @return array{state:string,run_now:int,by:int,at:int} */
	public static function desired(): array {
		$o = get_option( self::STATE_OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array(
			// Safe default: nothing runs (and no API money is spent) until someone presses Start.
			'state'   => ( 'running' === ( $o['state'] ?? '' ) ) ? 'running' : 'paused',
			'run_now' => absint( $o['run_now'] ?? 0 ),
			'by'      => absint( $o['by'] ?? 0 ),
			'at'      => absint( $o['at'] ?? 0 ),
		);
	}

	public static function is_running(): bool {
		return 'running' === self::desired()['state'];
	}

	/** Start / pause / run now. Returns the new desired state, or false for an unknown action. */
	public static function apply( string $action, int $user_id = 0 ) {
		$d = self::desired();
		switch ( $action ) {
			case 'start':
				$d['state'] = 'running';
				break;
			case 'pause':
				$d['state']   = 'paused';
				$d['run_now'] = 0;
				break;
			case 'run_now':
				$d['state']   = 'running'; // "run now" implies the agents should be working.
				$d['run_now'] = time();
				break;
			default:
				return false;
		}
		$d['by'] = $user_id ?: get_current_user_id();
		$d['at'] = time();
		update_option( self::STATE_OPTION, $d, false );
		return $d;
	}

	/** Minutes between runs while working (the same setting that drives the dashboard's late warning). */
	public static function interval_minutes(): int {
		return max( 5, (int) LNH_Settings::get( 'expected_interval' ) );
	}

	// ---------------------------------------------------------------- worker (reported by the agents).

	/** What the agents last told us. */
	public static function worker(): array {
		$w = get_option( self::WORKER_OPTION, array() );
		$w = is_array( $w ) ? $w : array();
		$seen = absint( $w['last_seen'] ?? 0 );
		$st   = in_array( $w['status'] ?? '', array( 'idle', 'working', 'paused', 'stopped' ), true ) ? $w['status'] : 'idle';
		return array(
			'seen'        => (bool) $seen,
			'connected'   => $seen && 'stopped' !== $st && ( time() - $seen ) <= self::CONNECTED_WITHIN,
			'last_seen'   => $seen,
			'status'      => $st,
			'message'     => (string) ( $w['message'] ?? '' ),
			'agent'       => in_array( $w['agent'] ?? '', self::AGENTS, true ) ? $w['agent'] : '',
			'host'        => (string) ( $w['host'] ?? '' ),
			'version'     => (string) ( $w['version'] ?? '' ),
			'next_run_at' => absint( $w['next_run_at'] ?? 0 ),
			'last_run_at' => absint( $w['last_run_at'] ?? 0 ),
		);
	}

	/**
	 * Called by the agents (POST /lnh/v1/control/sync): store their status, hand back the desired state.
	 *
	 * @param array $in Payload from the worker (untrusted: clipped and whitelisted here).
	 */
	public static function sync( array $in ): array {
		$clip = function ( $v, int $max ): string {
			return LNH_Util::clip( sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ), $max );
		};
		$status = in_array( $in['status'] ?? '', array( 'idle', 'working', 'paused', 'stopped' ), true ) ? $in['status'] : 'idle';
		update_option(
			self::WORKER_OPTION,
			array(
				'last_seen'   => time(),
				'status'      => $status,
				'message'     => $clip( $in['message'] ?? '', 200 ),
				'agent'       => in_array( $in['agent'] ?? '', self::AGENTS, true ) ? $in['agent'] : '',
				'host'        => $clip( $in['host'] ?? '', 80 ),
				'version'     => $clip( $in['version'] ?? '', 20 ),
				'next_run_at' => absint( $in['next_run_at'] ?? 0 ),
				'last_run_at' => absint( $in['last_run_at'] ?? 0 ),
			),
			false
		);
		$d = self::desired();
		// The worker confirms which "run now" request it started; clear exactly that one (a newer click survives).
		$handled = absint( $in['handled_run_now'] ?? 0 );
		if ( $handled && $handled === $d['run_now'] ) {
			$d['run_now'] = 0;
			update_option( self::STATE_OPTION, $d, false );
		}
		return array(
			'state'            => $d['state'],
			'run_now'          => $d['run_now'],
			'interval_minutes' => self::interval_minutes(),
			'server_time'      => time(),
		);
	}

	// ---------------------------------------------------------------- what the screens show.

	/** One summary used by the dashboard card, the activity page and the live refresh. */
	public static function status(): array {
		$d = self::desired();
		$w = self::worker();
		if ( 'paused' === $d['state'] ) {
			$phase = ( $w['connected'] && 'working' === $w['status'] ) ? 'pausing' : 'paused';
		} elseif ( ! $w['connected'] ) {
			$phase = 'offline';
		} elseif ( 'working' === $w['status'] ) {
			$phase = 'working';
		} else {
			$phase = 'waiting';
		}
		$labels = array(
			'paused'  => __( 'Paused', 'lehigh-news-hub' ),
			'pausing' => __( 'Pausing after the current article…', 'lehigh-news-hub' ),
			'offline' => __( 'Waiting for the agents to connect', 'lehigh-news-hub' ),
			'working' => __( 'Working now', 'lehigh-news-hub' ),
			'waiting' => __( 'Running – waiting for the next cycle', 'lehigh-news-hub' ),
		);
		return array(
			'state'      => $d['state'],
			'phase'      => $phase,
			'label'      => $labels[ $phase ],
			'run_queued' => $d['run_now'] > 0,
			'worker'     => $w + array( 'seen_ago' => $w['last_seen'] ? LNH_Util::ago( $w['last_seen'] ) : '' ),
			'next_in'    => ( 'waiting' === $phase && $w['next_run_at'] > time() ) ? human_time_diff( time(), $w['next_run_at'] ) : '',
			'interval'   => self::interval_minutes(),
		);
	}

	// ---------------------------------------------------------------- admin-post handler (works without JavaScript).

	public static function handle(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'lehigh-news-hub' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lnh_control' );
		$action = isset( $_POST['lnh_do'] ) ? sanitize_key( wp_unslash( $_POST['lnh_do'] ) ) : '';
		$ok     = self::apply( $action );
		$codes  = array( 'start' => 'agents_started', 'pause' => 'agents_paused', 'run_now' => 'agents_run_now' );
		$back   = isset( $_POST['_back'] ) ? esc_url_raw( wp_unslash( $_POST['_back'] ) ) : '';
		$back   = wp_validate_redirect( $back, admin_url( 'admin.php?page=lnh' ) );
		wp_safe_redirect( add_query_arg( 'lnh_notice', $ok ? $codes[ $action ] : 'error', remove_query_arg( array( 'lnh_notice', 'lnh_n' ), $back ) ) );
		exit;
	}
}
