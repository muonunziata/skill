<?php
/** @var array $run */
defined( 'ABSPATH' ) || exit;
if ( ! $run ) {
	echo '<p>' . esc_html__( 'Run not found.', 'lehigh-news-hub' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=lnh-activity' ) ) . '">' . esc_html__( 'Back to activity', 'lehigh-news-hub' ) . '</a></p>';
	return;
}
$agents = LNH_Admin::agents();
$dur    = $run['finished_at'] && $run['started_at'] ? max( 0, $run['finished_at'] - $run['started_at'] ) : 0;
$by_item = array();
foreach ( $run['events'] as $ev ) {
	$by_item[ $ev['item'] ][] = $ev;
}
$titles = array();
foreach ( $run['items'] as $it ) {
	$titles[ $it['url'] ] = $it;
}
?>
<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-activity' ) ); ?>">← <?php esc_html_e( 'Back to activity', 'lehigh-news-hub' ); ?></a></p>
<section class="lnh-card-box">
	<header><h2><code><?php echo esc_html( $run['run_id'] ); ?></code></h2>
		<span class="lnh-chip lnh-chip--<?php echo 'ok' === $run['status'] ? 'good' : 'bad'; ?>"><?php echo 'ok' === $run['status'] ? esc_html__( 'OK', 'lehigh-news-hub' ) : esc_html__( 'Error', 'lehigh-news-hub' ); ?></span></header>
	<dl class="lnh-facts">
		<div><dt><?php esc_html_e( 'Started', 'lehigh-news-hub' ); ?></dt><dd><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['started_at'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Duration', 'lehigh-news-hub' ); ?></dt><dd><?php echo $dur ? esc_html( sprintf( '%dm %02ds', intdiv( $dur, 60 ), $dur % 60 ) ) : '—'; ?></dd></div>
		<div><dt><?php esc_html_e( 'Topic', 'lehigh-news-hub' ); ?></dt><dd><?php echo esc_html( $run['topic'] ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Mode', 'lehigh-news-hub' ); ?></dt><dd><?php echo $run['dry_run'] ? esc_html__( 'Test (nothing sent)', 'lehigh-news-hub' ) : esc_html__( 'Live', 'lehigh-news-hub' ); ?></dd></div>
	</dl>
	<p><?php foreach ( $run['models'] as $role => $model ) : if ( '' === $model ) { continue; } ?><span class="lnh-chip lnh-chip--ok"><?php echo esc_html( ( $agents[ $role ]['label'] ?? ucfirst( $role ) ) . ': ' . $model ); ?></span> <?php endforeach; ?></p>
	<?php if ( $run['usage'] ) : ?>
		<table class="lnh-table"><thead><tr><th><?php esc_html_e( 'Model', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Calls', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Input tokens', 'lehigh-news-hub' ); ?></th><th><?php esc_html_e( 'Output tokens', 'lehigh-news-hub' ); ?></th></tr></thead><tbody>
		<?php foreach ( $run['usage'] as $model => $u ) : ?><tr><td><code><?php echo esc_html( $model ); ?></code></td><td><?php echo (int) $u['calls']; ?></td><td><?php echo esc_html( number_format_i18n( $u['input'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $u['output'] ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
	<?php endif; ?>
</section>

<?php foreach ( $by_item as $item_url => $events ) :
	$it = $titles[ $item_url ] ?? null;
	?>
	<section class="lnh-card-box">
		<header>
			<h2><?php echo esc_html( $item_url ? ( $it['titulo_fuente'] ?? $item_url ) : __( 'Run-level steps', 'lehigh-news-hub' ) ); ?></h2>
			<?php if ( $it ) : ?>
				<span><?php echo LNH_Admin::ring( (int) $it['audit_score'], 36 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ( $it['post_id'] ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-review&post=' . (int) $it['post_id'] ) ); ?>"><?php esc_html_e( 'Open article', 'lehigh-news-hub' ); ?></a><?php endif; ?></span>
			<?php endif; ?>
		</header>
		<?php if ( $it && $it['error'] ) : ?><p class="lnh-check lnh-check--bad">✖ <?php echo esc_html( $it['error'] ); ?></p><?php endif; ?>
		<ol class="lnh-timeline">
			<?php foreach ( $events as $ev ) :
				$a = $agents[ $ev['agent'] ] ?? $agents['pipeline'];
				?>
				<li class="lnh-tl lnh-tl--<?php echo esc_attr( $ev['level'] ); ?>">
					<span class="lnh-tl__ico" title="<?php echo esc_attr( $a['label'] ); ?>"><?php echo esc_html( $a['icon'] ); ?></span>
					<div><span class="lnh-tl__type"><?php echo esc_html( LNH_Admin::event_label( $ev['type'] ) ); ?></span> <span class="lnh-tl__msg"><?php echo esc_html( $ev['message'] ); ?></span>
					<span class="lnh-muted lnh-tl__time">+<?php echo (int) max( 0, $ev['ts'] - $run['started_at'] ); ?>s</span>
					<?php if ( ! empty( $ev['data']['prompt'] ) ) : ?><details><summary><?php esc_html_e( 'Show the image prompt', 'lehigh-news-hub' ); ?></summary><p class="lnh-small lnh-prompt"><?php echo esc_html( $ev['data']['prompt'] ); ?></p></details><?php endif; ?>
					<?php foreach ( (array) ( $ev['data']['urls'] ?? array() ) as $u ) : ?><br><a class="lnh-small" href="<?php echo esc_url( $u ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( LNH_Util::host( $u ) ); ?> ↗</a><?php endforeach; ?></div>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
<?php endforeach; ?>
