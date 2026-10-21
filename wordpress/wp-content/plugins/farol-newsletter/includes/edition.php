<?php
/**
 * Edições: o resumo com as matérias publicadas desde a última edição.
 *
 * O cron (de hora em hora) vê se está na hora da próxima edição; quando
 * está, monta o e-mail e começa o envio em lotes. Cada execução manda o
 * que couber em ~40 s e agenda a próxima, até acabar a lista. O progresso
 * fica na opção `farol_newsletter_campaign` — se o servidor cair no meio,
 * o envio continua de onde parou.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

const FAROL_NEWSLETTER_MAX_POSTS = 12;
const FAROL_NEWSLETTER_BATCH     = 50;
const FAROL_NEWSLETTER_BUDGET    = 40;

/** @return array{frequency: string, weekday: int, hour: int, intro: string} */
function farol_newsletter_settings(): array {
	return wp_parse_args(
		(array) get_option( 'farol_newsletter', array() ),
		array(
			'frequency' => 'off', // off | daily | weekly
			'weekday'   => 1,     // 1 = segunda … 7 = domingo
			'hour'      => 7,
			'intro'     => '',
		)
	);
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
		if ( farol_newsletter_is_due() && ! get_option( 'farol_newsletter_campaign' ) ) {
			farol_newsletter_start();
		}
	}
);

add_action( 'farol_newsletter_batch', 'farol_newsletter_run_batch' );

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
 * Matérias desde a última edição (na primeira, dos últimos 7 dias).
 *
 * @return WP_Post[]
 */
function farol_newsletter_posts(): array {
	$since = (int) get_option( 'farol_newsletter_last_sent', 0 ) ?: time() - WEEK_IN_SECONDS;
	return get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'numberposts'      => FAROL_NEWSLETTER_MAX_POSTS,
			'date_query'       => array( array( 'column' => 'post_date_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $since ) ) ),
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		)
	);
}

/**
 * Monta a edição. O link de descadastro fica como {{unsubscribe}} e é
 * trocado por inscrito na hora do envio.
 *
 * @param WP_Post[] $posts
 * @return array{subject: string, html: string, text: string}
 */
function farol_newsletter_build( array $posts ): array {
	$site  = get_bloginfo( 'name' );
	$s     = farol_newsletter_settings();
	$lead  = html_entity_decode( get_the_title( $posts[0] ), ENT_QUOTES, 'UTF-8' );
	$intro = trim( $s['intro'] );

	$body = '';
	$text = '';
	if ( '' !== $intro ) {
		$body .= '<p style="margin:0 0 20px;">' . nl2br( esc_html( $intro ) ) . '</p>';
		$text .= $intro . "\n\n";
	}

	foreach ( $posts as $i => $post ) {
		$url     = get_permalink( $post );
		$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$dek     = wp_strip_all_tags( has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 30 ) );
		$cat     = get_the_category( $post->ID )[0] ?? null;
		$image   = 0 === $i ? get_the_post_thumbnail_url( $post, 'medium_large' ) : '';
		$border  = $i ? 'border-top:1px solid #e3e4e8;' : '';

		$body .= '<div style="padding:18px 0;' . $border . '">';
		if ( $image ) {
			$body .= '<a href="' . esc_url( $url ) . '"><img src="' . esc_url( $image ) . '" alt="" width="544" style="display:block;width:100%;max-width:544px;height:auto;margin:0 0 14px;border:0;"></a>';
		}
		if ( $cat ) {
			$body .= '<p style="margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#c4161c;">' . esc_html( $cat->name ) . '</p>';
		}
		$size  = 0 === $i ? 24 : 19;
		$body .= '<p style="margin:0 0 6px;font-family:Georgia,\'Times New Roman\',serif;font-size:' . $size . 'px;line-height:1.25;font-weight:700;"><a href="' . esc_url( $url ) . '" style="color:#16171a;text-decoration:none;">' . esc_html( $title ) . '</a></p>';
		if ( $dek ) {
			$body .= '<p style="margin:0;color:#5b5e66;font-size:15px;">' . esc_html( $dek ) . '</p>';
		}
		$body .= '</div>';

		$text .= "{$title}\n{$url}\n\n";
	}

	$footer = 'Você recebe este e-mail porque se inscreveu na newsletter do ' . esc_html( $site ) . '. '
		. '<a href="{{unsubscribe}}" style="color:#5b5e66;">Cancelar inscrição</a>.';
	$text  .= "---\nPara cancelar a inscrição: {{unsubscribe}}";

	return array(
		'subject' => "{$site}: {$lead}",
		'html'    => farol_newsletter_layout( count( $posts ) > 1 ? sprintf( 'E mais %d matérias', count( $posts ) - 1 ) : $lead, $body, $footer ),
		'text'    => $text,
	);
}

