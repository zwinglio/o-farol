<?php
/**
 * Plugin Name: O Farol — Núcleo headless
 * Description: Integra o WordPress ao front em Astro: CORS da REST API, links apontando para o site público, campos extras, página de configurações do app (com IA via OpenRouter) e limpeza do que não usamos.
 * Author:      Samuel Zwinglio
 * Version:     0.1.0
 *
 * mu-plugin: carregado sempre, não pode ser desativado pelo painel.
 *
 * @package OFarol
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/farol-core/settings.php';
require_once __DIR__ . '/farol-core/ai.php';

/**
 * URL pública do site (Astro). Vazia = comportamento padrão do WordPress.
 */
function farol_frontend_url( string $path = '' ): string {
	$base = defined( 'FAROL_FRONTEND_URL' ) ? FAROL_FRONTEND_URL : '';
	return '' === $base ? '' : $base . '/' . ltrim( $path, '/' );
}

/**
 * Origens liberadas no CORS (FAROL_CORS_ORIGINS + o próprio front).
 *
 * @return string[]
 */
function farol_allowed_origins(): array {
	$origins = array_map( 'trim', explode( ',', defined( 'FAROL_CORS_ORIGINS' ) ? FAROL_CORS_ORIGINS : '' ) );
	if ( defined( 'FAROL_FRONTEND_URL' ) ) {
		$origins[] = FAROL_FRONTEND_URL;
	}

	return array_values( array_unique( array_filter( array_map( fn( $o ) => rtrim( $o, '/' ), $origins ) ) ) );
}

/*
 * ---------------------------------------------------------------------
 * CORS: troca o "reflete qualquer origem" do core por uma lista fechada.
 * ---------------------------------------------------------------------
 */
add_action(
	'rest_api_init',
	function () {
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

		add_filter(
			'rest_pre_serve_request',
			function ( $served ) {
				$origin = get_http_origin();
				header( 'Vary: Origin', false );

				if ( $origin && in_array( rtrim( $origin, '/' ), farol_allowed_origins(), true ) ) {
					header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
					header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
					header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
					header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, Link' );
					header( 'Access-Control-Max-Age: 600' );
				}

				return $served;
			}
		);
	},
	15
);

/*
 * ---------------------------------------------------------------------
 * Links do painel ("Ver post", permalinks na API) apontam para o Astro.
 * Formato espelhado em front/src/pages/noticias/[slug].astro.
 * ---------------------------------------------------------------------
 */
add_filter(
	'post_link',
	function ( string $link, WP_Post $post ) {
		if ( 'publish' !== $post->post_status || '' === farol_frontend_url() ) {
			return $link;
		}
		return farol_frontend_url( 'noticias/' . $post->post_name );
	},
	10,
	2
);

/*
 * Quem abrir o CMS no navegador (fora do painel/API/login) vai para o site.
 * Prioridade 1: roda antes do redirect_canonical do core, que transformaria
 * ?author=1 em /author/<login>/ e revelaria nomes de usuário.
 */
add_action(
	'template_redirect',
	function () {
		if ( '' === farol_frontend_url() || is_preview() ) {
			return;
		}

		$target = match ( true ) {
			is_singular( 'post' ) => get_permalink(),
			is_feed()             => farol_frontend_url( 'rss.xml' ),
			default               => farol_frontend_url(),
		};
		wp_redirect( $target, 301, 'O Farol' );
		exit;
	},
	1
);

