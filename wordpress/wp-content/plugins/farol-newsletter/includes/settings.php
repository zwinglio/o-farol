<?php
/**
 * Aba "Newsletter" em Configurações → O Farol: programação das edições,
 * envio manual e de teste, inscritos (exportar e apagar) e histórico.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'farol_settings_tabs',
	fn( $tabs ) => $tabs + array( 'newsletter' => array( 'label' => 'Newsletter', 'order' => 40, 'render' => 'farol_newsletter_render_settings' ) )
);

/** Recado para o próximo carregamento da aba. */
function farol_newsletter_notice( string $type, string $message ): void {
	set_transient( 'farol_newsletter_notice_' . get_current_user_id(), array( $type, $message ), 60 );
}

/**
 * Registra uma ação do formulário da aba (admin-post.php?action=farol_newsletter_<nome>).
 *
 * @param callable $handler Recebe nada; pode chamar farol_newsletter_notice().
 */
function farol_newsletter_admin_action( string $name, callable $handler ): void {
	add_action(
		"admin_post_farol_newsletter_{$name}",
		function () use ( $name, $handler ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Sem permissão.', 403 );
			}
			check_admin_referer( "farol_newsletter_{$name}" );
			$handler();
			wp_safe_redirect( farol_settings_url( 'newsletter' ) );
			exit;
		}
	);
}

farol_newsletter_admin_action(
	'save',
	function () {
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		update_option(
			'farol_newsletter',
			array(
				'frequency' => in_array( $in['frequency'] ?? '', array( 'off', 'daily', 'weekly' ), true ) ? $in['frequency'] : 'off',
				'weekday'   => min( 7, max( 1, (int) ( $in['weekday'] ?? 1 ) ) ),
				'hour'      => min( 23, max( 0, (int) ( $in['hour'] ?? 7 ) ) ),
				'intro'     => sanitize_textarea_field( $in['intro'] ?? '' ),
			)
		);
		farol_newsletter_notice( 'success', 'Configurações salvas.' );
	}
);

farol_newsletter_admin_action(
	'test',
	function () {
		$posts = farol_newsletter_posts() ?: get_posts( array( 'numberposts' => 5, 'post_status' => 'publish' ) );
		if ( ! $posts ) {
			farol_newsletter_notice( 'error', 'Ainda não há matérias publicadas para montar a edição.' );
			return;
		}
		$user    = wp_get_current_user();
		$edition = farol_newsletter_build( $posts );
		$edition['subject'] = '[Teste] ' . $edition['subject'];
		$ok = farol_newsletter_send_to( $edition, $user->user_email, str_repeat( '0', 32 ) );
		farol_newsletter_notice( $ok ? 'success' : 'error', $ok ? "Edição de teste enviada para {$user->user_email}." : 'O envio falhou. Confira as variáveis SMTP_* no .env.' );
	}
);

farol_newsletter_admin_action(
	'send',
	function () {
		$result = farol_newsletter_start();
		if ( is_wp_error( $result ) ) {
			farol_newsletter_notice( 'error', $result->get_error_message() );
		} elseif ( 0 === $result ) {
			farol_newsletter_notice( 'warning', 'Nenhuma matéria nova desde a última edição.' );
		} else {
			farol_newsletter_notice( 'success', "Edição com {$result} matérias em envio. O progresso aparece abaixo." );
		}
	}
);

farol_newsletter_admin_action(
	'cancel',
	function () {
		$campaign = get_option( 'farol_newsletter_campaign' );
		if ( $campaign ) {
			farol_newsletter_finish( $campaign, true );
			farol_newsletter_notice( 'warning', "Envio cancelado depois de {$campaign['sent']} e-mails." );
		}
	}
);

farol_newsletter_admin_action(
	'delete',
	function () {
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ok    = $email && farol_newsletter_delete( $email );
		farol_newsletter_notice( $ok ? 'success' : 'error', $ok ? "{$email} foi apagado da lista." : 'E-mail não encontrado na lista.' );
	}
);

