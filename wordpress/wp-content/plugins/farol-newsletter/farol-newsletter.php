<?php
/**
 * Plugin Name:       O Farol — Newsletter
 * Description:       Inscrição com confirmação por e-mail, resumo automático das matérias (diário ou semanal) enviado em lotes pelo cron e descadastro com um clique.
 * Version:           0.1.0
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

define( 'FAROL_NEWSLETTER_VERSION', '0.1.0' );

require_once __DIR__ . '/includes/subscribers.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/edition.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/settings.php';

register_activation_hook( __FILE__, 'farol_newsletter_install' );
register_deactivation_hook( __FILE__, fn() => wp_clear_scheduled_hook( 'farol_newsletter_tick' ) );
