<?php
/**
 * In-WordPress test runner for Lehigh News Hub.
 *
 *   php wordpress/tests/run.php /path/to/wordpress
 *
 * Boots the given WordPress install (the plugin must be active) and exercises the plugin through its real code paths.
 * It creates and deletes its own posts and users; use a throw-away site, never production.
 */
$root = $argv[1] ?? getenv( 'WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php run.php /path/to/wordpress\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
$_SERVER['REQUEST_URI'] = '/';
error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'display_errors', '0' );
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( ! class_exists( 'LNH_Util' ) ) {
	fwrite( STDERR, "Lehigh News Hub is not active on this site.\n" );
	exit( 2 );
}

$pass = 0;
$fail = 0;
function t( string $name, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  ok   $name\n";
	} else {
		++$fail;
		echo "  FAIL $name " . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) . "\n";
	}
}

$created = array();
$mk      = function ( array $meta, string $status = 'draft', string $title = 'T' ) use ( &$created ) {
	$id = wp_insert_post( array( 'post_title' => $title, 'post_content' => '<p>Body</p>', 'post_status' => $status, 'post_type' => 'post', 'post_excerpt' => 'Ex' ) );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	$created[] = $id;
	return $id;
};
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
wp_set_current_user( $admin );

echo "LNH_Util\n";
t( 'json_list tolerates garbage', LNH_Util::json_list( 'not json' ) === array() && LNH_Util::json_list( '["a"]' ) === array( 'a' ) );
t( 'clamp', LNH_Util::clamp( '99', 1, 10, 5 ) === 10 && LNH_Util::clamp( 'x', 1, 10, 5 ) === 5 && LNH_Util::clamp( -4, 1, 10, 5 ) === 1 );
t( 'hex_color accepts valid, rejects script', LNH_Util::hex_color( '#abc', '#000' ) === '#abc' && LNH_Util::hex_color( 'red;}</style>', '#000' ) === '#000' );
t( 'parse_since', LNH_Util::parse_since( '7 days', 1000000 ) === 1000000 - 604800 && 0 === LNH_Util::parse_since( 'yesterday' ) && 0 === LNH_Util::parse_since( '' ) );
t( 'ratio', LNH_Util::ratio( '16:9' ) === '16 / 9' && '' === LNH_Util::ratio( 'auto' ) && '' === LNH_Util::ratio( 'x:y' ) );
t( 'words truncates', LNH_Util::words( '<p>one two three four five</p>', 3 ) === 'one two three…' );
t( 'fill', LNH_Util::fill( 'a {x} b {y}', array( 'x' => 1, 'y' => 'z' ) ) === 'a 1 b z' );
t( 'score_class', 'good' === LNH_Util::score_class( 90 ) && 'ok' === LNH_Util::score_class( 75 ) && 'bad' === LNH_Util::score_class( 10 ) );

echo "Meta registration\n";
$reg = get_registered_meta_keys( 'post', 'post' );
t( 'all keys registered with show_in_rest', count( array_filter( array_keys( LNH_Meta::KEYS ), function ( $k ) use ( $reg ) { return isset( $reg[ $k ] ) && $reg[ $k ]['show_in_rest']; } ) ) === count( LNH_Meta::KEYS ) );
t( 'protected from the Custom Fields box', is_protected_meta( 'lnh_audit_score', 'post' ) );