/** Começa uma edição. Retorna quantas matérias entraram (0 = nada novo). */
function farol_newsletter_start(): int|WP_Error {
	if ( get_option( 'farol_newsletter_campaign' ) ) {
		return new WP_Error( 'farol_newsletter_busy', 'Já há uma edição sendo enviada.' );
	}
	$posts = farol_newsletter_posts();
	if ( ! $posts ) {
		// Nada novo: conta como edição do dia, para não tentar de novo a cada hora.
		update_option( 'farol_newsletter_last_sent', time(), false );
		return 0;
	}

	$edition = farol_newsletter_build( $posts );
	update_option(
		'farol_newsletter_campaign',
		$edition + array(
			'cursor'  => 0,
			'sent'    => 0,
			'failed'  => 0,
			'total'   => farol_newsletter_counts()['active'],
			'started' => time(),
		),
		false
	);
	// A próxima edição pega o que for publicado a partir de agora.
	update_option( 'farol_newsletter_last_sent', time(), false );
	wp_schedule_single_event( time(), 'farol_newsletter_batch' );
	return count( $posts );
}

/** Troca o marcador de descadastro e envia para um inscrito. */
function farol_newsletter_send_to( array $edition, string $email, string $token ): bool {
	$url = farol_newsletter_link( '/newsletter/sair', $token );
	return farol_newsletter_mail(
		$email,
		$edition['subject'],
		str_replace( '{{unsubscribe}}', esc_url( $url ), $edition['html'] ),
		str_replace( '{{unsubscribe}}', $url, $edition['text'] ),
		array(
			'List-Unsubscribe: <' . farol_newsletter_one_click_url( $token ) . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		)
	);
}

function farol_newsletter_run_batch(): void {
	// Trava contra duas execuções simultâneas do cron.
	if ( get_transient( 'farol_newsletter_lock' ) ) {
		return;
	}
	set_transient( 'farol_newsletter_lock', 1, 2 * FAROL_NEWSLETTER_BUDGET );

	$campaign = get_option( 'farol_newsletter_campaign' );
	if ( ! $campaign ) {
		delete_transient( 'farol_newsletter_lock' );
		return;
	}

	$start = time();
	do {
		$rows = farol_newsletter_active_after( (int) $campaign['cursor'], FAROL_NEWSLETTER_BATCH );
		foreach ( $rows as $row ) {
			farol_newsletter_send_to( $campaign, $row->email, $row->token ) ? ++$campaign['sent'] : ++$campaign['failed'];
			$campaign['cursor'] = (int) $row->id;
		}
		update_option( 'farol_newsletter_campaign', $campaign, false );
	} while ( $rows && time() - $start < FAROL_NEWSLETTER_BUDGET );

	if ( $rows ) {
		wp_schedule_single_event( time() + 60, 'farol_newsletter_batch' );
	} else {
		farol_newsletter_finish( $campaign );
	}
	delete_transient( 'farol_newsletter_lock' );
}

function farol_newsletter_finish( array $campaign, bool $cancelled = false ): void {
	$log = (array) get_option( 'farol_newsletter_log', array() );
	array_unshift(
		$log,
		array(
			'date'      => $campaign['started'],
			'subject'   => $campaign['subject'],
			'sent'      => $campaign['sent'],
			'failed'    => $campaign['failed'],
			'cancelled' => $cancelled,
		)
	);
	update_option( 'farol_newsletter_log', array_slice( $log, 0, 20 ), false );
	delete_option( 'farol_newsletter_campaign' );
	wp_clear_scheduled_hook( 'farol_newsletter_batch' );
}
