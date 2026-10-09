<?php
// Minimal WordPress stand-ins: enough to run VeloxForge's real widget registry, style generator and renderer.
define( 'ABSPATH', '/x/' );
$VF = getenv( 'VF_SRC' ); $GLH = getenv( 'GLH_SRC' ); $OUT = getenv( 'OUT_DIR' );
$GLOBALS['hooks'] = array(); $GLOBALS['enq'] = array();
function add_action( $h, $cb, $p = 10 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function do_action( $h, ...$a ) { foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $cb ) { call_user_func( $cb, ...$a ); } }
function apply_filters( $h, $v, ...$a ) { foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $cb ) { $v = call_user_func( $cb, $v, ...$a ); } return $v; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', $c ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u, $p = null ) { $u = trim( (string) $u ); if ( $u === '' ) return ''; if ( $u[0] === '#' || $u[0] === '/' ) return htmlspecialchars( $u ); return preg_match( '#^(https?|tel|mailto):#i', $u ) ? htmlspecialchars( $u ) : ''; }
function esc_url_raw( $u, $p = null ) { return esc_url( $u ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function wp_get_attachment_image_url( $id, $s = 'large' ) { return false; }
function wp_enqueue_style( ...$a ) { $GLOBALS['enq'][] = array( 'style', $a[0] ); }
function wp_enqueue_script( ...$a ) { $GLOBALS['enq'][] = array( 'script', $a[0] ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }
function register_activation_hook( ...$a ) {}
function wp_kses_post( $s ) { return $s; }
function sanitize_textarea_field( $s ) { return (string) $s; }
function wpautop( $s ) { return $s; }
function wp_rand() { return 1; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function current_user_can( $c ) { return true; }
function get_option( $k, $d = false ) { return $d; }
function update_option( ...$a ) { return true; }
function delete_transient( $k ) {}
function get_post_types() { return array(); }
class VF_Security { public static function text( $s, $n = 200 ) { return substr( strip_tags( (string) $s ), 0, $n ); } }
class VF_Conditions { public static function normalize( $v ) { return $v; } public static function matches( $c ) { return true; } }
class VF_Design { public static function font_options() { return array( '' => 'x', 'heading' => 'h', 'body' => 'b' ); } }
class VF_Builder_Dynamic { public static function apply( $s, $c, $ctx ) { return $s; } }
class VF_Modules { public static $m = array(); public static function register( $slug, $a ) { self::$m[ $slug ] = $a; } }
define( 'VF_VER', '1.3.0' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 );
require $VF . '/modules/builder/class-widgets.php';
require $VF . '/modules/builder/class-style.php';
require $VF . '/modules/builder/class-render.php';

define( 'GLH_FILE', $GLH . '/veloxforge-golehighacres.php' );
// bootstrap exactly as the plugin does
define( 'GLH_VER', '1.0.0' ); define( 'GLH_DIR', $GLH . '/' ); define( 'GLH_URL', 'https://example.test/wp-content/plugins/veloxforge-golehighacres/' );
require GLH_DIR . 'includes/class-glh.php'; require GLH_DIR . 'includes/business.php'; require GLH_DIR . 'includes/widgets.php'; require GLH_DIR . 'includes/presets.php';
GLH::init(); do_action( 'veloxforge_register_modules' );
assert( isset( VF_Modules::$m['golehighacres'] ) ); call_user_func( VF_Modules::$m['golehighacres']['boot'] );

$all = VF_Builder_Widgets::all();
$mine = array_filter( array_keys( $all ), function ( $k ) { return strpos( $k, 'glh-' ) === 0; } );
echo "widgets registered: " . implode( ', ', $mine ) . "\n";
$problems = 0;
foreach ( $mine as $slug ) {
	$ctrls = VF_Builder_Widgets::widget_controls( $slug );
	$ids = array();
	foreach ( $ctrls as $c ) {
		if ( isset( $ids[ $c['id'] ] ) ) { echo "DUPLICATE control id $slug.{$c['id']}\n"; $problems++; }
		$ids[ $c['id'] ] = 1;
		if ( array_key_exists( 'default', $c ) && $c['type'] !== 'repeater' && empty( $c['responsive'] ) ) {
			$got = VF_Builder_Widgets::sanitize_value( $c, $c['default'] );
			if ( $got != $c['default'] && ! ( $c['type'] === 'number' && (float) $got === (float) $c['default'] ) ) { echo "DEFAULT REJECTED $slug.{$c['id']}: " . json_encode( $c['default'] ) . " -> " . json_encode( $got ) . "\n"; $problems++; }
		}
		if ( $c['type'] === 'repeater' && isset( $c['default'] ) ) {
			$got = VF_Builder_Widgets::sanitize_value( $c, $c['default'] );
			if ( count( $got ) !== count( $c['default'] ) ) { echo "REPEATER DEFAULT SHORT $slug.{$c['id']}\n"; $problems++; }
			foreach ( $c['default'] as $i => $row ) { foreach ( $row as $k => $v ) { if ( ! isset( $got[ $i ][ $k ] ) || $got[ $i ][ $k ] != $v ) { echo "REPEATER FIELD REJECTED $slug.{$c['id']}[$i].$k: " . json_encode( $v ) . " -> " . json_encode( $got[ $i ][ $k ] ?? null ) . "\n"; $problems++; } } }
		}
		foreach ( (array) ( $c['css'] ?? array() ) as $rule ) { if ( count( $rule ) < 2 ) { echo "BAD CSS RULE $slug.{$c['id']}\n"; $problems++; } }
	}
}
echo "control problems: $problems\n";

// presets -> sanitize, render, css
$presets = apply_filters( 'vf_builder_presets', array() );
$n = 0;
function fresh( array $nodes ) { global $n; foreach ( $nodes as $i => $x ) { $nodes[ $i ]['id'] = ( $x['type'] === 'container' ? 'c' : 'w' ) . ( ++$n ) . 'aa'; if ( ! empty( $x['children'] ) ) { $nodes[ $i ]['children'] = fresh( $x['children'] ); } } return $nodes; }
function clean( array $nodes ) { foreach ( $nodes as $i => $x ) { $nodes[ $i ]['settings'] = VF_Builder_Widgets::sanitize_settings( $x, $x['settings'] ?? array() ); if ( ! empty( $x['children'] ) ) { $nodes[ $i ]['children'] = clean( $x['children'] ); } } return $nodes; }
foreach ( $presets as $id => $p ) {
	$tree = clean( fresh( $p['tree'] ) );
	$html = VF_Builder_Render::tree( $tree );
	$css = VF_Builder_Style::css( $tree );
	file_put_contents( "$OUT/$id.html", $html ); file_put_contents( "$OUT/$id.css", $css );
	echo sprintf( "preset %-18s html=%6d css=%5d\n", $id, strlen( $html ), strlen( $css ) );
}
echo "enqueued: " . json_encode( $GLOBALS['enq'] ) . "\n";

// ---- custom trees: a real listing with full profile data, and the standalone profile block
$tree = array(
	array( 'type' => 'container', 'id' => 'cA1', 'settings' => array( 'boxed' => 'full' ), 'children' => array(
		array( 'type' => 'widget', 'widget' => 'glh-directory', 'id' => 'wA1', 'settings' => array(
			'listings' => array( array( 'name' => 'Lehigh Coffee Roasters', 'category' => 'Restaurants & Food', 'type' => 'cafe', 'area' => 'Lehigh Acres', 'desc' => 'Small-batch coffee roasted in Lehigh Acres since 2019.', 'address' => '123 Homestead Rd S, Lehigh Acres, FL 33936', 'phone' => '(239) 555-0123', 'email' => 'hello@lehigh-coffee.example', 'web' => 'https://lehigh-coffee.example', 'hours' => "Mon – Fri|7:00 AM – 4:00 PM\nSat|8:00 AM – 2:00 PM", 'tags' => 'Coffee, Pastries, Wi-Fi', 'rating' => 4.7, 'reviews' => 31, 'founding' => 1, 'facebook' => 'https://facebook.com/example' ) ),
		) ),
	) ),
	array( 'type' => 'container', 'id' => 'cB1', 'settings' => array( 'boxed' => 'full' ), 'children' => array(
		array( 'type' => 'widget', 'widget' => 'glh-business', 'id' => 'wB1', 'settings' => array( 'name' => 'Lehigh Coffee Roasters', 'category' => 'Restaurants & Food', 'desc' => 'Small-batch coffee.', 'address' => '123 Homestead Rd S, Lehigh Acres, FL 33936', 'phone' => '(239) 555-0123', 'web' => 'https://lehigh-coffee.example', 'hours' => "Mon – Fri|7:00 AM – 4:00 PM", 'tags' => 'Coffee', 'rating' => 4.7, 'reviews' => 31, 'founding' => 1 ) ),
	) ),
);
$tree = clean( $tree );
file_put_contents( "$OUT/custom.html", VF_Builder_Render::tree( $tree ) ); file_put_contents( "$OUT/custom.css", VF_Builder_Style::css( $tree ) );
echo "custom ok\n";
