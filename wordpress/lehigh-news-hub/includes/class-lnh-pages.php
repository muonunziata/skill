<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pages the plugin needs on the public site, created on activation (never duplicated, never overwritten).
 *
 * - news         the article listing ([lehigh_news] with pagination)
 * - how-we-work  editorial policy / how the AI agents and the editors work (transparency)
 * - corrections  how readers report errors, with an optional public contact address
 */
final class LNH_Pages {

	const OPTION  = 'lnh_pages';
	const WELCOME = 'lnh_welcome';
	const META    = '_lnh_page_key';

	public static function init(): void {
		add_action( 'admin_post_lnh_pages', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_lnh_dismiss_welcome', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_shortcode( 'lehigh_news_contact', array( __CLASS__, 'contact_shortcode' ) );
	}

	/** Spanish content for Spanish locales, English otherwise. */
	private static function is_spanish(): bool {
		return 0 === strpos( determine_locale(), 'es' );
	}

	/**
	 * Page definitions in creation order (referenced pages first). Placeholders: {how_url}, {corrections_url}.
	 */
	public static function definitions(): array {
		$es = self::is_spanish();
		return array(
			'how-we-work' => array(
				'slug'  => $es ? 'como-trabajamos' : 'how-we-work',
				'title' => $es ? 'Cómo trabajamos' : 'How we work',
				'uses'  => '',
				'html'  => $es ? self::how_es() : self::how_en(),
			),
			'corrections' => array(
				'slug'  => $es ? 'correcciones' : 'corrections',
				'title' => $es ? 'Correcciones y contacto' : 'Corrections & contact',
				'uses'  => '[lehigh_news_contact]',
				'html'  => $es ? self::corrections_es() : self::corrections_en(),
			),
			'news'        => array(
				'slug'  => $es ? 'noticias' : 'news',
				'title' => $es ? 'Noticias de Lehigh Acres' : 'Lehigh Acres News',
				'uses'  => '[lehigh_news]',
				'html'  => $es ? self::news_es() : self::news_en(),
			),
		);
	}

	private static function shortcode_block( string $shortcode ): string {
		return "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->\n\n";
	}

	private static function p( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->\n\n";
	}

	private static function h2( string $text ): string {
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . $text . "</h2>\n<!-- /wp:heading -->\n\n";
	}

	private static function list_block( array $items, bool $ordered = false ): string {
		$tag  = $ordered ? 'ol' : 'ul';
		$attr = $ordered ? ' {"ordered":true}' : '';
		$out  = '<!-- wp:list' . $attr . " -->\n<" . $tag . " class=\"wp-block-list\">";
		foreach ( $items as $i ) {
			$out .= "<!-- wp:list-item -->\n<li>" . $i . "</li>\n<!-- /wp:list-item -->";
		}
		return $out . '</' . $tag . ">\n<!-- /wp:list -->\n\n";
	}

	private static function news_en(): string {
		return self::p( 'Local news for Lehigh Acres, Florida: researched, written and fact-checked with the help of AI agents, and reviewed by our editors before publication. <a href="{how_url}">See how we work</a>.' )
			. self::shortcode_block( '[lehigh_news layout="featured" count="9" columns="3" pagination="1" show_source="1"]' );
	}

	private static function news_es(): string {
		return self::p( 'Noticias locales de Lehigh Acres, Florida: investigadas, redactadas y verificadas con ayuda de agentes de IA, y revisadas por nuestros editores antes de publicarse. <a href="{how_url}">Conoce cómo trabajamos</a>.' )
			. self::shortcode_block( '[lehigh_news layout="featured" count="9" columns="3" pagination="1" show_source="1"]' );
	}

	private static function how_en(): string {
		return self::p( 'We cover Lehigh Acres and Lee County, Florida. Our newsroom combines AI agents with human editors. Here is exactly what each one does.' )
			. self::h2( 'Our newsroom, step by step' )
			. self::list_block( array(
				'<strong>Research.</strong> A research agent searches the web for recent local news and opens the original pages. It only reports what it actually read.',
				'<strong>Writing.</strong> A writing agent drafts an original article from those facts, credits the source and links to it.',
				'<strong>Audit.</strong> An audit agent compares the article with the source, checks figures, names, spelling and links, and gives it a score. Articles that do not pass are held back.',
				'<strong>Human decision.</strong> An editor reviews every article and decides whether it is published.',
			), true )
			. self::h2( 'Sources' )
			. self::p( 'Every article names its source and links to it. We prefer official sources (county, sheriff, fire district, schools) and established local outlets, and we do not publish rumours or unverified social-media claims.' )
			. self::h2( 'Images' )
			. self::p( 'Photos come from openly licensed archives and carry their credit. Some articles use AI-generated illustrations; they are always labelled as such and are not photographs of the events described.' )
			. self::h2( 'Mistakes' )
			. self::p( 'AI and people both make mistakes. If you find one, tell us on the <a href="{corrections_url}">corrections page</a>.' );
	}

	private static function how_es(): string {
		return self::p( 'Cubrimos Lehigh Acres y el condado de Lee, en Florida. Nuestra redacción combina agentes de IA con editores humanos. Esto es exactamente lo que hace cada uno.' )
			. self::h2( 'Nuestra redacción, paso a paso' )
			. self::list_block( array(
				'<strong>Investigación.</strong> Un agente de investigación busca en la web noticias locales recientes y abre las páginas originales. Solo informa de lo que realmente leyó.',
				'<strong>Redacción.</strong> Un agente de redacción escribe un artículo original a partir de esos hechos, cita la fuente y enlaza a ella.',
				'<strong>Auditoría.</strong> Un agente auditor compara el artículo con la fuente, revisa cifras, nombres, ortografía y enlaces, y le asigna una puntuación. Los artículos que no la superan se retienen.',
				'<strong>Decisión humana.</strong> Un editor revisa cada artículo y decide si se publica.',
			), true )
			. self::h2( 'Fuentes' )
			. self::p( 'Cada artículo indica su fuente y enlaza a ella. Preferimos fuentes oficiales (condado, sheriff, distrito de bomberos, escuelas) y medios locales establecidos; no publicamos rumores ni afirmaciones sin verificar de redes sociales.' )
			. self::h2( 'Imágenes' )
			. self::p( 'Las fotos proceden de archivos con licencia abierta e incluyen su crédito. Algunos artículos usan ilustraciones generadas con IA; siempre se identifican como tales y no son fotografías de los hechos descritos.' )
			. self::h2( 'Errores' )
			. self::p( 'Tanto la IA como las personas se equivocan. Si encuentras uno, avísanos en la <a href="{corrections_url}">página de correcciones</a>.' );
	}

	private static function corrections_en(): string {
		return self::p( 'Found a mistake, an outdated detail or something we should cover? We want to know.' )
			. self::shortcode_block( '[lehigh_news_contact]' )
			. self::h2( 'How we handle corrections' )
			. self::list_block( array(
				'We check the report against the original source.',
				'If it is confirmed, we fix the article and add a note saying what changed and when.',
				'If a story cannot be verified, we remove it.',
			) )
			. self::p( 'Read more about <a href="{how_url}">how we work</a>.' );
	}

	private static function corrections_es(): string {
		return self::p( '¿Encontraste un error, un dato desactualizado o algo que deberíamos cubrir? Queremos saberlo.' )
			. self::shortcode_block( '[lehigh_news_contact]' )
			. self::h2( 'Cómo gestionamos las correcciones' )
			. self::list_block( array(
				'Contrastamos el aviso con la fuente original.',
				'Si se confirma, corregimos el artículo y añadimos una nota con lo que cambió y cuándo.',
				'Si una noticia no se puede verificar, la retiramos.',
			) )
			. self::p( 'Más información sobre <a href="{how_url}">cómo trabajamos</a>.' );
	}

	// ------------------------------------------------------------------ creation.

	/** @return array<string,int> key => page ID of the pages that are now known to the plugin */
	public static function ids(): array {
		$ids = get_option( self::OPTION, array() );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	public static function url( string $key ): string {
		$id = self::ids()[ $key ] ?? 0;
		return ( $id && 'publish' === get_post_status( $id ) ) ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Create every missing page. Existing pages are never modified.
	 *
	 * @param bool $restore_trashed Bring back pages that were sent to the trash (explicit user request only).
	 * @return array{created:string[],existing:string[],restored:string[]}
	 */
	public static function ensure_all( bool $restore_trashed = false ): array {
		$ids    = self::ids();
		$result = array( 'created' => array(), 'existing' => array(), 'restored' => array() );
		$author = get_current_user_id();
		if ( ! $author ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			$author = $admins ? (int) $admins[0] : 1;
		}

		foreach ( self::definitions() as $key => $def ) {
			$id   = $ids[ $key ] ?? 0;
			$post = $id ? get_post( $id ) : null;
			if ( ! $post || 'page' !== $post->post_type ) {
				// The option may have been lost: re-adopt a page that carries our key.
				$found = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), 'meta_key' => self::META, 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids' ) );
				$post  = $found ? get_post( (int) $found[0] ) : null;
			}
			if ( $post ) {
				$ids[ $key ] = (int) $post->ID;
				if ( 'trash' === $post->post_status ) {
					if ( $restore_trashed ) {
						wp_untrash_post( $post->ID );
						wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ) );
						$result['restored'][] = $key;
					}
					continue; // Respect a deliberate deletion unless asked to restore it.
				}
				$result['existing'][] = $key;
				continue;
			}
			$content = strtr(
				$def['html'],
				array(
					'{how_url}'         => esc_url( $ids['how-we-work'] ?? 0 ? (string) get_permalink( $ids['how-we-work'] ) : home_url( '/' ) ),
					'{corrections_url}' => esc_url( $ids['corrections'] ?? 0 ? (string) get_permalink( $ids['corrections'] ) : home_url( '/' ) ),
				)
			);
			$new = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => $def['title'],
						'post_name'    => $def['slug'],
						'post_content' => $content,
						'post_author'  => $author,
					)
				),
				true
			);
			if ( is_wp_error( $new ) || ! $new ) {
				continue;
			}
			update_post_meta( $new, self::META, $key );
			$ids[ $key ]          = (int) $new;
			$result['created'][] = $key;
		}
		update_option( self::OPTION, $ids, false );
		self::ensure_category();
		return $result;
	}

	/** A "News" category, used as the default for agent articles that arrive uncategorised. */
	private static function ensure_category(): void {
		$settings = LNH_Settings::all();
		if ( (int) $settings['default_category'] > 0 && term_exists( (int) $settings['default_category'], 'category' ) ) {
			return;
		}
		$name = self::is_spanish() ? 'Noticias' : 'News';
		$term = term_exists( $name, 'category' );
		if ( ! $term ) {
			$term = wp_insert_term( $name, 'category' );
		}
		if ( ! is_wp_error( $term ) && $term ) {
			$settings['default_category'] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			update_option( LNH_Settings::OPTION, $settings, false );
		}
	}

	/** Rows for the settings screen. */
	public static function status(): array {
		$ids  = self::ids();
		$rows = array();
		foreach ( self::definitions() as $key => $def ) {
			$id     = $ids[ $key ] ?? 0;
			$post   = $id ? get_post( $id ) : null;
			$state  = ( $post && 'page' === $post->post_type ) ? $post->post_status : 'missing';
			$rows[ $key ] = array(
				'title' => $post ? get_the_title( $post ) : $def['title'],
				'id'    => $post ? (int) $post->ID : 0,
				'state' => $state,
				'uses'  => $def['uses'],
				'view'  => ( $post && 'publish' === $state ) ? (string) get_permalink( $post ) : '',
				'edit'  => ( $post && 'trash' !== $state ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
			);
		}
		return $rows;
	}

	// ------------------------------------------------------------------ activation UX.

	public static function on_activate(): void {
		$result = self::ensure_all( false );
		if ( $result['created'] ) {
			update_option( self::WELCOME, $result['created'], false );
		}
		set_transient( 'lnh_activation_redirect', 1, 60 );
	}

	/** Take the administrator to the hub right after activation (not for bulk activations). */
	public static function maybe_redirect(): void {
		if ( ! get_transient( 'lnh_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'lnh_activation_redirect' );
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'edit_others_posts' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lnh' ) );
		exit;
	}

	public static function handle_create(): void {
		check_admin_referer( 'lnh_pages' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'lehigh-news-hub' ), 403 );
		}
		$r = self::ensure_all( true );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lnh-settings', 'tab' => 'pages', 'lnh_notice' => 'pages', 'lnh_n' => count( $r['created'] ) + count( $r['restored'] ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_dismiss(): void {
		check_admin_referer( 'lnh_dismiss_welcome' );
		if ( current_user_can( 'edit_others_posts' ) ) {
			delete_option( self::WELCOME );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lnh' ) );
		exit;
	}

	// ------------------------------------------------------------------ [lehigh_news_contact].

	public static function contact_shortcode(): string {
		$email = sanitize_email( (string) LNH_Settings::get( 'public_contact_email' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return '<p class="lnh-contact">' . esc_html__( 'Please use the contact options of this website to reach the newsroom.', 'lehigh-news-hub' ) . '</p>';
		}
		return '<p class="lnh-contact">' . esc_html__( 'Write to the newsroom:', 'lehigh-news-hub' ) . ' <a href="mailto:' . esc_attr( antispambot( $email ) ) . '">' . esc_html( antispambot( $email ) ) . '</a></p>';
	}
}
