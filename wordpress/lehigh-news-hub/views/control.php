<?php
/** @var array $control Summary from LNH_Control::status(). @var string $back Where to return after pressing a button. */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'edit_others_posts' ) ) {
	return;
}
$phase  = $control['phase'];
$w      = $control['worker'];
$dot    = array( 'working' => 'ok', 'waiting' => 'ok', 'paused' => 'paused', 'pausing' => 'late', 'offline' => 'late' )[ $phase ] ?? 'never';
$detail = '';
if ( 'working' === $phase && '' !== $w['message'] ) {
	$detail = $w['message'];
} elseif ( 'waiting' === $phase && $control['next_in'] ) {
	/* translators: %s: time until the next run */
	$detail = sprintf( __( 'next cycle in %s', 'lehigh-news-hub' ), $control['next_in'] );
} elseif ( 'waiting' === $phase && $control['run_queued'] ) {
	$detail = __( 'a run was requested', 'lehigh-news-hub' );
}
$btn = function ( string $do, string $label, string $class ) use ( $back ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="lnh_control"><input type="hidden" name="lnh_do" value="' . esc_attr( $do ) . '"><input type="hidden" name="_back" value="' . esc_url( $back ) . '">';
	wp_nonce_field( 'lnh_control' );
	echo '<button class="button ' . esc_attr( $class ) . '" data-lnh-do="' . esc_attr( $do ) . '">' . esc_html( $label ) . '</button></form>';
};
?>
<section class="lnh-card-box lnh-control lnh-control--<?php echo esc_attr( $phase ); ?>" data-lnh-control data-state="<?php echo esc_attr( $control['state'] ); ?>" data-phase="<?php echo esc_attr( $phase ); ?>" data-connected="<?php echo $w['connected'] ? '1' : '0'; ?>">
	<div class="lnh-control__main">
		<span class="lnh-dot lnh-dot--<?php echo esc_attr( $dot ); ?>" data-lnh-control-dot></span>
		<div>
			<strong class="lnh-control__phase" data-lnh-control-phase><?php echo esc_html( $control['label'] ); ?></strong>
			<span class="lnh-muted" data-lnh-control-detail><?php echo $detail ? '· ' . esc_html( $detail ) : ''; ?></span>
			<div class="lnh-small lnh-muted" data-lnh-control-worker>
				<?php
				if ( $w['connected'] ) {
					echo esc_html( sprintf( /* translators: %s: time ago */ __( 'Agents connected · last seen %s', 'lehigh-news-hub' ), $control['worker']['seen_ago'] ) );
					echo $w['host'] ? ' · ' . esc_html( $w['host'] ) : '';
				} elseif ( $w['seen'] ) {
					echo esc_html( sprintf( /* translators: %s: time ago */ __( 'Agents disconnected · last seen %s', 'lehigh-news-hub' ), $control['worker']['seen_ago'] ) );
				} else {
					esc_html_e( 'The agents have not connected yet.', 'lehigh-news-hub' );
				}
				?>
			</div>
		</div>
	</div>
	<div class="lnh-control__buttons">
		<?php if ( 'running' === $control['state'] ) : ?>
			<?php $btn( 'run_now', '⚡ ' . __( 'Run now', 'lehigh-news-hub' ), '' ); ?>
			<?php $btn( 'pause', '⏸ ' . __( 'Pause', 'lehigh-news-hub' ), 'button-hero' ); ?>
		<?php else : ?>
			<?php $btn( 'start', '▶ ' . __( 'Start working', 'lehigh-news-hub' ), 'button-primary button-hero' ); ?>
		<?php endif; ?>
	</div>
	<?php if ( ! $w['connected'] ) : ?>
		<div class="lnh-control__help">
			<p><strong><?php esc_html_e( 'The buttons control the agents, but the agents have to be running to obey.', 'lehigh-news-hub' ); ?></strong>
				<?php esc_html_e( 'On the computer or server where you installed them, start them once; they connect to this site by themselves with the credentials in their .env file:', 'lehigh-news-hub' ); ?></p>
			<div class="lnh-copy"><pre id="lnh-start-cmd">./start.sh</pre><button type="button" class="button" data-lnh-copy-target="#lnh-start-cmd"><?php esc_html_e( 'Copy', 'lehigh-news-hub' ); ?></button></div>
			<p class="lnh-small lnh-muted"><?php esc_html_e( 'Windows: start.bat. Servers: Docker or systemd keep them running (see the README).', 'lehigh-news-hub' ); ?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-connect' ) ); ?>"><?php esc_html_e( 'Generate agent credentials', 'lehigh-news-hub' ); ?></a><?php endif; ?></p>
		</div>
	<?php elseif ( 'paused' === $phase || 'pausing' === $phase ) : ?>
		<p class="lnh-small lnh-muted lnh-control__note"><?php esc_html_e( 'Pausing never cuts an article in half: the agents finish the one in progress, then wait until you press Start.', 'lehigh-news-hub' ); ?></p>
	<?php endif; ?>
</section>
