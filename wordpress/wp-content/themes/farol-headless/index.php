<?php
/**
 * Nada é renderizado aqui: o mu-plugin farol-core redireciona para o front.
 * Se FAROL_FRONTEND_URL não estiver definido, mostra só um aviso.
 *
 * @package OFarol
 */

status_header( 200 );
?>
<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="robots" content="noindex"><title>O Farol — CMS</title></head>
<body style="font-family:system-ui;max-width:32rem;margin:4rem auto;padding:0 1rem">
	<h1>O Farol — CMS</h1>
	<p>Este endereço serve apenas o painel e a API. Defina <code>FAROL_FRONTEND_URL</code> no <code>.env</code> para redirecionar visitantes ao site.</p>
	<p><a href="<?php echo esc_url( admin_url() ); ?>">Ir para o painel</a></p>
</body>
</html>
