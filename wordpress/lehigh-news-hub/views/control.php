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
$ag     = $control['agents'];
$upd    = $ag['pending'] ? 'pending' : ( $w['connected'] && $ag['outdated'] ? ( $ag['can_update'] ? 'available' : 'old' ) : 'none' );
$order  = LNH_Control::AGENTS;
$all    = LNH_Admin::agents();
$active = array_search( $w['agent'], $order, true );
$active = ( false === $active || ! in_array( $phase, array( 'working', 'pausing' ), true ) ) ? -1 : $active;
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
<section class="lnh-card-box lnh-control lnh-control--<?php echo esc_attr( $phase ); ?>" data-lnh-control data-state="<?php echo esc_attr( $control['state'] ); ?>" data-phase="<?php echo esc_attr( $phase ); ?>" data-connected="<?php echo $w['connected'] ? '1' : '0'; ?>" data-active="<?php echo (int) $active; ?>" data-update="<?php echo esc_attr( $upd ); ?>">
	<div class="lnh-control__main">
		<span class="lnh-dot lnh-dot--<?php echo esc_attr( $dot ); ?>" data-lnh-control-dot></span>
		<div>
			<strong class="lnh-control__phase" data-lnh-control-phase><?php echo esc_html( $control['label'] ); ?></strong>
			<span class="lnh-muted" data-lnh-control-detail><?php echo $detail ? '· ' . esc_html( $detail ) : ''; ?></span><span class="lnh-dots" aria-hidden="true"><i></i><i></i><i></i></span>
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
	<?php
	// The working animation: the four agents as stations of a pipeline; the one that is busy gets the logo's colour ring.
	?>
	<div class="lnh-anim" data-lnh-anim aria-hidden="true">
		<?php foreach ( $order as $i => $key ) : ?>
			<?php if ( $i > 0 ) : ?><i class="lnh-anim__link<?php echo $i === $active ? ' is-flow' : ( $i < $active ? ' is-done' : '' ); ?>" data-i="<?php echo (int) $i; ?>"><b></b><b></b><b></b></i><?php endif; ?>
			<div class="lnh-node<?php echo $i === $active ? ' is-active' : ( $i < $active ? ' is-done' : '' ); ?>" data-i="<?php echo (int) $i; ?>" data-agent="<?php echo esc_attr( $key ); ?>">
				<span class="lnh-node__disc"><span class="lnh-node__ring"></span><span class="lnh-node__ico"><?php echo esc_html( $all[ $key ]['icon'] ); ?></span><span class="lnh-node__check">✓</span>
					<em class="lnh-spark lnh-spark--1"></em><em class="lnh-spark lnh-spark--2"></em><em class="lnh-spark lnh-spark--3"></em></span>
				<span class="lnh-node__label"><?php echo esc_html( $all[ $key ]['label'] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>
	<?php if ( 'none' !== $upd ) : ?>
		<div class="lnh-control__update">
			<?php if ( $ag['pending'] ) : ?>
				<p><strong>⬆ <?php esc_html_e( 'Updating the agents…', 'lehigh-news-hub' ); ?></strong> <?php esc_html_e( 'They download the new version from this site and restart by themselves in a few seconds.', 'lehigh-news-hub' ); ?>
					<?php echo $ag['note'] ? '<span class="lnh-muted">' . esc_html( $ag['note'] ) . '</span>' : ''; ?></p>
			<?php elseif ( $ag['can_update'] ) : ?>
				<p><strong>⬆ <?php echo esc_html( sprintf( /* translators: 1: installed version, 2: new version */ __( 'New version of the agents: you have %1$s, the latest is %2$s.', 'lehigh-news-hub' ), $ag['version'], $ag['latest'] ) ); ?></strong>
					<?php esc_html_e( 'Updates fix problems such as retired Gemini models. Your settings and keys are kept.', 'lehigh-news-hub' ); ?>
					<?php echo $ag['note'] ? '<span class="lnh-muted">' . esc_html( $ag['note'] ) . '</span>' : ''; ?></p>
				<?php $btn( 'update_agents', '⬆ ' . __( 'Update the agents', 'lehigh-news-hub' ), 'button-primary' ); ?>
			<?php else : ?>
				<p><strong>⚠ <?php echo esc_html( sprintf( /* translators: 1: installed version, 2: new version */ __( 'Your agents are version %1$s and the plugin includes %2$s.', 'lehigh-news-hub' ), $ag['version'], $ag['latest'] ) ); ?></strong>
					<?php esc_html_e( 'These older agents cannot update themselves: download them once more (they keep nothing important in the folder) and open INICIAR. From then on they update with one button.', 'lehigh-news-hub' ); ?>
					<?php if ( current_user_can( 'manage_options' ) ) : ?><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-connect' ) ); ?>"><?php esc_html_e( 'Set up my agents (2 minutes)', 'lehigh-news-hub' ); ?></a><?php endif; ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php if ( ! $w['connected'] ) : ?>
		<div class="lnh-control__help">
			<p><strong><?php esc_html_e( 'The agents are not connected yet.', 'lehigh-news-hub' ); ?></strong>
				<?php esc_html_e( 'The buttons control the agents, but the agents run on a computer: download them already configured for this site and double-click INICIAR.', 'lehigh-news-hub' ); ?></p>
			<p><?php if ( current_user_can( 'manage_options' ) ) : ?><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-connect' ) ); ?>"><?php esc_html_e( 'Set up my agents (2 minutes)', 'lehigh-news-hub' ); ?></a><?php endif; ?>
				<span class="lnh-small lnh-muted"><?php esc_html_e( 'Already downloaded them? Open INICIAR.bat (Windows) or INICIAR.command (Mac) and leave the window open.', 'lehigh-news-hub' ); ?></span></p>
		</div>
	<?php elseif ( 'paused' === $phase || 'pausing' === $phase ) : ?>
		<p class="lnh-small lnh-muted lnh-control__note"><?php esc_html_e( 'Pausing never cuts an article in half: the agents finish the one in progress, then wait until you press Start.', 'lehigh-news-hub' ); ?></p>
	<?php endif; ?>
</section>
