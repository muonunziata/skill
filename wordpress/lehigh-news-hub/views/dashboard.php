<?php
/** @var array $control @var array $welcome @var array $pages @var array $counts @var array $health @var array $stats @var array $waiting @var array $runs @var int $published7 @var int $avg @var string $rest_url @var string $profile */
defined( 'ABSPATH' ) || exit;
$agents = LNH_Admin::agents();
$state  = $health['state'];
?>
<?php if ( ! empty( $welcome ) && current_user_can( 'edit_others_posts' ) ) : ?>
<section class="lnh-card-box lnh-welcome">
	<header><h2>🎉 <?php esc_html_e( 'Lehigh News Hub is ready', 'lehigh-news-hub' ); ?></h2></header>
	<p><?php esc_html_e( 'We created the pages your site needs. They are published and ready to edit:', 'lehigh-news-hub' ); ?></p>
	<ul class="lnh-welcome__pages">
		<?php foreach ( $pages as $row ) : if ( ! $row['id'] ) { continue; } ?>
			<li><strong><?php echo esc_html( $row['title'] ); ?></strong>
				<?php if ( $row['view'] ) : ?><a href="<?php echo esc_url( $row['view'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'lehigh-news-hub' ); ?></a><?php endif; ?>
				<?php if ( $row['edit'] ) : ?>· <a href="<?php echo esc_url( $row['edit'] ); ?>"><?php esc_html_e( 'Edit', 'lehigh-news-hub' ); ?></a><?php endif; ?></li>
		<?php endforeach; ?>
	</ul>
	<p><?php esc_html_e( 'Next: connect your agents (see “Connect your agents” below), add the News page to your menu, and review the first articles in the queue.', 'lehigh-news-hub' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lnh_dismiss_welcome"><?php wp_nonce_field( 'lnh_dismiss_welcome' ); ?>
		<button class="button"><?php esc_html_e( 'Got it', 'lehigh-news-hub' ); ?></button>
		<a class="button" href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>"><?php esc_html_e( 'Add the pages to my menu', 'lehigh-news-hub' ); ?></a>
	</form>
</section>
<?php endif; ?>

<?php LNH_Admin::view( 'control', array( 'control' => $control, 'back' => admin_url( 'admin.php?page=lnh' ) ) ); ?>

<div class="lnh-banner lnh-banner--<?php echo esc_attr( $state ); ?>">
	<span class="lnh-dot lnh-dot--<?php echo esc_attr( $state ); ?>"></span>
	<div>
		<strong><?php echo esc_html( $health['label'] ); ?></strong>
		<?php if ( $health['last'] ) : ?>
			<span class="lnh-muted"> · <?php echo esc_html( sprintf( /* translators: %s: time ago */ __( 'last report %s', 'lehigh-news-hub' ), LNH_Util::ago( (int) $health['last'] ) ) ); ?></span>
		<?php endif; ?>
	</div>
	<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-activity' ) ); ?>"><?php esc_html_e( 'See what the agents did', 'lehigh-news-hub' ); ?></a>
</div>

<div class="lnh-kpis">
	<a class="lnh-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-queue' ) ); ?>"><span class="lnh-kpi__n"><?php echo (int) $counts['review']; ?></span><span class="lnh-kpi__l"><?php esc_html_e( 'Waiting for review', 'lehigh-news-hub' ); ?></span></a>
	<a class="lnh-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-queue&tab=flagged' ) ); ?>"><span class="lnh-kpi__n"><?php echo (int) $counts['flagged']; ?></span><span class="lnh-kpi__l"><?php esc_html_e( 'Flagged by the auditor', 'lehigh-news-hub' ); ?></span></a>
	<a class="lnh-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-queue&tab=published' ) ); ?>"><span class="lnh-kpi__n"><?php echo (int) $published7; ?></span><span class="lnh-kpi__l"><?php esc_html_e( 'Published this week', 'lehigh-news-hub' ); ?></span></a>
	<div class="lnh-kpi"><span class="lnh-kpi__n"><?php echo $avg ? LNH_Admin::ring( $avg, 52 ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?></span><span class="lnh-kpi__l"><?php esc_html_e( 'Average audit score (30 days)', 'lehigh-news-hub' ); ?></span></div>
	<div class="lnh-kpi"><span class="lnh-kpi__n"><?php echo (int) $stats['social']['kits']; ?></span><span class="lnh-kpi__l"><?php esc_html_e( 'Social kits this week', 'lehigh-news-hub' ); ?></span></div>
	<div class="lnh-kpi"><span class="lnh-kpi__n"><?php echo (int) $stats['redactor']['images']; ?></span><span class="lnh-kpi__l"><?php esc_html_e( 'AI images this week', 'lehigh-news-hub' ); ?></span></div>
	<div class="lnh-kpi"><span class="lnh-kpi__n"><?php echo esc_html( number_format_i18n( $stats['tokens'] ) ); ?></span><span class="lnh-kpi__l"><?php esc_html_e( 'Tokens used this week', 'lehigh-news-hub' ); ?></span></div>
</div>

<div class="lnh-cols">
	<div class="lnh-col lnh-col--main">
		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Waiting for your decision', 'lehigh-news-hub' ); ?></h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-queue' ) ); ?>"><?php esc_html_e( 'Open the queue', 'lehigh-news-hub' ); ?> →</a></header>
			<?php if ( ! $waiting ) : ?>
				<p class="lnh-empty"><?php esc_html_e( 'Nothing to review right now. New articles appear here as soon as the agents finish them.', 'lehigh-news-hub' ); ?></p>
			<?php else : ?>
				<ul class="lnh-list">
					<?php foreach ( $waiting as $p ) :
						$score = (int) get_post_meta( $p->ID, 'lnh_audit_score', true );
						?>
						<li>
							<span class="lnh-thumb"><?php echo has_post_thumbnail( $p ) ? get_the_post_thumbnail( $p, array( 72, 48 ) ) : '<i></i>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<div class="lnh-list__main">
								<a class="lnh-list__title" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-review&post=' . $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a>
								<span class="lnh-muted"><?php echo esc_html( LNH_Util::host( (string) get_post_meta( $p->ID, 'lnh_source_url', true ) ) ); ?> · <?php echo esc_html( LNH_Util::ago( LNH_Util::post_timestamp( $p ) ) ); ?></span>
							</div>
							<?php echo LNH_Admin::ring( $score ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-review&post=' . $p->ID ) ); ?>"><?php esc_html_e( 'Review', 'lehigh-news-hub' ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Recent agent runs', 'lehigh-news-hub' ); ?></h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-activity' ) ); ?>"><?php esc_html_e( 'All activity', 'lehigh-news-hub' ); ?> →</a></header>
			<?php if ( ! $runs ) : ?>
				<p class="lnh-empty"><?php esc_html_e( 'No runs yet.', 'lehigh-news-hub' ); ?></p>
			<?php else : ?>
				<?php LNH_Admin::view( 'runs-table', array( 'runs' => $runs ) ); ?>
			<?php endif; ?>
		</section>
	</div>

	<div class="lnh-col lnh-col--side">
		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Your agents', 'lehigh-news-hub' ); ?></h2></header>
			<ul class="lnh-agents">
				<?php foreach ( array( 'rastreador', 'redactor', 'auditor', 'social' ) as $key ) :
					$a = $agents[ $key ];
					$s = $stats[ $key ];
					?>
					<li>
						<span class="lnh-agent-ico"><?php echo esc_html( $a['icon'] ); ?></span>
						<div>
							<strong><?php echo esc_html( $a['label'] ); ?></strong>
							<span class="lnh-muted"><?php echo esc_html( $a['role'] ); ?></span>
							<?php if ( $s['model'] ) : ?><code><?php echo esc_html( $s['model'] ); ?></code><?php endif; ?>
							<span class="lnh-muted"><?php echo esc_html( $s['last'] ? sprintf( /* translators: %s: time ago */ __( 'active %s', 'lehigh-news-hub' ), LNH_Util::ago( (int) $s['last'] ) ) : __( 'no activity yet', 'lehigh-news-hub' ) ); ?></span>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Connect your agents', 'lehigh-news-hub' ); ?></h2></header>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-connect' ) ); ?>"><?php esc_html_e( 'Generate agent credentials', 'lehigh-news-hub' ); ?></a>
				<span class="lnh-muted lnh-small"><?php esc_html_e( 'One click: creates the account and gives you the .env lines.', 'lehigh-news-hub' ); ?></span></p>
			<?php endif; ?>
			<ol class="lnh-steps">
				<li><?php
					printf(
						/* translators: %s: link to the profile screen */
						esc_html__( 'Create an %s for the account the agents will post with.', 'lehigh-news-hub' ),
						'<a href="' . esc_url( $profile ) . '">' . esc_html__( 'Application Password', 'lehigh-news-hub' ) . '</a>'
					);
				?></li>
				<li><?php esc_html_e( 'Put these two lines in the agents’ .env file:', 'lehigh-news-hub' ); ?>
					<div class="lnh-copy"><pre>WP_REST_URL=<?php echo esc_html( $rest_url ); ?>
WP_AUTH_TOKEN=<?php echo esc_html( wp_get_current_user()->user_login ); ?>:xxxx xxxx xxxx xxxx xxxx xxxx</pre><button type="button" class="button" data-lnh-copy><?php esc_html_e( 'Copy', 'lehigh-news-hub' ); ?></button></div></li>
				<li><?php esc_html_e( 'Run python main.py setup (it configures the rest for you), then start them with ./start.sh and press “Start working” above.', 'lehigh-news-hub' ); ?></li>
			</ol>
		</section>
	</div>
</div>
