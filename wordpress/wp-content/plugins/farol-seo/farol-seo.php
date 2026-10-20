<?php
/**
 * Plugin Name:       O Farol — SEO e GEO
 * Description:       SEO e GEO (otimização para buscadores com IA) para o front headless: metadados automáticos a partir do texto e editáveis, dados estruturados de notícia e scores de SEO/GEO no editor.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Samuel Zwinglio
 * Text Domain:       farol-seo
 *
 * @package FarolSEO
 */

defined( 'ABSPATH' ) || exit;

define( 'FAROL_SEO_VERSION', '0.1.0' );
define( 'FAROL_SEO_DIR', __DIR__ );
define( 'FAROL_SEO_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-analyzer.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/meta.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/admin.php';
