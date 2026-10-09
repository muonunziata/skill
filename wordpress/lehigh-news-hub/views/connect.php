<?php
/** @var array|WP_Error|null $result @var bool $available @var WP_User|null $existing */
defined( 'ABSPATH' ) || exit;
?>
<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lnh' ) ); ?>">← <?php esc_html_e( 'Back to the dashboard', 'lehigh-news-hub' ); ?></a></p>

<?php if ( is_wp_error( $result ) ) : ?>
	<div class="notice notice-error"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
<?php endif; ?>

<?php if ( is_array( $result ) ) : ?>
	<section class="lnh-card-box lnh-welcome">
		<header><h2>🔑 <?php esc_html_e( 'Credentials for your agents', 'lehigh-news-hub' ); ?></h2></header>
		<p><strong><?php esc_html_e( 'Copy them now: the password is shown only once.', 'lehigh-news-hub' ); ?></strong>
			<?php
			echo esc_html(
				$result['created_user']
					? sprintf( /* translators: %s: user name */ __( 'We created the account “%s” (Author role) for the agents.', 'lehigh-news-hub' ), $result['user'] )
					: sprintf( /* translators: %s: user name */ __( 'We used the existing agents’ account “%s” and added a new password.', 'lehigh-news-hub' ), $result['user'] )
			);
			?>
		</p>
		<div class="lnh-copy"><pre data-lnh-env><?php echo esc_html( $result['env'] ); ?></pre>
			<span class="lnh-copy__buttons">
				<button type="button" class="button button-primary" data-lnh-copy-target="[data-lnh-env]"><?php esc_html_e( 'Copy', 'lehigh-news-hub' ); ?></button>
				<button type="button" class="button" data-lnh-download="[data-lnh-env]" data-filename=".env"><?php esc_html_e( 'Download .env', 'lehigh-news-hub' ); ?></button>
			</span></div>
		<ol class="lnh-steps">
			<li><?php esc_html_e( 'Save these two lines in the .env file inside the agents folder (or download the file and move it there).', 'lehigh-news-hub' ); ?></li>
			<li><?php esc_html_e( 'In a terminal inside that folder run:', 'lehigh-news-hub' ); ?> <code>python main.py setup</code> — <?php esc_html_e( 'it asks only for your Gemini key and configures everything else.', 'lehigh-news-hub' ); ?></li>
			<li><?php esc_html_e( 'Start the agents with ./start.sh (Windows: start.bat). They connect by themselves; then press “Start working” in the News Hub dashboard.', 'lehigh-news-hub' ); ?></li>
		</ol>
		<p class="lnh-small lnh-muted"><?php esc_html_e( 'You can revoke this access any time in Users → Profile → Application Passwords.', 'lehigh-news-hub' ); ?></p>
	</section>
<?php else : ?>
	<section class="lnh-card-box">
		<header><h2><?php esc_html_e( 'Connect your agents in one click', 'lehigh-news-hub' ); ?></h2></header>
		<p><?php esc_html_e( 'We create a dedicated “Lehigh Agents” account with the Author role and an Application Password, and give you the two lines your agents need. Nothing is stored in plain text.', 'lehigh-news-hub' ); ?></p>
		<?php if ( $existing ) : ?>
			<p class="lnh-muted"><?php echo esc_html( sprintf( /* translators: %s: user name */ __( 'The account “%s” already exists; a new password will be added to it.', 'lehigh-news-hub' ), $existing->user_login ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! $available ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Application Passwords are not available on this site. They require HTTPS (or a local environment) and must not be disabled by a security plugin.', 'lehigh-news-hub' ); ?></p></div>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=lnh-connect' ) ); ?>">
				<?php wp_nonce_field( 'lnh_connect' ); ?>
				<button class="button button-primary button-hero"><?php esc_html_e( 'Generate agent credentials', 'lehigh-news-hub' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
