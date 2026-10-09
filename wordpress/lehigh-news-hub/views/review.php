<?php
/** @var WP_Post $post @var array $audit */
defined( 'ABSPATH' ) || exit;
$id       = $post->ID;
$agents   = LNH_Admin::agents();
$status   = $post->post_status;
$src_url  = (string) get_post_meta( $id, 'lnh_source_url', true );
$src_name = (string) get_post_meta( $id, 'lnh_source_name', true );
$src_title = (string) get_post_meta( $id, 'lnh_source_title', true );
$src_date = (string) get_post_meta( $id, 'lnh_source_date', true );
$facts    = (string) get_post_meta( $id, 'lnh_facts', true );
$meta_desc = (string) get_post_meta( $id, 'lnh_meta_description', true );
$ai_img   = (bool) get_post_meta( $id, 'lnh_featured_image_ai', true );
$prompt   = (string) get_post_meta( $id, 'lnh_image_prompt', true );
$provider = (string) get_post_meta( $id, 'lnh_image_provider', true );
$run_id   = (string) get_post_meta( $id, 'lnh_run_id', true );
$reason   = (string) get_post_meta( $id, 'lnh_reject_reason', true );
$checks   = $audit['checks'];
$status_labels = array( 'draft' => __( 'Draft – waiting for review', 'lehigh-news-hub' ), 'pending' => __( 'Flagged – pending review', 'lehigh-news-hub' ), 'future' => __( 'Scheduled', 'lehigh-news-hub' ), 'publish' => __( 'Published', 'lehigh-news-hub' ), 'trash' => __( 'Rejected', 'lehigh-news-hub' ) );
$first_ts = $audit['trace'] ? (int) $audit['trace'][0]['ts'] : 0;
$back_url = admin_url( 'admin.php?page=lnh-queue' );
?>
<p><a href="<?php echo esc_url( $back_url ); ?>">← <?php esc_html_e( 'Back to the queue', 'lehigh-news-hub' ); ?></a></p>

