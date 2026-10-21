<?php
/**
 * Ações do painel (admin-post.php?action=farol_newsletter_*), a prévia ao
 * vivo do montador (admin-ajax) e a página Newsletter → Automação:
 * programação do resumo, template, listas, envio de teste e estado do SMTP.
 * As outras páginas do menu ficam em admin.php.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

/** Recado para o próximo carregamento da página. */
function farol_newsletter_notice( string $type, string $message ): void {
	set_transient( 'farol_newsletter_notice_' . get_current_user_id(), array( $type, $message ), 60 );
}

/**
 * Registra uma ação de formulário (admin-post.php?action=farol_newsletter_<nome>)
 * e volta para a página que chamou.
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
			wp_safe_redirect( wp_get_referer() ?: admin_url() );
			exit;
		}
	);
}

/* ------------------------- Automação ------------------------- */

farol_newsletter_admin_action(
	'save',
	function () {
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		update_option(
			'farol_newsletter',
			array(
				'frequency'   => in_array( $in['frequency'] ?? '', array( 'off', 'daily', 'weekly' ), true ) ? $in['frequency'] : 'off',
				'weekday'     => min( 7, max( 1, (int) ( $in['weekday'] ?? 1 ) ) ),
				'hour'        => min( 23, max( 0, (int) ( $in['hour'] ?? 7 ) ) ),
				'template_id' => (int) ( $in['template_id'] ?? 0 ),
				'list_ids'    => farol_newsletter_valid_list_ids( (array) ( $in['list_ids'] ?? array() ) ),
			)
		);
		farol_newsletter_notice( 'success', 'Configurações salvas.' );
	}
);

farol_newsletter_admin_action(
	'test',
	function () {
		$edition = farol_newsletter_digest( true );
		if ( ! $edition || ! $edition['posts'] ) {
			farol_newsletter_notice( 'error', 'Ainda não há matérias publicadas para montar a edição.' );
			return;
		}
		$user = wp_get_current_user();
		$ok   = farol_newsletter_send_to( farol_newsletter_test_campaign( $edition ), $user->user_email, str_repeat( '0', 32 ) );
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
			farol_newsletter_notice( 'success', "Edição com {$result} matérias entrou na fila. O progresso aparece em Newsletter → Campanhas." );
		}
	}
);

/* ------------------------- Campanhas ------------------------- */

