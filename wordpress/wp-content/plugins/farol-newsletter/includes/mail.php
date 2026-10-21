<?php
/**
 * Envio: SMTP pelo .env (SMTP_*), remetente, a moldura HTML dos e-mails e o
 * e-mail de confirmação (conteúdo no template "Confirmação").
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

function farol_newsletter_smtp_enabled(): bool {
	return defined( 'FAROL_SMTP_HOST' ) && '' !== FAROL_SMTP_HOST;
}

add_action(
	'phpmailer_init',
	function ( $mailer ) {
		if ( ! farol_newsletter_smtp_enabled() ) {
			return;
		}
		$mailer->isSMTP();
		$mailer->Host       = FAROL_SMTP_HOST;
		$mailer->Port       = FAROL_SMTP_PORT;
		// none: sem criptografia (só para testes locais).
		$mailer->SMTPSecure  = 'none' === FAROL_SMTP_SECURE ? '' : FAROL_SMTP_SECURE;
		$mailer->SMTPAutoTLS = 'none' !== FAROL_SMTP_SECURE;
		if ( '' !== FAROL_SMTP_USER ) {
			$mailer->SMTPAuth = true;
			$mailer->Username = FAROL_SMTP_USER;
			$mailer->Password = FAROL_SMTP_PASS;
		}
	}
);

add_filter( 'wp_mail_from', fn( $from ) => defined( 'FAROL_MAIL_FROM' ) && '' !== FAROL_MAIL_FROM ? FAROL_MAIL_FROM : $from );
add_filter( 'wp_mail_from_name', fn() => defined( 'FAROL_MAIL_FROM_NAME' ) && '' !== FAROL_MAIL_FROM_NAME ? FAROL_MAIL_FROM_NAME : get_bloginfo( 'name' ) );

/** URL de uma página da newsletter no site público, com o token. */
function farol_newsletter_link( string $path, string $token ): string {
	$base = farol_frontend_url( $path ) ?: home_url( $path );
	return add_query_arg( 'token', $token, $base );
}

/** Endpoint de descadastro com um clique (List-Unsubscribe-Post, RFC 8058). */
function farol_newsletter_one_click_url( string $token ): string {
	return add_query_arg( 'token', $token, rest_url( 'farol-newsletter/v1/unsubscribe' ) );
}

/**
 * Envia um e-mail HTML com versão em texto.
 *
 * @param string[] $headers
 */
function farol_newsletter_mail( string $to, string $subject, string $html, string $text, array $headers = array() ): bool {
	$alt = function ( $mailer ) use ( $text ) {
		$mailer->AltBody = $text;
	};
	add_action( 'phpmailer_init', $alt );
	$ok = wp_mail( $to, $subject, $html, array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), $headers ) );
	remove_action( 'phpmailer_init', $alt );
	return $ok;
}

/**
 * Moldura comum dos e-mails: tabelas e estilos inline, que é o que os
 * clientes de e-mail entendem.
 */
function farol_newsletter_layout( string $preheader, string $body, string $footer ): string {
	$site = esc_html( get_bloginfo( 'name' ) );
	$home = esc_url( farol_frontend_url() ?: home_url( '/' ) );
	ob_start();
	?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?php echo $site; // phpcs:ignore WordPress.Security.EscapeOutput ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f6;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?php echo esc_html( $preheader ); ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f6;">
<tr><td align="center" style="padding:24px 12px;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-top:4px solid #16171a;">
		<tr><td style="padding:24px 28px 8px;font-family:Georgia,'Times New Roman',serif;font-size:28px;font-weight:700;color:#16171a;">
			<a href="<?php echo $home; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="color:#16171a;text-decoration:none;"><?php echo $site; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
		</td></tr>
		<tr><td style="padding:8px 28px 28px;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:16px;line-height:1.55;color:#16171a;">
			<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</td></tr>
		<tr><td style="padding:18px 28px;border-top:1px solid #e3e4e8;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:12px;line-height:1.5;color:#5b5e66;">
			<?php echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</td></tr>
	</table>
</td></tr>
</table>
</body>
</html>
	<?php
	return (string) ob_get_clean();
}

function farol_newsletter_button( string $url, string $label ): string {
	return sprintf(
		'<p style="margin:24px 0;"><a href="%s" style="display:inline-block;padding:12px 22px;background:#c4161c;color:#ffffff;font-weight:700;text-decoration:none;">%s</a></p>',
		esc_url( $url ),
		esc_html( $label )
	);
}

/** E-mail de confirmação, montado com o template "Confirmação". */
function farol_newsletter_send_confirmation( string $email, string $token ): bool {
	$url = farol_newsletter_link( '/newsletter/confirmar', $token );
	$m   = farol_newsletter_confirmation_email( $url );
	return farol_newsletter_mail( $email, $m['subject'], $m['html'], $m['text'] );
}

/**
 * Monta o e-mail de confirmação. O link também vai no rodapé, fora do
 * template, para a confirmação funcionar mesmo que o botão seja apagado.
 *
 * @return array{subject: string, html: string, text: string}
 */
function farol_newsletter_confirmation_email( string $url, ?object $template = null ): array {
	$m      = farol_newsletter_compose( $template ?? farol_newsletter_confirmation_template(), array( 'vars' => array( 'link_confirmacao' => $url ) ) );
	$footer = 'Se o botão não funcionar, copie e cole este endereço no navegador:<br><a href="' . esc_url( $url ) . '" style="color:#5b5e66;word-break:break-all;">' . esc_html( $url ) . '</a><br><br>'
		. 'Se não foi você, ignore este e-mail: sem a confirmação, você não será inscrito.';
	return array(
		'subject' => $m['subject'],
		'html'    => farol_newsletter_layout( $m['preheader'], $m['html'], $footer ),
		'text'    => $m['text'] . "\nPara confirmar, abra:\n{$url}\n\nSe não foi você, ignore este e-mail.",
	);
}
