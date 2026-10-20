<?php
/**
 * Plugin Name:       O Farol — Fontes
 * Description:       Bloco "Fonte": cite posts (X, Bluesky, Mastodon), vídeos (YouTube, Vimeo, TikTok) e matérias de outros veículos como cartões estáticos, com cópia do conteúdo, sem scripts de terceiros e com dados estruturados.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Samuel Zwinglio
 * Text Domain:       farol-fontes
 *
 * O editor do bloco é compilado de src/ para build/ (cd neste diretório e
 * `bun install && bun run build`). O build/ é versionado: o servidor não precisa de Node.
 *
 * @package FarolFontes
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-resolver.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/registry.php';
require_once __DIR__ . '/includes/rest.php';

add_action(
	'init',
	function () {
		register_block_type( __DIR__ . '/block.json', array( 'render_callback' => 'farol_fontes_render' ) );
	}
);

/** A análise de SEO/GEO lê o bloco já renderizado (texto do post, links, citação). */
add_filter( 'farol_seo_content', fn( $content ) => has_block( 'farol/fonte', $content ) ? do_blocks( $content ) : $content );
