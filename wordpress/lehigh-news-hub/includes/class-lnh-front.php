<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public side of agent articles: source line and AI disclosure.
 */
final class LNH_Front {

	public static function init(): void {
		add_filter( 'the_content', array( __CLASS__, 'decorate' ), 30 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'label_thumbnail' ), 20, 4 );
	}

	/**
	 * Theme-independent disclosure: AI-generated featured images are always labelled on the article page.
	 */
	public static function label_thumbnail( $html, $post_id, $thumb_id, $size ) {
		if ( ! is_singular( 'post' ) || ! is_main_query() || (int) get_queried_object_id() !== (int) $post_id ) {
			return $html;
		}
		if ( ! get_post_meta( $post_id, 'lnh_featured_image_ai', true ) || false !== strpos( (string) $html, 'lnh-ai-caption' ) ) {
			return $html;
		}
		$caption = $thumb_id ? wp_get_attachment_caption( (int) $thumb_id ) : '';
		$caption = $caption ? $caption : __( 'AI-generated illustration', 'lehigh-news-hub' );
		wp_enqueue_style( 'lnh-front' );
		return $html . '<span class="lnh-ai-caption">' . esc_html( wp_strip_all_tags( $caption ) ) . '</span>';
	}

	public static function decorate( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = get_the_ID();
		if ( ! $id || ! LNH_Meta::is_agent_post( $id ) ) {
			return $content;
		}
		$s      = LNH_Settings::all();
		$before = '';
		$after  = '';

		if ( $s['show_attribution'] ) {
			$url  = (string) get_post_meta( $id, 'lnh_source_url', true );
			$name = (string) get_post_meta( $id, 'lnh_source_name', true );
			$name = '' !== $name ? $name : LNH_Util::host( $url );
			if ( '' !== $url && '' !== $name ) {
				$date = (string) get_post_meta( $id, 'lnh_source_date', true );
				$line = LNH_Util::fill(
					esc_html( $s['attribution_text'] ),
					array(
						'source' => '<a href="' . esc_url( $url ) . '" rel="nofollow noopener" target="_blank">' . esc_html( $name ) . '</a>',
						'date'   => esc_html( $date ),
					)
				);
				$box = '<p class="lnh-source">' . $line . '</p>';
				if ( 'before' === $s['attribution_position'] ) {
					$before .= $box;
				} else {
					$after .= $box;
				}
				wp_enqueue_style( 'lnh-front' );
			}
		}
		if ( $s['show_disclosure'] && '' !== trim( (string) $s['disclosure_text'] ) ) {
			$text   = LNH_Util::fill(
				esc_html( $s['disclosure_text'] ),
				array(
					'score'  => (int) get_post_meta( $id, 'lnh_audit_score', true ),
					'source' => esc_html( (string) get_post_meta( $id, 'lnh_source_name', true ) ),
				)
			);
			$after .= '<aside class="lnh-disclosure" role="note"><span class="lnh-disclosure__tag">AI</span><p>' . $text . '</p></aside>';
			wp_enqueue_style( 'lnh-front' );
		}
		return $before . $content . $after;
	}
}
