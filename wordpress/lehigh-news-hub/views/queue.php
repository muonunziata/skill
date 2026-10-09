<?php
/** @var string $tab @var array $tabs @var array $counts @var WP_Query $query @var array $opts */
defined( 'ABSPATH' ) || exit;
$base = admin_url( 'admin.php?page=lnh-queue' );
$back = add_query_arg( array_filter( array( 'tab' => $tab, 's' => $opts['s'], 'min_score' => $opts['min_score'], 'orderby' => $opts['orderby'], 'paged' => $opts['paged'] > 1 ? $opts['paged'] : null ) ), $base );
?>
<ul class="lnh-tabs">
	<?php foreach ( $tabs as $slug => $label ) : ?>
		<li><a class="<?php echo $slug === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base ) ); ?>"><?php echo esc_html( $label ); ?> <span class="lnh-count"><?php echo (int) $counts[ $slug ]; ?></span></a></li>
	<?php endforeach; ?>
</ul>

<form method="get" class="lnh-filters">
	<input type="hidden" name="page" value="lnh-queue"><input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
	<input type="search" name="s" value="<?php echo esc_attr( $opts['s'] ); ?>" placeholder="<?php esc_attr_e( 'Search articles…', 'lehigh-news-hub' ); ?>">
	<label><?php esc_html_e( 'Min. score', 'lehigh-news-hub' ); ?> <input type="number" name="min_score" min="0" max="100" value="<?php echo $opts['min_score'] ? (int) $opts['min_score'] : ''; ?>" class="small-text"></label>
	<select name="orderby">
		<option value=""><?php esc_html_e( 'Newest first', 'lehigh-news-hub' ); ?></option>
		<option value="score" <?php selected( $opts['orderby'], 'score' ); ?>><?php esc_html_e( 'Highest score first', 'lehigh-news-hub' ); ?></option>
	</select>
	<button class="button"><?php esc_html_e( 'Filter', 'lehigh-news-hub' ); ?></button>
</form>

<?php if ( ! $query->have_posts() ) : ?>
	<div class="lnh-card-box"><p class="lnh-empty"><?php esc_html_e( 'No articles here.', 'lehigh-news-hub' ); ?></p></div>
