<?php
/**
 * Uninstall: remove plugin data only when the site owner opted in. Articles are never deleted.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'lnh_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

delete_option( 'lnh_settings' );
delete_option( 'lnh_presets' );
delete_option( 'lnh_pages' );
delete_option( 'lnh_welcome' );
delete_option( 'lnh_cache_v' );

$runs = get_posts( array( 'post_type' => 'lnh_run', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
foreach ( $runs as $run_id ) {
	wp_delete_post( $run_id, true );
}
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lnh\_sc\_%' OR option_name LIKE '\_transient\_timeout\_lnh\_sc\_%'" );
wp_clear_scheduled_hook( 'lnh_cleanup' );