farol_newsletter_admin_action(
	'campaign_save',
	function () {
		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$id  = (int) ( $in['id'] ?? 0 );
		// Qual botão foi apertado: do_draft | do_test | do_schedule | do_send.
		$do = 'draft';
		foreach ( array( 'test', 'schedule', 'send' ) as $action ) {
			if ( isset( $in[ "do_{$action}" ] ) ) {
				$do = $action;
			}
		}
		// O corpo montado fica cru na coluna html; o envio embala na moldura
		// com o rodapé de descadastro. Os blocos ficam no meta para editar.
		$source = array(
			'subject'   => sanitize_text_field( $in['subject'] ?? '' ),
			'preheader' => sanitize_text_field( $in['preheader'] ?? '' ),
			'blocks'    => farol_newsletter_blocks_sanitize( (string) ( $in['blocks'] ?? '' ) ),
		);
		$built = farol_newsletter_compose( $source );

		$data = array(
			'name'    => sanitize_text_field( $in['name'] ?? '' ),
			'subject' => $built['subject'],
			'html'    => $built['html'],
			'text'    => $built['text'],
			'meta'    => array(
				// *_raw: como foi digitado (com marcadores), para reabrir no montador.
				'blocks'        => $source['blocks'],
				'subject_raw'   => $source['subject'],
				'preheader'     => $built['preheader'],
				'preheader_raw' => $source['preheader'],
				'template_id'   => (int) ( $in['template_id'] ?? 0 ),
				'list_ids'      => farol_newsletter_valid_list_ids( (array) ( $in['list_ids'] ?? array() ) ),
			),
			'kind'    => 'custom',
		);

		if ( $id ) {
			$c = farol_newsletter_campaign_get( $id );
			if ( ! $c || 'draft' !== $c->status ) {
				farol_newsletter_notice( 'error', 'Só rascunhos podem ser editados.' );
				return;
			}
			farol_newsletter_campaign_update( $id, $data );
		} else {
			$id = farol_newsletter_campaign_insert( $data );
		}
		if ( ! $id ) {
			farol_newsletter_notice( 'error', 'Não foi possível salvar a campanha.' );
			return;
		}

		switch ( $do ) {
			case 'test':
				$user = wp_get_current_user();
				$c    = farol_newsletter_campaign_get( $id );
				$ok   = farol_newsletter_send_to( farol_newsletter_test_campaign( $c ), $user->user_email, str_repeat( '0', 32 ) );
				farol_newsletter_notice( $ok ? 'success' : 'error', $ok ? "Teste enviado para {$user->user_email}. A campanha continua como rascunho." : 'O envio falhou. Confira as variáveis SMTP_* no .env.' );
				break;
			case 'schedule':
				$when = (string) ( $in['when'] ?? '' );
				$ts   = $when ? ( new DateTimeImmutable( $when, wp_timezone() ) )->getTimestamp() : 0;
				if ( $ts <= time() ) {
					farol_newsletter_notice( 'error', 'Escolha uma data futura em "Agendar para".' );
					break;
				}
				$ok = farol_newsletter_campaign_enqueue( $id, $ts );
				farol_newsletter_notice( is_wp_error( $ok ) ? 'error' : 'success', is_wp_error( $ok ) ? $ok->get_error_message() : 'Campanha agendada para ' . wp_date( 'd/m/Y H:i', $ts ) . '.' );
				if ( ! is_wp_error( $ok ) ) {
					wp_safe_redirect( farol_newsletter_admin_url() );
					exit;
				}
				break;
			case 'send':
				$ok = farol_newsletter_campaign_enqueue( $id );
				farol_newsletter_notice( is_wp_error( $ok ) ? 'error' : 'success', is_wp_error( $ok ) ? $ok->get_error_message() : 'Campanha na fila. O progresso aparece aqui na lista.' );
				if ( ! is_wp_error( $ok ) ) {
					wp_safe_redirect( farol_newsletter_admin_url() );
					exit;
				}
				break;
			default:
				farol_newsletter_notice( 'success', 'Rascunho salvo.' );
		}
		wp_safe_redirect( farol_newsletter_admin_url( 'farol-newsletter-new', array( 'id' => $id ) ) );
		exit;
	}
);

/** Cópia da campanha (ou edição) com "[Teste]" no assunto, para o envio de teste. */
function farol_newsletter_test_campaign( object|array $campaign ): object {
	$c          = clone (object) $campaign;
	$c->subject = '[Teste] ' . $c->subject;
	if ( is_array( $campaign ) ) {
		$c->meta = wp_json_encode( array( 'preheader' => $campaign['preheader'] ?? '' ) );
	}
	return $c;
}

