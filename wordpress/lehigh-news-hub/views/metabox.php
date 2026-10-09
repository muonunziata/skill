<?php
/** @var WP_Post $post @var array $audit */
defined( 'ABSPATH' ) || exit;
?>
<div class="lnh-metabox">
	<div class="lnh-audit-top"><?php echo LNH_Admin::ring( $audit['score'], 56 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div><?php echo LNH_Admin::chip( $audit['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php if ( get_post_meta( $post->ID, 'lnh_featured_image_ai', true ) ) : ?><br><span class="lnh-chip lnh-chip--info"><?php esc_html_e( 'AI image', 'lehigh-news-hub' ); ?></span><?php endif; ?></div></div>
	<?php if ( $audit['notes'] ) : ?>
		<ul class="lnh-notes lnh-small">
			<?php foreach ( array_slice( $audit['notes'], 0, 4 ) as $n ) : ?><li><?php echo esc_html( trim( preg_replace( '/^\[auto\]\s*/', '', (string) $n ) ) ); ?></li><?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-review&post=' . $post->ID ) ); ?>"><?php esc_html_e( 'Open full review', 'lehigh-news-hub' ); ?></a></p>
</div>
