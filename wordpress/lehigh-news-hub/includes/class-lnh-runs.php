<?php
defined( 'ABSPATH' ) || exit;

/**
 * Activity log: one private post of type lnh_run per agent run (events, items, models, token usage).
 */
final class LNH_Runs {

	const CPT    = 'lnh_run';
	const AGENTS = array( 'pipeline', 'rastreador', 'redactor', 'auditor', 'social' );

	/** @var array<string, array> Decoded runs by "limit:page" for the current request (reset whenever a run is stored). */
	private static $memo = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'lnh_cleanup', array( __CLASS__, 'cleanup' ) );
	}

	public static function register(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array( 'name' => __( 'Agent runs', 'lehigh-news-hub' ) ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/** Validate and bound an incoming run report from the agents. */
	public static function sanitize( array $raw ): array {
		$str = function ( $v, $max = 200 ) {
			return LNH_Util::clip( sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ), $max );
		};
		$int = function ( $v ) {
			return is_numeric( $v ) ? (int) $v : 0;
		};

		$run = array(
			'run_id'      => $str( $raw['run_id'] ?? '', 40 ),
			'started_at'  => $int( $raw['started_at'] ?? 0 ),
			'finished_at' => $int( $raw['finished_at'] ?? 0 ),
			'status'      => in_array( $raw['status'] ?? '', array( 'ok', 'error', 'running' ), true ) ? (string) $raw['status'] : 'ok',
			'dry_run'     => ! empty( $raw['dry_run'] ),
			'topic'       => $str( $raw['topic'] ?? '' ),
			'models'      => array(),
			'counts'      => array(),
			'usage'       => array(),
			'items'       => array(),
			'events'      => array(),
		);
		if ( '' === $run['run_id'] ) {
			return array();
		}
		foreach ( (array) ( $raw['models'] ?? array() ) as $k => $v ) {
			$run['models'][ sanitize_key( $k ) ] = $str( $v, 80 );
		}
		foreach ( (array) ( $raw['counts'] ?? array() ) as $k => $v ) {
			$run['counts'][ sanitize_key( $k ) ] = $int( $v );
		}
		foreach ( array_slice( (array) ( $raw['usage'] ?? array() ), 0, 12, true ) as $model => $u ) {
			$run['usage'][ $str( $model, 80 ) ] = array(
				'input'  => $int( $u['input'] ?? 0 ),
				'output' => $int( $u['output'] ?? 0 ),
				'calls'  => $int( $u['calls'] ?? 0 ),
			);
		}
		foreach ( array_slice( (array) ( $raw['items'] ?? array() ), 0, 40 ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$run['items'][] = array(
				'titulo_fuente'     => $str( $it['titulo_fuente'] ?? '', 200 ),
				'url'               => esc_url_raw( (string) ( $it['url'] ?? '' ) ),
				'status'            => $str( $it['status'] ?? '', 20 ),
				'audit_score'       => $int( $it['audit_score'] ?? 0 ),
				'post_title'        => $str( $it['post_title'] ?? '', 200 ),
				'featured_image_ai' => ! empty( $it['featured_image_ai'] ),
				'revisions'         => $int( $it['revisions'] ?? 0 ),
				'posted'            => ! empty( $it['posted'] ),
				'post_id'           => $int( $it['post_id'] ?? 0 ),
				'error'             => $str( $it['error'] ?? '', 300 ),
			);
		}
		foreach ( array_slice( (array) ( $raw['events'] ?? array() ), -400 ) as $ev ) {
			if ( ! is_array( $ev ) ) {
				continue;
			}
			$agent = sanitize_key( $ev['agent'] ?? '' );
			$data  = array();
			$d     = is_array( $ev['data'] ?? null ) ? $ev['data'] : array();
			foreach ( array( 'queries' => $str, 'urls' => 'esc_url_raw' ) as $k => $fn ) {
				if ( isset( $d[ $k ] ) && is_array( $d[ $k ] ) ) {
					$data[ $k ] = array_map( $fn, array_slice( array_filter( $d[ $k ], 'is_scalar' ), 0, 5 ) );
				}
			}
			foreach ( array( 'prompt' => 1500, 'model' => 80, 'provider' => 40, 'link' => 300, 'status' => 20 ) as $k => $max ) {
				if ( isset( $d[ $k ] ) ) {
					$data[ $k ] = $str( $d[ $k ], $max );
				}
			}
			foreach ( array( 'score', 'post_id', 'words', 'count', 'seconds' ) as $k ) {
				if ( isset( $d[ $k ] ) ) {
					$data[ $k ] = $int( $d[ $k ] );
				}
			}
			$level = isset( $ev['level'] ) ? (string) $ev['level'] : 'info';
			$run['events'][] = array(
				'ts'      => $int( $ev['ts'] ?? 0 ),
				'agent'   => in_array( $agent, self::AGENTS, true ) ? $agent : 'pipeline',
				'type'    => $str( $ev['type'] ?? '', 30 ),
				'level'   => in_array( $level, array( 'info', 'warn', 'error' ), true ) ? $level : 'info',
				'message' => $str( $ev['message'] ?? '', 400 ),
				'item'    => esc_url_raw( (string) ( $ev['item'] ?? '' ) ),
				'data'    => $data,
			);
		}
		return $run;
	}

	/** Insert or update a run (idempotent on run_id). Returns the post ID. */
	public static function store( array $run ) {
		$existing = self::find_post( $run['run_id'] );
		$args     = array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_title'  => $run['run_id'],
			'post_name'   => sanitize_title( $run['run_id'] ),
		);
		if ( $existing ) {
			$args['ID'] = $existing;
		}
		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::$memo = array();
		update_post_meta( $id, 'lnh_run_data', wp_slash( wp_json_encode( $run, JSON_UNESCAPED_UNICODE ) ) );
		update_post_meta( $id, 'lnh_run_started', (int) $run['started_at'] );
		return $id;
	}

	private static function find_post( string $run_id ): int {
		$q = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'name'           => sanitize_title( $run_id ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $q ? (int) $q[0] : 0;
	}

	private static function decode( int $post_id ): array {
		$data = json_decode( (string) get_post_meta( $post_id, 'lnh_run_data', true ), true );
		return is_array( $data ) ? $data : array();
	}

	/** Most recent runs, newest first. */
	public static function recent( int $limit = 20, int $page = 1 ): array {
		// The activity screen asks for the same 200 runs several times (stats, funnel); decode them once per request.
		$memo = $limit . ':' . $page;
		if ( isset( self::$memo[ $memo ] ) ) {
			return self::$memo[ $memo ];
		}
		$q = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'paged'          => $page,
				'meta_key'       => 'lnh_run_started',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $q->posts as $p ) {
			$data = self::decode( $p->ID );
			if ( $data ) {
				$out[] = $data;
			}
		}
		self::$memo[ $memo ] = $out;
		return $out;
	}

	public static function get( string $run_id ): array {
		$id = self::find_post( $run_id );
		return $id ? self::decode( $id ) : array();
	}

	public static function last(): array {
		$r = self::recent( 1 );
		return $r ? $r[0] : array();
	}

	/**
	 * Flat event feed across recent runs, newest first.
	 *
	 * @param string $agent Optional agent filter.
	 */
	public static function feed( string $agent = '', int $limit = 100, int $since = 0 ): array {
		$events = array();
		foreach ( self::recent( 15 ) as $run ) {
			foreach ( $run['events'] as $ev ) {
				if ( ( '' !== $agent && $ev['agent'] !== $agent ) || ( $since && $ev['ts'] <= $since ) ) {
					continue;
				}
				$ev['run_id']  = $run['run_id'];
				$ev['dry_run'] = ! empty( $run['dry_run'] );
				$events[]      = $ev;
			}
		}
		usort(
			$events,
			function ( $a, $b ) {
				return $b['ts'] <=> $a['ts'];
			}
		);
		return array_slice( $events, 0, $limit );
	}

	/** Per-agent activity over the last $days days, derived from the stored events. */
	public static function agent_stats( int $days = 7 ): array {
		$since = time() - $days * DAY_IN_SECONDS;
		$stats = array(
			'rastreador' => array( 'searches' => 0, 'pages' => 0, 'findings' => 0, 'skipped' => 0, 'last' => 0, 'model' => '' ),
			'redactor'   => array( 'drafts' => 0, 'images' => 0, 'prompts' => 0, 'last' => 0, 'model' => '' ),
			'auditor'    => array( 'audits' => 0, 'approved' => 0, 'flagged' => 0, 'submitted' => 0, 'last' => 0, 'model' => '' ),
			'social'     => array( 'kits' => 0, 'slides' => 0, 'videos' => 0, 'last' => 0, 'model' => '' ),
			'runs'       => 0,
			'tokens'     => 0,
			'image_model' => '',
		);
		foreach ( self::recent( 200 ) as $run ) {
			if ( $run['started_at'] < $since ) {
				continue;
			}
			++$stats['runs'];
			foreach ( $run['usage'] as $u ) {
				$stats['tokens'] += $u['input'] + $u['output'];
			}
			foreach ( array( 'rastreador', 'redactor', 'auditor', 'social' ) as $role ) {
				if ( '' === $stats[ $role ]['model'] && ! empty( $run['models'][ $role ] ) ) {
					$stats[ $role ]['model'] = $run['models'][ $role ];
				}
			}
			if ( '' === $stats['image_model'] ) {
				$stats['image_model'] = $run['models']['image'] ?? '';
			}
			foreach ( $run['events'] as $ev ) {
				$a = $ev['agent'];
				if ( isset( $stats[ $a ]['last'] ) ) {
					$stats[ $a ]['last'] = max( $stats[ $a ]['last'], $ev['ts'] );
				}
				switch ( $a . ':' . $ev['type'] ) {
					case 'rastreador:search':
						++$stats['rastreador']['searches'];
						break;
					case 'rastreador:fetch':
						++$stats['rastreador']['pages'];
						break;
					case 'rastreador:finding':
						++$stats['rastreador']['findings'];
						break;
					case 'rastreador:skipped':
						++$stats['rastreador']['skipped'];
						break;
					case 'redactor:draft':
						++$stats['redactor']['drafts'];
						break;
					case 'redactor:image_ready':
						++$stats['redactor']['images'];
						break;
					case 'redactor:image_prompt':
						++$stats['redactor']['prompts'];
						break;
					case 'auditor:audit':
						++$stats['auditor']['audits'];
						if ( 'approved' === ( $ev['data']['status'] ?? '' ) ) {
							++$stats['auditor']['approved'];
						} else {
							++$stats['auditor']['flagged'];
						}
						break;
					case 'auditor:submitted':
						++$stats['auditor']['submitted'];
						break;
					case 'social:done':
						++$stats['social']['kits'];
						break;
					case 'social:render':
						$stats['social']['slides'] += (int) ( $ev['data']['count'] ?? 0 );
						break;
					case 'social:video':
						++$stats['social']['videos'];
						break;
				}
			}
		}
		return $stats;
	}

	public static function cleanup(): void {
		$days = (int) LNH_Settings::get( 'retention_days' );
		$old  = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'date_query'     => array( array( 'column' => 'post_date_gmt', 'before' => $days . ' days ago' ) ),
			)
		);
		foreach ( $old as $id ) {
			wp_delete_post( $id, true );
		}
	}
}
