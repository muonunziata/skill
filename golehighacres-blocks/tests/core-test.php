<?php
// Prueba del núcleo sin WordPress: php tests/core-test.php
define( 'GLH_DIR', dirname( __DIR__ ) . '/' );
define( 'GLH_URL', 'https://example.org/glh/' );
require GLH_DIR . 'core/load.php';

$fail = 0;
$ok = function ( $cond, $msg ) use ( &$fail ) {
	echo ( $cond ? 'OK   ' : 'FAIL ' ) . $msg . "\n";
	$fail += $cond ? 0 : 1;
};
$blocks = GLH_Registry::all();
$ok( count( $blocks ) === 12, 'bloques registrados: ' . count( $blocks ) . ' (' . implode( ', ', array_keys( $blocks ) ) . ')' );
$asked = 0;
GLH::on_need( function () use ( &$asked ) { $asked++; } );
foreach ( $blocks as $slug => $b ) {
	$h = GLH_Registry::render( $slug, array(), 'x' . $slug );
	$ok( strpos( $h, 'glh-b-' . $slug ) !== false && strpos( $h, 'glh: error' ) === false && strlen( $h ) > 200, "$slug dibuja (" . strlen( $h ) . ' bytes)' );
}
$ok( $asked === 1, 'recursos pedidos una sola vez' );
foreach ( GLH_Pages::all() as $k => $p ) {
	$h = GLH_Registry::render_list( GLH_Pages::blocks( $k ) );
	$ok( substr_count( $h, '<section class="glh-block ' ) === count( $p['blocks'] ), "plantilla $k: " . count( $p['blocks'] ) . ' bloques' );
}
$s = GLH_Registry::sanitize_settings( 'glh-hero', array( 'w_css' => 'selector{color:red}</style><script>x</script>', 'w_tint' => 'evil', 'min_h' => array( 'desktop' => 999, 'mobile' => 50 ), 'nope' => 1 ) );
$ok( strpos( $s['w_css'], '<' ) === false, 'CSS propio sin etiquetas' );
$ok( $s['w_tint'] === '', 'opción no válida descartada' );
$ok( $s['min_h']['desktop'] == 120 && $s['min_h']['mobile'] == 50, 'número responsive acotado' );
$ok( ! isset( $s['nope'] ), 'campo desconocido descartado' );
$h = GLH_Registry::render( 'glh-stats', array( 'w_tint' => 'glh-tint-2', 'w_anchor' => 'city', 'w_pt' => 120 ), 'ab12' );
$ok( strpos( $h, 'glh-tint-2' ) !== false && strpos( $h, 'id="city"' ) !== false && strpos( $h, 'padding-top:120px' ) !== false && strpos( $h, 'padding-top:56px' ) !== false, 'capa de color, ancla y relleno (con tope en móvil)' );
$ok( strpos( GLH::asset_tags( 'https://x.org/glh' ), 'assets/glh.js?ver=' . GLH_VER ) !== false, 'etiquetas de recursos para sitios sin WordPress' );
$ok( json_encode( GLH_Registry::schema() ) !== false, 'esquema del editor en JSON' );
echo $fail ? "\n$fail fallos\n" : "\nTodo bien\n";
exit( $fail ? 1 : 0 );
