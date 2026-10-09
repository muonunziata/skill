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

// Any PHP warning/notice raised by the plugin's own files is a test failure.
$php_problems = array();
set_error_handler(
	function ( $no, $msg, $file, $line ) use ( &$php_problems ) {
		if ( false !== strpos( $file, 'lehigh-news-hub' ) && ! ( $no & ( E_DEPRECATED | E_USER_DEPRECATED ) ) ) {
			$php_problems[] = basename( $file ) . ":$line $msg";
		}
		return true;
	}
);

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
t( 'invalid colour/theme fall back, radius clamped, unchecked box = 0', '#1b6a55' === $s['ds_accent'] && 'auto' === $s['ds_theme'] && 40 === $s['ds_radius'] && 0 === $s['ds_shadow'], $s );
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
t( 'contributors can neither read the feed nor forge run reports', ! LNH_Rest::can_review() && ! LNH_Rest::can_post() );
wp_set_current_user( $admin );
t( 'delete', true === LNH_Queue::delete( $p2 ) && null === get_post( $p2 ) );
$c = LNH_Queue::counts();
t( 'counts per tab', isset( $c['review'], $c['flagged'], $c['published'], $c['rejected'], $c['scheduled'] ) && $c['scheduled'] >= 1, $c );
wp_delete_user( $contrib );

echo "REST: agent creates a post -> auto-publish + notification\n";
update_option( LNH_Settings::OPTION, array_merge( LNH_Settings::all(), array( 'auto_publish' => 1, 'auto_publish_min_score' => 90, 'notify' => 1 ) ) );
delete_transient( 'lnh_notify_' . gmdate( 'YmdH' ) ); // the hourly e-mail cap would otherwise make repeated runs fail
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
$contrib2 = wp_insert_user( array( 'user_login' => 'lnh_contrib2_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'contributor' ) );
wp_set_current_user( $contrib2 );
$forged = $post_via_rest( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 100 ) );
$created[] = $forged['id'];
t( 'a contributor cannot self-publish by claiming an approved audit', 'draft' === get_post_status( $forged['id'] ), get_post_status( $forged['id'] ) );
wp_set_current_user( $admin );
wp_delete_user( $contrib2 );
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
echo "Queue: scheduled articles, dates\n";
$sch = $mk( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 80 ) );
LNH_Queue::publish( $sch, time() + 86400 * 3 );
t( 'scheduled for the future', 'future' === get_post_status( $sch ) );
t( '"Publish now" on a scheduled article really publishes it', true === LNH_Queue::publish( $sch ) && 'publish' === get_post_status( $sch ), get_post_status( $sch ) );
$fd = $mk( array( 'lnh_audit_status' => 'approved' ) );
t( 'draft timestamp falls back to the local date (drafts have no GMT date)', abs( LNH_Util::post_timestamp( get_post( $fd ) ) - time() ) < 300, LNH_Util::post_timestamp( get_post( $fd ) ) );
t( 'a future date is never shown as "ago"', false === strpos( LNH_Util::ago( time() + 2 * DAY_IN_SECONDS ), 'ago' ) );
t( 'words() never cuts a multibyte letter', mb_check_encoding( LNH_Util::words( 'El pozo se ABRIÓ ayer', 4 ), 'UTF-8' ) && 'El pozo se ABRIÓ…' === LNH_Util::words( 'El pozo se ABRIÓ ayer', 4 ) );
echo "Public REST output\n";
$pub = $mk( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 90, 'lnh_audit_notes' => '["secret note"]', 'lnh_trace' => '[]' ), 'publish' );
wp_set_current_user( 0 );
$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $pub ) )->get_data();
t( 'visitors do not see lnh_* meta on published agent articles', 200 === ( $res ? 200 : 0 ) && ! isset( $res['meta']['lnh_audit_notes'] ) && ! isset( $res['meta']['lnh_trace'] ) && ! isset( $res['meta']['lnh_audit_score'] ), $res['meta'] ?? $res );
wp_set_current_user( $admin );
$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $pub ) )->get_data();
t( 'editors still see it', 90 === ( $res['meta']['lnh_audit_score'] ?? null ) );
t( 'to_string neutralises quotes and brackets', '[lehigh_news heading="Top (news) ”x”"]' === LNH_Shortcode::to_string( array( 'heading' => 'Top [news] "x"' ) ), LNH_Shortcode::to_string( array( 'heading' => 'Top [news] "x"' ) ) );
echo "SEO sync\n";
$sp = $mk( array( 'lnh_audit_status' => 'approved', 'lnh_meta_description' => 'Meta desc' ) );
wp_update_post( array( 'ID' => $sp, 'post_title' => 'T2' ) );
t( 'meta description mirrored to Yoast/RankMath keys', 'Meta desc' === get_post_meta( $sp, '_yoast_wpseo_metadesc', true ) );

