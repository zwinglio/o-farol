<?php
/**
 * Suportes necessários para o editor (imagem destacada, títulos) e posições de menu.
 * A tipografia e as cores do editor ficam em theme.json, espelhando front/src/styles/global.css.
 *
 * @package OFarol
 */

add_action(
	'after_setup_theme',
	function () {
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'align-wide' );

		// Lidas pelo front via /wp-json/farol/v1/menus/<posição> (mu-plugin farol-core).
		register_nav_menus(
			array(
				'principal'     => 'Principal — barra de editorias (as editorias também viram blocos na capa)',
				'institucional' => 'Institucional — topo e rodapé (Sobre, Contato…)',
			)
		);
	}
);
