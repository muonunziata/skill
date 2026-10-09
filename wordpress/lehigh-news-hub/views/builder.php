<?php
/** @var array $schema @var array $presets @var array $categories @var string $initial */
defined( 'ABSPATH' ) || exit;

$bool = function ( $key, $label ) use ( $schema ) {
	$v = (int) $schema[ $key ]['default'];
	echo '<label class="lnh-field"><span>' . esc_html( $label ) . '</span><select name="a_' . esc_attr( $key ) . '" data-default="' . $v . '"><option value="1"' . selected( $v, 1, false ) . '>' . esc_html__( 'Yes', 'lehigh-news-hub' ) . '</option><option value="0"' . selected( $v, 0, false ) . '>' . esc_html__( 'No', 'lehigh-news-hub' ) . '</option></select></label>';
};
$select = function ( $key, $label, $options ) use ( $schema ) {
	$d = (string) $schema[ $key ]['default'];
	echo '<label class="lnh-field"><span>' . esc_html( $label ) . '</span><select name="a_' . esc_attr( $key ) . '" data-default="' . esc_attr( $d ) . '">';
	foreach ( $options as $val => $text ) {
		echo '<option value="' . esc_attr( (string) $val ) . '"' . selected( $d, (string) $val, false ) . '>' . esc_html( $text ) . '</option>';
	}
	echo '</select></label>';
};
$number = function ( $key, $label ) use ( $schema ) {
	$s = $schema[ $key ];
	echo '<label class="lnh-field"><span>' . esc_html( $label ) . '</span><input type="number" name="a_' . esc_attr( $key ) . '" min="' . (int) $s['min'] . '" max="' . (int) $s['max'] . '" value="' . (int) $s['default'] . '" data-default="' . (int) $s['default'] . '"></label>';
};
$text = function ( $key, $label, $ph = '' ) use ( $schema ) {
	echo '<label class="lnh-field"><span>' . esc_html( $label ) . '</span><input type="text" name="a_' . esc_attr( $key ) . '" value="' . esc_attr( (string) $schema[ $key ]['default'] ) . '" data-default="' . esc_attr( (string) $schema[ $key ]['default'] ) . '" placeholder="' . esc_attr( $ph ) . '"></label>';
};
$yn_opts = array();
?>
<div class="lnh-builder">
	<form id="lnh-builder-form" class="lnh-card-box lnh-builder__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lnh_preset"><input type="hidden" name="op" value="save">
		<?php wp_nonce_field( 'lnh_preset' ); ?>

		<fieldset><legend><?php esc_html_e( 'Layout', 'lehigh-news-hub' ); ?></legend>
			<?php
			$select( 'layout', __( 'Layout', 'lehigh-news-hub' ), array( 'grid' => __( 'Grid of cards', 'lehigh-news-hub' ), 'list' => __( 'List with thumbnails', 'lehigh-news-hub' ), 'featured' => __( 'Featured first + grid', 'lehigh-news-hub' ), 'compact' => __( 'Compact list', 'lehigh-news-hub' ), 'minimal' => __( 'Headlines only', 'lehigh-news-hub' ) ) );
			$number( 'count', __( 'Number of articles', 'lehigh-news-hub' ) );
			$number( 'columns', __( 'Columns', 'lehigh-news-hub' ) );
			$select( 'image_ratio', __( 'Image shape', 'lehigh-news-hub' ), array( '16:9' => '16:9', '3:2' => '3:2', '4:3' => '4:3', '1:1' => '1:1', 'auto' => __( 'Original', 'lehigh-news-hub' ) ) );
			$select( 'theme', __( 'Colour scheme', 'lehigh-news-hub' ), array( 'auto' => __( 'Match the site theme', 'lehigh-news-hub' ), 'light' => __( 'Light', 'lehigh-news-hub' ), 'dark' => __( 'Dark', 'lehigh-news-hub' ) ) );
			?>
			<label class="lnh-field"><span><?php esc_html_e( 'Accent colour', 'lehigh-news-hub' ); ?></span><input type="color" name="a_accent" value="<?php echo esc_attr( $schema['accent']['default'] ); ?>" data-default="<?php echo esc_attr( $schema['accent']['default'] ); ?>"></label>
		</fieldset>

		<fieldset><legend><?php esc_html_e( 'Which articles', 'lehigh-news-hub' ); ?></legend>
			<?php
			$select( 'source', __( 'Source', 'lehigh-news-hub' ), array( 'hub' => __( 'Created by the agents', 'lehigh-news-hub' ), 'all' => __( 'All posts', 'lehigh-news-hub' ) ) );
			$select( 'orderby', __( 'Order by', 'lehigh-news-hub' ), array( 'date' => __( 'Date', 'lehigh-news-hub' ), 'modified' => __( 'Last modified', 'lehigh-news-hub' ), 'score' => __( 'Audit score', 'lehigh-news-hub' ), 'title' => __( 'Title', 'lehigh-news-hub' ), 'comment_count' => __( 'Comments', 'lehigh-news-hub' ), 'rand' => __( 'Random', 'lehigh-news-hub' ) ) );
			$select( 'order', __( 'Direction', 'lehigh-news-hub' ), array( 'DESC' => __( 'Newest / highest first', 'lehigh-news-hub' ), 'ASC' => __( 'Oldest / lowest first', 'lehigh-news-hub' ) ) );
			?>
			<label class="lnh-field"><span><?php esc_html_e( 'Categories', 'lehigh-news-hub' ); ?></span>
				<select name="a_category[]" multiple size="4" data-default="">
					<?php foreach ( $categories as $c ) : ?><option value="<?php echo esc_attr( $c->slug ); ?>"><?php echo esc_html( $c->name ); ?></option><?php endforeach; ?>
				</select></label>
			<?php
			$text( 'exclude_category', __( 'Exclude categories (slugs)', 'lehigh-news-hub' ), 'weather,events' );
			$text( 'tag', __( 'Tags (slugs)', 'lehigh-news-hub' ), 'lehigh-acres' );
			$text( 'since', __( 'Only newer than', 'lehigh-news-hub' ), '7 days' );
			$number( 'min_score', __( 'Minimum audit score', 'lehigh-news-hub' ) );
			$number( 'offset', __( 'Skip the first N', 'lehigh-news-hub' ) );
			$text( 'ids', __( 'Only these post IDs', 'lehigh-news-hub' ), '12,34' );
			$text( 'exclude_ids', __( 'Exclude post IDs', 'lehigh-news-hub' ), '56' );
			$text( 'lang', __( 'Language code', 'lehigh-news-hub' ), 'es' );
			?>
		</fieldset>

		<fieldset><legend><?php esc_html_e( 'What to show', 'lehigh-news-hub' ); ?></legend>
			<?php
			$bool( 'show_image', __( 'Image', 'lehigh-news-hub' ) );
			$bool( 'show_category', __( 'Category', 'lehigh-news-hub' ) );
			$bool( 'show_date', __( 'Date', 'lehigh-news-hub' ) );
			$bool( 'show_excerpt', __( 'Excerpt', 'lehigh-news-hub' ) );
			$bool( 'show_source', __( 'Source name', 'lehigh-news-hub' ) );
			$bool( 'show_author', __( 'Author', 'lehigh-news-hub' ) );
			$bool( 'show_readmore', __( '“Read more” link', 'lehigh-news-hub' ) );
			$bool( 'show_ai_badge', __( '“AI image” badge', 'lehigh-news-hub' ) );
			$number( 'excerpt_length', __( 'Excerpt words', 'lehigh-news-hub' ) );
			$select( 'date_format', __( 'Date style', 'lehigh-news-hub' ), array( 'relative' => __( '3 hours ago', 'lehigh-news-hub' ), 'absolute' => __( 'Site date format', 'lehigh-news-hub' ) ) );
			$text( 'readmore_text', __( '“Read more” text', 'lehigh-news-hub' ) );
			$select( 'title_tag', __( 'Title tag', 'lehigh-news-hub' ), array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'p' => 'P' ) );
			$select( 'link_target', __( 'Open links', 'lehigh-news-hub' ), array( '_self' => __( 'In the same tab', 'lehigh-news-hub' ), '_blank' => __( 'In a new tab', 'lehigh-news-hub' ) ) );
			?>
		</fieldset>

		<fieldset><legend><?php esc_html_e( 'Extras', 'lehigh-news-hub' ); ?></legend>
			<?php
			$text( 'heading', __( 'Section heading', 'lehigh-news-hub' ), __( 'Latest news', 'lehigh-news-hub' ) );
			$text( 'more_link', __( '“View all” link (URL)', 'lehigh-news-hub' ), 'https://' );
			$text( 'more_text', __( '“View all” text', 'lehigh-news-hub' ) );
			$bool( 'pagination', __( 'Pagination', 'lehigh-news-hub' ) );
			$number( 'cache', __( 'Cache (minutes)', 'lehigh-news-hub' ) );
			$text( 'class', __( 'Extra CSS class', 'lehigh-news-hub' ) );
			$text( 'empty_message', __( 'Message when empty', 'lehigh-news-hub' ) );
			?>
		</fieldset>

		<div class="lnh-preset-save">
			<input type="text" name="name" placeholder="<?php esc_attr_e( 'Preset name, e.g. homepage', 'lehigh-news-hub' ); ?>" pattern="[A-Za-z0-9 _\-]+">
			<button class="button"><?php esc_html_e( 'Save as preset', 'lehigh-news-hub' ); ?></button>
		</div>
	</form>

	<div class="lnh-builder__out">
		<section class="lnh-card-box lnh-sticky">
			<header><h2><?php esc_html_e( 'Shortcode', 'lehigh-news-hub' ); ?></h2></header>
			<div class="lnh-copy"><pre data-lnh-shortcode>[lehigh_news]</pre><button type="button" class="button button-primary" data-lnh-copy-target="[data-lnh-shortcode]"><?php esc_html_e( 'Copy', 'lehigh-news-hub' ); ?></button></div>
			<p class="lnh-small lnh-muted"><?php esc_html_e( 'Only options that differ from your defaults are included. Change the defaults in Settings → Shortcode defaults.', 'lehigh-news-hub' ); ?></p>
		</section>

		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Live preview', 'lehigh-news-hub' ); ?></h2><span class="lnh-muted" data-lnh-preview-state></span></header>
			<div class="lnh-preview-frame" data-lnh-preview><?php echo $initial; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered by LNH_Shortcode, escaped there. ?></div>
		</section>

		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Saved presets', 'lehigh-news-hub' ); ?></h2></header>
			<?php if ( ! $presets ) : ?>
				<p class="lnh-empty"><?php esc_html_e( 'No presets yet. Save one to reuse it with [lehigh_news preset="name"].', 'lehigh-news-hub' ); ?></p>
			<?php else : ?>
				<ul class="lnh-presets">
					<?php foreach ( $presets as $name => $atts ) : ?>
						<li>
							<code>[lehigh_news preset="<?php echo esc_html( $name ); ?>"]</code>
							<span>
								<button type="button" class="button" data-lnh-copy-text="<?php echo esc_attr( '[lehigh_news preset="' . $name . '"]' ); ?>"><?php esc_html_e( 'Copy', 'lehigh-news-hub' ); ?></button>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="lnh_preset"><input type="hidden" name="op" value="delete"><input type="hidden" name="name" value="<?php echo esc_attr( $name ); ?>">
									<?php wp_nonce_field( 'lnh_preset' ); ?>
									<button class="button" data-confirm="delete"><?php esc_html_e( 'Delete', 'lehigh-news-hub' ); ?></button>
								</form>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	</div>
</div>
