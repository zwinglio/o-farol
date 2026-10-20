<?php
/**
 * Plugin Name:       O Farol — Imagens para redes
 * Description:       Gera no editor as artes da notícia para redes sociais (feed 4:5 e stories 9:16) com título, editoria e foto destacada. Baixe o PNG ou copie para colar.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Samuel Zwinglio
 * Text Domain:       farol-social
 *
 * As imagens são desenhadas no navegador (canvas) — nada é gerado nem
 * guardado no servidor além dos ajustes do post (título da arte e foco da foto).
 *
 * @package FarolSocial
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function () {
		$auth = fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id );

		// Título usado só na arte (vazio = título da notícia).
		register_post_meta(
			'post',
			'_farol_social_title',
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => $auth,
			)
		);

		// Ponto de foco da foto no recorte: { x, y } entre 0 e 1.
		register_post_meta(
			'post',
			'_farol_social_focal',
			array(
				'type'          => 'object',
				'single'        => true,
				'default'       => array( 'x' => 0.5, 'y' => 0.5 ),
				'show_in_rest'  => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'x' => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ),
							'y' => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ),
						),
					),
				),
				'auth_callback' => $auth,
			)
		);
	}
);

add_action(
	'enqueue_block_editor_assets',
	function () {
		if ( 'post' !== get_current_screen()?->post_type ) {
			return;
		}

		$ver = filemtime( __DIR__ . '/assets/editor.js' );
		wp_enqueue_script(
			'farol-social-editor',
			plugins_url( 'assets/editor.js', __FILE__ ),
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-html-entities' ),
			$ver,
			true
		);
		wp_enqueue_style( 'farol-social-editor', plugins_url( 'assets/editor.css', __FILE__ ), array(), $ver );

		$front = function_exists( 'farol_frontend_url' ) && farol_frontend_url() ? farol_frontend_url() : home_url();
		wp_add_inline_script(
			'farol-social-editor',
			'window.farolSocial = ' . wp_json_encode(
				array(
					'siteName' => get_bloginfo( 'name' ),
					'host'     => preg_replace( '/^www\./', '', (string) wp_parse_url( $front, PHP_URL_HOST ) . ( wp_parse_url( $front, PHP_URL_PORT ) ? ':' . wp_parse_url( $front, PHP_URL_PORT ) : '' ) ),
				)
			) . ';',
			'before'
		);
	}
);
