<?php
/** @var array $health @var array $stats @var array $runs @var array $feed @var array $funnel */
defined( 'ABSPATH' ) || exit;
$agents = LNH_Admin::agents();
$state  = $health['state'];
?>
<div class="lnh-banner lnh-banner--<?php echo esc_attr( $state ); ?>">
	<span class="lnh-dot lnh-dot--<?php echo esc_attr( $state ); ?>"></span>
	<div><strong><?php echo esc_html( $health['label'] ); ?></strong>
	<?php if ( $health['last'] ) : ?><span class="lnh-muted"> · <?php echo esc_html( sprintf( /* translators: %s: time ago */ __( 'last report %s', 'lehigh-news-hub' ), LNH_Util::ago( (int) $health['last'] ) ) ); ?></span><?php endif; ?></div>
	<span class="lnh-muted"><?php echo esc_html( sprintf( /* translators: %d: number of runs */ _n( '%d run in the last 7 days', '%d runs in the last 7 days', $stats['runs'], 'lehigh-news-hub' ), $stats['runs'] ) ); ?></span>
</div>

<div class="lnh-agent-cards">
	<?php
	$cards = array(
		'rastreador' => array(
			array( $stats['rastreador']['searches'], __( 'web searches', 'lehigh-news-hub' ) ),
			array( $stats['rastreador']['pages'], __( 'pages read', 'lehigh-news-hub' ) ),
			array( $stats['rastreador']['findings'], __( 'stories found', 'lehigh-news-hub' ) ),
			array( $stats['rastreador']['skipped'], __( 'skipped', 'lehigh-news-hub' ) ),
		),
		'redactor'   => array(
			array( $stats['redactor']['drafts'], __( 'drafts written', 'lehigh-news-hub' ) ),
			array( $stats['redactor']['prompts'], __( 'image prompts', 'lehigh-news-hub' ) ),
			array( $stats['redactor']['images'], __( 'images generated', 'lehigh-news-hub' ) ),
		),
		'auditor'    => array(
			array( $stats['auditor']['audits'], __( 'audits', 'lehigh-news-hub' ) ),
			array( $stats['auditor']['approved'], __( 'approved', 'lehigh-news-hub' ) ),
			array( $stats['auditor']['flagged'], __( 'flagged', 'lehigh-news-hub' ) ),
			array( $stats['auditor']['submitted'], __( 'sent to WordPress', 'lehigh-news-hub' ) ),
		),
	);
	foreach ( $cards as $key => $nums ) :
		$a = $agents[ $key ];
		?>
		<section class="lnh-card-box lnh-agent-card lnh-agent-card--<?php echo esc_attr( $key ); ?>">
			<header><h2><span class="lnh-agent-ico"><?php echo esc_html( $a['icon'] ); ?></span> <?php echo esc_html( $a['label'] ); ?></h2>
				<span class="lnh-muted"><?php echo esc_html( $stats[ $key ]['last'] ? LNH_Util::ago( (int) $stats[ $key ]['last'] ) : __( 'idle', 'lehigh-news-hub' ) ); ?></span></header>
			<p class="lnh-muted"><?php echo esc_html( $a['role'] ); ?><?php if ( $stats[ $key ]['model'] ) : ?><br><code><?php echo esc_html( $stats[ $key ]['model'] ); ?></code><?php endif; ?>
			<?php if ( 'redactor' === $key && $stats['image_model'] ) : ?><br><code>🖼 <?php echo esc_html( $stats['image_model'] ); ?></code><?php endif; ?></p>
			<dl class="lnh-nums"><?php foreach ( $nums as $n ) : ?><div><dt><?php echo (int) $n[0]; ?></dt><dd><?php echo esc_html( $n[1] ); ?></dd></div><?php endforeach; ?></dl>
		</section>
	<?php endforeach; ?>
</div>

<section class="lnh-card-box">
	<header><h2><?php esc_html_e( 'Pipeline – last 7 days', 'lehigh-news-hub' ); ?></h2></header>
	<?php
	$steps = array(
		'found'     => __( 'Stories found', 'lehigh-news-hub' ),
		'written'   => __( 'Articles written', 'lehigh-news-hub' ),
		'approved'  => __( 'Approved by auditor', 'lehigh-news-hub' ),
		'sent'      => __( 'Sent to WordPress', 'lehigh-news-hub' ),
		'published' => __( 'Published by you', 'lehigh-news-hub' ),
	);
	$max = max( 1, max( $funnel ) );
	?>
	<div class="lnh-funnel">
		<?php foreach ( $steps as $k => $label ) : ?>
			<div class="lnh-funnel__row"><span><?php echo esc_html( $label ); ?></span><div class="lnh-bar"><i style="width:<?php echo (int) round( $funnel[ $k ] / $max * 100 ); ?>%"></i></div><b><?php echo (int) $funnel[ $k ]; ?></b></div>
		<?php endforeach; ?>
	</div>
</section>

<div class="lnh-cols">
	<div class="lnh-col lnh-col--main">
		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Live feed', 'lehigh-news-hub' ); ?></h2>
				<div class="lnh-feed-tools">
					<select data-lnh-feed-agent>
						<option value=""><?php esc_html_e( 'All agents', 'lehigh-news-hub' ); ?></option>
						<?php foreach ( array( 'rastreador', 'redactor', 'auditor' ) as $k ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $agents[ $k ]['label'] ); ?></option><?php endforeach; ?>
					</select>
					<label class="lnh-live"><input type="checkbox" data-lnh-live checked> <span><?php esc_html_e( 'Live', 'lehigh-news-hub' ); ?></span></label>
				</div>
			</header>
			<ol class="lnh-timeline lnh-timeline--feed" data-lnh-feed>
				<?php if ( ! $feed ) : ?><li class="lnh-empty"><?php esc_html_e( 'No activity yet.', 'lehigh-news-hub' ); ?></li><?php endif; ?>
				<?php foreach ( $feed as $ev ) :
					$a = $agents[ $ev['agent'] ] ?? $agents['pipeline'];
					?>
					<li class="lnh-tl lnh-tl--<?php echo esc_attr( $ev['level'] ); ?>" data-agent="<?php echo esc_attr( $ev['agent'] ); ?>" data-ts="<?php echo (int) $ev['ts']; ?>">
						<span class="lnh-tl__ico" title="<?php echo esc_attr( $a['label'] ); ?>"><?php echo esc_html( $a['icon'] ); ?></span>
						<div><span class="lnh-tl__type"><?php echo esc_html( LNH_Admin::event_label( $ev['type'] ) ); ?></span> <span class="lnh-tl__msg"><?php echo esc_html( $ev['message'] ); ?></span>
						<span class="lnh-muted lnh-tl__time"><?php echo esc_html( LNH_Util::ago( (int) $ev['ts'] ) ); ?></span></div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
	</div>
	<div class="lnh-col lnh-col--side">
		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Runs', 'lehigh-news-hub' ); ?></h2></header>
			<?php if ( ! $runs ) : ?><p class="lnh-empty"><?php esc_html_e( 'No runs yet.', 'lehigh-news-hub' ); ?></p><?php else : ?>
				<?php LNH_Admin::view( 'runs-table', array( 'runs' => $runs ) ); ?>
			<?php endif; ?>
		</section>
	</div>
</div>