echo "Pages created on activation\n";
$cleanup_pages = function () {
	foreach ( LNH_Pages::ids() as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => LNH_Pages::META, 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
		wp_delete_post( $id, true );
	}
	delete_option( LNH_Pages::OPTION );
	delete_option( LNH_Pages::WELCOME );
};
$cleanup_pages();
$orig_default_cat = LNH_Settings::get( 'default_category' );
$r1 = LNH_Pages::ensure_all();
t( 'first activation creates the 3 pages', 3 === count( $r1['created'] ) && array( 'how-we-work', 'corrections', 'news' ) === $r1['created'], $r1 );
$ids = LNH_Pages::ids();
t( 'pages are published pages with our key', 3 === count( array_filter( $ids, function ( $id ) { return 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) && '' !== get_post_meta( $id, LNH_Pages::META, true ); } ) ) );
$news = get_post( $ids['news'] );
t( 'news page uses the listing shortcode and links to how-we-work', false !== strpos( $news->post_content, '[lehigh_news layout="featured"' ) && false !== strpos( $news->post_content, get_permalink( $ids['how-we-work'] ) ), $news->post_content );
t( 'no unresolved placeholders anywhere', 0 === count( array_filter( $ids, function ( $id ) { return (bool) preg_match( '/\{(how|corrections)_url\}/', get_post( $id )->post_content ); } ) ) );
$html = do_shortcode( $news->post_content );
t( 'news page renders through the shortcode', false !== strpos( $html, 'class="lnh ' ) );
t( 'a News category exists and is the default for agent posts', (int) LNH_Settings::get( 'default_category' ) > 0 && term_exists( (int) LNH_Settings::get( 'default_category' ), 'category' ) );
$r2 = LNH_Pages::ensure_all();
t( 'reactivation creates nothing (idempotent)', array() === $r2['created'] && 3 === count( $r2['existing'] ) && LNH_Pages::ids() === $ids );
wp_update_post( array( 'ID' => $ids['news'], 'post_content' => 'MY OWN EDIT' ) );
LNH_Pages::ensure_all();
t( 'user edits are never overwritten', 'MY OWN EDIT' === get_post( $ids['news'] )->post_content );
wp_trash_post( $ids['news'] );
$r3 = LNH_Pages::ensure_all();
t( 'a deliberately trashed page is not resurrected on activation', 'trash' === get_post_status( $ids['news'] ) && array() === $r3['created'] );
$st = LNH_Pages::status();
t( 'status reports the trashed page', 'trash' === $st['news']['state'] && '' === $st['news']['view'] );
$r4 = LNH_Pages::ensure_all( true );
t( 'explicit "create missing" restores it without duplicating', array( 'news' ) === $r4['restored'] && 'publish' === get_post_status( $ids['news'] ) && LNH_Pages::ids() === $ids );
delete_option( LNH_Pages::OPTION );
$r5 = LNH_Pages::ensure_all();
t( 'lost option: pages are re-adopted by key, not duplicated', array() === $r5['created'] && LNH_Pages::ids() === $ids );
wp_delete_post( $ids['corrections'], true );
$r6 = LNH_Pages::ensure_all();
t( 'a hard-deleted page is recreated', array( 'corrections' ) === $r6['created'] );
update_option( LNH_Settings::OPTION, array_merge( LNH_Settings::all(), array( 'public_contact_email' => '' ) ) );
t( 'contact shortcode without email shows no address', false === strpos( do_shortcode( '[lehigh_news_contact]' ), 'mailto' ) );
update_option( LNH_Settings::OPTION, array_merge( LNH_Settings::all(), array( 'public_contact_email' => 'desk@example.com' ) ) );
$c = do_shortcode( '[lehigh_news_contact]' );
t( 'contact shortcode shows an obfuscated mailto', false !== strpos( $c, 'mailto:' ) && false === strpos( $c, 'desk@example.com' ) );
t( 'welcome notice flag is set by on_activate', ( function () use ( $cleanup_pages ) { $cleanup_pages(); LNH_Pages::on_activate(); return array( 'how-we-work', 'corrections', 'news' ) === get_option( LNH_Pages::WELCOME ) && (bool) get_transient( 'lnh_activation_redirect' ); } )() );
delete_transient( 'lnh_activation_redirect' );
// Spanish site language -> Spanish pages
$cleanup_pages();
add_filter( 'locale', $es_filter = function () { return 'es_ES'; } );
$ids_es = array();
LNH_Pages::ensure_all();
$ids_es = LNH_Pages::ids();
t( 'Spanish locale creates Spanish pages', 'Noticias de Lehigh Acres' === get_the_title( $ids_es['news'] ) && 'noticias' === get_post( $ids_es['news'] )->post_name && false !== strpos( get_post( $ids_es['how-we-work'] )->post_content, 'Nuestra redacción, paso a paso' ) );
remove_filter( 'locale', $es_filter );
$cleanup_pages();
foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'name' => array( 'News', 'Noticias' ) ) ) as $term ) {
	if ( (int) $term->term_id !== (int) get_option( 'default_category' ) ) {
		wp_delete_term( $term->term_id, 'category' );
	}
}
update_option( LNH_Settings::OPTION, array_merge( LNH_Settings::all(), array( 'public_contact_email' => '', 'default_category' => $orig_default_cat ) ) );