echo "Shortcode\n";
$a = LNH_Shortcode::resolve( array( 'count' => '999', 'columns' => '-3', 'layout' => 'evil"><script>', 'accent' => 'javascript:x', 'title_tag' => 'script', 'category' => 'News, <b>x</b>,,weather', 'ids' => '1, 2;DROP,3', 'orderby' => 'rand();--', 'more_link' => 'javascript:alert(1)', 'class' => 'ok "x" onload=y' ) );
t( 'count clamped', 50 === $a['count'] );
t( 'columns clamped', 1 === $a['columns'] );
t( 'enum falls back', 'grid' === $a['layout'] && 'h3' === $a['title_tag'] && 'date' === $a['orderby'] );
t( 'colour falls back to the configured default', LNH_Shortcode::schema()['accent']['default'] === $a['accent'], $a['accent'] );
t( 'csv slugs sanitised (tags stripped, blanks dropped)', 'news,x,weather' === $a['category'], $a['category'] );
t( 'ids numeric only', '1,2,3' === $a['ids'], $a['ids'] );
t( 'javascript: URL dropped', '' === $a['more_link'], $a['more_link'] );
$html = LNH_Shortcode::markup( array(), $a, 'u', 'v', 1, 0 );
t( 'markup escapes class', false === strpos( $html, 'onload=' ) || false === strpos( $html, '"x"' ), $html );
$args = LNH_Shortcode::query_args( LNH_Shortcode::resolve( array( 'source' => 'hub', 'min_score' => 80, 'since' => '3 days', 'orderby' => 'score', 'lang' => 'es', 'exclude_ids' => '5,6' ) ) );
t( 'query: hub origin + min score + lang', 3 === count( $args['meta_query'] ) - 1 && 'lnh_audit_score' === $args['meta_key'], $args );
t( 'query: date + exclusions', isset( $args['date_query'] ) && array( 5, 6 ) === $args['post__not_in'] );
t( 'to_string only non-defaults', '[lehigh_news layout="list" count="2"]' === LNH_Shortcode::to_string( array( 'layout' => 'list', 'count' => 2 ) ) );
t( 'to_string with defaults is bare', '[lehigh_news]' === LNH_Shortcode::to_string( array() ) );

echo "Run reports\n";
$run = LNH_Runs::sanitize( array( 'run_id' => '<b>r1</b>', 'started_at' => '100', 'status' => 'hacked', 'models' => array( 'x' => '<script>' ),
	'items' => array( 'x', array( 'url' => 'javascript:alert(1)', 'titulo_fuente' => '<i>T</i>', 'audit_score' => '9x' ) ),
	'events' => array( array( 'agent' => 'evil', 'message' => '<script>alert(1)</script>ok', 'ts' => 5, 'data' => array( 'urls' => array( 'javascript:x', 'https://a.b/c' ), 'prompt' => '<b>p</b>', 'secret' => 'x' ) ) ) ) );
t( 'run id sanitised', 'r1' === $run['run_id'] );
t( 'status whitelisted', 'ok' === $run['status'] );
t( 'strings stripped of tags', false === strpos( wp_json_encode( $run ), '<script' ) && false === strpos( wp_json_encode( $run ), '<b>' ), wp_json_encode( $run ) );
t( 'bad urls dropped, unknown data keys dropped', ! in_array( 'javascript:x', $run['events'][0]['data']['urls'], true ) && ! isset( $run['events'][0]['data']['secret'] ) );
t( 'unknown agent mapped to pipeline', 'pipeline' === $run['events'][0]['agent'] );
t( 'empty run_id rejected', array() === LNH_Runs::sanitize( array( 'run_id' => '' ) ) );
$big = array( 'run_id' => 'big', 'events' => array_fill( 0, 1000, array( 'message' => 'm', 'agent' => 'auditor' ) ) );
t( 'events capped at 400', 400 === count( LNH_Runs::sanitize( $big )['events'] ) );
$id1 = LNH_Runs::store( $run );
$id2 = LNH_Runs::store( $run );
t( 'store is idempotent per run_id', $id1 === $id2 && is_int( $id1 ) );
wp_delete_post( $id1, true );