<?php else : ?>
	<?php LNH_Admin::nonce_form_open( 'bulk', $back ); ?>
		<div class="lnh-bulk">
			<select name="bulk_action">
				<option value=""><?php esc_html_e( 'Bulk actions', 'lehigh-news-hub' ); ?></option>
				<?php if ( in_array( $tab, array( 'review', 'flagged', 'rejected' ), true ) ) : ?><option value="publish"><?php esc_html_e( 'Publish', 'lehigh-news-hub' ); ?></option><?php endif; ?>
				<?php if ( 'rejected' !== $tab ) : ?><option value="reject" data-confirm="reject"><?php esc_html_e( 'Reject', 'lehigh-news-hub' ); ?></option><?php endif; ?>
				<?php if ( 'rejected' === $tab ) : ?><option value="restore"><?php esc_html_e( 'Move back to review', 'lehigh-news-hub' ); ?></option><option value="delete" data-confirm="delete"><?php esc_html_e( 'Delete permanently', 'lehigh-news-hub' ); ?></option><?php endif; ?>
			</select>
			<button class="button" data-lnh-bulk><?php esc_html_e( 'Apply', 'lehigh-news-hub' ); ?></button>
			<span class="lnh-muted"><?php echo esc_html( sprintf( /* translators: %d: number of articles */ _n( '%d article', '%d articles', $query->found_posts, 'lehigh-news-hub' ), $query->found_posts ) ); ?></span>
		</div>
		<table class="lnh-table lnh-table--queue">
			<thead><tr>
				<td class="check"><input type="checkbox" data-lnh-all></td>
				<th><?php esc_html_e( 'Article', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Audit', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Source', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Received', 'lehigh-news-hub' ); ?></th><th></th>
			</tr></thead>
			<tbody>
			<?php
			$quick = '';
			foreach ( $query->posts as $p ) :
				$id    = $p->ID;
				$score = (int) get_post_meta( $id, 'lnh_audit_score', true );
				$st    = (string) get_post_meta( $id, 'lnh_audit_status', true );
				$todo  = LNH_Util::json_list( get_post_meta( $id, 'lnh_media_todo', true ) );
				$rev   = (int) get_post_meta( $id, 'lnh_revisions', true );
				$ai    = (bool) get_post_meta( $id, 'lnh_featured_image_ai', true );
				$url   = (string) get_post_meta( $id, 'lnh_source_url', true );
				$review_url = admin_url( 'admin.php?page=lnh-review&post=' . $id );
				?>
				<tr>
					<th class="check"><input type="checkbox" name="post[]" value="<?php echo (int) $id; ?>"></th>
					<td class="lnh-article">
						<span class="lnh-thumb lnh-thumb--lg"><?php echo has_post_thumbnail( $p ) ? get_the_post_thumbnail( $p, array( 96, 64 ) ) : '<i></i>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<div>
							<a class="lnh-list__title" href="<?php echo esc_url( $review_url ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a>
							<p class="lnh-excerpt"><?php echo esc_html( LNH_Util::clip( wp_strip_all_tags( $p->post_excerpt ), 140 ) ); ?></p>
							<div class="lnh-badges">
								<?php if ( $ai ) : ?><span class="lnh-chip lnh-chip--info"><?php esc_html_e( 'AI image', 'lehigh-news-hub' ); ?></span><?php endif; ?>
								<?php if ( $rev ) : ?><span class="lnh-chip lnh-chip--info"><?php echo esc_html( sprintf( /* translators: %d: number of revisions */ _n( '%d revision', '%d revisions', $rev, 'lehigh-news-hub' ), $rev ) ); ?></span><?php endif; ?>
								<?php if ( $todo ) : ?><span class="lnh-chip lnh-chip--ok"><?php echo esc_html( sprintf( /* translators: %d: number of pending media items */ _n( '%d media item pending', '%d media items pending', count( $todo ), 'lehigh-news-hub' ), count( $todo ) ) ); ?></span><?php endif; ?>
							</div>
						</div>
					</td>
					<td class="lnh-audit"><?php echo LNH_Admin::ring( $score ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo LNH_Admin::chip( $st ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( LNH_Util::host( $url ) ) . '</a>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo esc_html( LNH_Util::ago( LNH_Util::post_timestamp( $p ) ) ); ?></td>
					<td class="lnh-actions">
						<a class="button" href="<?php echo esc_url( $review_url ); ?>"><?php esc_html_e( 'Review', 'lehigh-news-hub' ); ?></a>
						<?php if ( in_array( $tab, array( 'review', 'flagged' ), true ) ) : ?>
							<button type="submit" form="q-<?php echo (int) $id; ?>-publish" class="button button-primary"><?php esc_html_e( 'Publish', 'lehigh-news-hub' ); ?></button>
							<button type="submit" form="q-<?php echo (int) $id; ?>-reject" class="button" data-confirm="reject"><?php esc_html_e( 'Reject', 'lehigh-news-hub' ); ?></button>
						<?php elseif ( 'rejected' === $tab ) : ?>
							<button type="submit" form="q-<?php echo (int) $id; ?>-restore" class="button"><?php esc_html_e( 'Restore', 'lehigh-news-hub' ); ?></button>
						<?php elseif ( 'published' === $tab ) : ?>
							<a class="button" href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'lehigh-news-hub' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<?php
				foreach ( array( 'publish', 'reject', 'restore' ) as $do ) {
					$quick .= '<form id="q-' . (int) $id . '-' . $do . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>'
						. '<input type="hidden" name="action" value="lnh_action"><input type="hidden" name="lnh_do" value="' . esc_attr( $do ) . '"><input type="hidden" name="post[]" value="' . (int) $id . '">'
						. '<input type="hidden" name="_back" value="' . esc_url( $back ) . '">' . wp_nonce_field( 'lnh_action', '_wpnonce', true, false ) . '</form>';
				}
			endforeach;
			?>
			</tbody>
		</table>
	</form>
	<?php echo $quick; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?>
	<?php
	$links = paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $back ), 'format' => '', 'current' => $opts['paged'], 'total' => max( 1, (int) $query->max_num_pages ), 'type' => 'list' ) );
	if ( $links ) {
		echo '<nav class="lnh-pager">' . $links . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	?>
<?php endif; ?>
