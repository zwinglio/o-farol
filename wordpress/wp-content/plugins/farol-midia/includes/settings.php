<?php
/**
 * Aba "Mídia" em Configurações → O Farol: estado e teste de conexão.
 * A configuração em si fica só no .env (R2_*): nada de segredo no banco.
 *
 * @package FarolMidia
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'farol_settings_tabs',
	fn( $tabs ) => $tabs + array( 'midia' => array( 'label' => 'Mídia', 'order' => 50, 'render' => 'farol_midia_render_settings' ) )
);

/** Contagem de anexos no R2 e pendentes. */
function farol_midia_counts(): array {
	global $wpdb;
	$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
	$offloaded = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_farol_r2'" );
	$errors    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_farol_r2_error'" );
	return compact( 'total', 'offloaded', 'errors' );
}

/**
 * Testa gravar, ler pelo domínio público e apagar um arquivo.
 *
 * @return array<int, array{0: bool, 1: string}>
 */
function farol_midia_selftest(): array {
	$client = farol_midia_client();
	if ( ! $client ) {
		return array( array( false, 'Configuração incompleta no .env.' ) );
	}
	$key   = 'farol-teste/' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false ) . '.txt';
	$tmp   = wp_tempnam( 'farol-r2' );
	$token = wp_generate_password( 24, false );
	file_put_contents( $tmp, $token ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$steps = array();

	$put     = $client->put_file( $key, $tmp, 'text/plain', 'no-store' );
	$steps[] = array( ! is_wp_error( $put ), is_wp_error( $put ) ? 'Gravar no bucket: ' . $put->get_error_message() : 'Gravar no bucket: ok' );
	wp_delete_file( $tmp );
	if ( is_wp_error( $put ) ) {
		return $steps;
	}

	$public  = wp_remote_get( farol_midia_url( $key ), array( 'timeout' => 15, 'headers' => array( 'Origin' => home_url() ) ) );
	$ok      = ! is_wp_error( $public ) && 200 === wp_remote_retrieve_response_code( $public ) && wp_remote_retrieve_body( $public ) === $token;
	$steps[] = array( $ok, $ok ? 'Ler pelo domínio público (' . FAROL_R2_PUBLIC_URL . '): ok' : 'Ler pelo domínio público: falhou (' . ( is_wp_error( $public ) ? $public->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $public ) ) . '). Confira o Custom Domain do bucket.' );

	if ( $ok ) {
		$cors    = wp_remote_retrieve_header( $public, 'access-control-allow-origin' );
		$steps[] = array( (bool) $cors, $cors ? "CORS para o painel: ok ({$cors})" : 'CORS: o bucket não liberou ' . home_url() . '. Sem isso, Imagens para redes e envio de vídeo falham (ver DEPLOY.md).' );
	}

	$del     = $client->delete( $key );
	$steps[] = array( ! is_wp_error( $del ), is_wp_error( $del ) ? 'Apagar: ' . $del->get_error_message() : 'Apagar: ok' );
	return $steps;
}

add_action(
	'admin_post_farol_midia_test',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.', 403 );
		}
		check_admin_referer( 'farol_midia_test' );
		set_transient( 'farol_midia_test_' . get_current_user_id(), farol_midia_selftest(), 60 );
		wp_safe_redirect( farol_settings_url( 'midia' ) );
		exit;
	}
);

function farol_midia_render_settings(): void {
	$test = get_transient( 'farol_midia_test_' . get_current_user_id() );
	if ( $test ) {
		delete_transient( 'farol_midia_test_' . get_current_user_id() );
		$all = ! in_array( false, array_column( $test, 0 ), true );
		echo '<div class="notice notice-' . ( $all ? 'success' : 'error' ) . '"><ul>';
		foreach ( $test as [ $ok, $msg ] ) {
			echo '<li>' . ( $ok ? '✅ ' : '❌ ' ) . esc_html( $msg ) . '</li>';
		}
		echo '</ul></div>';
	}

	$on = farol_midia_enabled();
	$c  = farol_midia_counts();
	?>
	<p>Imagens e vídeos ficam no <strong>Cloudflare R2</strong> e são servidos pelo domínio público do bucket. A configuração fica só no <code>.env</code> do servidor (variáveis <code>R2_*</code>).</p>
	<table class="form-table" role="presentation">
		<tr><th scope="row">Estado</th><td><?php echo $on ? '✅ Ativo' : '⚪ Desligado — mídia fica no servidor'; ?></td></tr>
		<?php if ( $on ) : ?>
			<tr><th scope="row">Bucket</th><td><code><?php echo esc_html( FAROL_R2_BUCKET ); ?></code></td></tr>
			<tr><th scope="row">Domínio público</th><td><code><?php echo esc_html( FAROL_R2_PUBLIC_URL ); ?></code></td></tr>
			<tr><th scope="row">Cópia no servidor</th><td><?php echo FAROL_R2_KEEP_LOCAL ? 'Mantida (<code>R2_KEEP_LOCAL=true</code>)' : 'Não mantida: novos uploads ficam só no R2'; ?></td></tr>
		<?php endif; ?>
		<tr>
			<th scope="row">Arquivos</th>
			<td>
				<?php echo esc_html( sprintf( '%d de %d anexos no R2', $c['offloaded'], $c['total'] ) ); ?>
				<?php if ( $c['errors'] ) : ?>
					<br><strong style="color:#b32d2e"><?php echo esc_html( "{$c['errors']} com erro de envio" ); ?></strong>
				<?php endif; ?>
				<?php if ( $on && $c['offloaded'] < $c['total'] ) : ?>
					<p class="description">Para enviar os que faltam: <code>wp farol-midia sync</code> no servidor.</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php if ( $on ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="farol_midia_test">
			<?php wp_nonce_field( 'farol_midia_test' ); ?>
			<?php submit_button( 'Testar conexão', 'secondary', 'submit', false ); ?>
			<span class="description">Grava, lê pelo domínio público, confere o CORS e apaga um arquivo de teste.</span>
		</form>
		<p><a class="button" href="<?php echo esc_url( admin_url( 'upload.php?page=farol-video' ) ); ?>">Enviar vídeo</a></p>
	<?php endif; ?>
	<?php
}