echo "Settings\n";
$before = get_option( LNH_Settings::OPTION );
LNH_Settings::save_tab( 'design', array( 'ds_accent' => 'nope', 'ds_radius' => '500', 'ds_theme' => 'neon', 'ds_shadow' => '' ) );
$s = LNH_Settings::all();
t( 'invalid colour/theme fall back, radius clamped, unchecked box = 0', '#1d6fdc' === $s['ds_accent'] && 'auto' === $s['ds_theme'] && 40 === $s['ds_radius'] && 0 === $s['ds_shadow'], $s );
LNH_Settings::save_tab( 'general', array( 'auto_publish' => '1', 'auto_publish_min_score' => '95', 'notify' => '' ) );
$s = LNH_Settings::all();
t( 'saving one tab keeps other tabs', 40 === $s['ds_radius'] && 1 === $s['auto_publish'] && 95 === $s['auto_publish_min_score'] );
update_option( LNH_Settings::OPTION, $before );

echo "Queue decisions + permissions\n";
$p1 = $mk( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 91 ) );
$plain = $mk( array() );
t( 'publish refuses non-agent posts', is_wp_error( LNH_Queue::publish( $plain ) ) );
t( 'publish ok', true === LNH_Queue::publish( $p1 ) && 'publish' === get_post_status( $p1 ) );
t( 'reviewer recorded', (int) get_post_meta( $p1, 'lnh_reviewed_by', true ) === $admin );
t( 'reject trashes with reason', true === LNH_Queue::reject( $p1, 'dup' ) && 'trash' === get_post_status( $p1 ) && 'dup' === get_post_meta( $p1, 'lnh_reject_reason', true ) );
t( 'restore returns to draft', true === LNH_Queue::restore( $p1 ) && 'draft' === get_post_status( $p1 ) );
t( 'schedule in the future', true === LNH_Queue::publish( $p1, time() + 86400 * 2 ) && 'future' === get_post_status( $p1 ) );
$contrib = wp_insert_user( array( 'user_login' => 'lnh_contrib_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'contributor' ) );
wp_set_current_user( $contrib );
$p2 = $mk( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 70 ) );
t( 'contributor cannot publish', is_wp_error( LNH_Queue::publish( $p2 ) ) && 'draft' === get_post_status( $p2 ) );
t( 'contributor cannot reject/delete others', is_wp_error( LNH_Queue::reject( $p1 ) ) && is_wp_error( LNH_Queue::delete( $p1 ) ) );
t( 'REST feed denied to contributor', ! LNH_Rest::can_review() && LNH_Rest::can_post() );
wp_set_current_user( $admin );
t( 'delete', true === LNH_Queue::delete( $p2 ) && null === get_post( $p2 ) );
$c = LNH_Queue::counts();
t( 'counts per tab', isset( $c['review'], $c['flagged'], $c['published'], $c['rejected'], $c['scheduled'] ) && $c['scheduled'] >= 1, $c );
wp_delete_user( $contrib );

echo "REST: agent creates a post -> auto-publish + notification\n";
update_option( LNH_Settings::OPTION, array_merge( LNH_Settings::all(), array( 'auto_publish' => 1, 'auto_publish_min_score' => 90, 'notify' => 1 ) ) );
$mails = array();
add_filter( 'pre_wp_mail', function ( $r, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
$post_via_rest = function ( array $meta ) {
	$req = new WP_REST_Request( 'POST', '/wp/v2/posts' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'title' => 'Rest ' . wp_rand(), 'content' => '<p>x</p>', 'status' => 'draft', 'meta' => $meta ) ) );
	$res = rest_do_request( $req );
	return $res->get_data();
};
$hi = $post_via_rest( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 95, 'lnh_trace' => '[]' ) );
$created[] = $hi['id'];
t( 'meta accepted through /wp/v2/posts', 95 === $hi['meta']['lnh_audit_score'], $hi['meta'] ?? $hi );
t( 'high score approved => auto-published', 'publish' === get_post_status( $hi['id'] ) && 0 === (int) get_post_meta( $hi['id'], 'lnh_reviewed_by', true ) );
$mails = array();
$lo = $post_via_rest( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 85 ) );
$created[] = $lo['id'];
t( 'below threshold stays draft and emails the editor', 'draft' === get_post_status( $lo['id'] ) && 1 === count( $mails ), count( $mails ) );
t( 'email links to the review screen', false !== strpos( $mails[0]['message'] ?? '', 'page=lnh-review&post=' . $lo['id'] ) );
$fl = $post_via_rest( array( 'lnh_audit_status' => 'flagged', 'lnh_audit_score' => 99 ) );
$created[] = $fl['id'];
t( 'flagged is never auto-published', 'publish' !== get_post_status( $fl['id'] ) );
$plainpost = $post_via_rest( array() );
$created[] = $plainpost['id'];
t( 'normal posts are untouched', 'draft' === get_post_status( $plainpost['id'] ) );
$bad = $post_via_rest( array( 'lnh_source_url' => 'javascript:alert(1)', 'lnh_audit_status' => '<b>approved</b>', 'lnh_audit_score' => 70 ) );
$created[] = $bad['id'];
t( 'meta sanitised on write (bad URL dropped, tags stripped)', '' === get_post_meta( $bad['id'], 'lnh_source_url', true ) && 'approved' === get_post_meta( $bad['id'], 'lnh_audit_status', true ) );
$invalid = $post_via_rest( array( 'lnh_audit_score' => 'abc' ) );
t( 'non-numeric score rejected by REST validation', isset( $invalid['code'] ) && 0 === strpos( $invalid['code'], 'rest_invalid' ), $invalid['code'] ?? 'accepted' );
update_option( LNH_Settings::OPTION, $before );

