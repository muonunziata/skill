<?php
/** @var array $rows */
defined( 'ABSPATH' ) || exit;
$states = array(
	'publish' => array( 'good', __( 'Published', 'lehigh-news-hub' ) ),
	'draft'   => array( 'ok', __( 'Draft', 'lehigh-news-hub' ) ),
	'pending' => array( 'ok', __( 'Pending', 'lehigh-news-hub' ) ),
	'private' => array( 'ok', __( 'Private', 'lehigh-news-hub' ) ),
	'future'  => array( 'ok', __( 'Scheduled', 'lehigh-news-hub' ) ),
	'trash'   => array( 'bad', __( 'In the trash', 'lehigh-news-hub' ) ),
	'missing' => array( 'bad', __( 'Missing', 'lehigh-news-hub' ) ),
);
$missing = array_filter( $rows, function ( $r ) { return in_array( $r['state'], array( 'missing', 'trash' ), true ); } );
?>
<section class="lnh-card-box lnh-settings">
	<p><?php esc_html_e( 'These pages were created when the plugin was activated. They are yours: edit them freely, the plugin never overwrites them.', 'lehigh-news-hub' ); ?></p>
	<table class="lnh-table">
		<thead><tr><th><?php esc_html_e( 'Page', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Status', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Uses', 'lehigh-news-hub' ); ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $rows as $r ) :
			$st = $states[ $r['state'] ] ?? array( 'ok', $r['state'] );
			?>
			<tr>
				<td><strong><?php echo esc_html( $r['title'] ); ?></strong></td>
				<td><span class="lnh-chip lnh-chip--<?php echo esc_attr( $st[0] ); ?>"><?php echo esc_html( $st[1] ); ?></span></td>
				<td><?php echo $r['uses'] ? '<code>' . esc_html( $r['uses'] ) . '</code>' : '—'; ?></td>
				<td class="lnh-actions">
					<?php if ( $r['view'] ) : ?><a class="button" href="<?php echo esc_url( $r['view'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'lehigh-news-hub' ); ?></a><?php endif; ?>
					<?php if ( $r['edit'] ) : ?><a class="button" href="<?php echo esc_url( $r['edit'] ); ?>"><?php esc_html_e( 'Edit', 'lehigh-news-hub' ); ?></a><?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem">
		<input type="hidden" name="action" value="lnh_pages">
		<?php wp_nonce_field( 'lnh_pages' ); ?>
		<button class="button button-primary" <?php disabled( ! $missing ); ?>><?php esc_html_e( 'Create missing pages', 'lehigh-news-hub' ); ?></button>
		<span class="lnh-muted"><?php echo $missing ? esc_html__( 'Recreates pages that were deleted or sent to the trash.', 'lehigh-news-hub' ) : esc_html__( 'All pages are in place.', 'lehigh-news-hub' ); ?></span>
	</form>
</section>
