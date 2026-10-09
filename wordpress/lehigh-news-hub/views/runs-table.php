<?php
/** @var array $runs */
defined( 'ABSPATH' ) || exit;
?>
<table class="lnh-table lnh-table--runs">
	<thead><tr>
		<th><?php esc_html_e( 'Run', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Started', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Duration', 'lehigh-news-hub' ); ?></th>
		<th><?php esc_html_e( 'Found', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Approved', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Flagged', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Failed', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Status', 'lehigh-news-hub' ); ?></th>
	</tr></thead>
	<tbody>
	<?php foreach ( $runs as $r ) :
		$dur = $r['finished_at'] && $r['started_at'] ? max( 0, $r['finished_at'] - $r['started_at'] ) : 0;
		?>
		<tr>
			<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-activity&run=' . rawurlencode( $r['run_id'] ) ) ); ?>"><code><?php echo esc_html( $r['run_id'] ); ?></code></a><?php if ( ! empty( $r['dry_run'] ) ) : ?> <span class="lnh-chip lnh-chip--ok"><?php esc_html_e( 'test', 'lehigh-news-hub' ); ?></span><?php endif; ?></td>
			<td><?php echo esc_html( LNH_Util::ago( (int) $r['started_at'] ) ); ?></td>
			<td><?php echo $dur ? esc_html( sprintf( '%dm %02ds', intdiv( $dur, 60 ), $dur % 60 ) ) : '—'; ?></td>
			<td><?php echo (int) ( $r['counts']['found'] ?? 0 ); ?></td>
			<td><?php echo (int) ( $r['counts']['approved'] ?? 0 ); ?></td>
			<td><?php echo (int) ( $r['counts']['flagged'] ?? 0 ); ?></td>
			<td><?php echo (int) ( $r['counts']['failed'] ?? 0 ); ?></td>
			<td><span class="lnh-chip lnh-chip--<?php echo 'ok' === $r['status'] ? 'good' : 'bad'; ?>"><?php echo 'ok' === $r['status'] ? esc_html__( 'OK', 'lehigh-news-hub' ) : esc_html__( 'Error', 'lehigh-news-hub' ); ?></span></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