add_action(
	'admin_post_farol_newsletter_export',
	function () {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.', 403 );
		}
		check_admin_referer( 'farol_newsletter_export' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT email, status, source, created_at, confirmed_at, unsubscribed_at FROM %i ORDER BY id', farol_newsletter_table() ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="newsletter-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'email', 'situacao', 'origem', 'inscrito_em', 'confirmado_em', 'saiu_em' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
);

/** Botão de ação da aba (form POST com nonce). */
function farol_newsletter_action_button( string $name, string $label, string $type = 'secondary', string $confirm = '' ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 6px 6px 0;"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : ''; ?>>
		<input type="hidden" name="action" value="farol_newsletter_<?php echo esc_attr( $name ); ?>">
		<?php wp_nonce_field( "farol_newsletter_{$name}" ); ?>
		<?php submit_button( $label, $type, 'submit', false ); ?>
	</form>
	<?php
}

function farol_newsletter_render_settings(): void {
	$notice = get_transient( 'farol_newsletter_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'farol_newsletter_notice_' . get_current_user_id() );
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
	}

	$s        = farol_newsletter_settings();
	$c        = farol_newsletter_counts();
	$campaign = get_option( 'farol_newsletter_campaign' );
	$last     = (int) get_option( 'farol_newsletter_last_sent', 0 );
	$log      = (array) get_option( 'farol_newsletter_log', array() );
	$days     = array( 1 => 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo' );
	?>
	<p>Resumo com as matérias publicadas desde a última edição, para quem se inscreveu e confirmou pelo e-mail. O formulário fica no rodapé do site, no fim das matérias e em <code>/newsletter</code>.</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Envio de e-mails</th>
			<td>
				<?php if ( farol_newsletter_smtp_enabled() ) : ?>
					✅ SMTP: <code><?php echo esc_html( FAROL_SMTP_HOST . ':' . FAROL_SMTP_PORT ); ?></code>
				<?php else : ?>
					⚠️ Sem <code>SMTP_*</code> no <code>.env</code>: os e-mails saem pelo PHP do servidor e tendem a cair no spam.
				<?php endif; ?>
				<br>Remetente: <code><?php echo esc_html( apply_filters( 'wp_mail_from_name', '' ) . ' <' . apply_filters( 'wp_mail_from', 'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST ) ) . '>' ); ?></code>
			</td>
		</tr>
		<tr>
			<th scope="row">Inscritos</th>
			<td>
				<strong><?php echo esc_html( number_format_i18n( $c['active'] ) ); ?></strong> ativos ·
				<?php echo esc_html( number_format_i18n( $c['pending'] ) ); ?> aguardando confirmação ·
				<?php echo esc_html( number_format_i18n( $c['unsubscribed'] ) ); ?> saíram
			</td>
		</tr>
		<tr>
			<th scope="row">Última edição</th>
			<td><?php echo $last ? esc_html( wp_date( 'd/m/Y H:i', $last ) ) : 'nenhuma ainda'; ?></td>
		</tr>
	</table>

	<?php if ( $campaign ) : ?>
		<div class="notice notice-info inline">
			<p>
				<strong>Enviando:</strong> <?php echo esc_html( $campaign['subject'] ); ?><br>
				<?php echo esc_html( sprintf( '%d de ~%d enviados', $campaign['sent'], $campaign['total'] ) ); ?>
				<?php echo $campaign['failed'] ? esc_html( sprintf( ' · %d falharam', $campaign['failed'] ) ) : ''; ?>
				— o cron manda um lote a cada execução.
			</p>
			<p><?php farol_newsletter_action_button( 'cancel', 'Cancelar envio', 'secondary', 'Cancelar o envio desta edição?' ); ?></p>
		</div>
	<?php endif; ?>

	<h2>Programação</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="farol_newsletter_save">
		<?php wp_nonce_field( 'farol_newsletter_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="farol-nl-frequency">Edições automáticas</label></th>
				<td>
					<select id="farol-nl-frequency" name="frequency">
						<option value="off" <?php selected( $s['frequency'], 'off' ); ?>>Desligadas (só envio manual)</option>
						<option value="daily" <?php selected( $s['frequency'], 'daily' ); ?>>Todo dia</option>
						<option value="weekly" <?php selected( $s['frequency'], 'weekly' ); ?>>Uma vez por semana</option>
					</select>
					<select name="weekday" aria-label="Dia da semana">
						<?php foreach ( $days as $n => $label ) : ?>
							<option value="<?php echo (int) $n; ?>" <?php selected( (int) $s['weekday'], $n ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					às
					<select name="hour" aria-label="Hora">
						<?php for ( $h = 0; $h < 24; $h++ ) : ?>
							<option value="<?php echo (int) $h; ?>" <?php selected( (int) $s['hour'], $h ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
						<?php endfor; ?>
					</select>
					<p class="description">O dia da semana só vale para o envio semanal. Sem matéria nova no período, a edição não sai.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="farol-nl-intro">Texto de abertura</label></th>
				<td>
					<textarea id="farol-nl-intro" name="intro" rows="3" class="large-text"><?php echo esc_textarea( $s['intro'] ); ?></textarea>
					<p class="description">Opcional. Aparece antes das matérias em todas as edições.</p>
				</td>
			</tr>
		</table>
		<?php submit_button( 'Salvar' ); ?>
	</form>

	<h2>Enviar</h2>
	<p>
		<?php farol_newsletter_action_button( 'test', 'Enviar teste para mim' ); ?>
		<?php
		if ( ! $campaign ) {
			farol_newsletter_action_button( 'send', 'Enviar edição agora', 'primary', sprintf( 'Enviar a edição agora para %d inscritos?', $c['active'] ) );
		}
		?>
	</p>
	<p class="description">O teste vai só para o seu e-mail, com as matérias que entrariam na próxima edição.</p>

	<h2>Inscritos</h2>
	<p><?php farol_newsletter_action_button( 'export', 'Exportar CSV' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Apagar este e-mail da lista de vez?')">
		<input type="hidden" name="action" value="farol_newsletter_delete">
		<?php wp_nonce_field( 'farol_newsletter_delete' ); ?>
		<label for="farol-nl-delete">Apagar um e-mail de vez (pedido de exclusão de dados):</label><br>
		<input type="email" id="farol-nl-delete" name="email" class="regular-text" required>
		<?php submit_button( 'Apagar', 'delete', 'submit', false ); ?>
	</form>

	<?php if ( $log ) : ?>
		<h2>Histórico</h2>
		<table class="widefat striped" style="max-width:60rem;">
			<thead><tr><th>Data</th><th>Assunto</th><th>Enviados</th><th>Falhas</th></tr></thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd/m/Y H:i', $entry['date'] ) ); ?></td>
						<td><?php echo esc_html( $entry['subject'] ); ?><?php echo $entry['cancelled'] ? ' <em>(cancelado)</em>' : ''; ?></td>
						<td><?php echo (int) $entry['sent']; ?></td>
						<td><?php echo (int) $entry['failed']; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}
