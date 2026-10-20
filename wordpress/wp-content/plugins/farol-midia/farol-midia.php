<?php
/**
 * Plugin Name:       O Farol — Mídia no R2
 * Description:       Guarda imagens e vídeos no Cloudflare R2 e serve pelo domínio público do bucket. Vídeos vão do navegador direto ao R2, em partes, sem passar pelo PHP.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Samuel Zwinglio
 * Text Domain:       farol-midia
 *
 * Configuração só pelo .env (R2_*), lido em wp-config.php. Sem ele, o
 * plugin não faz nada e a mídia continua local.
 *
 * @package FarolMidia
 */

defined( 'ABSPATH' ) || exit;

define( 'FAROL_MIDIA_VERSION', '0.1.0' );

require_once __DIR__ . '/includes/class-r2-client.php';
require_once __DIR__ . '/includes/offload.php';
require_once __DIR__ . '/includes/video.php';
require_once __DIR__ . '/includes/settings.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli.php';
}
