<?php
/**
 * REST: dados de SEO nos posts, configurações públicas do site e análise
 * ao vivo para o editor.
 *
 * @package FarolSEO
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		// Campo pronto para o <head> do front: GET /wp/v2/posts?_fields=farol_seo
		register_rest_field(
			'post',
			'farol_seo',
			array(
				'schema'       => array( 'type' => 'object', 'readonly' => true, 'context' => array( 'view', 'edit' ) ),
				'get_callback' => fn( array $post ) => farol_seo_for_post_cached( get_post( $post['id'] ) ),
			)
		);

		register_rest_route(
			'farol-seo/v1',
			'/site',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => fn() => rest_ensure_response( farol_seo_site() ),
			)
		);

		register_rest_route(
			'farol-seo/v1',
			'/analyze',
			array(
				'methods'             => 'POST',
				'permission_callback' => fn( WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['post_id'] ),
				'args'                => array( 'post_id' => array( 'type' => 'integer', 'required' => true ) ),
				'callback'            => function ( WP_REST_Request $r ) {
					$post = get_post( (int) $r['post_id'] );
					if ( ! $post ) {
						return new WP_Error( 'farol_seo_post', 'Post não encontrado.', array( 'status' => 404 ) );
					}

					$edits = array_intersect_key(
						(array) $r->get_json_params(),
						array_flip( array( 'title', 'content', 'excerpt', 'slug', 'meta', 'featured_media', 'categories', 'author' ) )
					);
					if ( isset( $edits['meta'] ) ) {
						$prefix        = '_farol_seo_';
						$edits['meta'] = array_combine(
							array_map( fn( $k ) => str_starts_with( $k, $prefix ) ? substr( $k, strlen( $prefix ) ) : $k, array_keys( (array) $edits['meta'] ) ),
							array_values( (array) $edits['meta'] )
						);
					}

					$result        = ( new Farol_SEO_Analyzer( farol_seo_input( $post, $edits ) ) )->run();
					$result['url'] = farol_seo_url( '/noticias/' . ( $edits['slug'] ?? $post->post_name ?: sanitize_title( $edits['title'] ?? $post->post_title ) ) );
					$result['site_name'] = get_bloginfo( 'name' );
					return rest_ensure_response( $result );
				},
			)
		);
	}
);
