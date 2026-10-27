<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adaptador para WordPress: guarda los bloques de cada página, los muestra en el sitio y ofrece un editor propio.
 * No necesita ningún constructor (VeloxForge, Elementor…): solo este plugin.
 *
 * Datos de una página: meta «_glh_blocks» (JSON con [ { id, type, settings } ]), «_glh_on» (usar los bloques) y «_glh_canvas» (pantalla completa sin cabecera ni pie del tema).
 */
class GLH_WP {

	const META   = '_glh_blocks';
	const ON     = '_glh_on';
	const CANVAS = '_glh_canvas';
	const NONCE  = 'glh_editor';

	private static $html = array();
	private static $preview = null;

	public static function init() {
		GLH::on_need( array( __CLASS__, 'enqueue' ) );
		add_shortcode( 'glh_page', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'glh_block', array( __CLASS__, 'shortcode_block' ) );
		add_action( 'wp', array( __CLASS__, 'prepare' ) );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 5 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'template_redirect', array( __CLASS__, 'preview' ), -1 ); // antes que la barra de administración (prioridad 0)
		if ( is_admin() ) {
			require_once GLH_DIR . 'wordpress/class-wp-admin.php';
			GLH_WP_Admin::init();
		}
	}

	public static function post_types() {
		return (array) apply_filters( 'glh_post_types', array( 'page' ) );
	}

	/* ---------- Datos ---------- */

	public static function blocks( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META, true );
		$arr = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : array();
		return is_array( $arr ) ? $arr : array();
	}

	/** Sanea una lista de bloques que llega del editor. */
	public static function clean_blocks( $list ) {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $b ) {
			if ( ! is_array( $b ) || empty( $b['type'] ) || ! GLH_Registry::get( (string) $b['type'] ) || count( $out ) >= 80 ) {
				continue;
			}
			$id    = isset( $b['id'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $b['id'] ) ) : '';
			$out[] = array(
				'id'       => $id !== '' ? substr( $id, 0, 16 ) : substr( md5( uniqid( '', true ) ), 0, 8 ),
				'type'     => (string) $b['type'],
				'settings' => GLH_Registry::sanitize_settings( (string) $b['type'], isset( $b['settings'] ) ? $b['settings'] : array() ),
			);
		}
		return $out;
	}

	public static function save( $post_id, array $blocks, $on, $canvas ) {
		update_post_meta( $post_id, self::META, wp_slash( wp_json_encode( self::clean_blocks( $blocks ), JSON_UNESCAPED_UNICODE ) ) );
		update_post_meta( $post_id, self::ON, $on ? 1 : 0 );
		update_post_meta( $post_id, self::CANVAS, $canvas ? 1 : 0 );
	}

	public static function uses_blocks( $post_id ) {
		if ( self::$preview && (int) $post_id === self::$preview['id'] ) {
			return true;
		}
		return $post_id && get_post_meta( $post_id, self::ON, true ) && self::blocks( $post_id );
	}

	public static function is_canvas( $post_id ) {
		if ( self::$preview && (int) $post_id === self::$preview['id'] ) {
			return self::$preview['canvas'];
		}
		return self::uses_blocks( $post_id ) && get_post_meta( $post_id, self::CANVAS, true ) !== '0';
	}

	public static function render_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! isset( self::$html[ $post_id ] ) ) {
			self::$html[ $post_id ] = '<div class="glh-page">' . GLH_Registry::render_list( self::blocks( $post_id ) ) . '</div>';
		}
		return self::$html[ $post_id ];
	}

	/* ---------- Sitio ---------- */

	/** Dibuja la página antes de wp_head para que los estilos se carguen en la cabecera. */
	public static function prepare() {
		if ( is_singular() && self::uses_blocks( get_queried_object_id() ) ) {
			self::render_post( get_queried_object_id() );
		} elseif ( is_singular() ) {
			$p = get_post( get_queried_object_id() );
			if ( $p && ( has_shortcode( $p->post_content, 'glh_page' ) || has_shortcode( $p->post_content, 'glh_block' ) ) ) {
				GLH::need();
			}
		}
	}

	public static function content( $content ) {
		$id = get_the_ID();
		if ( ! $id || ! in_the_loop() || ! is_main_query() || ! is_singular() || (int) $id !== (int) get_queried_object_id() || ! self::uses_blocks( $id ) ) {
			return $content;
		}
		return self::render_post( $id );
	}

	public static function template( $tpl ) {
		if ( is_singular() && self::is_canvas( get_queried_object_id() ) ) {
			return GLH_DIR . 'wordpress/canvas.php';
		}
		return $tpl;
	}

	public static function body_class( $c ) {
		if ( is_singular() && self::is_canvas( get_queried_object_id() ) ) {
			$c[] = 'glh-canvas';
		}
		return $c;
	}

	public static function enqueue( array $a ) {
		$go = function () use ( $a ) {
			if ( $a['fonts'] !== '' ) {
				wp_enqueue_style( 'glh-fonts', $a['fonts'], array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
			}
			foreach ( $a['css'] as $k => $f ) {
				wp_enqueue_style( $k, GLH_URL . $f['file'], array(), $f['ver'] );
			}
			foreach ( $a['js'] as $k => $f ) {
				wp_enqueue_script( $k, GLH_URL . $f['file'], array(), $f['ver'], true );
			}
		};
		if ( did_action( 'wp_enqueue_scripts' ) || doing_action( 'wp_enqueue_scripts' ) ) {
			$go();
		} else {
			add_action( 'wp_enqueue_scripts', $go );
		}
	}

	/** [glh_page id="12"]: inserta los bloques de otra página en cualquier sitio. */
	public static function shortcode( $atts ) {
		$a  = shortcode_atts( array( 'id' => 0 ), $atts, 'glh_page' );
		$id = (int) $a['id'];
		$p  = $id ? get_post( $id ) : null;
		if ( ! $p || ( $p->post_status !== 'publish' && ! current_user_can( 'edit_post', $id ) ) || ! empty( $p->post_password ) ) {
			return '';
		}
		return self::render_post( $id );
	}

	/** [glh_block type="glh-stats"]: un bloque suelto con su contenido por defecto. */
	public static function shortcode_block( $atts ) {
		$a = shortcode_atts( array( 'type' => '', 'id' => '' ), $atts, 'glh_block' );
		return GLH_Registry::render( sanitize_key( $a['type'] ), array(), $a['id'] !== '' ? $a['id'] : 'sc' . substr( md5( $a['type'] ), 0, 6 ) );
	}

	/* ---------- Vista previa del editor (lo que aún no se ha guardado) ---------- */

	/** El editor envía los bloques por POST a la propia dirección de la página; aquí se dibujan sin guardarlos. */
	public static function preview() {
		if ( empty( $_POST['glh_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $post_id || $post_id !== (int) get_queried_object_id() || ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['_glh'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_glh'] ) ), self::NONCE . $post_id ) ) {
			wp_die( 'La vista previa caducó: recarga el editor.', '', array( 'response' => 403 ) );
		}
		$list = json_decode( wp_unslash( isset( $_POST['blocks'] ) ? (string) $_POST['blocks'] : '[]' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::$preview = array( 'id' => $post_id, 'canvas' => ! empty( $_POST['canvas'] ) );
		self::$html[ $post_id ] = '<div class="glh-page">' . GLH_Registry::render_list( self::clean_blocks( $list ) ) . '</div>';
		add_filter( 'show_admin_bar', '__return_false' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
	}
}
