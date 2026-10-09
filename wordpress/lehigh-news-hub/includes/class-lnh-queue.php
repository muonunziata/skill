<?php
defined( 'ABSPATH' ) || exit;

/**
 * Review-queue logic: querying agent posts and applying editor decisions.
 */
final class LNH_Queue {

	/** tab => post statuses */
	const TABS = array(
		'review'    => array( 'draft' ),
		'flagged'   => array( 'pending' ),
		'scheduled' => array( 'future' ),
		'published' => array( 'publish' ),
		'rejected'  => array( 'trash' ),
	);

	const COUNTS_KEY = 'lnh_counts';

	public static function init(): void {
		add_action( 'rest_after_insert_post', array( __CLASS__, 'on_rest_insert' ), 20, 3 );
		add_filter( 'rest_pre_insert_post', array( __CLASS__, 'filter_agent_content' ), 10, 2 );
		foreach ( array( 'transition_post_status', 'deleted_post', 'added_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_counts' ) );
		}
	}

	public static function flush_counts(): void {
		delete_transient( self::COUNTS_KEY );
	}

	/**
	 * Agent articles are untrusted input (their text comes from web pages through a language model): whatever
	 * account the agents use, strip anything a normal author could not publish.
	 *
	 * @param stdClass        $prepared Prepared post.
	 * @param WP_REST_Request $request  Request.
	 */
	public static function filter_agent_content( $prepared, $request ) {
		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) && isset( $meta['lnh_audit_status'] ) && isset( $prepared->post_content ) ) {
			$prepared->post_content = wp_kses_post( $prepared->post_content );
			if ( isset( $prepared->post_excerpt ) ) {
				$prepared->post_excerpt = wp_kses_post( $prepared->post_excerpt );
			}
		}
		return $prepared;
	}

	public static function tab_labels(): array {
		return array(
			'review'    => __( 'To review', 'lehigh-news-hub' ),
			'flagged'   => __( 'Flagged', 'lehigh-news-hub' ),
			'scheduled' => __( 'Scheduled', 'lehigh-news-hub' ),
			'published' => __( 'Published', 'lehigh-news-hub' ),
			'rejected'  => __( 'Rejected', 'lehigh-news-hub' ),
		);
	}

	private static function base_args( string $tab ): array {
		return array(
			'post_type'   => 'post',
			'post_status' => self::TABS[ $tab ] ?? array( 'draft' ),
			'meta_query'  => array(
				array(
					'key'     => 'lnh_audit_status',
					'compare' => 'EXISTS',
				),
			),
		);
	}

	public static function query( string $tab, array $opts = array() ): WP_Query {
		$args                   = self::base_args( $tab );
		$args['posts_per_page'] = (int) ( $opts['per_page'] ?? LNH_Settings::get( 'per_page' ) );
		$args['paged']          = max( 1, (int) ( $opts['paged'] ?? 1 ) );
		if ( ! empty( $opts['s'] ) ) {
			$args['s'] = sanitize_text_field( $opts['s'] );
		}
		if ( ! empty( $opts['min_score'] ) ) {
			$args['meta_query'][] = array(
				'key'     => 'lnh_audit_score',
				'value'   => (int) $opts['min_score'],
				'type'    => 'NUMERIC',
				'compare' => '>=',
			);
		}
		if ( 'score' === ( $opts['orderby'] ?? '' ) ) {
			$args['meta_key'] = 'lnh_audit_score';
			$args['orderby']  = 'meta_value_num';
		} else {
			$args['orderby'] = 'date';
		}
		$args['order'] = 'asc' === strtolower( $opts['order'] ?? '' ) ? 'ASC' : 'DESC';
		return new WP_Query( $args );
	}

	/** Number of posts per tab (cached briefly: it is shown on every admin page). */
	public static function counts(): array {
		$cached = get_transient( self::COUNTS_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array();
		foreach ( array_keys( self::TABS ) as $tab ) {
			$args                   = self::base_args( $tab );
			$args['posts_per_page'] = 1;
			$args['fields']         = 'ids';
			$out[ $tab ]            = (int) ( new WP_Query( $args ) )->found_posts;
		}
		set_transient( self::COUNTS_KEY, $out, 60 );
		return $out;
	}

	// ---------------------------------------------------------------- decisions.

	/**
	 * Publish now, or schedule when $timestamp is in the future.
	 *
	 * @return true|WP_Error
	 */
	public static function publish( int $post_id, int $timestamp = 0, bool $trusted = false ) {
		if ( ! $trusted && ! current_user_can( 'publish_post', $post_id ) ) {
			return new WP_Error( 'lnh_forbidden', __( 'You are not allowed to publish this article.', 'lehigh-news-hub' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type || ! LNH_Meta::is_agent_post( $post_id ) ) {
			return new WP_Error( 'lnh_not_agent_post', __( 'This is not an agent article.', 'lehigh-news-hub' ) );
		}
		$args = array( 'ID' => $post_id, 'post_status' => 'publish' );
		if ( $timestamp > time() + 60 ) {
			$args['post_status']   = 'future';
			$args['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
			$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
			$args['edit_date']     = true;
		} elseif ( in_array( $post->post_status, array( 'draft', 'pending', 'trash', 'auto-draft' ), true ) ) {
			$args['post_date']     = current_time( 'mysql' );
			$args['post_date_gmt'] = current_time( 'mysql', true );
			$args['edit_date']     = true;
		}
		$author = (int) LNH_Settings::get( 'post_author' );
		if ( $author && get_userdata( $author ) ) {
			$args['post_author'] = $author;
		}
		$result = wp_update_post( wp_slash( $args ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::ensure_category( $post_id );
		update_post_meta( $post_id, 'lnh_reviewed_by', get_current_user_id() );
		update_post_meta( $post_id, 'lnh_reviewed_at', time() );
		delete_post_meta( $post_id, 'lnh_reject_reason' );
		LNH_Shortcode::bump_cache();
		return true;
	}

	/** @return true|WP_Error */
	public static function reject( int $post_id, string $reason = '' ) {
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new WP_Error( 'lnh_forbidden', __( 'You are not allowed to reject this article.', 'lehigh-news-hub' ) );
		}
		if ( ! LNH_Meta::is_agent_post( $post_id ) ) {
			return new WP_Error( 'lnh_not_agent_post', __( 'This is not an agent article.', 'lehigh-news-hub' ) );
		}
		update_post_meta( $post_id, 'lnh_reject_reason', sanitize_textarea_field( $reason ) );
		update_post_meta( $post_id, 'lnh_reviewed_by', get_current_user_id() );
		update_post_meta( $post_id, 'lnh_reviewed_at', time() );
		wp_trash_post( $post_id );
		LNH_Shortcode::bump_cache();
		return true;
	}

	/** Back to the review queue as a draft. @return true|WP_Error */
	public static function restore( int $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) || ! LNH_Meta::is_agent_post( $post_id ) ) {
			return new WP_Error( 'lnh_forbidden', __( 'You are not allowed to restore this article.', 'lehigh-news-hub' ) );
		}
		if ( 'trash' === get_post_status( $post_id ) ) {
			wp_untrash_post( $post_id );
		}
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		LNH_Shortcode::bump_cache();
		return true;
	}

	/** @return true|WP_Error */
	public static function delete( int $post_id ) {
		if ( ! current_user_can( 'delete_post', $post_id ) || ! LNH_Meta::is_agent_post( $post_id ) ) {
			return new WP_Error( 'lnh_forbidden', __( 'You are not allowed to delete this article.', 'lehigh-news-hub' ) );
		}
		wp_delete_post( $post_id, true );
		LNH_Shortcode::bump_cache();
		return true;
	}

	private static function ensure_category( int $post_id ): void {
		$default = (int) LNH_Settings::get( 'default_category' );
		if ( ! $default ) {
			return;
		}
		$terms = wp_get_post_categories( $post_id );
		$unc   = (int) get_option( 'default_category' );
		if ( ! $terms || ( 1 === count( $terms ) && (int) $terms[0] === $unc ) ) {
			wp_set_post_categories( $post_id, array( $default ) );
		}
	}

	// ---------------------------------------------------------------- arrival of a new agent draft.

	public static function on_rest_insert( $post, $request, $creating ): void {
		if ( ! $creating || ! $post instanceof WP_Post || ! LNH_Meta::is_agent_post( $post->ID ) ) {
			return;
		}
		$settings = LNH_Settings::all();
		update_option( 'lnh_last_heartbeat', time(), false );
		$score  = (int) get_post_meta( $post->ID, 'lnh_audit_score', true );
		$status = (string) get_post_meta( $post->ID, 'lnh_audit_status', true );

		if ( $settings['auto_publish'] && 'approved' === $status && $score >= (int) $settings['auto_publish_min_score'] && 'draft' === $post->post_status ) {
			// Editorial rule configured by an administrator: no user switching, the capability check is replaced by the rule.
			if ( true === self::publish( $post->ID, 0, true ) ) {
				update_post_meta( $post->ID, 'lnh_reviewed_by', 0 ); // 0 = published automatically.
				return;
			}
		}
		if ( $settings['notify'] ) {
			self::notify( $post, $score, $status, (string) $settings['notify_email'] );
		}
	}

	private static function notify( WP_Post $post, int $score, string $status, string $email ): void {
		$key = 'lnh_notify_' . gmdate( 'YmdH' );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) { // Hard cap: ten emails per hour.
			return;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		$to = is_email( $email ) ? $email : get_option( 'admin_email' );
		/* translators: 1: site name, 2: article title, 3: audit score */
		$subject = sprintf( __( '[%1$s] New AI draft to review: %2$s (score %3$d)', 'lehigh-news-hub' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), wp_strip_all_tags( $post->post_title ), $score );
		$link    = admin_url( 'admin.php?page=lnh-review&post=' . $post->ID );
		/* translators: 1: audit status, 2: audit score, 3: review URL */
		$body = sprintf( __( "The agents have a new article waiting for your decision.\n\nAudit: %1\$s (%2\$d/100)\n\nReview it here: %3\$s\n", 'lehigh-news-hub' ), $status, $score, $link );
		wp_mail( $to, $subject, $body );
	}
}
