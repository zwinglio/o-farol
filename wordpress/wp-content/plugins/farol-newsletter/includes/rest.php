<?php
/**
 * REST: o front chama estas rotas a partir das próprias páginas
 * (/newsletter, /newsletter/confirmar, /newsletter/sair). O descadastro
 * também recebe o POST de "um clique" dos clientes de e-mail (Gmail etc.),
 * com o token na URL.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		$token = array(
			'type'     => 'string',
			'required' => true,
			'pattern'  => '^[a-f0-9]{32}$',
		);

		register_rest_route(
			'farol-newsletter/v1',
			'/subscribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'  => array( 'type' => 'string', 'required' => true ),
					'source' => array( 'type' => 'string', 'default' => '' ),
					// Slugs das listas; sem nenhum válido, entra nas listas padrão.
					'lists'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'default' => array() ),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$result = farol_newsletter_subscribe( (string) $r['email'], (string) $r['source'], farol_newsletter_list_ids_from_slugs( (array) $r['lists'] ) );
					return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
				},
			)
		);

		register_rest_route(
			'farol-newsletter/v1',
			'/confirm',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array( 'token' => $token ),
				'callback'            => fn( WP_REST_Request $r ) => farol_newsletter_confirm( $r['token'] )
					? rest_ensure_response( array( 'ok' => true ) )
					: new WP_Error( 'farol_newsletter_token', 'Link inválido ou expirado.', array( 'status' => 404 ) ),
			)
		);

		register_rest_route(
			'farol-newsletter/v1',
			'/unsubscribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array( 'token' => $token ),
				'callback'            => fn( WP_REST_Request $r ) => farol_newsletter_unsubscribe( $r['token'] )
					? rest_ensure_response( array( 'ok' => true ) )
					: new WP_Error( 'farol_newsletter_token', 'Link inválido.', array( 'status' => 404 ) ),
			)
		);
	}
);
