<?php
/**
 * POST /farol-fontes/v1/resolve { url, post_id } → { source }
 *
 * @package FarolFontes
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'farol-fontes/v1',
			'/resolve',
			array(
				'methods'             => 'POST',
				'permission_callback' => fn( WP_REST_Request $r ) => $r['post_id'] ? current_user_can( 'edit_post', (int) $r['post_id'] ) : current_user_can( 'edit_posts' ),
				'args'                => array(
					'url'     => array( 'type' => 'string', 'format' => 'uri', 'required' => true ),
					'post_id' => array( 'type' => 'integer', 'default' => 0 ),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$source = ( new Farol_Fontes_Resolver( (int) $r['post_id'] ) )->resolve( (string) $r['url'] );
					return is_wp_error( $source ) ? $source : rest_ensure_response( array( 'source' => $source ) );
				},
			)
		);
	}
);