farol_newsletter_admin_action(
	'campaign_send',
	function () {
		$id = (int) ( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ok = farol_newsletter_campaign_enqueue( $id );
		farol_newsletter_notice( is_wp_error( $ok ) ? 'error' : 'success', is_wp_error( $ok ) ? $ok->get_error_message() : 'Campanha na fila.' );
	}
);

farol_newsletter_admin_action(
	'campaign_cancel',
	function () {
		$id = (int) ( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ok = farol_newsletter_campaign_cancel( $id );
		farol_newsletter_notice( $ok ? 'warning' : 'error', $ok ? 'Campanha cancelada.' : 'Não foi possível cancelar.' );
	}
);

farol_newsletter_admin_action(
	'campaign_delete',
	function () {
		$id = (int) ( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ok = farol_newsletter_campaign_delete( $id );
		farol_newsletter_notice( $ok ? 'success' : 'error', $ok ? 'Campanha apagada.' : 'Não foi possível apagar (campanha em envio?).' );
	}
);

/* ------------------------- Inscritos ------------------------- */

farol_newsletter_admin_action(
	'add',
	function () {
		$lists = farol_newsletter_valid_list_ids( (array) wp_unslash( $_POST['list_ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ok    = farol_newsletter_add_manually( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ), $lists ); // phpcs:ignore WordPress.Security.NonceVerification
		farol_newsletter_notice( is_wp_error( $ok ) ? 'error' : 'success', is_wp_error( $ok ) ? $ok->get_error_message() : 'E-mail inscrito como ativo.' );
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

/** Ações em lote na tela de inscritos: pôr em lista, tirar de lista, apagar. */
farol_newsletter_admin_action(
	'bulk',
	function () {
		global $wpdb;
		$in   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$ids  = array_values( array_filter( array_map( 'intval', (array) ( $in['ids'] ?? array() ) ) ) );
		$list = farol_newsletter_valid_list_ids( array( (int) ( $in['list_id'] ?? 0 ) ) );
		$op   = (string) ( $in['op'] ?? '' );
		if ( ! $ids ) {
			farol_newsletter_notice( 'warning', 'Marque ao menos um inscrito.' );
			return;
		}
		switch ( $op ) {
			case 'list_add':
			case 'list_remove':
				if ( ! $list ) {
					farol_newsletter_notice( 'error', 'Escolha a lista.' );
					return;
				}
				'list_add' === $op ? farol_newsletter_members_add( $ids, $list ) : farol_newsletter_members_remove( $ids, $list );
				farol_newsletter_notice( 'success', sprintf( '%d inscritos %s "%s".', count( $ids ), 'list_add' === $op ? 'adicionados à lista' : 'removidos da lista', farol_newsletter_list_get( $list[0] )->name ) );
				break;
			case 'delete':
				$in_ids = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$emails = $wpdb->get_col( $wpdb->prepare( "SELECT email FROM %i WHERE id IN ({$in_ids})", farol_newsletter_table(), ...$ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				array_map( 'farol_newsletter_delete', $emails );
				farol_newsletter_notice( 'success', sprintf( '%d inscritos apagados de vez.', count( $emails ) ) );
				break;
			default:
				farol_newsletter_notice( 'warning', 'Escolha uma ação.' );
		}
	}
);

/* ------------------------- Listas ------------------------- */

farol_newsletter_admin_action(
	'list_save',
	function () {
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$id = (int) ( $in['id'] ?? 0 );
		$ok = farol_newsletter_list_save( $in, $id );
		farol_newsletter_notice( is_wp_error( $ok ) ? 'error' : 'success', is_wp_error( $ok ) ? $ok->get_error_message() : ( $id ? 'Lista atualizada.' : 'Lista criada.' ) );
		if ( ! is_wp_error( $ok ) ) {
			wp_safe_redirect( farol_newsletter_admin_url( 'farol-newsletter-lists' ) );
			exit;
		}
	}
);

farol_newsletter_admin_action(
	'list_delete',
	function () {
		$id = (int) ( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ok = farol_newsletter_list_delete( $id );
		farol_newsletter_notice( $ok ? 'success' : 'error', $ok ? 'Lista apagada. Os inscritos continuam cadastrados.' : 'Não foi possível apagar a lista.' );
	}
);

/* ------------------------- Templates ------------------------- */

farol_newsletter_admin_action(
	'template_save',
	function () {
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$id = farol_newsletter_template_save(
			array(
				'name'      => $in['name'] ?? '',
				'kind'      => $in['kind'] ?? 'campaign',
				'subject'   => $in['subject'] ?? '',
				'preheader' => $in['preheader'] ?? '',
				'blocks'    => (string) ( $in['blocks'] ?? '' ),
			),
			(int) ( $in['id'] ?? 0 )
		);
		if ( is_wp_error( $id ) ) {
			farol_newsletter_notice( 'error', $id->get_error_message() );
			return;
		}
		if ( isset( $in['do_test'] ) ) {
			$ok = farol_newsletter_template_test( farol_newsletter_template_get( $id ) );
			farol_newsletter_notice( $ok ? 'success' : 'error', $ok ? 'Template salvo e teste enviado para ' . wp_get_current_user()->user_email . '.' : 'Template salvo, mas o envio do teste falhou. Confira as variáveis SMTP_* no .env.' );
		} else {
			farol_newsletter_notice( 'success', 'Template salvo.' );
		}
		wp_safe_redirect( farol_newsletter_admin_url( 'farol-newsletter-templates', array( 'id' => $id ) ) );
		exit;
	}
);

/** Envia um template para o próprio admin, como ficaria de verdade. */
function farol_newsletter_template_test( object $t ): bool {
	$email = wp_get_current_user()->user_email;
	if ( 'confirmation' === $t->kind ) {
		$m = farol_newsletter_confirmation_email( farol_newsletter_link( '/newsletter/confirmar', str_repeat( '0', 32 ) ), $t );
		return farol_newsletter_mail( $email, '[Teste] ' . $m['subject'], $m['html'], $m['text'] );
	}
	return farol_newsletter_send_to( farol_newsletter_test_campaign( farol_newsletter_compose( $t, array( 'fallback' => true ) ) ), $email, str_repeat( '0', 32 ) );
}

farol_newsletter_admin_action(
	'template_duplicate',
	function () {
		$t = farol_newsletter_template_get( (int) ( wp_unslash( $_POST['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $t || 'campaign' !== $t->kind ) {
			farol_newsletter_notice( 'error', 'Só templates de campanha podem ser duplicados.' );
			return;
		}
		$id = farol_newsletter_template_save( array( 'name' => $t->name . ' (cópia)', 'kind' => $t->kind, 'subject' => $t->subject, 'preheader' => $t->preheader, 'blocks' => $t->blocks ) );
		farol_newsletter_notice( 'success', 'Template duplicado.' );
		wp_safe_redirect( farol_newsletter_admin_url( 'farol-newsletter-templates', array( 'id' => $id ) ) );
		exit;
	}
);

farol_newsletter_admin_action(
	'template_reset',
	function () {
		$ok = farol_newsletter_template_reset( (int) ( wp_unslash( $_POST['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		farol_newsletter_notice( $ok ? 'success' : 'error', $ok ? 'Template restaurado ao padrão.' : 'Só os templates de fábrica podem ser restaurados.' );
	}
);

farol_newsletter_admin_action(
	'template_delete',
	function () {
		$id = (int) ( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( (int) farol_newsletter_settings()['template_id'] === $id ) {
			farol_newsletter_notice( 'error', 'Este template está em uso no resumo automático. Troque em Automação antes de apagar.' );
			return;
		}
		$ok = farol_newsletter_template_delete( $id );
		farol_newsletter_notice( $ok ? 'success' : 'error', $ok ? 'Template apagado.' : 'Os templates de fábrica não podem ser apagados (dá para restaurar).' );
	}
);

/* ------------------------- Prévia ------------------------- */

/** Prévia do HTML do e-mail (?id=N de campanha, ?template=N, ou ?digest=1 do resumo). */
add_action(
	'admin_post_farol_newsletter_preview',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.', 403 );
		}
		check_admin_referer( 'farol_newsletter_preview' );
		$html = '';
		if ( isset( $_GET['digest'] ) ) {
			$edition = farol_newsletter_digest( true );
			if ( $edition && $edition['posts'] ) {
				$html = farol_newsletter_wrap( $edition['html'], $edition['text'], $edition['preheader'] )['html'];
			}
		} elseif ( isset( $_GET['template'] ) ) {
			$t = farol_newsletter_template_get( (int) $_GET['template'] );
			if ( $t ) {
				$html = farol_newsletter_preview_html( $t, $t->kind );
			}
		} else {
			$c = farol_newsletter_campaign_get( (int) ( $_GET['id'] ?? 0 ) );
			if ( $c ) {
				$html = farol_newsletter_campaign_html( $c );
			}
		}
		if ( ! $html ) {
			wp_die( 'Nada para mostrar.' );
		}
		echo str_replace( '{{unsubscribe}}', '#', $html ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
);

/**
 * HTML do e-mail para prévia, a partir de assunto, pré-visualização e
 * blocos (campanha/template) — com as matérias mais recentes nos blocos
 * automáticos.
 */
function farol_newsletter_preview_html( object|array $source, string $kind = 'campaign' ): string {
	if ( 'confirmation' === $kind ) {
		return farol_newsletter_confirmation_email( farol_newsletter_link( '/newsletter/confirmar', str_repeat( '0', 32 ) ), (object) $source )['html'];
	}
	$m = farol_newsletter_compose( $source, array( 'fallback' => true ) );
	return str_replace( '{{unsubscribe}}', '#', farol_newsletter_wrap( $m['html'], $m['text'], $m['preheader'] )['html'] );
}

/** Prévia ao vivo do montador: recebe os campos do formulário, devolve o HTML. */
add_action(
	'wp_ajax_farol_newsletter_render',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'farol_newsletter_render' );
		$in     = wp_unslash( $_POST );
		$kind   = 'confirmation' === ( $in['kind'] ?? '' ) ? 'confirmation' : 'campaign';
		$source = array(
			'subject'   => sanitize_text_field( $in['subject'] ?? '' ),
			'preheader' => sanitize_text_field( $in['preheader'] ?? '' ),
			'blocks'    => (string) ( $in['blocks'] ?? '' ),
		);
		$m = 'confirmation' === $kind ? null : farol_newsletter_compose( $source, array( 'fallback' => true ) );
		wp_send_json_success(
			array(
				'html'     => farol_newsletter_preview_html( $source, $kind ),
				'subject'  => $m ? $m['subject'] : farol_newsletter_vars( $source['subject'], array( 'site' => get_bloginfo( 'name' ) ) ),
				'audience' => isset( $in['list_ids'] ) ? farol_newsletter_audience_count( farol_newsletter_valid_list_ids( (array) $in['list_ids'] ) ?: array( -1 ) ) : null,
			)
		);
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
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT s.email, s.status, s.source, s.created_at, s.confirmed_at, s.unsubscribed_at,
					( SELECT GROUP_CONCAT( l.slug ORDER BY l.slug SEPARATOR ' ' ) FROM %i m JOIN %i l ON l.id = m.list_id WHERE m.subscriber_id = s.id ) AS lists
				FROM %i s ORDER BY s.id",
				farol_newsletter_members_table(),
				farol_newsletter_lists_table(),
				farol_newsletter_table()
			),
			ARRAY_N
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="newsletter-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'email', 'situacao', 'origem', 'inscrito_em', 'confirmado_em', 'saiu_em', 'listas' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
);

/** Botão de ação (form POST com nonce). $fields = campos ocultos extras. */
function farol_newsletter_action_button( string $name, string $label, string $type = 'secondary', string $confirm = '', array $fields = array() ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 6px 6px 0;"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : ''; ?>>
		<input type="hidden" name="action" value="farol_newsletter_<?php echo esc_attr( $name ); ?>">
		<?php foreach ( $fields as $k => $v ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
		<?php endforeach; ?>
		<?php wp_nonce_field( "farol_newsletter_{$name}" ); ?>
		<?php submit_button( $label, $type, 'submit', false ); ?>
	</form>
	<?php
}

/* ---------------------------------------------------------------- *
 *  Newsletter → Automação
 * ---------------------------------------------------------------- */

function farol_newsletter_render_settings(): void {
	$s        = farol_newsletter_settings();
	$c        = farol_newsletter_counts();
	$sending  = farol_newsletter_sending();
	$last     = (int) get_option( 'farol_newsletter_last_sent', 0 );
	$days     = array( 1 => 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo' );
	$quota    = farol_newsletter_quota_left();
	$limit    = farol_newsletter_daily_limit();
	$template = farol_newsletter_digest_template();
	$lists    = farol_newsletter_lists();
	$chosen   = farol_newsletter_digest_list_ids();
	$audience = farol_newsletter_audience_count( $chosen ?: array( -1 ) );
	?>
	<div class="wrap">
		<h1>Newsletter — Automação</h1>
		<?php farol_newsletter_render_notice(); ?>
		<p>Resumo com as matérias publicadas desde a última edição, para quem se inscreveu e confirmou pelo e-mail (<?php echo esc_html( number_format_i18n( $audience ) ); ?> nas listas escolhidas). O formulário fica no rodapé do site, no fim das matérias e em <code>/newsletter</code>. Cada edição vira uma campanha do tipo "Resumo automático" em <a href="<?php echo esc_url( farol_newsletter_admin_url() ); ?>">Campanhas</a>.</p>

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
					<?php if ( $limit ) : ?>
						<br>Limite diário: <strong><?php echo esc_html( farol_newsletter_sent_today() . ' / ' . $limit ); ?></strong> usados hoje<?php echo 0 === $quota ? ' — a fila continua amanhã às ' . esc_html( sprintf( '%02d:00', (int) $s['hour'] ) ) : ''; ?>.
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Inscritos</th>
				<td>
					<strong><?php echo esc_html( number_format_i18n( $c['active'] ) ); ?></strong> ativos ·
					<?php echo esc_html( number_format_i18n( $c['pending'] ) ); ?> aguardando confirmação ·
					<?php echo esc_html( number_format_i18n( $c['unsubscribed'] ) ); ?> saíram
					— <a href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-subscribers' ) ); ?>">gerenciar</a>
				</td>
			</tr>
			<tr>
				<th scope="row">Última edição</th>
				<td><?php echo $last ? esc_html( wp_date( 'd/m/Y H:i', $last ) ) : 'nenhuma ainda'; ?></td>
			</tr>
		</table>

		<?php if ( $sending ) : ?>
			<div class="notice notice-info inline">
				<p><strong>Enviando:</strong> <?php echo esc_html( $sending->subject ); ?> — <?php echo esc_html( sprintf( '%d de ~%d enviados', $sending->sent, $sending->total ) ); ?><?php echo $sending->failed ? esc_html( sprintf( ' · %d falharam', $sending->failed ) ) : ''; ?>.</p>
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
						<p class="description">O dia da semana só vale para o envio semanal. Sem matéria nova no período, a edição não sai. Quando a cota diária acaba, a fila volta nessa hora no dia seguinte.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="farol-nl-template">Template</label></th>
					<td>
						<select id="farol-nl-template" name="template_id">
							<?php foreach ( farol_newsletter_templates( 'campaign' ) as $t ) : ?>
								<option value="<?php echo (int) $t->id; ?>" <?php selected( $template && (int) $template->id === (int) $t->id ); ?>><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( $template ) : ?>
							<a href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-templates', array( 'id' => $template->id ) ) ); ?>">editar template</a>
						<?php endif; ?>
						<p class="description">Assunto, texto de abertura e blocos da edição. O bloco "Matérias recentes" pega o que foi publicado desde a última edição.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Enviar para</th>
					<td>
						<?php farol_newsletter_list_checkboxes( $chosen ); ?>
						<p class="description">Sem nenhuma marcada, vai para as listas padrão. Quem está em mais de uma recebe uma vez só.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Salvar' ); ?>
		</form>

		<h2>Enviar resumo</h2>
		<p>
			<a class="button button-secondary" href="<?php echo esc_url( farol_newsletter_preview_url( array( 'digest' => 1 ) ) ); ?>" target="_blank" rel="noopener">Ver modelo da próxima edição</a>
			<?php farol_newsletter_action_button( 'test', 'Enviar teste para mim' ); ?>
			<?php farol_newsletter_action_button( 'send', 'Enviar edição agora', 'primary', sprintf( 'Enviar a edição agora para %d inscritos?', $audience ) ); ?>
		</p>
		<p class="description">O teste vai só para o seu e-mail, com as matérias que entrariam na próxima edição.</p>
	</div>
	<?php
}
