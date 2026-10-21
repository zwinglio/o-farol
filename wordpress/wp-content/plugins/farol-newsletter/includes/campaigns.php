<?php
/**
 * Campanhas: criadas no painel (Newsletter → Campanhas) ou pela automação
 * (resumo de matérias). Cada campanha é uma linha na tabela própria — o corpo
 * já montado em html/text; blocos, template, listas e pré-visualização no
 * meta — e vai para a fila: um envio por vez, em lotes pelo cron, respeitando o limite diário
 * do provedor SMTP (NEWSLETTER_DAILY_LIMIT no .env — ex.: 280 no plano
 * gratuito do Brevo, que fecha em 300/dia).
 *
 * Situações: draft → scheduled → sending → sent | cancelled.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

const FAROL_NEWSLETTER_BATCH  = 50;
const FAROL_NEWSLETTER_BUDGET = 40;

/* ---------------------------------------------------------------- *
 *  CRUD
 * ---------------------------------------------------------------- */

/** @return object|null Linha da campanha. */
function farol_newsletter_campaign_get( int $id ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', farol_newsletter_campaigns_table(), $id ) ) ?: null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** @return object[] Campanhas, mais recentes primeiro. */
function farol_newsletter_campaigns( int $limit = 50 ): array {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', farol_newsletter_campaigns_table(), $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** Cria uma campanha (rascunho). Retorna o id. */
function farol_newsletter_campaign_insert( array $data ): int {
	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		farol_newsletter_campaigns_table(),
		array(
			'name'       => substr( (string) ( $data['name'] ?? '' ), 0, 190 ),
			'subject'    => substr( (string) ( $data['subject'] ?? '' ), 0, 255 ),
			'html'       => (string) ( $data['html'] ?? '' ),
			'text'       => (string) ( $data['text'] ?? '' ),
			'meta'       => isset( $data['meta'] ) ? ( is_string( $data['meta'] ) ? $data['meta'] : wp_json_encode( $data['meta'] ) ) : null,
			'kind'       => in_array( $data['kind'] ?? '', array( 'digest', 'custom' ), true ) ? $data['kind'] : 'custom',
			'status'     => 'draft',
			'created_at' => farol_newsletter_now(),
		)
	);
	return (int) $wpdb->insert_id;
}

/** Atualiza campos livres de uma campanha (só rascunho/agendada). */
function farol_newsletter_campaign_update( int $id, array $data ): bool {
	global $wpdb;
	$c = farol_newsletter_campaign_get( $id );
	if ( ! $c || ! in_array( $c->status, array( 'draft', 'scheduled' ), true ) ) {
		return false;
	}
	$fields = array();
	foreach ( array( 'name', 'subject', 'html', 'text' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$fields[ $k ] = $data[ $k ];
		}
	}
	if ( isset( $data['meta'] ) ) {
		$fields['meta'] = is_string( $data['meta'] ) ? $data['meta'] : wp_json_encode( $data['meta'] );
	}
	return (bool) $wpdb->update( farol_newsletter_campaigns_table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** Apaga campanha que ainda não saiu (draft/scheduled/cancelled). */
function farol_newsletter_campaign_delete( int $id ): bool {
	global $wpdb;
	$c = farol_newsletter_campaign_get( $id );
	if ( ! $c || 'sending' === $c->status ) {
		return false;
	}
	return (bool) $wpdb->delete( farol_newsletter_campaigns_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/* ---------------------------------------------------------------- *
 *  Fila e cota diária
 * ---------------------------------------------------------------- */

/** Limite diário de envios (0 = sem limite). Vem de NEWSLETTER_DAILY_LIMIT. */
function farol_newsletter_daily_limit(): int {
	return defined( 'FAROL_NEWSLETTER_DAILY_LIMIT' ) ? max( 0, (int) FAROL_NEWSLETTER_DAILY_LIMIT ) : 0;
}

/** Quantos e-mails de campanha já saíram hoje (dia do site). */
function farol_newsletter_sent_today(): int {
	$quota = (array) get_option( 'farol_newsletter_quota', array() );
	return ( $quota['date'] ?? '' ) === wp_date( 'Y-m-d' ) ? (int) $quota['count'] : 0;
}

/** E-mails que ainda cabem hoje (null = sem limite). */
function farol_newsletter_quota_left(): ?int {
	$limit = farol_newsletter_daily_limit();
	return $limit ? max( 0, $limit - farol_newsletter_sent_today() ) : null;
}

function farol_newsletter_quota_tick(): void {
	$today = wp_date( 'Y-m-d' );
	$quota = (array) get_option( 'farol_newsletter_quota', array() );
	update_option(
		'farol_newsletter_quota',
		array(
			'date'  => $today,
			'count' => ( ( $quota['date'] ?? '' ) === $today ? (int) $quota['count'] : 0 ) + 1,
		),
		false
	);
}

/** Hora em que a fila volta a rodar quando a cota do dia acaba. */
function farol_newsletter_quota_reset_ts(): int {
	$hour = (int) farol_newsletter_settings()['hour'];
	return ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->setTime( $hour, 0 )->getTimestamp();
}

/** Há outra campanha sendo enviada agora? */
function farol_newsletter_sending(): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'sending' ORDER BY id LIMIT 1", farol_newsletter_campaigns_table() ) ) ?: null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Coloca a campanha na fila. $when = timestamp local do site (null = agora).
 * Se outra já estiver enviando, ela fica agendada para agora e entra em
 * seguida, na ordem da fila.
 */
function farol_newsletter_campaign_enqueue( int $id, ?int $when = null ): true|WP_Error {
	global $wpdb;
	$c = farol_newsletter_campaign_get( $id );
	if ( ! $c ) {
		return new WP_Error( 'farol_newsletter_404', 'Campanha não encontrada.' );
	}
	if ( ! in_array( $c->status, array( 'draft', 'scheduled', 'cancelled' ), true ) ) {
		return new WP_Error( 'farol_newsletter_status', 'Esta campanha já foi enviada ou está na fila.' );
	}
	if ( '' === trim( (string) $c->subject ) || '' === trim( (string) $c->html ) ) {
		return new WP_Error( 'farol_newsletter_empty', 'Preencha assunto e conteúdo antes de enviar.' );
	}
	// Campanhas antigas (sem listas no meta) vão para todos os ativos.
	if ( array_key_exists( 'list_ids', farol_newsletter_campaign_meta( $c ) ) && ! farol_newsletter_campaign_list_ids( $c ) ) {
		return new WP_Error( 'farol_newsletter_lists', 'Escolha para quais listas a campanha vai.' );
	}

	$now = time();
	if ( null === $when || $when <= $now ) {
		$status       = farol_newsletter_sending() ? 'scheduled' : 'sending';
		$scheduled_at = null;
		if ( 'sending' === $status ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				farol_newsletter_campaigns_table(),
				array( 'status' => 'sending', 'scheduled_at' => null, 'last_id' => 0, 'sent' => 0, 'failed' => 0, 'total' => farol_newsletter_audience_count( farol_newsletter_campaign_list_ids( $c ) ), 'started_at' => farol_newsletter_now(), 'finished_at' => null ),
				array( 'id' => $id )
			);
		} else {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				farol_newsletter_campaigns_table(),
				array( 'status' => 'scheduled', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', $now ) ),
				array( 'id' => $id )
			);
		}
		wp_schedule_single_event( $now, 'farol_newsletter_batch' );
	} else {
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			farol_newsletter_campaigns_table(),
			array( 'status' => 'scheduled', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', $when ) ),
			array( 'id' => $id )
		);
		wp_schedule_single_event( $when, 'farol_newsletter_batch' );
	}
	return true;
}

/** Cancela o envio de uma campanha na fila ou em andamento. */
function farol_newsletter_campaign_cancel( int $id ): bool {
	global $wpdb;
	$c = farol_newsletter_campaign_get( $id );
	if ( ! $c || ! in_array( $c->status, array( 'scheduled', 'sending' ), true ) ) {
		return false;
	}
	$wpdb->update( farol_newsletter_campaigns_table(), array( 'status' => 'cancelled', 'finished_at' => farol_newsletter_now() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return true;
}

/** Próxima campanha que precisa de lote: a que envia agora ou a agendada vencida. */
function farol_newsletter_next_campaign(): ?object {
	global $wpdb;
	return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT * FROM %i WHERE status = 'sending' OR ( status = 'scheduled' AND scheduled_at <= %s ) ORDER BY status = 'sending' DESC, scheduled_at, id LIMIT 1", farol_newsletter_campaigns_table(), gmdate( 'Y-m-d H:i:s' ) )
	) ?: null;
}

/** Metadados da campanha (blocos, template, listas, pré-visualização…). */
function farol_newsletter_campaign_meta( object $campaign ): array {
	return (array) json_decode( (string) ( $campaign->meta ?? '' ), true );
}

/** @return int[] Listas de destino (vazio = todos os ativos). */
function farol_newsletter_campaign_list_ids( object $campaign ): array {
	return farol_newsletter_valid_list_ids( (array) ( farol_newsletter_campaign_meta( $campaign )['list_ids'] ?? array() ) );
}

/**
 * Embala o corpo cru da campanha na moldura com o rodapé de descadastro.
 * Resumos de versões antigas já chegam embalados (têm {{unsubscribe}}).
 *
 * @return array{html: string, text: string}
 */
function farol_newsletter_wrap( string $html, string $text, string $preheader ): array {
	if ( ! str_contains( $html, '{{unsubscribe}}' ) ) {
		$footer = 'Você recebe este e-mail porque se inscreveu na newsletter do ' . esc_html( get_bloginfo( 'name' ) ) . '. '
			. '<a href="{{unsubscribe}}" style="color:#5b5e66;">Cancelar inscrição</a>.';
		$html = farol_newsletter_layout( $preheader, $html, $footer );
		$text = trim( $text ) . "\n\n---\nPara cancelar a inscrição: {{unsubscribe}}";
	}
	return array( 'html' => $html, 'text' => $text );
}

/** Texto de pré-visualização da campanha (o assunto, se não houver). */
function farol_newsletter_campaign_preheader( object $campaign ): string {
	return (string) ( farol_newsletter_campaign_meta( $campaign )['preheader'] ?? '' ) ?: (string) $campaign->subject;
}

/** HTML final da campanha para a prévia no painel (descadastro → #). */
function farol_newsletter_campaign_html( object $campaign ): string {
	$w = farol_newsletter_wrap( (string) $campaign->html, (string) $campaign->text, farol_newsletter_campaign_preheader( $campaign ) );
	return str_replace( '{{unsubscribe}}', '#', $w['html'] );
}

/**
 * Envia para um inscrito (troca o marcador de descadastro). O corpo das
 * campanhas manuais fica cru no banco; aqui ele é embalado no layout com o
 * rodapé de descadastro. Resumos já chegam embalados.
 */
function farol_newsletter_send_to( object|array $campaign, string $email, string $token ): bool {
	$campaign = (object) $campaign;
	$w        = farol_newsletter_wrap( (string) $campaign->html, (string) $campaign->text, farol_newsletter_campaign_preheader( $campaign ) );
	$url      = farol_newsletter_link( '/newsletter/sair', $token );
	return farol_newsletter_mail(
		$email,
		$campaign->subject,
		str_replace( '{{unsubscribe}}', esc_url( $url ), $w['html'] ),
		str_replace( '{{unsubscribe}}', $url, $w['text'] ),
		array(
			'List-Unsubscribe: <' . farol_newsletter_one_click_url( $token ) . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		)
	);
}

add_action( 'farol_newsletter_batch', 'farol_newsletter_run_batch' );

function farol_newsletter_run_batch(): void {
	global $wpdb;
	if ( get_transient( 'farol_newsletter_lock' ) ) {
		return;
	}
	set_transient( 'farol_newsletter_lock', 1, 2 * FAROL_NEWSLETTER_BUDGET );

	$campaign = farol_newsletter_next_campaign();
	if ( ! $campaign ) {
		delete_transient( 'farol_newsletter_lock' );
		return;
	}

	// Agendada que chegou a hora (ou entrou na fila atrás de outra): começa.
	if ( 'scheduled' === $campaign->status ) {
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			farol_newsletter_campaigns_table(),
			array( 'status' => 'sending', 'scheduled_at' => null, 'last_id' => 0, 'sent' => 0, 'failed' => 0, 'total' => farol_newsletter_audience_count( farol_newsletter_campaign_list_ids( $campaign ) ), 'started_at' => farol_newsletter_now() ),
			array( 'id' => $campaign->id )
		);
		$campaign = farol_newsletter_campaign_get( (int) $campaign->id );
	}

	$start = time();
	$quota = farol_newsletter_quota_left();
	if ( 0 === $quota ) {
		// Cota do dia estourada: volta amanhã na hora da programação.
		wp_schedule_single_event( farol_newsletter_quota_reset_ts(), 'farol_newsletter_batch' );
		delete_transient( 'farol_newsletter_lock' );
		return;
	}

	$left  = $quota; // null = sem limite
	$lists = farol_newsletter_campaign_list_ids( $campaign );
	if ( ! $lists && array_key_exists( 'list_ids', farol_newsletter_campaign_meta( $campaign ) ) ) {
		// As listas de destino foram apagadas depois de agendar: não manda para todo mundo.
		farol_newsletter_campaign_cancel( (int) $campaign->id );
		delete_transient( 'farol_newsletter_lock' );
		return;
	}
	do {
		$rows = farol_newsletter_active_after( (int) $campaign->last_id, FAROL_NEWSLETTER_BATCH, $lists );
		foreach ( $rows as $row ) {
			if ( null !== $left && $left <= 0 ) {
				break 2;
			}
			farol_newsletter_send_to( $campaign, $row->email, $row->token ) ? ++$campaign->sent : ++$campaign->failed;
			farol_newsletter_quota_tick();
			null === $left || --$left;
			$campaign->last_id = (int) $row->id;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			farol_newsletter_campaigns_table(),
			array( 'last_id' => $campaign->last_id, 'sent' => $campaign->sent, 'failed' => $campaign->failed ),
			array( 'id' => $campaign->id )
		);
	} while ( $rows && time() - $start < FAROL_NEWSLETTER_BUDGET );

	if ( ! $rows ) {
		farol_newsletter_campaign_finish( (int) $campaign->id );
	} elseif ( null !== $left && $left <= 0 ) {
		wp_schedule_single_event( farol_newsletter_quota_reset_ts(), 'farol_newsletter_batch' );
	} else {
		wp_schedule_single_event( time() + 60, 'farol_newsletter_batch' );
	}
	delete_transient( 'farol_newsletter_lock' );
}

function farol_newsletter_campaign_finish( int $id ): void {
	global $wpdb;
	$wpdb->update( farol_newsletter_campaigns_table(), array( 'status' => 'sent', 'finished_at' => farol_newsletter_now() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Pode haver outra campanha agendada esperando na fila.
	if ( farol_newsletter_next_campaign() ) {
		wp_schedule_single_event( time() + 60, 'farol_newsletter_batch' );
	}
}

/** Rótulo legível do status, para o painel. */
function farol_newsletter_status_label( string $status ): string {
	return array(
		'draft'     => 'Rascunho',
		'scheduled' => 'Agendada',
		'sending'   => 'Enviando',
		'sent'      => 'Enviada',
		'cancelled' => 'Cancelada',
	)[ $status ] ?? $status;
}
