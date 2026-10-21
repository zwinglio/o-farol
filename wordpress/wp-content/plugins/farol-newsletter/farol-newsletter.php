<?php
/**
 * Plugin Name:       O Farol — Newsletter
 * Description:       Newsletter completa no painel: listas, templates, montador de campanhas em blocos e resumo automático das matérias, fila de envio com limite diário, gestão de inscritos (confirmação por e-mail e descadastro com um clique).
 * Version:           0.3.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Samuel Zwinglio
 * Text Domain:       farol-newsletter
 *
 * Os e-mails saem por wp_mail(). Com SMTP_* no .env (lido em wp-config.php),
 * todos os e-mails do WordPress passam pelo serviço de envio configurado.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

define( 'FAROL_NEWSLETTER_VERSION', '0.3.0' );

require_once __DIR__ . '/includes/subscribers.php';
require_once __DIR__ . '/includes/lists.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/blocks.php';
require_once __DIR__ . '/includes/templates.php';
require_once __DIR__ . '/includes/campaigns.php';
require_once __DIR__ . '/includes/edition.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/admin.php';

register_activation_hook( __FILE__, 'farol_newsletter_install' );
register_deactivation_hook( __FILE__, fn() => wp_clear_scheduled_hook( 'farol_newsletter_tick' ) );