$xss = $post_via_rest( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 80 ) );
$created[] = $xss['id'];
$req = new WP_REST_Request( 'POST', '/wp/v2/posts' );
$req->set_header( 'content-type', 'application/json' );
$req->set_body( wp_json_encode( array( 'title' => 'x', 'status' => 'draft', 'content' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(2)><iframe src="//evil"></iframe>', 'meta' => array( 'lnh_audit_status' => 'approved' ) ) ) );
$xr = rest_do_request( $req )->get_data();
$created[] = $xr['id'];
$stored = get_post_field( 'post_content', $xr['id'] );
t( 'agent HTML is kses-filtered even for an admin account', false === strpos( $stored, '<script' ) && false === strpos( $stored, 'onerror' ) && false === strpos( $stored, '<iframe' ) && false !== strpos( $stored, '<p>ok</p>' ), $stored );
$req = new WP_REST_Request( 'POST', '/wp/v2/posts' );
$req->set_header( 'content-type', 'application/json' );
$req->set_body( wp_json_encode( array( 'title' => 'plain', 'status' => 'draft', 'content' => '<p>ok</p>' ) ) );
$created[] = rest_do_request( $req )->get_data()['id'];
echo "SEO sync\n";
$sp = $mk( array( 'lnh_audit_status' => 'approved', 'lnh_meta_description' => 'Meta desc' ) );
wp_update_post( array( 'ID' => $sp, 'post_title' => 'T2' ) );
t( 'meta description mirrored to Yoast/RankMath keys', 'Meta desc' === get_post_meta( $sp, '_yoast_wpseo_metadesc', true ) );

echo "Front-end decorations\n";
$fp = $mk( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 88, 'lnh_source_url' => 'https://wink.example/a', 'lnh_source_name' => 'WINK <b>News</b>', 'lnh_source_date' => '2026-10-01' ), 'publish' );
$GLOBALS['post'] = get_post( $fp );
setup_postdata( $GLOBALS['post'] );
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'p' => $fp ) );
$GLOBALS['wp_query']->the_post();
$out = apply_filters( 'the_content', '<p>Body</p>' );
t( 'source line escaped + linked', false !== strpos( $out, 'rel="nofollow noopener"' ) && false === strpos( $out, '<b>News</b>' ) );
t( 'AI disclosure with score', false !== strpos( $out, 'lnh-disclosure' ) && false !== strpos( $out, '88/100' ) );
wp_reset_postdata();

foreach ( $created as $id ) {
	wp_delete_post( $id, true );
}
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