echo "One-click agent credentials\n";
if ( $old_agent = LNH_Connect::agent_user() ) { // leftovers from a previous manual run
	foreach ( WP_Application_Passwords::get_user_application_passwords( $old_agent->ID ) as $item ) {
		WP_Application_Passwords::delete_application_password( $old_agent->ID, $item['uuid'] );
	}
	wp_delete_user( $old_agent->ID );
}
$pre_users = (int) ( new WP_User_Query( array( 'count_total' => true, 'fields' => 'ID', 'number' => 1 ) ) )->get_total();
$g = LNH_Connect::generate();
t( 'generate() succeeds for an administrator', is_array( $g ), $g instanceof WP_Error ? $g->get_error_message() : '' );
$u = LNH_Connect::agent_user();
t( 'creates a dedicated Author account flagged as the agents\' user', $u && in_array( 'author', $u->roles, true ) && 'lehigh-agents' === $u->user_login );
t( 'the .env block has the REST root and a quoted token', false !== strpos( $g['env'], 'WP_REST_URL=' . untrailingslashit( rest_url() ) ) && 1 === preg_match( '/^WP_AUTH_TOKEN="lehigh-agents:[A-Za-z0-9 ]{20,}"$/m', $g['env'] ), $g['env'] );
add_filter( 'application_password_is_api_request', '__return_true' );
$authed = wp_authenticate_application_password( null, $g['user'], $g['password'] );
remove_filter( 'application_password_is_api_request', '__return_true' );
t( 'the generated password authenticates', $authed instanceof WP_User && (int) $authed->ID === (int) $u->ID );
t( 'the plain password is not stored anywhere', 0 === count( array_filter( WP_Application_Passwords::get_user_application_passwords( $u->ID ), function ( $p ) use ( $g ) { return false !== strpos( wp_json_encode( $p ), str_replace( ' ', '', $g['password'] ) ); } ) ) );
$g2 = LNH_Connect::generate();
$users_now = (int) ( new WP_User_Query( array( 'count_total' => true, 'fields' => 'ID', 'number' => 1 ) ) )->get_total();
t( 'second call reuses the account and adds another password', is_array( $g2 ) && false === $g2['created_user'] && 2 === count( WP_Application_Passwords::get_user_application_passwords( $u->ID ) ) && $users_now === $pre_users + 1, array( is_array( $g2 ) ? $g2['created_user'] : $g2->get_error_message(), count( WP_Application_Passwords::get_user_application_passwords( $u->ID ) ), $users_now, $pre_users ) );
$ap_user = $u;
add_filter( 'wp_is_application_passwords_available', '__return_false', 99 );
$g3 = LNH_Connect::generate();
t( 'no Application Passwords (HTTP / security plugin): falls back to the plugin\'s own connection key', is_array( $g3 ) && 'key' === $g3['mode'] && 0 === strpos( $g3['password'], 'lnh_' ) && false !== strpos( $g3['env'], 'WP_AUTH_TOKEN=lnh_' ), $g3 );
t( 'the key is stored only as a hash', hash( 'sha256', $g3['password'] ) === get_user_meta( $u->ID, LNH_Connect::KEY_META, true ) && '' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->usermeta} WHERE meta_value = %s", $g3['password'] ) ) );
$server_backup = $_SERVER;
$as = function ( array $server ) use ( $server_backup ) {
	$_SERVER = array_merge( $server_backup, array( 'REQUEST_URI' => '/wp-json/lnh/v1/ping' ) );
	unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_LEHIGH_KEY'] );
	foreach ( $server as $k => $v ) {
		$_SERVER[ $k ] = $v;
	}
	$r = LNH_Connect::authenticate_key( false );
	$_SERVER = $server_backup;
	return $r;
};
t( 'key: Authorization Bearer authenticates as the agents\' account on REST requests', (int) $u->ID === $as( array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $g3['password'] ) ) );
t( 'key: the X-Lehigh-Key header works for hosts that strip Authorization', (int) $u->ID === $as( array( 'HTTP_X_LEHIGH_KEY' => $g3['password'] ) ) );
t( 'key: a wrong or malformed key is refused', false === $as( array( 'HTTP_AUTHORIZATION' => 'Bearer lnh_' . str_repeat( 'a', 40 ) ) ) && false === $as( array( 'HTTP_AUTHORIZATION' => 'Bearer nope' ) ) && false === $as( array( 'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode( 'a:b' ) ) ) );
t( 'key: only on the REST API, never on normal pages or wp-admin', false === $as( array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $g3['password'], 'REQUEST_URI' => '/wp-admin/options.php' ) ) );
t( 'key: an already authenticated user is left alone', 7 === LNH_Connect::authenticate_key( 7 ) );
$g4 = LNH_Connect::generate();
t( 'generating a new key revokes the previous one', false === $as( array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $g3['password'] ) ) && (int) $u->ID === $as( array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $g4['password'] ) ) );
if ( LNH_Package::available() ) {
	$pk = LNH_Package::build( 'AIzaTestKeyTestKeyTestKey1', false );
	$ze = '';
	if ( is_array( $pk ) ) {
		$zz = new ZipArchive();
		$zz->open( $pk['path'] );
		$ze = (string) $zz->getFromName( 'golehighacres-agents/.env' );
		$zz->close();
		wp_delete_file( $pk['path'] );
	}
	t( 'the downloadable package also works without Application Passwords', 1 === preg_match( '/^WP_AUTH_TOKEN=lnh_[A-Za-z0-9]{40}$/m', $ze ), is_wp_error( $pk ) ? $pk->get_error_message() : $ze );
}
delete_user_meta( $u->ID, LNH_Connect::KEY_META );
t( 'removing the key (or the account) disables it', false === $as( array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $g4['password'] ) ) );
remove_filter( 'wp_is_application_passwords_available', '__return_false', 99 );
$contrib2 = wp_insert_user( array( 'user_login' => 'lnh_c2_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
wp_set_current_user( $contrib2 );
t( 'non-administrators cannot generate credentials', is_wp_error( LNH_Connect::generate() ) && 'lnh_forbidden' === LNH_Connect::generate()->get_error_code() );
wp_set_current_user( $admin );
$agent_post = wp_insert_post( array( 'post_title' => 'x', 'post_status' => 'draft', 'post_author' => $u->ID ) );
wp_set_current_user( $u->ID );
t( 'the agents\' account can create drafts and post run reports', current_user_can( 'edit_posts' ) && LNH_Rest::can_post() && ! LNH_Rest::can_review() );
wp_set_current_user( $admin );
wp_delete_post( $agent_post, true );
foreach ( WP_Application_Passwords::get_user_application_passwords( $u->ID ) as $item ) {
	WP_Application_Passwords::delete_application_password( $u->ID, $item['uuid'] );
}
wp_delete_user( $u->ID );
wp_delete_user( $contrib2 );
t( 'cleanup leaves no agents account', null === LNH_Connect::agent_user() );

echo "Start / pause control\n";
delete_option( LNH_Control::STATE_OPTION );
delete_option( LNH_Control::WORKER_OPTION );
t( 'safe default: paused until someone presses Start', 'paused' === LNH_Control::desired()['state'] && ! LNH_Control::is_running() && 'paused' === LNH_Control::status()['phase'] );
t( 'unknown actions are refused', false === LNH_Control::apply( 'explode' ) && 'paused' === LNH_Control::desired()['state'] );
LNH_Control::apply( 'start', $admin );
t( 'Start: running, but nobody listening yet', LNH_Control::is_running() && 'offline' === LNH_Control::status()['phase'] && $admin === LNH_Control::desired()['by'] );
$d = LNH_Control::sync( array( 'status' => 'idle', 'host' => 'laptop-<b>1</b>', 'version' => '1.4.0', 'message' => str_repeat( 'x', 500 ), 'next_run_at' => time() + 600 ) );
t( 'sync returns the desired state and the interval', 'running' === $d['state'] && $d['interval_minutes'] >= 5 && $d['server_time'] >= time() - 5, $d );
$st = LNH_Control::status();
t( 'a reporting worker means connected + waiting for the next cycle', true === $st['worker']['connected'] && 'waiting' === $st['phase'] && '' !== $st['next_in'], $st );
t( 'worker text is clipped and stripped of HTML', 'laptop-1' === $st['worker']['host'] && mb_strlen( $st['worker']['message'] ) <= 200 );
LNH_Control::sync( array( 'status' => 'working', 'message' => 'Auditor: revisando enlaces' ) );
t( 'working phase shows what the agents are doing', 'working' === LNH_Control::status()['phase'] && 'Auditor: revisando enlaces' === LNH_Control::status()['worker']['message'] );
LNH_Control::sync( array( 'status' => 'working', 'agent' => 'redactor', 'message' => 'Escribiendo' ) );
t( 'the busy agent is known (whitelisted)', 'redactor' === LNH_Control::worker()['agent'] );
LNH_Control::sync( array( 'status' => 'working', 'agent' => '<script>', 'message' => 'x' ) );
t( 'an unknown agent name is dropped', '' === LNH_Control::worker()['agent'] );
LNH_Control::sync( array( 'status' => 'working', 'agent' => 'auditor', 'message' => 'Revisando enlaces' ) );
ob_start();
LNH_Admin::view( 'control', array( 'control' => LNH_Control::status(), 'back' => '' ) );
$anim = ob_get_clean();
t( 'animation: the auditor is active, the earlier agents are ticked, the later one is dim',
	1 === preg_match( '/lnh-node is-done" data-i="0"/', $anim ) && 1 === preg_match( '/lnh-node is-done" data-i="1"/', $anim ) && 1 === preg_match( '/lnh-node is-active" data-i="2"/', $anim ) && 1 === preg_match( '/lnh-node" data-i="3"/', $anim ) && 1 === preg_match( '/lnh-anim__link is-flow" data-i="2"/', $anim ) && false !== strpos( $anim, 'data-active="2"' ), $anim );
t( 'the animation is decorative (hidden from screen readers)', false !== strpos( $anim, 'data-lnh-anim aria-hidden="true"' ) );
LNH_Control::apply( 'pause', $admin );
t( 'Pause while working = pausing after the current article', 'pausing' === LNH_Control::status()['phase'] && 'paused' === LNH_Control::sync( array( 'status' => 'working' ) )['state'] );
LNH_Control::sync( array( 'status' => 'paused' ) );
t( 'Pause once the worker is idle = paused', 'paused' === LNH_Control::status()['phase'] );
t( 'health says paused, not "late"', 'paused' === LNH_Admin::health()['state'] );
LNH_Control::apply( 'run_now', $admin );
$req = LNH_Control::desired();
t( 'Run now starts the agents and queues exactly one request', 'running' === $req['state'] && $req['run_now'] > 0 && true === LNH_Control::status()['run_queued'] );
t( 'an older confirmation does not clear a newer request', $req['run_now'] === LNH_Control::sync( array( 'status' => 'idle', 'handled_run_now' => $req['run_now'] - 5 ) )['run_now'] );
t( 'the worker confirming the request clears it', 0 === LNH_Control::sync( array( 'status' => 'working', 'handled_run_now' => $req['run_now'] ) )['run_now'] && false === LNH_Control::status()['run_queued'] );
LNH_Control::sync( array( 'status' => 'stopped' ) );
t( 'a stopped worker is shown as not connected at once', false === LNH_Control::worker()['connected'] && 'offline' === LNH_Control::status()['phase'] );
LNH_Control::sync( array( 'status' => 'idle' ) );
$w = get_option( LNH_Control::WORKER_OPTION );
$w['last_seen'] = time() - 600;
update_option( LNH_Control::WORKER_OPTION, $w );
t( 'a silent worker times out', false === LNH_Control::worker()['connected'] && true === LNH_Control::worker()['seen'] );
LNH_Control::sync( array( 'status' => 'idle' ) );
t( 'the "run every" setting reaches the agents', LNH_Control::interval_minutes() === LNH_Control::sync( array() )['interval_minutes'] );

$mk_user = function ( string $role ) {
	$id = wp_insert_user( array( 'user_login' => 'lnh_ctl_' . $role . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => $role, 'user_email' => $role . wp_rand() . '@example.test' ) );
	return $id;
};
$u_author = $mk_user( 'author' );
$u_editor = $mk_user( 'editor' );
$u_contrib = $mk_user( 'contributor' );
$rest = function ( string $method, string $route, array $body, int $uid ) {
	wp_set_current_user( $uid );
	$r = new WP_REST_Request( $method, $route );
	$r->set_header( 'content-type', 'application/json' );
	$r->set_body( wp_json_encode( $body ) );
	return rest_do_request( $r );
};
t( 'REST: the agents (Author) can sync', 200 === $rest( 'POST', '/lnh/v1/control/sync', array( 'status' => 'idle' ), $u_author )->get_status() );
t( 'REST: a contributor cannot spoof the worker', 403 === $rest( 'POST', '/lnh/v1/control/sync', array( 'status' => 'idle' ), $u_contrib )->get_status() );
t( 'REST: anonymous cannot sync', in_array( $rest( 'POST', '/lnh/v1/control/sync', array(), 0 )->get_status(), array( 401, 403 ), true ) );
t( 'REST: the agents\' account cannot press the buttons', 403 === $rest( 'POST', '/lnh/v1/control', array( 'action' => 'start' ), $u_author )->get_status() );
$res = $rest( 'POST', '/lnh/v1/control', array( 'action' => 'start' ), $u_editor );
t( 'REST: an editor can start the agents', 200 === $res->get_status() && 'running' === $res->get_data()['state'] );
t( 'REST: bad action is a 400', 400 === $rest( 'POST', '/lnh/v1/control', array( 'action' => 'nope' ), $u_editor )->get_status() );
t( 'REST: status for editors', 200 === $rest( 'GET', '/lnh/v1/control', array(), $u_editor )->get_status() && 403 === $rest( 'GET', '/lnh/v1/control', array(), $u_author )->get_status() );
wp_set_current_user( $admin );
foreach ( array( $u_author, $u_editor, $u_contrib ) as $uid ) {
	wp_delete_user( $uid );
}
t( 'the admin-post handler is registered', false !== has_action( 'admin_post_lnh_control' ) );

LNH_Control::apply( 'pause', $admin );
delete_option( LNH_Control::WORKER_OPTION );
$render = function () use ( $admin ) {
	wp_set_current_user( $admin );
	ob_start();
	LNH_Admin::view( 'control', array( 'control' => LNH_Control::status(), 'back' => admin_url( 'admin.php?page=lnh' ) ) );
	return ob_get_clean();
};
$html = $render();
t( 'paused view: Start button, no Pause, set-up link, nonce', false !== strpos( $html, 'data-lnh-do="start"' ) && false === strpos( $html, 'data-lnh-do="pause"' ) && false !== strpos( $html, 'page=lnh-connect' ) && false !== strpos( $html, 'INICIAR' ) && false !== strpos( $html, '_wpnonce' ) );
LNH_Control::apply( 'start', $admin );
LNH_Control::sync( array( 'status' => 'idle', 'host' => 'srv1' ) );
$html = $render();
t( 'running view: Pause + Run now, connected line, no setup help', false !== strpos( $html, 'data-lnh-do="pause"' ) && false !== strpos( $html, 'data-lnh-do="run_now"' ) && false !== strpos( $html, 'Agents connected' ) && false === strpos( $html, 'INICIAR' ), $html );
wp_set_current_user( $mk_user( 'subscriber' ) );
ob_start();
LNH_Admin::view( 'control', array( 'control' => LNH_Control::status(), 'back' => '' ) );
t( 'people who cannot review do not even see the buttons', '' === ob_get_clean() );
wp_set_current_user( $admin );
delete_option( LNH_Control::STATE_OPTION );
delete_option( LNH_Control::WORKER_OPTION );

echo "Download my agents (package)\n";
$bundle = LNH_Package::bundle_dir();
$had_bundle = is_dir( $bundle );
t( 'without the bundled agents the feature reports itself unavailable', $had_bundle || false === LNH_Package::available() );
if ( ! $had_bundle ) {
	mkdir( $bundle . '/lehigh_agents', 0777, true );
	mkdir( $bundle . '/tests', 0777, true );
	mkdir( $bundle . '/state', 0777, true );
	file_put_contents( $bundle . '/main.py', "print('hi')\n" );
	file_put_contents( $bundle . '/lehigh_agents/__init__.py', "" );
	file_put_contents( $bundle . '/tests/test_x.py', "x" );
	file_put_contents( $bundle . '/state/db', "x" );
	file_put_contents( $bundle . '/.env', "GEMINI_API_KEY=LEAKED-SECRET\n" );
	file_put_contents( $bundle . '/install.sh', "#!/bin/sh\n" );
	file_put_contents( $bundle . '/INICIAR.command', "#!/bin/sh\n" );
	file_put_contents( $bundle . '/INICIAR.bat', "@echo off\n" );
	file_put_contents( $bundle . '/.env.example', "# keys\nGEMINI_API_KEY=\nWP_REST_URL=https://your-site.com/wp-json\nWP_AUTH_TOKEN=\nREDACCTOR_MODEL=gemini-1.5-flash\nIMAGE_PROVIDER=replicate\nSOCIAL_ENABLED=true\n" );
}
t( 'available once the agents are bundled (zip extension present)', class_exists( 'ZipArchive' ) ? LNH_Package::available() : true );
$env = LNH_Package::build_env( "# c\nA=1\nB=old\n", array( 'B' => 'new value', 'C' => 'x"y', 'D' => '' ) );
t( 'env: replaces, quotes only when needed, appends missing, keeps the rest', "# c\nA=1\nB=\"new value\"\nC=\"x\\\"y\"\nD=\n" === $env, $env );
$pre = function ( $code ) {
	return function () use ( $code ) {
		return is_wp_error( $code ) ? $code : array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => $code, 'message' => '' ) );
	};
};
add_filter( 'pre_http_request', $pre( 200 ), 10, 3 );
$ok_key = LNH_Package::key_is_accepted( 'AIzaGoodKeyGoodKeyGoodKey' );
remove_all_filters( 'pre_http_request' );
add_filter( 'pre_http_request', $pre( 400 ), 10, 3 );
$bad_key = LNH_Package::key_is_accepted( 'AIzaBadKeyBadKeyBadKey1' );
remove_all_filters( 'pre_http_request' );
add_filter( 'pre_http_request', $pre( new WP_Error( 'x', 'offline' ) ), 10, 3 );
$unk_key = LNH_Package::key_is_accepted( 'AIzaWhoKnowsWhoKnows1' );
remove_all_filters( 'pre_http_request' );
t( 'Gemini key check: accepted / rejected / unknown (offline never blocks)', true === $ok_key && false === $bad_key && null === $unk_key );
if ( class_exists( 'ZipArchive' ) ) {
	$built = LNH_Package::build( 'AIzaTestKeyTestKeyTestKey1', true );
	t( 'the package is built', is_array( $built ) && is_file( $built['path'] ), is_wp_error( $built ) ? $built->get_error_message() : '' );
	$z = new ZipArchive();
	$z->open( $built['path'] );
	$names = array();
	for ( $i = 0; $i < $z->numFiles; $i++ ) {
		$names[] = $z->getNameIndex( $i );
	}
	$envz = (string) $z->getFromName( 'golehighacres-agents/.env' );
	t( 'zip: agents + launchers + readme + generated .env, in one folder', in_array( 'golehighacres-agents/main.py', $names, true ) && in_array( 'golehighacres-agents/lehigh_agents/__init__.py', $names, true ) && in_array( 'golehighacres-agents/INICIAR.bat', $names, true ) && in_array( 'golehighacres-agents/LEEME.txt', $names, true ), $names );
	t( 'zip: tests, state and the bundle\'s own .env are left out', ! preg_grep( '#/(tests|state)/#', $names ) && false === strpos( $envz, 'LEAKED-SECRET' ) );
	t( '.env: site, token, key, working models and language are filled in', false !== strpos( $envz, 'WP_REST_URL=' . untrailingslashit( rest_url() ) ) && 1 === preg_match( '/^WP_AUTH_TOKEN="lehigh-agents[\w-]*:[A-Za-z0-9 ]+"$/m', $envz ) && false !== strpos( $envz, 'GEMINI_API_KEY=AIzaTestKeyTestKeyTestKey1' ) && 3 === preg_match_all( '/^(RASTREADOR|REDACCTOR|AUDITOR)_MODEL=gemini-2\.5-flash$/m', $envz ) && false !== strpos( $envz, 'SOCIAL_ENABLED=true' ), $envz );
	t( '.env: AI images only when asked', false !== strpos( $envz, 'IMAGE_PROVIDER=gemini' ) && false !== strpos( $envz, 'IMAGE_MODEL=gemini-2.5-flash-image' ) );
	t( 'zip: launchers keep their executable bit, .env is private', ( $z->getExternalAttributesName( 'golehighacres-agents/INICIAR.command', $o, $a ) ? ( ( $a >> 16 ) & 0111 ) > 0 : false ) && ( $z->getExternalAttributesName( 'golehighacres-agents/.env', $o, $a2 ) ? ( ( $a2 >> 16 ) & 0077 ) === 0 : false ) );
	$z->close();
	preg_match( '/^WP_AUTH_TOKEN="([^:]+):([^"]+)"/m', $envz, $mm );
	add_filter( 'application_password_is_api_request', '__return_true' );
	$authed = wp_authenticate_application_password( null, $mm[1] ?? '', $mm[2] ?? '' );
	remove_filter( 'application_password_is_api_request', '__return_true' );
	t( 'the token inside the package really authenticates as the agents\' account', $authed instanceof WP_User && LNH_Connect::agent_user() && (int) $authed->ID === (int) LNH_Connect::agent_user()->ID );
	wp_delete_file( $built['path'] );
	wp_set_current_user( $mk_user( 'editor' ) );
	t( 'only administrators can build it', is_wp_error( LNH_Package::build( 'AIzaTestKeyTestKeyTestKey1', false ) ) );
	wp_set_current_user( $admin );
	$conn = LNH_Connect::agent_user();
	if ( $conn ) {
		foreach ( WP_Application_Passwords::get_user_application_passwords( $conn->ID ) as $item ) {
			WP_Application_Passwords::delete_application_password( $conn->ID, $item['uuid'] );
		}
	}
}
ob_start();
LNH_Admin::view( 'connect', array( 'package' => true, 'result' => null, 'https' => false, 'existing' => null ) );
$conn_html = ob_get_clean();
t( 'connect screen: 3 easy steps with the key field, advanced credentials tucked away', false !== strpos( $conn_html, 'name="gemini_key"' ) && false !== strpos( $conn_html, 'value="lnh_agents_package"' ) && false !== strpos( $conn_html, 'INICIAR' ) && false !== strpos( $conn_html, '<details class="lnh-card-box lnh-advanced">' ) && false !== strpos( $conn_html, 'connection key' ) );
ob_start();
LNH_Admin::view( 'connect', array( 'package' => false, 'result' => null, 'https' => true, 'existing' => null ) );
t( 'connect screen without the bundle: no download form, manual steps remain', false === strpos( ob_get_clean(), 'name="gemini_key"' ) );
t( 'the package handler is registered and its notices exist', false !== has_action( 'admin_post_lnh_agents_package' ) );
if ( ! $had_bundle ) {
	$rm = function ( $d ) use ( &$rm ) {
		foreach ( array_diff( scandir( $d ), array( '.', '..' ) ) as $f ) {
			is_dir( "$d/$f" ) ? $rm( "$d/$f" ) : unlink( "$d/$f" );
		}
		rmdir( $d );
	};
	$rm( $bundle );
}

