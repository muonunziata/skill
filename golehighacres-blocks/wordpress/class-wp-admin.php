<?php
defined( 'ABSPATH' ) || exit;

/** Administrador: menú «GoLehighAcres», editor de bloques a pantalla completa, botón en cada página y guardado. */
class GLH_WP_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_glh_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'admin_post_glh_new', array( __CLASS__, 'create' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'display_post_states', array( __CLASS__, 'state' ), 10, 2 );
	}

	public static function edit_url( $post_id ) {
		return admin_url( 'admin.php?page=glh-editor&post=' . (int) $post_id );
	}

	public static function menu() {
		add_menu_page( 'GoLehighAcres', 'GoLehighAcres', 'edit_pages', 'glh', array( __CLASS__, 'screen_list' ), 'dashicons-admin-home', 21 );
		add_submenu_page( 'glh', 'Páginas con bloques', 'Páginas', 'edit_pages', 'glh', array( __CLASS__, 'screen_list' ) );
		$hook = add_submenu_page( '', 'Editor de bloques', 'Editor', 'edit_pages', 'glh-editor', array( __CLASS__, 'screen_editor' ) ); // pantalla oculta (sin entrada en el menú)
		add_action( 'load-' . $hook, function () {
			$GLOBALS['title'] = 'Editor de bloques · GoLehighAcres'; // las páginas ocultas no tienen título propio
		} );
	}

	/* ---------- Lista y creación ---------- */

	public static function screen_list() {
		$q = new WP_Query( array( 'post_type' => GLH_WP::post_types(), 'post_status' => 'any', 'posts_per_page' => 100, 'meta_key' => GLH_WP::META, 'orderby' => 'modified' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		echo '<div class="wrap glh-admin-list"><h1 class="wp-heading-inline">GoLehighAcres · v' . esc_html( GLH_VER ) . '</h1>';
		echo '<p>Bloques propios de GoLehighAcres.org, sin VeloxForge ni otros constructores. Crea una página desde una plantilla o abre cualquier página con «Editar con GoLehighAcres».</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="glh-new"><input type="hidden" name="action" value="glh_new">';
		wp_nonce_field( 'glh_new' );
		echo '<input type="text" name="title" placeholder="Título de la página" required> <select name="tpl"><option value="">Página vacía</option>';
		foreach ( GLH_Pages::all() as $k => $p ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $p['icon'] . ' ' . $p['title'] ) . '</option>';
		}
		echo '</select> <button class="button button-primary">Crear y editar</button></form>';
		echo '<table class="widefat striped"><thead><tr><th>Página</th><th>Estado</th><th>Bloques</th><th>Modificada</th><th></th></tr></thead><tbody>';
		if ( ! $q->have_posts() ) {
			echo '<tr><td colspan="5">Aún no hay páginas con bloques.</td></tr>';
		}
		foreach ( $q->posts as $p ) {
			echo '<tr><td><b>' . esc_html( get_the_title( $p ) ?: '(sin título)' ) . '</b></td><td>' . esc_html( get_post_status_object( $p->post_status )->label ) . ( get_post_meta( $p->ID, GLH_WP::ON, true ) ? '' : ' · bloques desactivados' ) . '</td><td>' . count( GLH_WP::blocks( $p->ID ) ) . '</td><td>' . esc_html( get_the_modified_date( '', $p ) ) . '</td>';
			echo '<td><a class="button button-primary" href="' . esc_url( self::edit_url( $p->ID ) ) . '">Editar bloques</a> <a class="button" href="' . esc_url( get_permalink( $p ) ) . '" target="_blank">Ver</a></td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>Insertar en otra página</h2><p>Código corto: <code>[glh_page id="ID"]</code> (todos los bloques de una página) o <code>[glh_block type="glh-stats"]</code> (un bloque suelto).</p></div>';
	}

	public static function create() {
		check_admin_referer( 'glh_new' );
		if ( ! current_user_can( 'publish_pages' ) ) {
			wp_die( 'Sin permiso.' );
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$tpl   = isset( $_POST['tpl'] ) ? sanitize_key( wp_unslash( $_POST['tpl'] ) ) : '';
		$id    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title !== '' ? $title : 'GoLehighAcres' ), true );
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}
		GLH_WP::save( $id, $tpl !== '' ? GLH_Pages::blocks( $tpl ) : array(), true, true );
		wp_safe_redirect( self::edit_url( $id ) );
		exit;
	}

	/* ---------- Enlaces desde las páginas ---------- */

	public static function row_action( $actions, $post ) {
		if ( in_array( $post->post_type, GLH_WP::post_types(), true ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['glh'] = '<a href="' . esc_url( self::edit_url( $post->ID ) ) . '">Editar con GoLehighAcres</a>';
		}
		return $actions;
	}

	public static function state( $states, $post ) {
		if ( GLH_WP::uses_blocks( $post->ID ) ) {
			$states['glh'] = 'GoLehighAcres';
		}
		return $states;
	}

	public static function metabox() {
		foreach ( GLH_WP::post_types() as $pt ) {
			add_meta_box( 'glh-box', 'GoLehighAcres', function ( $post ) {
				$on = GLH_WP::uses_blocks( $post->ID );
				echo '<p>' . ( $on ? 'Esta página se muestra con ' . count( GLH_WP::blocks( $post->ID ) ) . ' bloques de GoLehighAcres (su contenido normal no se ve).' : 'Diseña esta página con los bloques de GoLehighAcres.' ) . '</p>';
				echo $post->post_status === 'auto-draft' ? '<p><i>Guarda primero el borrador.</i></p>' : '<p><a class="button button-primary" href="' . esc_url( self::edit_url( $post->ID ) ) . '">Editar con GoLehighAcres</a></p>';
			}, $pt, 'side', 'high' );
		}
	}

	/* ---------- Editor ---------- */

	public static function assets( $hook ) {
		$css = GLH_VER . '.' . (int) @filemtime( GLH_DIR . 'wordpress/admin.css' );
		wp_enqueue_style( 'glh-admin', GLH_URL . 'wordpress/admin.css', array(), $css );
		if ( empty( $_GET['page'] ) || $_GET['page'] !== 'glh-editor' ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'glh-admin', GLH_URL . 'wordpress/admin.js', array( 'jquery' ), GLH_VER . '.' . (int) @filemtime( GLH_DIR . 'wordpress/admin.js' ), true );
		$post  = get_post( $post_id );
		$pages = array();
		foreach ( GLH_Pages::all() as $k => $p ) {
			$pages[ $k ] = array( 'title' => $p['title'], 'icon' => $p['icon'], 'desc' => $p['desc'], 'blocks' => GLH_Pages::blocks( $k ) );
		}
		$link = get_permalink( $post );
		if ( $post->post_status !== 'publish' ) {
			$link = add_query_arg( 'preview', 'true', $link );
		}
		wp_localize_script( 'glh-admin', 'GLH_ED', array(
			'schema'  => GLH_Registry::schema(),
			'pages'   => $pages,
			'blocks'  => GLH_WP::blocks( $post_id ),
			'on'      => get_post_meta( $post_id, GLH_WP::ON, true ) === '' ? 1 : (int) get_post_meta( $post_id, GLH_WP::ON, true ),
			'canvas'  => get_post_meta( $post_id, GLH_WP::CANVAS, true ) === '0' ? 0 : 1,
			'post'    => $post_id,
			'title'   => get_the_title( $post ),
			'status'  => $post->post_status,
			'link'    => $link,
			'back'    => admin_url( 'admin.php?page=glh' ),
			'wpedit'  => get_edit_post_link( $post_id, 'raw' ),
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( GLH_WP::NONCE . $post_id ),
			'ver'     => GLH_VER,
		) );
	}

	public static function screen_editor() {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $post_id || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'No puedes editar esta página.' );
		}
		echo '<div id="glh-editor" class="glh-ed"><p class="glh-ed__loading">Cargando el editor…</p></div>';
	}

	public static function ajax_save() {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! check_ajax_referer( GLH_WP::NONCE . $post_id, '_glh', false ) ) {
			wp_send_json_error( array( 'msg' => 'Sin permiso o la sesión caducó: recarga la página.' ), 403 );
		}
		$list = json_decode( wp_unslash( isset( $_POST['blocks'] ) ? (string) $_POST['blocks'] : '[]' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $list ) ) {
			wp_send_json_error( array( 'msg' => 'Datos no válidos.' ), 400 );
		}
		GLH_WP::save( $post_id, $list, ! empty( $_POST['on'] ), ! empty( $_POST['canvas'] ) );
		$publish = ! empty( $_POST['publish'] ) && current_user_can( 'publish_post', $post_id );
		if ( $publish && get_post_status( $post_id ) !== 'publish' ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		} else {
			wp_update_post( array( 'ID' => $post_id ) ); // actualiza la fecha de modificación y vacía cachés de la página
		}
		wp_send_json_success( array( 'blocks' => GLH_WP::blocks( $post_id ), 'status' => get_post_status( $post_id ), 'link' => get_permalink( $post_id ) ) );
	}
}
