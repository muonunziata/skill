<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings: one option array, a declarative field schema that drives both rendering and sanitising.
 */
final class LNH_Settings {

	const OPTION = 'lnh_settings';

	public static function defaults(): array {
		return array(
			// General.
			'auto_publish'           => 0,
			'auto_publish_min_score' => 90,
			'notify'                 => 1,
			'notify_email'           => '',
			'post_author'            => 0,
			'default_category'       => 0,
			'per_page'               => 20,
			// Publishing.
			'show_attribution'       => 1,
			'attribution_text'       => 'Source: {source}',
			'attribution_position'   => 'after',
			'show_disclosure'        => 1,
			'public_contact_email'   => '',
			'disclosure_text'        => 'This article was researched, written and fact-checked with the help of AI agents (audit score {score}/100) and reviewed by our editors before publication.',
			// Shortcode defaults.
			'sc_count'               => 6,
			'sc_columns'             => 3,
			'sc_layout'              => 'grid',
			'sc_source'              => 'hub',
			'sc_image_ratio'         => '16:9',
			'sc_image_size'          => 'medium_large',
			'sc_excerpt_length'      => 24,
			'sc_date_format'         => 'relative',
			'sc_show_image'          => 1,
			'sc_show_category'       => 1,
			'sc_show_date'           => 1,
			'sc_show_excerpt'        => 1,
			'sc_show_source'         => 0,
			'sc_show_author'         => 0,
			'sc_show_readmore'       => 1,
			'sc_show_ai_badge'       => 1,
			'sc_readmore_text'       => 'Read more',
			'sc_cache'               => 10,
			// Design.
			'ds_accent'              => '#1b6a55',
			'ds_radius'              => 12,
			'ds_gap'                 => 20,
			'ds_shadow'              => 1,
			'ds_theme'               => 'auto',
			'ds_title_scale'         => 100,
			// Agents.
			'expected_interval'      => 60,
			'retention_days'         => 60,
			// Advanced.
			'delete_on_uninstall'    => 0,
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Tab definitions: slug => label + fields. Rendered generically in views/settings.php. */
	public static function schema(): array {
		$layouts = array(
			'grid'     => __( 'Grid of cards', 'lehigh-news-hub' ),
			'list'     => __( 'List with thumbnails', 'lehigh-news-hub' ),
			'featured' => __( 'Featured first + grid', 'lehigh-news-hub' ),
			'compact'  => __( 'Compact list', 'lehigh-news-hub' ),
			'minimal'  => __( 'Headlines only', 'lehigh-news-hub' ),
		);
		return array(
			'general'    => array(
				'label'  => __( 'General', 'lehigh-news-hub' ),
				'fields' => array(
					'auto_publish'           => array( 'type' => 'checkbox', 'label' => __( 'Auto-publish approved drafts', 'lehigh-news-hub' ), 'desc' => __( 'Publish agent drafts immediately when the Auditor approves them with a high score. Off by default: you decide what goes live.', 'lehigh-news-hub' ) ),
					'auto_publish_min_score' => array( 'type' => 'number', 'label' => __( 'Minimum score for auto-publish', 'lehigh-news-hub' ), 'min' => 50, 'max' => 100, 'desc' => __( 'Only used when auto-publish is on.', 'lehigh-news-hub' ) ),
					'notify'                 => array( 'type' => 'checkbox', 'label' => __( 'Email me when a new draft arrives', 'lehigh-news-hub' ) ),
					'notify_email'           => array( 'type' => 'email', 'label' => __( 'Notification address', 'lehigh-news-hub' ), 'desc' => __( 'Leave empty to use the site admin email.', 'lehigh-news-hub' ) ),
					'post_author'            => array( 'type' => 'user', 'label' => __( 'Author for published articles', 'lehigh-news-hub' ), 'desc' => __( 'Leave on “keep as is” to keep the account the agents post with.', 'lehigh-news-hub' ) ),
					'default_category'       => array( 'type' => 'category', 'label' => __( 'Default category', 'lehigh-news-hub' ), 'desc' => __( 'Assigned to agent articles that have no category yet.', 'lehigh-news-hub' ) ),
					'per_page'               => array( 'type' => 'number', 'label' => __( 'Rows per page in the queue', 'lehigh-news-hub' ), 'min' => 5, 'max' => 100 ),
				),
			),
			'publishing' => array(
				'label'  => __( 'Transparency', 'lehigh-news-hub' ),
				'fields' => array(
					'show_attribution'     => array( 'type' => 'checkbox', 'label' => __( 'Show the source under agent articles', 'lehigh-news-hub' ) ),
					'attribution_text'     => array( 'type' => 'text', 'label' => __( 'Source line', 'lehigh-news-hub' ), 'desc' => __( 'Variables: {source} (linked publisher), {date}.', 'lehigh-news-hub' ) ),
					'attribution_position' => array( 'type' => 'select', 'label' => __( 'Source line position', 'lehigh-news-hub' ), 'options' => array( 'after' => __( 'After the article', 'lehigh-news-hub' ), 'before' => __( 'Before the article', 'lehigh-news-hub' ) ) ),
					'show_disclosure'      => array( 'type' => 'checkbox', 'label' => __( 'Show an AI disclosure on agent articles', 'lehigh-news-hub' ), 'desc' => __( 'Recommended. AI-generated featured images are always labelled in their caption.', 'lehigh-news-hub' ) ),
					'public_contact_email' => array( 'type' => 'email', 'label' => __( 'Public contact email for corrections', 'lehigh-news-hub' ), 'desc' => __( 'Shown on the “Corrections & contact” page (spam-protected). Leave empty to show no address.', 'lehigh-news-hub' ) ),
					'disclosure_text'      => array( 'type' => 'textarea', 'label' => __( 'Disclosure text', 'lehigh-news-hub' ), 'desc' => __( 'Variables: {score} (audit score), {source}.', 'lehigh-news-hub' ) ),
				),
			),
			'pages'      => array(
				'label'  => __( 'Pages', 'lehigh-news-hub' ),
				'custom' => true,
				'fields' => array(),
			),
			'shortcode'  => array(
				'label'  => __( 'Shortcode defaults', 'lehigh-news-hub' ),
				'fields' => array(
					'sc_layout'         => array( 'type' => 'select', 'label' => __( 'Layout', 'lehigh-news-hub' ), 'options' => $layouts ),
					'sc_count'          => array( 'type' => 'number', 'label' => __( 'Number of articles', 'lehigh-news-hub' ), 'min' => 1, 'max' => 50 ),
					'sc_columns'        => array( 'type' => 'number', 'label' => __( 'Columns (grid)', 'lehigh-news-hub' ), 'min' => 1, 'max' => 6 ),
					'sc_source'         => array( 'type' => 'select', 'label' => __( 'Which articles', 'lehigh-news-hub' ), 'options' => array( 'hub' => __( 'Only articles created by the agents', 'lehigh-news-hub' ), 'all' => __( 'All posts', 'lehigh-news-hub' ) ) ),
					'sc_image_ratio'    => array( 'type' => 'select', 'label' => __( 'Image shape', 'lehigh-news-hub' ), 'options' => array( '16:9' => '16:9', '3:2' => '3:2', '4:3' => '4:3', '1:1' => '1:1', 'auto' => __( 'Original', 'lehigh-news-hub' ) ) ),
					'sc_excerpt_length' => array( 'type' => 'number', 'label' => __( 'Excerpt length (words)', 'lehigh-news-hub' ), 'min' => 5, 'max' => 120 ),
					'sc_date_format'    => array( 'type' => 'select', 'label' => __( 'Date style', 'lehigh-news-hub' ), 'options' => array( 'relative' => __( '3 hours ago', 'lehigh-news-hub' ), 'absolute' => __( 'Site date format', 'lehigh-news-hub' ) ) ),
					'sc_readmore_text'  => array( 'type' => 'text', 'label' => __( '“Read more” text', 'lehigh-news-hub' ) ),
					'sc_show_image'     => array( 'type' => 'checkbox', 'label' => __( 'Show image', 'lehigh-news-hub' ) ),
					'sc_show_category'  => array( 'type' => 'checkbox', 'label' => __( 'Show category', 'lehigh-news-hub' ) ),
					'sc_show_date'      => array( 'type' => 'checkbox', 'label' => __( 'Show date', 'lehigh-news-hub' ) ),
					'sc_show_excerpt'   => array( 'type' => 'checkbox', 'label' => __( 'Show excerpt', 'lehigh-news-hub' ) ),
					'sc_show_source'    => array( 'type' => 'checkbox', 'label' => __( 'Show source name', 'lehigh-news-hub' ) ),
					'sc_show_author'    => array( 'type' => 'checkbox', 'label' => __( 'Show author', 'lehigh-news-hub' ) ),
					'sc_show_readmore'  => array( 'type' => 'checkbox', 'label' => __( 'Show “read more” link', 'lehigh-news-hub' ) ),
					'sc_show_ai_badge'  => array( 'type' => 'checkbox', 'label' => __( 'Show “AI image” badge on AI-generated images', 'lehigh-news-hub' ) ),
					'sc_cache'          => array( 'type' => 'number', 'label' => __( 'Cache (minutes)', 'lehigh-news-hub' ), 'min' => 0, 'max' => 1440, 'desc' => __( '0 disables caching. The cache is cleared automatically when posts change.', 'lehigh-news-hub' ) ),
				),
			),
			'design'     => array(
				'label'  => __( 'Design', 'lehigh-news-hub' ),
				'fields' => array(
					'ds_accent'      => array( 'type' => 'color', 'label' => __( 'Accent colour', 'lehigh-news-hub' ) ),
					'ds_radius'      => array( 'type' => 'number', 'label' => __( 'Corner radius (px)', 'lehigh-news-hub' ), 'min' => 0, 'max' => 40 ),
					'ds_gap'         => array( 'type' => 'number', 'label' => __( 'Gap between cards (px)', 'lehigh-news-hub' ), 'min' => 0, 'max' => 60 ),
					'ds_title_scale' => array( 'type' => 'number', 'label' => __( 'Title size (%)', 'lehigh-news-hub' ), 'min' => 70, 'max' => 160 ),
					'ds_shadow'      => array( 'type' => 'checkbox', 'label' => __( 'Card shadow', 'lehigh-news-hub' ) ),
					'ds_theme'       => array( 'type' => 'select', 'label' => __( 'Colour scheme', 'lehigh-news-hub' ), 'options' => array( 'auto' => __( 'Match the site theme', 'lehigh-news-hub' ), 'light' => __( 'Always light', 'lehigh-news-hub' ), 'dark' => __( 'Always dark', 'lehigh-news-hub' ) ) ),
				),
			),
			'agents'     => array(
				'label'  => __( 'Agents', 'lehigh-news-hub' ),
				'fields' => array(
					'expected_interval' => array( 'type' => 'number', 'label' => __( 'Run every (minutes)', 'lehigh-news-hub' ), 'min' => 5, 'max' => 1440, 'desc' => __( 'How often the connected agents start a new cycle while they are running. The dashboard warns you when they have not reported for twice this time.', 'lehigh-news-hub' ) ),
					'retention_days'    => array( 'type' => 'number', 'label' => __( 'Keep activity logs (days)', 'lehigh-news-hub' ), 'min' => 7, 'max' => 365 ),
				),
			),
			'advanced'   => array(
				'label'  => __( 'Advanced', 'lehigh-news-hub' ),
				'fields' => array(
					'delete_on_uninstall' => array( 'type' => 'checkbox', 'label' => __( 'Delete all plugin data when uninstalling', 'lehigh-news-hub' ), 'desc' => __( 'Removes settings, presets and activity logs. Articles and their audit metadata are never deleted.', 'lehigh-news-hub' ) ),
				),
			),
		);
	}

	/** Sanitise the submitted values of ONE tab and merge them into the stored option. */
	public static function save_tab( string $tab, array $post ): array {
		$schema = self::schema();
		$stored = self::all();
		if ( ! isset( $schema[ $tab ] ) || ! empty( $schema[ $tab ]['custom'] ) ) {
			return $stored;
		}
		foreach ( $schema[ $tab ]['fields'] as $key => $field ) {
			$raw = isset( $post[ $key ] ) ? wp_unslash( $post[ $key ] ) : null;
			switch ( $field['type'] ) {
				case 'checkbox':
					$stored[ $key ] = null !== $raw && LNH_Util::to_bool( $raw ) ? 1 : 0;
					break;
				case 'number':
					$stored[ $key ] = LNH_Util::clamp( $raw, $field['min'], $field['max'], self::defaults()[ $key ] );
					break;
				case 'select':
					$stored[ $key ] = array_key_exists( (string) $raw, $field['options'] ) ? (string) $raw : self::defaults()[ $key ];
					break;
				case 'color':
					$stored[ $key ] = LNH_Util::hex_color( $raw, self::defaults()[ $key ] );
					break;
				case 'email':
					$stored[ $key ] = sanitize_email( (string) $raw );
					break;
				case 'user':
				case 'category':
					$stored[ $key ] = absint( $raw );
					break;
				case 'textarea':
					$stored[ $key ] = sanitize_textarea_field( (string) $raw );
					break;
				default:
					$stored[ $key ] = sanitize_text_field( (string) $raw );
			}
		}
		update_option( self::OPTION, $stored, false );
		LNH_Shortcode::bump_cache();
		return $stored;
	}

	/** Shortcode presets: name => array of attributes. */
	public static function presets(): array {
		$p = get_option( 'lnh_presets', array() );
		return is_array( $p ) ? $p : array();
	}

	public static function save_preset( string $name, array $atts ): bool {
		$name = sanitize_title( $name );
		if ( '' === $name ) {
			return false;
		}
		$all          = self::presets();
		$all[ $name ] = $atts;
		update_option( 'lnh_presets', $all, false );
		LNH_Shortcode::bump_cache();
		return true;
	}

	public static function delete_preset( string $name ): void {
		$all = self::presets();
		unset( $all[ sanitize_title( $name ) ] );
		update_option( 'lnh_presets', $all, false );
		LNH_Shortcode::bump_cache();
	}
}
