<?php
/** @var int $id Post ID. Social kit written by Agent 4 (Social Designer). */
defined( 'ABSPATH' ) || exit;
$kit = LNH_Meta::social( $id );
?>
<section class="lnh-card-box lnh-social" id="lnh-social">
	<header>
		<h2>🎨 <?php esc_html_e( 'Social kit', 'lehigh-news-hub' ); ?></h2>
		<?php if ( $kit && $kit['generated_at'] ) : ?><span class="lnh-muted lnh-small"><?php echo esc_html( sprintf( /* translators: %s: time ago */ __( 'made %s', 'lehigh-news-hub' ), LNH_Util::ago( (int) $kit['generated_at'] ) ) ); ?></span><?php endif; ?>
	</header>
	<?php if ( ! $kit ) : ?>
		<p class="lnh-muted"><?php esc_html_e( 'The Social designer has not made Instagram or TikTok content for this article yet. It runs automatically after an article is approved, or you can ask for it:', 'lehigh-news-hub' ); ?></p>
		<div class="lnh-copy"><pre id="lnh-social-cmd">python main.py social --post <?php echo (int) $id; ?></pre><button type="button" class="button" data-lnh-copy-target="#lnh-social-cmd"><?php esc_html_e( 'Copy', 'lehigh-news-hub' ); ?></button></div>
	<?php else : ?>
		<?php if ( $kit['hook'] ) : ?><p class="lnh-social__hook">“<?php echo esc_html( $kit['hook'] ); ?>”</p><?php endif; ?>
		<p class="lnh-badges">
			<span class="lnh-chip lnh-chip--ok"><?php esc_html_e( 'Brand: GoLehighAcres.org', 'lehigh-news-hub' ); ?></span>
			<?php if ( $kit['ai_image'] ) : ?><span class="lnh-chip lnh-chip--info"><?php esc_html_e( 'Uses an AI-generated image (labelled on the slides)', 'lehigh-news-hub' ); ?></span><?php endif; ?>
			<?php if ( $kit['voice'] ) : ?><span class="lnh-chip lnh-chip--ok"><?php echo esc_html( sprintf( /* translators: %s: voice provider */ __( 'Voice: %s', 'lehigh-news-hub' ), $kit['voice'] ) ); ?></span><?php endif; ?>
			<?php if ( $kit['model'] ) : ?><span class="lnh-chip lnh-chip--ok"><?php echo esc_html( $kit['model'] ); ?></span><?php endif; ?>
		</p>
		<?php foreach ( $kit['warnings'] as $w ) : ?><p class="lnh-check lnh-check--ok"><span aria-hidden="true">!</span> <?php echo esc_html( $w ); ?></p><?php endforeach; ?>

		<?php
		$networks = array(
			'instagram' => array( __( 'Instagram carousel (4:5)', 'lehigh-news-hub' ), $kit['instagram'] ),
			'tiktok'    => array( __( 'TikTok photo carousel (9:16)', 'lehigh-news-hub' ), $kit['tiktok'] ),
		);
		foreach ( $networks as $key => $net ) :
			if ( ! $net[1]['slides'] && '' === $net[1]['caption'] ) {
				continue;
			}
			$caption = trim( $net[1]['caption'] . "\n\n" . implode( ' ', $kit['hashtags'] ) );
			?>
			<h3><?php echo esc_html( $net[0] ); ?></h3>
			<?php if ( $net[1]['slides'] ) : ?>
				<ol class="lnh-slides lnh-slides--<?php echo esc_attr( $key ); ?>">
					<?php foreach ( $net[1]['slides'] as $i => $f ) : ?>
						<li><a href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener"><img loading="lazy" src="<?php echo esc_url( $f['url'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %d: slide number */ __( 'Slide %d', 'lehigh-news-hub' ), $i + 1 ) ); ?>"></a>
						<a class="lnh-small" href="<?php echo esc_url( $f['url'] ); ?>" download="<?php echo esc_attr( $f['name'] ?: $key . '-' . ( $i + 1 ) . '.png' ); ?>"><?php esc_html_e( 'Download', 'lehigh-news-hub' ); ?></a></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<?php if ( '' !== $net[1]['caption'] ) : ?>
				<label class="lnh-label" for="lnh-cap-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Caption (with hashtags)', 'lehigh-news-hub' ); ?></label>
				<textarea id="lnh-cap-<?php echo esc_attr( $key ); ?>" class="lnh-wide" rows="5" readonly><?php echo esc_textarea( $caption ); ?></textarea>
				<p><button type="button" class="button" data-lnh-copy-target="#lnh-cap-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Copy caption', 'lehigh-news-hub' ); ?></button></p>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php if ( $kit['video'] ) : ?>
			<h3><?php echo esc_html( sprintf( /* translators: %s: seconds */ __( 'Vertical video (%ss)', 'lehigh-news-hub' ), rtrim( rtrim( number_format( (float) $kit['seconds'], 1, '.', '' ), '0' ), '.' ) ) ); ?></h3>
			<video class="lnh-social__video" controls preload="metadata" playsinline src="<?php echo esc_url( $kit['video']['url'] ); ?>"></video>
			<p><a class="button" href="<?php echo esc_url( $kit['video']['url'] ); ?>" download="<?php echo esc_attr( $kit['video']['name'] ?: 'video.mp4' ); ?>"><?php esc_html_e( 'Download video', 'lehigh-news-hub' ); ?></a></p>
		<?php endif; ?>

		<?php if ( $kit['alt_text'] ) : ?><p class="lnh-small"><strong><?php esc_html_e( 'Alt text for the cover:', 'lehigh-news-hub' ); ?></strong> <?php echo esc_html( $kit['alt_text'] ); ?></p><?php endif; ?>
		<p class="lnh-small lnh-muted"><?php esc_html_e( 'The hub does not post to the networks by itself: download the files and publish them from the Instagram / TikTok apps or Meta Business Suite once you have approved the article. Review the content first — it was written by an AI from the article.', 'lehigh-news-hub' ); ?></p>
	<?php endif; ?>
</section>