echo "Social kit (Agent 4)\n";
$kit_in = array(
	'generated_at' => 1790000000, 'model' => 'gemini-2.5-flash', 'voice' => '', 'seconds' => 14.04, 'ai_image' => true, 'hook' => 'Cuatro carriles <script>alert(1)</script>',
	'instagram' => array( 'caption' => "Línea uno\n\nLínea dos <b>x</b>", 'slides' => array(
		array( 'id' => 12, 'url' => 'https://site.example/wp-content/uploads/a.png', 'name' => 'instagram-01.png' ),
		array( 'id' => 13, 'url' => 'javascript:alert(1)', 'name' => 'evil.png' ),
	) ),
	'tiktok' => array( 'caption' => 'tt', 'slides' => array( array( 'id' => 14, 'url' => 'https://site.example/t.png', 'name' => 'tiktok-01.png' ) ) ),
	'video' => array( 'id' => 15, 'url' => 'https://site.example/v.mp4', 'name' => 'video.mp4' ),
	'hashtags' => array( '#LehighAcres', 'Go Lehigh', '<i>x</i>', '' ), 'alt_text' => 'Portada', 'warnings' => array( 'Sin voz <u>x</u>' ),
);
$sp1 = $post_via_rest( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 50, 'lnh_social' => wp_json_encode( $kit_in ) ) );
$created[] = $sp1['id'];
$kit = LNH_Meta::social( $sp1['id'] );
t( 'kit arrives through REST and is stored as normalised JSON', 'Cuatro carriles alert(1)' === $kit['hook'] || false === strpos( $kit['hook'], '<script' ), $kit['hook'] ?? '' );
t( 'javascript: URLs are dropped from slides', 1 === count( $kit['instagram']['slides'] ) && 0 === strpos( $kit['instagram']['slides'][0]['url'], 'https://' ), $kit['instagram']['slides'] );
t( 'hashtags are normalised', array( '#LehighAcres', '#GoLehigh', '#ixi' ) === $kit['hashtags'], $kit['hashtags'] );
t( 'tags are stripped from captions and warnings', false === strpos( $kit['instagram']['caption'], '<b>' ) && false === strpos( $kit['warnings'][0], '<u>' ) && false !== strpos( $kit['instagram']['caption'], "\n\n" ) );
t( 'video and flags survive', 'video.mp4' === $kit['video']['name'] && true === $kit['ai_image'] && 14.0 === $kit['seconds'], $kit );
t( 'garbage is stored as empty, never as a kit', '' === LNH_Meta::sanitize_social( 'not json' ) && array() === LNH_Meta::normalize_social( array( 'hook' => 'x' ) ) );
$anon = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $sp1['id'] ) );
wp_set_current_user( 0 );
$vis = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $sp1['id'] ) )->get_data();
wp_set_current_user( $admin );
t( 'visitors never see the kit meta', ! isset( $vis['meta']['lnh_social'] ) );
$spost = get_post( $sp1['id'] );
ob_start();
LNH_Admin::view( 'social-kit', array( 'id' => $spost->ID ) );
$html = ob_get_clean();
t( 'review card shows slides, video, caption and download links', false !== strpos( $html, 'lnh-slides--tiktok' ) && false !== strpos( $html, '<video' ) && false !== strpos( $html, 'download="instagram-01.png"' ) && false !== strpos( $html, '#GoLehigh' ) );
t( 'review card is escaped', false === strpos( $html, '<script' ) && false === strpos( $html, 'javascript:' ) );
$none = $mk( array( 'lnh_audit_status' => 'approved', 'lnh_audit_score' => 60 ) );
ob_start();
LNH_Admin::view( 'social-kit', array( 'id' => $none ) );
$empty = ob_get_clean();
t( 'no kit: the card explains how to request one', false !== strpos( $empty, 'python main.py social --post ' . $none ) && false === strpos( $empty, '<video' ) );
$agents = LNH_Admin::agents();
t( 'the social designer is a known agent', isset( $agents['social'] ) && isset( LNH_Admin::event_labels()['done'] ) && in_array( 'social', LNH_Runs::AGENTS, true ) );
$run = LNH_Runs::sanitize( array(
	'run_id' => 'social-test-1', 'started_at' => time(), 'finished_at' => time(), 'status' => 'ok', 'models' => array( 'social' => 'gemini-2.5-flash' ),
	'events' => array(
		array( 'ts' => time(), 'agent' => 'social', 'type' => 'render', 'message' => '6 slides', 'data' => array( 'count' => 6 ) ),
		array( 'ts' => time(), 'agent' => 'social', 'type' => 'video', 'message' => 'video', 'data' => array( 'seconds' => 14 ) ),
		array( 'ts' => time(), 'agent' => 'social', 'type' => 'done', 'message' => 'ready' ),
	),
) );
t( 'social events keep their agent and numeric data', 'social' === $run['events'][0]['agent'] && 6 === $run['events'][0]['data']['count'] && 14 === $run['events'][1]['data']['seconds'], $run['events'] );
$rid = LNH_Runs::store( $run );
$st  = LNH_Runs::agent_stats( 7 );
t( 'agent stats count kits, slides and videos', $st['social']['kits'] >= 1 && $st['social']['slides'] >= 6 && $st['social']['videos'] >= 1 && 'gemini-2.5-flash' === $st['social']['model'], $st['social'] );
wp_delete_post( $rid, true );
ob_start();
LNH_Admin::view( 'dashboard', array( 'control' => LNH_Control::status(), 'welcome' => array(), 'pages' => array(), 'counts' => array( 'review' => 0, 'flagged' => 0 ), 'health' => LNH_Admin::health(), 'stats' => LNH_Runs::agent_stats( 7 ), 'waiting' => array(), 'runs' => array(), 'published7' => 0, 'avg' => 0, 'rest_url' => rest_url(), 'profile' => '' ) );
$dash = ob_get_clean();
t( 'dashboard lists the 4th agent and its KPI', false !== strpos( $dash, 'Social designer' ) && false !== strpos( $dash, 'Social kits this week' ) );
t( 'brand: default accent is the GoLehighAcres.org green', '#1b6a55' === LNH_Settings::defaults()['ds_accent'] );
t( 'brand logo ships with the plugin', is_file( LNH_DIR . 'assets/img/golehighacres-logo.png' ) );

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
delete_transient( 'lnh_notify_' . gmdate( 'YmdH' ) );
t( 'no PHP warnings or notices from the plugin', array() === $php_problems, $php_problems );
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