<div class="lnh-review">
	<div class="lnh-review__main">
		<article class="lnh-card-box lnh-article-preview">
			<div class="lnh-article-preview__head">
				<?php echo LNH_Admin::chip( $audit['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="lnh-chip lnh-chip--ok"><?php echo esc_html( $status_labels[ $status ] ?? $status ); ?></span>
				<?php if ( $ai_img ) : ?><span class="lnh-chip lnh-chip--info"><?php esc_html_e( 'AI-generated image', 'lehigh-news-hub' ); ?></span><?php endif; ?>
			</div>
			<?php if ( has_post_thumbnail( $post ) ) : ?>
				<figure class="lnh-hero"><?php echo get_the_post_thumbnail( $post, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php $cap = get_the_post_thumbnail_caption( $post ); if ( $cap ) : ?><figcaption><?php echo esc_html( $cap ); ?></figcaption><?php endif; ?></figure>
			<?php else : ?>
				<div class="lnh-hero lnh-hero--empty"><?php esc_html_e( 'No featured image', 'lehigh-news-hub' ); ?></div>
			<?php endif; ?>
			<h2 class="lnh-article-title"><?php echo esc_html( get_the_title( $post ) ); ?></h2>
			<p class="lnh-lead"><?php echo esc_html( $post->post_excerpt ); ?></p>
			<div class="lnh-snippet" aria-label="<?php esc_attr_e( 'Search result preview', 'lehigh-news-hub' ); ?>">
				<span class="lnh-snippet__url"><?php echo esc_html( preg_replace( '#^https?://#', '', home_url( '/' ) ) ); ?> › <?php echo esc_html( $post->post_name ?: sanitize_title( $post->post_title ) ); ?></span>
				<span class="lnh-snippet__title"><?php echo esc_html( LNH_Util::clip( get_the_title( $post ), 60 ) ); ?></span>
				<span class="lnh-snippet__desc"><?php echo esc_html( LNH_Util::clip( $meta_desc ?: $post->post_excerpt, 160 ) ); ?></span>
			</div>
			<div class="lnh-preview entry-content"><?php echo apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals ?></div>
		</article>
	</div>

	<aside class="lnh-review__side">
		<section class="lnh-card-box lnh-decision">
			<header><h2><?php esc_html_e( 'Your decision', 'lehigh-news-hub' ); ?></h2></header>
			<?php if ( 'publish' === $status ) : ?>
				<p><?php esc_html_e( 'This article is live.', 'lehigh-news-hub' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View article', 'lehigh-news-hub' ); ?></a>
			<?php else : ?>
				<?php if ( 'trash' === $status && $reason ) : ?><p class="lnh-muted"><?php echo esc_html( sprintf( /* translators: %s: reason */ __( 'Rejected: %s', 'lehigh-news-hub' ), $reason ) ); ?></p><?php endif; ?>
				<?php LNH_Admin::nonce_form_open( 'publish' ); ?>
					<input type="hidden" name="post[]" value="<?php echo (int) $id; ?>"><input type="hidden" name="from_review" value="1">
					<button class="button button-primary button-hero lnh-wide"><?php esc_html_e( 'Publish now', 'lehigh-news-hub' ); ?></button>
				</form>
				<?php LNH_Admin::nonce_form_open( 'publish' ); ?>
					<input type="hidden" name="post[]" value="<?php echo (int) $id; ?>"><input type="hidden" name="from_review" value="1">
					<label class="lnh-label"><?php esc_html_e( 'Or schedule it', 'lehigh-news-hub' ); ?></label>
					<div class="lnh-inline"><input type="datetime-local" name="schedule" required><button class="button"><?php esc_html_e( 'Schedule', 'lehigh-news-hub' ); ?></button></div>
				</form>
				<?php if ( 'trash' === $status ) : ?>
					<?php LNH_Admin::nonce_form_open( 'restore' ); ?>
						<input type="hidden" name="post[]" value="<?php echo (int) $id; ?>"><input type="hidden" name="from_review" value="1">
						<button class="button lnh-wide"><?php esc_html_e( 'Move back to review', 'lehigh-news-hub' ); ?></button>
					</form>
				<?php else : ?>
					<?php LNH_Admin::nonce_form_open( 'reject' ); ?>
						<input type="hidden" name="post[]" value="<?php echo (int) $id; ?>"><input type="hidden" name="from_review" value="1">
						<label class="lnh-label" for="lnh-reason"><?php esc_html_e( 'Reject (optional reason)', 'lehigh-news-hub' ); ?></label>
						<textarea id="lnh-reason" name="reason" rows="2" class="lnh-wide"></textarea>
						<button class="button lnh-wide lnh-danger" data-confirm="reject"><?php esc_html_e( 'Reject article', 'lehigh-news-hub' ); ?></button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( get_edit_post_link( $id, 'raw' ) ); ?>"><?php esc_html_e( 'Edit in the WordPress editor', 'lehigh-news-hub' ); ?> ↗</a></p>
		</section>

		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Audit', 'lehigh-news-hub' ); ?></h2><?php echo LNH_Admin::chip( $audit['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></header>
			<div class="lnh-audit-top"><?php echo LNH_Admin::ring( $audit['score'], 72 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="lnh-muted"><?php esc_html_e( 'Score given by the Auditor agent after comparing the article with the facts reported by the Researcher.', 'lehigh-news-hub' ); ?></p></div>
			<ul class="lnh-checks">
				<?php
				$links  = $checks['links'] ?? array();
				$broken = $links['broken'] ?? array();
				$rows   = array();
				if ( isset( $links['checked'] ) ) {
					$rows[] = array( $broken ? 'bad' : 'good', $broken
						? sprintf( /* translators: %d: number of broken links */ _n( '%d broken link', '%d broken links', count( $broken ), 'lehigh-news-hub' ), count( $broken ) )
						: sprintf( /* translators: %d: number of links checked */ __( '%d links checked, none broken', 'lehigh-news-hub' ), (int) $links['checked'] ) );
				}
				if ( isset( $checks['unsafe_html'] ) ) {
					$rows[] = array( $checks['unsafe_html'] ? 'bad' : 'good', $checks['unsafe_html'] ? __( 'Unsafe HTML found', 'lehigh-news-hub' ) : __( 'HTML is clean', 'lehigh-news-hub' ) );
				}
				if ( isset( $checks['unsupported_numbers'] ) ) {
					$rows[] = array( $checks['unsupported_numbers'] ? 'ok' : 'good', $checks['unsupported_numbers']
						? sprintf( /* translators: %s: list of numbers */ __( 'Figures not found in the source: %s', 'lehigh-news-hub' ), implode( ', ', array_map( 'sanitize_text_field', $checks['unsupported_numbers'] ) ) )
						: __( 'All figures appear in the source', 'lehigh-news-hub' ) );
				}
				if ( isset( $checks['source_overlap'] ) ) {
					$o      = (float) $checks['source_overlap'];
					$rows[] = array( $o > 0.3 ? 'bad' : ( $o > 0.15 ? 'ok' : 'good' ), sprintf( /* translators: %d: percentage */ __( 'Copied from source: %d%%', 'lehigh-news-hub' ), round( $o * 100 ) ) );
				}
				if ( isset( $checks['words'] ) ) {
					$rows[] = array( 'good', sprintf( /* translators: %d: word count */ __( '%d words', 'lehigh-news-hub' ), (int) $checks['words'] ) );
				}
				foreach ( $rows as $r ) :
					?>
					<li class="lnh-check lnh-check--<?php echo esc_attr( $r[0] ); ?>"><span aria-hidden="true"><?php echo 'good' === $r[0] ? '✔' : ( 'ok' === $r[0] ? '!' : '✖' ); ?></span> <?php echo esc_html( $r[1] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $broken ) : ?><ul class="lnh-small"><?php foreach ( $broken as $u ) : ?><li><code><?php echo esc_html( $u ); ?></code></li><?php endforeach; ?></ul><?php endif; ?>
			<h3><?php esc_html_e( 'Auditor notes', 'lehigh-news-hub' ); ?></h3>
			<?php if ( ! $audit['notes'] ) : ?>
				<p class="lnh-muted"><?php esc_html_e( 'No issues reported.', 'lehigh-news-hub' ); ?></p>
			<?php else : ?>
				<ul class="lnh-notes">
					<?php foreach ( $audit['notes'] as $n ) :
						$n    = (string) $n;
						$auto = 0 === strpos( $n, '[auto]' );
						?>
						<li><span class="lnh-chip lnh-chip--<?php echo $auto ? 'ok' : 'info'; ?>"><?php echo $auto ? esc_html__( 'Auto', 'lehigh-news-hub' ) : esc_html__( 'AI', 'lehigh-news-hub' ); ?></span> <?php echo esc_html( trim( preg_replace( '/^\[auto\]\s*/', '', $n ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Source', 'lehigh-news-hub' ); ?></h2></header>
			<?php if ( $src_url ) : ?>
				<p><a href="<?php echo esc_url( $src_url ); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html( $src_title ?: $src_url ); ?></strong></a><br>
				<span class="lnh-muted"><?php echo esc_html( $src_name ?: LNH_Util::host( $src_url ) ); ?><?php echo $src_date ? ' · ' . esc_html( $src_date ) : ''; ?></span></p>
			<?php endif; ?>
			<?php if ( $facts ) : ?><details><summary><?php esc_html_e( 'Facts the Researcher extracted', 'lehigh-news-hub' ); ?></summary><p class="lnh-small"><?php echo nl2br( esc_html( $facts ) ); ?></p></details><?php endif; ?>
			<?php if ( $audit['keywords'] ) : ?><p><?php foreach ( $audit['keywords'] as $k ) : ?><span class="lnh-chip lnh-chip--ok"><?php echo esc_html( (string) $k ); ?></span> <?php endforeach; ?></p><?php endif; ?>
		</section>

		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'Featured image', 'lehigh-news-hub' ); ?></h2></header>
			<?php if ( $ai_img ) : ?>
				<p><span class="lnh-chip lnh-chip--info"><?php esc_html_e( 'AI-generated', 'lehigh-news-hub' ); ?></span> <?php if ( $provider ) : ?><code><?php echo esc_html( $provider ); ?></code><?php endif; ?></p>
				<p class="lnh-small"><?php esc_html_e( 'It is labelled as an illustration in its caption. Check that it does not suggest something the story does not say.', 'lehigh-news-hub' ); ?></p>
				<?php if ( $prompt ) : ?><details><summary><?php esc_html_e( 'Prompt the Writer wrote', 'lehigh-news-hub' ); ?></summary><p class="lnh-small lnh-prompt"><?php echo esc_html( $prompt ); ?></p></details><?php endif; ?>
			<?php elseif ( has_post_thumbnail( $post ) ) : ?>
				<p class="lnh-small"><?php esc_html_e( 'Openly licensed photo – credit is in the caption.', 'lehigh-news-hub' ); ?></p>
			<?php else : ?>
				<p class="lnh-small"><?php esc_html_e( 'No image yet. Set one in the editor.', 'lehigh-news-hub' ); ?></p>
			<?php endif; ?>
			<?php if ( $audit['todo'] ) : ?>
				<h3><?php esc_html_e( 'Media still to add', 'lehigh-news-hub' ); ?></h3>
				<ul class="lnh-small"><?php foreach ( $audit['todo'] as $t ) : ?><li><?php echo esc_html( (string) $t ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
		</section>

		<section class="lnh-card-box">
			<header><h2><?php esc_html_e( 'How the agents got here', 'lehigh-news-hub' ); ?></h2><?php if ( $run_id ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-activity&run=' . rawurlencode( $run_id ) ) ); ?>"><?php esc_html_e( 'Full run', 'lehigh-news-hub' ); ?> →</a><?php endif; ?></header>
			<?php if ( $audit['models'] ) : ?>
				<p class="lnh-models"><?php foreach ( $audit['models'] as $role => $model ) : ?><span class="lnh-chip lnh-chip--ok"><?php echo esc_html( ( $agents[ $role ]['label'] ?? $role ) . ': ' . $model ); ?></span> <?php endforeach; ?></p>
			<?php endif; ?>
			<?php if ( ! $audit['trace'] ) : ?>
				<p class="lnh-muted"><?php esc_html_e( 'No trace stored for this article.', 'lehigh-news-hub' ); ?></p>
			<?php else : ?>
				<ol class="lnh-timeline">
					<?php foreach ( $audit['trace'] as $ev ) :
						$agent = $agents[ $ev['agent'] ?? 'pipeline' ] ?? $agents['pipeline'];
						?>
						<li class="lnh-tl lnh-tl--<?php echo esc_attr( $ev['level'] ?? 'info' ); ?>">
							<span class="lnh-tl__ico" title="<?php echo esc_attr( $agent['label'] ); ?>"><?php echo esc_html( $agent['icon'] ); ?></span>
							<div><span class="lnh-tl__msg"><?php echo esc_html( $ev['message'] ?? '' ); ?></span>
							<span class="lnh-muted lnh-tl__time">+<?php echo (int) max( 0, ( $ev['ts'] ?? 0 ) - $first_ts ); ?>s</span></div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
	</aside>
</div>
