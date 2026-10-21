<?php
/**
 * Edições (automação): o resumo com as matérias publicadas desde a última
 * edição, montado com o template escolhido em Newsletter → Automação
 * ("Semanal" de fábrica) e enviado para as listas escolhidas lá. O cron de
 * hora em hora vê se está na hora da próxima; quando está, monta o e-mail e
 * coloca uma campanha do tipo "digest" na fila (a fila, em
 * includes/campaigns.php, manda em lotes e respeita o limite diário do
 * provedor SMTP).
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

const FAROL_NEWSLETTER_MAX_POSTS = 12;

/** @return array{frequency: string, weekday: int, hour: int, template_id: int, list_ids: int[]} */
function farol_newsletter_settings(): array {
	return wp_parse_args(
		(array) get_option( 'farol_newsletter', array() ),
		array(
			'frequency'   => 'off', // off | daily | weekly
			'weekday'     => 1,     // 1 = segunda … 7 = domingo
			'hour'        => 7,
			'template_id' => 0,       // 0 = "Semanal"
			'list_ids'    => array(), // vazio = listas padrão
		)
	);
}

/** Template do resumo: o escolhido na Automação, ou o "Semanal". */
function farol_newsletter_digest_template(): ?object {
	$id = (int) farol_newsletter_settings()['template_id'];
	$t  = $id ? farol_newsletter_template_get( $id ) : null;
	return $t && 'campaign' === $t->kind ? $t : farol_newsletter_template_by_slug( 'semanal' );
}

/** @return int[] Listas do resumo: as escolhidas na Automação, ou as padrão. */
function farol_newsletter_digest_list_ids(): array {
	return farol_newsletter_valid_list_ids( (array) farol_newsletter_settings()['list_ids'] ) ?: farol_newsletter_default_list_ids();
}

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'farol_newsletter_tick' ) ) {
			wp_schedule_event( time(), 'hourly', 'farol_newsletter_tick' );
		}
	}
);

add_action(
	'farol_newsletter_tick',
	function () {
		// Rede de segurança: se alguma campanha ficou parada (ex.: servidor
		// reiniciou entre dois lotes), o tick retoma a fila.
		if ( farol_newsletter_next_campaign() && ! wp_next_scheduled( 'farol_newsletter_batch' ) ) {
			wp_schedule_single_event( time(), 'farol_newsletter_batch' );
		}
		if ( farol_newsletter_is_due() && ! farol_newsletter_sending() ) {
			farol_newsletter_start();
		}
	}
);

/** Hora da edição programada e ainda não saiu nenhuma hoje? */
function farol_newsletter_is_due(): bool {
	$s = farol_newsletter_settings();
	if ( 'off' === $s['frequency'] ) {
		return false;
	}
	$now = new DateTimeImmutable( 'now', wp_timezone() );
	if ( (int) $now->format( 'G' ) < (int) $s['hour'] ) {
		return false;
	}
	if ( 'weekly' === $s['frequency'] && (int) $now->format( 'N' ) !== (int) $s['weekday'] ) {
		return false;
	}
	$last = (int) get_option( 'farol_newsletter_last_sent', 0 );
	return ! $last || wp_date( 'Y-m-d', $last ) !== $now->format( 'Y-m-d' );
}

/**
 * Monta o resumo com o template, pegando as matérias desde a última edição
 * (na primeira, dos últimos 7 dias). $fallback = true usa as mais recentes
 * quando não há nada novo (prévia e teste).
 *
 * @return array{subject: string, preheader: string, html: string, text: string, posts: WP_Post[]}|null
 */
function farol_newsletter_digest( bool $fallback = false ): ?array {
	$t = farol_newsletter_digest_template();
	if ( ! $t ) {
		return null;
	}
	$since = (int) get_option( 'farol_newsletter_last_sent', 0 ) ?: time() - WEEK_IN_SECONDS;
	return farol_newsletter_compose( $t, array( 'since' => $since, 'fallback' => $fallback ) );
}

/** Matérias recentes para o seletor do montador. */
function farol_newsletter_recent_posts( int $limit = 40 ): array {
	return get_posts(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'numberposts' => $limit,
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);
}

/**
 * Cria a edição do resumo e a põe na fila. Retorna quantas matérias
 * entraram (0 = nada novo).
 */
function farol_newsletter_start(): int|WP_Error {
	$edition = farol_newsletter_digest();
	if ( ! $edition || ! $edition['posts'] ) {
		// Nada novo: conta como edição do dia, para não tentar de novo a cada hora.
		update_option( 'farol_newsletter_last_sent', time(), false );
		return 0;
	}
	$lists = farol_newsletter_digest_list_ids();
	if ( ! $lists ) {
		return new WP_Error( 'farol_newsletter_lists', 'Nenhuma lista para o resumo: escolha uma em Newsletter → Automação ou marque uma lista como padrão.' );
	}

	$t  = farol_newsletter_digest_template();
	$id = farol_newsletter_campaign_insert(
		array(
			'name'    => sprintf( 'Resumo de %s', wp_date( 'd/m/Y' ) ),
			'subject' => $edition['subject'],
			'html'    => $edition['html'],
			'text'    => $edition['text'],
			'kind'    => 'digest',
			'meta'    => array(
				'template_id' => (int) $t->id,
				'list_ids'    => $lists,
				'preheader'   => $edition['preheader'],
				'post_ids'    => wp_list_pluck( $edition['posts'], 'ID' ),
			),
		)
	);
	// A próxima edição pega o que for publicado a partir de agora.
	update_option( 'farol_newsletter_last_sent', time(), false );
	return farol_newsletter_campaign_enqueue( $id ) === true ? count( $edition['posts'] ) : new WP_Error( 'farol_newsletter_queue', 'A edição foi criada, mas não entrou na fila.' );
}