/*
 * ---------------------------------------------------------------------
 * Campos extras na REST API.
 * ---------------------------------------------------------------------
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_field(
			'post',
			'farol_reading_time',
			array(
				'description' => 'Tempo estimado de leitura, em minutos (≈ 200 palavras/min).',
				'schema'      => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'get_callback' => function ( array $post ) {
					$text  = wp_strip_all_tags( get_post_field( 'post_content', $post['id'] ) );
					$words = count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
					return max( 1, (int) ceil( $words / 200 ) );
				},
			)
		);
	}
);

/*
 * ---------------------------------------------------------------------
 * Menus: GET /wp-json/farol/v1/menus/<posição>
 *
 * O endpoint nativo (/wp/v2/menu-items) exige login e devolve URLs do CMS.
 * Este é público, só leitura, e já entrega os links no formato do front:
 * categoria → /<slug>, post → /noticias/<slug>, link personalizado → caminho
 * relativo quando aponta para o próprio site.
 * ---------------------------------------------------------------------
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'farol/v1',
			'/menus/(?P<location>[a-z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					$location  = $request['location'];
					$locations = get_nav_menu_locations();

					if ( ! array_key_exists( $location, get_registered_nav_menus() ) ) {
						return new WP_Error( 'farol_menu_location', 'Posição de menu desconhecida.', array( 'status' => 404 ) );
					}

					$items = empty( $locations[ $location ] ) ? array() : wp_get_nav_menu_items( $locations[ $location ] );
					$tree  = array();
					$index = array();

					foreach ( (array) $items as $item ) {
						$node = farol_menu_node( $item );
						$index[ $item->ID ] = $node;

						if ( $item->menu_item_parent && isset( $index[ $item->menu_item_parent ] ) ) {
							$index[ $item->menu_item_parent ]->children[] = $node;
						} else {
							$tree[] = $node;
						}
					}

					return rest_ensure_response( $tree );
				},
			)
		);
	}
);

/**
 * Converte um item de menu do WP no formato consumido pelo Astro.
 */
function farol_menu_node( WP_Post $item ): stdClass {
	$category = null;
	$href     = $item->url;

	if ( 'taxonomy' === $item->type && 'category' === $item->object ) {
		$term = get_term( (int) $item->object_id, 'category' );
		if ( $term && ! is_wp_error( $term ) ) {
			$category = $term->slug;
			$href     = '/' . $term->slug;
		}
	} elseif ( 'post_type' === $item->type && 'post' === $item->object ) {
		$href = '/noticias/' . get_post_field( 'post_name', (int) $item->object_id );
	} else {
		// Links absolutos para o próprio front (ou para o CMS) viram caminhos relativos.
		foreach ( array_filter( array( farol_frontend_url(), home_url( '/' ) ) ) as $base ) {
			if ( str_starts_with( $href, $base ) ) {
				$href = '/' . ltrim( substr( $href, strlen( $base ) ), '/' );
				break;
			}
		}
	}

	return (object) array(
		'id'       => $item->ID,
		'label'    => html_entity_decode( $item->title, ENT_QUOTES ),
		'href'     => '' === $href ? '/' : $href,
		'category' => $category,
		'external' => '_blank' === $item->target,
		'children' => array(),
	);
}

/*
 * ---------------------------------------------------------------------
 * Limpeza: o que um CMS headless não precisa expor.
 * ---------------------------------------------------------------------
 */
add_filter( 'xmlrpc_enabled', '__return_false' );

// Sem pingbacks nem "enclosures": ao publicar, o WordPress visitaria cada link
// citado (X, YouTube, outros veículos…) — lento e sem utilidade num site headless.
add_filter( 'pre_option_default_pingback_flag', '__return_zero' );
add_filter( 'pre_option_default_ping_status', fn() => 'closed' );
add_filter( 'enclosure_links', '__return_empty_array' );
remove_action( 'do_pings', 'do_all_pings', 10 );
add_filter( 'wp_headers', fn( $headers ) => array_diff_key( $headers, array( 'X-Pingback' => true ) ) );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wp_generator' );

// Na API pública, usuário é só o nome de exibição (assinatura das matérias).
// Slug e link entregariam o login — meio caminho para ataques de senha.
add_filter(
	'rest_prepare_user',
	function ( WP_REST_Response $response, WP_User $user, WP_REST_Request $request ) {
		if ( ! current_user_can( 'list_users' ) ) {
			$data = $response->get_data();
			unset( $data['url'], $data['link'], $data['slug'], $data['description'], $data['avatar_urls'] ); // avatar = hash do e-mail (Gravatar)
			$response->set_data( $data );
		}
		return $response;
	},
	10,
	3
);

// O mesmo no oEmbed (author_url apontaria para /author/<login>/).
add_filter(
	'oembed_response_data',
	function ( array $data ) {
		unset( $data['author_url'] );
		return $data;
	}
);

// Sitemaps do core listariam usuários; o site público tem os seus (front/src/pages/sitemap.xml.ts).
add_filter( 'wp_sitemaps_enabled', '__return_false' );

// Erro de login genérico: não confirma se o usuário existe.
add_filter( 'login_errors', fn() => '<strong>Erro:</strong> usuário ou senha incorretos.' );
