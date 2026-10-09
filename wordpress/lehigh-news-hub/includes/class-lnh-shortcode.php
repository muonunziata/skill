<?php
defined( 'ABSPATH' ) || exit;

/**
 * [lehigh_news] – latest articles, fully configurable from the shortcode, saved presets and the settings screen.
 */
final class LNH_Shortcode {

	const TAG = 'lehigh_news';

	public static function init(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'bump_cache' ) );
		}
	}

	public static function register_assets(): void {
		wp_register_style( 'lnh-front', LNH_URL . 'assets/css/front.css', array(), LNH_VERSION );
	}

	public static function bump_cache(): void {
		update_option( 'lnh_cache_v', (int) get_option( 'lnh_cache_v', 1 ) + 1, false );
	}

	/** Attribute schema: single source of truth for validation, the builder UI and the docs. */
	public static function schema(): array {
		$s = LNH_Settings::all();
		return array(
			'preset'           => array( 'type' => 'text', 'default' => '' ),
			'layout'           => array( 'type' => 'enum', 'default' => $s['sc_layout'], 'options' => array( 'grid', 'list', 'featured', 'compact', 'minimal' ) ),
			'count'            => array( 'type' => 'int', 'default' => (int) $s['sc_count'], 'min' => 1, 'max' => 50 ),
			'columns'          => array( 'type' => 'int', 'default' => (int) $s['sc_columns'], 'min' => 1, 'max' => 6 ),
			'source'           => array( 'type' => 'enum', 'default' => $s['sc_source'], 'options' => array( 'hub', 'all' ) ),
			'category'         => array( 'type' => 'csv', 'default' => '' ),
			'exclude_category' => array( 'type' => 'csv', 'default' => '' ),
			'tag'              => array( 'type' => 'csv', 'default' => '' ),
			'ids'              => array( 'type' => 'ids', 'default' => '' ),
			'exclude_ids'      => array( 'type' => 'ids', 'default' => '' ),
			'lang'             => array( 'type' => 'text', 'default' => '' ),
			'orderby'          => array( 'type' => 'enum', 'default' => 'date', 'options' => array( 'date', 'modified', 'title', 'rand', 'comment_count', 'score' ) ),
			'order'            => array( 'type' => 'enum', 'default' => 'DESC', 'options' => array( 'DESC', 'ASC' ) ),
			'offset'           => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 200 ),
			'since'            => array( 'type' => 'text', 'default' => '' ),
			'min_score'        => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 100 ),
			'image_ratio'      => array( 'type' => 'enum', 'default' => $s['sc_image_ratio'], 'options' => array( '16:9', '3:2', '4:3', '1:1', 'auto' ) ),
			'image_size'       => array( 'type' => 'enum', 'default' => $s['sc_image_size'], 'options' => array( 'thumbnail', 'medium', 'medium_large', 'large', 'full' ) ),
			'show_image'       => array( 'type' => 'bool', 'default' => (int) $s['sc_show_image'] ),
			'show_category'    => array( 'type' => 'bool', 'default' => (int) $s['sc_show_category'] ),
			'show_date'        => array( 'type' => 'bool', 'default' => (int) $s['sc_show_date'] ),
			'show_excerpt'     => array( 'type' => 'bool', 'default' => (int) $s['sc_show_excerpt'] ),
			'show_source'      => array( 'type' => 'bool', 'default' => (int) $s['sc_show_source'] ),
			'show_author'      => array( 'type' => 'bool', 'default' => (int) $s['sc_show_author'] ),
			'show_readmore'    => array( 'type' => 'bool', 'default' => (int) $s['sc_show_readmore'] ),
			'show_ai_badge'    => array( 'type' => 'bool', 'default' => (int) $s['sc_show_ai_badge'] ),
			'excerpt_length'   => array( 'type' => 'int', 'default' => (int) $s['sc_excerpt_length'], 'min' => 5, 'max' => 120 ),
			'date_format'      => array( 'type' => 'enum', 'default' => $s['sc_date_format'], 'options' => array( 'relative', 'absolute' ) ),
			'readmore_text'    => array( 'type' => 'text', 'default' => (string) $s['sc_readmore_text'] ),
			'title_tag'        => array( 'type' => 'enum', 'default' => 'h3', 'options' => array( 'h2', 'h3', 'h4', 'h5', 'p' ) ),
			'link_target'      => array( 'type' => 'enum', 'default' => '_self', 'options' => array( '_self', '_blank' ) ),
			'heading'          => array( 'type' => 'text', 'default' => '' ),
			'more_link'        => array( 'type' => 'url', 'default' => '' ),
			'more_text'        => array( 'type' => 'text', 'default' => __( 'View all', 'lehigh-news-hub' ) ),
			'pagination'       => array( 'type' => 'bool', 'default' => 0 ),
			'class'            => array( 'type' => 'text', 'default' => '' ),
			'accent'           => array( 'type' => 'color', 'default' => $s['ds_accent'] ),
			'theme'            => array( 'type' => 'enum', 'default' => $s['ds_theme'], 'options' => array( 'auto', 'light', 'dark' ) ),
			'cache'            => array( 'type' => 'int', 'default' => (int) $s['sc_cache'], 'min' => 0, 'max' => 1440 ),
			'empty_message'    => array( 'type' => 'text', 'default' => __( 'No articles yet.', 'lehigh-news-hub' ) ),
		);
	}

	/** Resolve preset + explicit attributes into validated attributes. */
	public static function resolve( $atts ): array {
		$schema = self::schema();
		$atts   = is_array( $atts ) ? $atts : array();
		$preset = isset( $atts['preset'] ) ? sanitize_title( $atts['preset'] ) : '';
		if ( '' !== $preset ) {
			$saved = LNH_Settings::presets();
			if ( isset( $saved[ $preset ] ) && is_array( $saved[ $preset ] ) ) {
				$atts = array_merge( $saved[ $preset ], $atts ); // Explicit attributes win over the preset.
			}
		}
		return LNH_Util::normalize_atts( $atts, $schema );
	}

	/** WP_Query arguments for validated attributes. */
	public static function query_args( array $a, int $page = 1 ): array {
		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $a['count'],
			'ignore_sticky_posts' => true,
			'has_password'        => false,
			'order'               => $a['order'],
			'no_found_rows'       => ! $a['pagination'],
		);
		if ( $a['pagination'] ) {
			$args['paged'] = $page;
		} elseif ( $a['offset'] ) {
			$args['offset'] = $a['offset'];
		}
		$args['orderby'] = $a['orderby'];
		if ( 'score' === $a['orderby'] ) {
			$args['meta_key'] = 'lnh_audit_score';
			$args['orderby']  = array( 'meta_value_num' => $a['order'], 'date' => 'DESC' );
		}
		$meta = array();
		if ( 'hub' === $a['source'] ) {
			$meta[] = array( 'key' => 'lnh_audit_status', 'compare' => 'EXISTS' );
		}
		if ( $a['min_score'] > 0 ) {
			$meta[] = array( 'key' => 'lnh_audit_score', 'value' => $a['min_score'], 'type' => 'NUMERIC', 'compare' => '>=' );
		}
		if ( '' !== $a['lang'] ) {
			$meta[] = array( 'key' => 'lnh_language', 'value' => $a['lang'] );
		}
		if ( $meta ) {
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta );
		}
		if ( '' !== $a['category'] ) {
			$args['category_name'] = $a['category'];
		}
		if ( '' !== $a['exclude_category'] ) {
			$ids = array();
			foreach ( explode( ',', $a['exclude_category'] ) as $slug ) {
				$term = get_category_by_slug( $slug );
				if ( $term ) {
					$ids[] = (int) $term->term_id;
				}
			}
			if ( $ids ) {
				$args['category__not_in'] = $ids;
			}
		}
		if ( '' !== $a['tag'] ) {
			$args['tag'] = $a['tag'];
		}
		if ( '' !== $a['ids'] ) {
			$args['post__in'] = array_map( 'intval', explode( ',', $a['ids'] ) );
			if ( 'date' === $a['orderby'] && 'DESC' === $a['order'] ) {
				$args['orderby'] = 'post__in';
			}
		}
		if ( '' !== $a['exclude_ids'] ) {
			$args['post__not_in'] = array_map( 'intval', explode( ',', $a['exclude_ids'] ) );
		}
		$since = LNH_Util::parse_since( $a['since'] );
		if ( $since ) {
			$args['date_query'] = array( array( 'after' => gmdate( 'Y-m-d H:i:s', $since ), 'inclusive' => true, 'column' => 'post_date_gmt' ) );
		}
		return $args;
	}

	public static function render( $atts = array() ): string {
		$a        = self::resolve( $atts );
		$uid      = substr( md5( wp_json_encode( $a ) ), 0, 6 );
		$var      = 'lnhp' . $uid;
		$page     = $a['pagination'] ? max( 1, absint( isset( $_GET[ $var ] ) ? wp_unslash( $_GET[ $var ] ) : 1 ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cache_on = $a['cache'] > 0 && 'rand' !== $a['orderby'] && ! is_preview();
		$key      = 'lnh_sc_' . md5( wp_json_encode( $a ) . '|' . $page . '|' . (int) get_option( 'lnh_cache_v', 1 ) . '|' . get_locale() );
		if ( $cache_on ) {
			$hit = get_transient( $key );
			if ( is_string( $hit ) ) {
				wp_enqueue_style( 'lnh-front' );
				return $hit;
			}
		}
		$query = new WP_Query( self::query_args( $a, $page ) );
		$html  = self::markup( $query->posts, $a, $uid, $var, $page, (int) $query->max_num_pages );
		wp_reset_postdata();
		wp_enqueue_style( 'lnh-front' );
		if ( $cache_on ) {
			set_transient( $key, $html, $a['cache'] * MINUTE_IN_SECONDS );
		}
		return $html;
	}

	private static function style_vars( array $a ): string {
		$s    = LNH_Settings::all();
		$vars = array(
			'--lnh-cols'        => (int) $a['columns'],
			'--lnh-accent'      => LNH_Util::hex_color( $a['accent'], '#1d6fdc' ),
			'--lnh-radius'      => (int) $s['ds_radius'] . 'px',
			'--lnh-gap'         => (int) $s['ds_gap'] . 'px',
			'--lnh-title-scale' => round( (int) $s['ds_title_scale'] / 100, 2 ),
		);
		$ratio = LNH_Util::ratio( $a['image_ratio'] );
		if ( $ratio ) {
			$vars['--lnh-ratio'] = $ratio;
		}
		$out = '';
		foreach ( $vars as $k => $v ) {
			$out .= $k . ':' . $v . ';';
		}
		return $out;
	}

	private static function date_html( WP_Post $post, array $a ): string {
		$ts = (int) get_post_time( 'U', true, $post );
		if ( 'relative' === $a['date_format'] && time() - $ts < 7 * DAY_IN_SECONDS && $ts <= time() ) {
			/* translators: %s: human readable time difference */
			$label = sprintf( __( '%s ago', 'lehigh-news-hub' ), human_time_diff( $ts ) );
		} else {
			$label = wp_date( get_option( 'date_format' ), $ts );
		}
		return '<time class="lnh-meta__date" datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '">' . esc_html( $label ) . '</time>';
	}

	private static function meta_html( WP_Post $post, array $a ): string {
		$bits = array();
		if ( $a['show_date'] ) {
			$bits[] = self::date_html( $post, $a );
		}
		if ( $a['show_source'] && LNH_Meta::is_agent_post( $post->ID ) ) {
			$name = (string) get_post_meta( $post->ID, 'lnh_source_name', true );
			$name = '' !== $name ? $name : LNH_Util::host( (string) get_post_meta( $post->ID, 'lnh_source_url', true ) );
			if ( '' !== $name ) {
				$bits[] = '<span class="lnh-meta__source">' . esc_html( $name ) . '</span>';
			}
		}
		if ( $a['show_author'] ) {
			$bits[] = '<span class="lnh-meta__author">' . esc_html( get_the_author_meta( 'display_name', (int) $post->post_author ) ) . '</span>';
		}
		return $bits ? '<div class="lnh-meta">' . implode( '<span class="lnh-meta__sep" aria-hidden="true">·</span>', $bits ) . '</div>' : '';
	}

	private static function card( WP_Post $post, array $a, bool $hero = false ): string {
		$url    = get_permalink( $post );
		$target = '_blank' === $a['link_target'] ? ' target="_blank" rel="noopener"' : '';
		$tag    = $a['title_tag'];
		$cat    = '';
		if ( $a['show_category'] ) {
			$cats = get_the_category( $post->ID );
			if ( $cats ) {
				$cat = '<span class="lnh-card__cat">' . esc_html( $cats[0]->name ) . '</span>';
			}
		}
		$media = '';
		if ( $a['show_image'] ) {
			$thumb = has_post_thumbnail( $post ) ? get_the_post_thumbnail( $post, $hero ? 'large' : $a['image_size'], array( 'class' => 'lnh-card__img', 'loading' => 'lazy', 'decoding' => 'async' ) ) : '';
			$badge = '';
			if ( $thumb && $a['show_ai_badge'] && get_post_meta( $post->ID, 'lnh_featured_image_ai', true ) ) {
				$badge = '<span class="lnh-badge" title="' . esc_attr__( 'AI-generated illustration', 'lehigh-news-hub' ) . '">' . esc_html__( 'AI image', 'lehigh-news-hub' ) . '</span>';
			}
			$inner = $thumb ? $thumb : '<span class="lnh-card__ph" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( wp_strip_all_tags( $post->post_title ), 0, 1 ) ) ) . '</span>';
			$media = '<a class="lnh-card__media" href="' . esc_url( $url ) . '"' . $target . ' tabindex="-1" aria-hidden="true">' . $inner . $badge . '</a>';
		}
		$excerpt = '';
		if ( $a['show_excerpt'] ) {
			$text    = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
			$excerpt = '<p class="lnh-card__excerpt">' . esc_html( LNH_Util::words( strip_shortcodes( preg_replace( '/<!--.*?-->/s', '', $text ) ), $a['excerpt_length'] ) ) . '</p>';
		}
		$more = $a['show_readmore'] ? '<a class="lnh-card__more" href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( $a['readmore_text'] ) . '<span class="screen-reader-text"> ' . esc_html( get_the_title( $post ) ) . '</span></a>' : '';
		return '<article class="lnh-card' . ( $hero ? ' lnh-card--hero' : '' ) . '">' . $media
			. '<div class="lnh-card__body">' . $cat
			. '<' . $tag . ' class="lnh-card__title"><a href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( get_the_title( $post ) ) . '</a></' . $tag . '>'
			. self::meta_html( $post, $a ) . $excerpt . $more . '</div></article>';
	}

	private static function row( WP_Post $post, array $a, bool $thumb ): string {
		$url    = get_permalink( $post );
		$target = '_blank' === $a['link_target'] ? ' target="_blank" rel="noopener"' : '';
		$img    = '';
		if ( $thumb && $a['show_image'] && has_post_thumbnail( $post ) ) {
			$img = '<a class="lnh-row__media" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true">' . get_the_post_thumbnail( $post, 'thumbnail', array( 'loading' => 'lazy', 'class' => 'lnh-row__img' ) ) . '</a>';
		}
		return '<li class="lnh-row">' . $img . '<div class="lnh-row__body"><a class="lnh-row__title" href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( get_the_title( $post ) ) . '</a>' . self::meta_html( $post, $a ) . '</div></li>';
	}

	public static function markup( array $posts, array $a, string $uid, string $var, int $page, int $max_pages ): string {
		$classes = array( 'lnh', 'lnh--' . $a['layout'], 'lnh-theme-' . $a['theme'] );
		if ( $a['class'] ) {
			$classes[] = implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $a['class'] ) ) );
		}
		if ( LNH_Settings::get( 'ds_shadow' ) ) {
			$classes[] = 'lnh--shadow';
		}
		$head = '';
		if ( $a['heading'] || $a['more_link'] ) {
			$head  = '<header class="lnh__head">';
			$head .= $a['heading'] ? '<h2 class="lnh__heading">' . esc_html( $a['heading'] ) . '</h2>' : '';
			$head .= $a['more_link'] ? '<a class="lnh__more" href="' . esc_url( $a['more_link'] ) . '">' . esc_html( $a['more_text'] ) . ' →</a>' : '';
			$head .= '</header>';
		}
		if ( ! $posts ) {
			return '<section class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( self::style_vars( $a ) ) . '">' . $head . '<p class="lnh__empty">' . esc_html( $a['empty_message'] ) . '</p></section>';
		}
		$body = '';
		switch ( $a['layout'] ) {
			case 'compact':
			case 'minimal':
				$thumb = 'compact' === $a['layout'];
				$body  = '<ul class="lnh__items">' . implode( '', array_map( function ( $p ) use ( $a, $thumb ) {
					return self::row( $p, $a, $thumb );
				}, $posts ) ) . '</ul>';
				break;
			case 'featured':
				$cards = array();
				foreach ( $posts as $i => $p ) {
					$cards[] = self::card( $p, $a, 0 === $i );
				}
				$body = '<div class="lnh__items">' . implode( '', $cards ) . '</div>';
				break;
			default:
				$body = '<div class="lnh__items">' . implode( '', array_map( function ( $p ) use ( $a ) {
					return self::card( $p, $a );
				}, $posts ) ) . '</div>';
		}
		$pager = '';
		if ( $a['pagination'] && $max_pages > 1 ) {
			$links = paginate_links( array(
				'base'      => esc_url_raw( add_query_arg( $var, '%#%' ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $max_pages,
				'type'      => 'list',
				'prev_text' => '←',
				'next_text' => '→',
			) );
			$pager = $links ? '<nav class="lnh__pager" aria-label="' . esc_attr__( 'Articles pagination', 'lehigh-news-hub' ) . '">' . $links . '</nav>' : '';
		}
		return '<section class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( self::style_vars( $a ) ) . '" data-lnh="' . esc_attr( $uid ) . '">' . $head . $body . $pager . '</section>';
	}

	/** Build the shortcode string for attributes that differ from the defaults (used by the builder). */
	public static function to_string( array $atts ): string {
		$schema = self::schema();
		$norm   = LNH_Util::normalize_atts( $atts, $schema );
		$parts  = array();
		foreach ( $norm as $k => $v ) {
			if ( 'preset' === $k && '' !== $v ) {
				array_unshift( $parts, 'preset="' . esc_attr( $v ) . '"' );
				continue;
			}
			if ( (string) $v !== (string) $schema[ $k ]['default'] ) {
				$parts[] = $k . '="' . str_replace( '"', '', (string) $v ) . '"';
			}
		}
		return '[' . self::TAG . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) . ']';
	}
}
