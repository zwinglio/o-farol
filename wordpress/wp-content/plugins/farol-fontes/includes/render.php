<?php
/**
 * HTML do bloco Fonte. Estático e sem scripts de terceiros: o player de
 * vídeo só é carregado quando o leitor clica (script mínimo no front).
 * Estilos: front/src/styles/fontes.css (espelhado em assets/fonte.css para o editor).
 *
 * @package FarolFontes
 */

defined( 'ABSPATH' ) || exit;

/** Modos possíveis para cada tipo de fonte. */
function farol_fontes_modes( string $kind ): array {
	return array(
		'video' => array( 'video', 'card' ),
		'post'  => array( 'post', 'card' ),
		'page'  => array( 'card' ),
	)[ $kind ] ?? array( 'card' );
}

function farol_fontes_render( array $attrs ): string {
	$s = $attrs['source'] ?? null;
	if ( empty( $s['url'] ) ) {
		return '';
	}

	$modes = farol_fontes_modes( $s['kind'] ?? 'page' );
	$mode  = in_array( $attrs['mode'] ?? '', $modes, true ) ? $attrs['mode'] : $modes[0];

	$body = match ( $mode ) {
		'post'  => farol_fontes_render_post( $s ),
		'video' => farol_fontes_render_video( $s ),
		default => farol_fontes_render_card( $s ),
	};

	$caption = trim( (string) ( $attrs['caption'] ?? '' ) );

	return sprintf(
		'<figure class="farol-fonte farol-fonte--%1$s farol-fonte--%2$s">%3$s%4$s</figure>',
		esc_attr( $mode ),
		esc_attr( $s['platform'] ?? 'web' ),
		$body,
		$caption ? '<figcaption class="farol-fonte__caption">' . wp_kses_post( $caption ) . '</figcaption>' : ''
	);
}

function farol_fontes_img( ?array $img, string $class, string $alt = '', string $loading = 'lazy' ): string {
	if ( empty( $img['url'] ) ) {
		return '';
	}
	// Endereço atual do anexo (acompanha a mudança para o R2); a URL gravada é reserva.
	if ( ! empty( $img['id'] ) && ( $current = wp_get_attachment_url( (int) $img['id'] ) ) ) {
		$img['url'] = $current;
	}
	return sprintf(
		'<img class="%s" src="%s" alt="%s"%s loading="%s" decoding="async">',
		esc_attr( $class ),
		esc_url( $img['url'] ),
		esc_attr( $alt ?: ( $img['alt'] ?? '' ) ),
		! empty( $img['width'] ) && ! empty( $img['height'] ) ? sprintf( ' width="%d" height="%d"', $img['width'], $img['height'] ) : '',
		esc_attr( $loading )
	);
}

function farol_fontes_date( string $iso, bool $with_time = false ): string {
	// Só o dia (AAAA-MM-DD): exibe como veio, sem hora e sem conversão de fuso.
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso ) ) {
		$utc = new DateTimeZone( 'UTC' );
		return sprintf( '<time datetime="%s">%s</time>', esc_attr( $iso ), esc_html( wp_date( 'j \d\e F \d\e Y', strtotime( "{$iso} UTC" ), $utc ) ) );
	}
	if ( ! $iso || ! ( $ts = strtotime( $iso ) ) ) {
		return '';
	}
	$label = wp_date( $with_time ? 'j \d\e F \d\e Y, H:i' : 'j \d\e F \d\e Y', $ts );
	return sprintf( '<time datetime="%s">%s</time>', esc_attr( gmdate( 'c', $ts ) ), esc_html( $label ) );
}

/** Texto de post social: parágrafos, quebras e links clicáveis. */
function farol_fontes_text( string $text ): string {
	$html = make_clickable( esc_html( $text ) );
	$html = str_replace( 'rel="nofollow"', 'rel="nofollow noopener" target="_blank"', $html );
	return wpautop( $html );
}

function farol_fontes_render_post( array $s ): string {
	$a      = $s['author'] ?? array();
	$handle = $a['handle'] ?? '';
	$handle = $handle ? '@' . ltrim( $handle, '@' ) : '';

	return sprintf(
		'<blockquote class="farol-fonte__post" cite="%1$s">
			<header class="farol-fonte__author">
				%2$s
				<span class="farol-fonte__who"><strong>%3$s</strong>%4$s</span>
				<span class="farol-fonte__platform">%5$s</span>
			</header>
			<div class="farol-fonte__text">%6$s</div>
			%7$s
			<footer class="farol-fonte__meta">%8$s<a href="%1$s" target="_blank" rel="noopener">Ver no %5$s ↗</a></footer>
		</blockquote>',
		esc_url( $s['url'] ),
		$a['avatar'] ?? null ? farol_fontes_img( $a['avatar'], 'farol-fonte__avatar', '' ) : '<span class="farol-fonte__avatar farol-fonte__avatar--empty" aria-hidden="true">' . esc_html( mb_substr( $a['name'] ?? '?', 0, 1 ) ) . '</span>',
		esc_html( $a['name'] ?? '' ),
		$handle ? ' <span>' . esc_html( $handle ) . '</span>' : '',
		esc_html( $s['provider'] ?? '' ),
		farol_fontes_text( (string) ( $s['text'] ?? '' ) ),
		farol_fontes_img( $s['image'] ?? null, 'farol-fonte__media' ),
		( $d = farol_fontes_date( (string) ( $s['date'] ?? '' ), true ) ) ? $d . ' · ' : ''
	);
}

/** Só estes players podem virar iframe: o atributo vem do post e poderia apontar para qualquer site. */
function farol_fontes_safe_embed( string $url ): string {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	$ok   = in_array( $host, array( 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.tiktok.com' ), true )
		&& str_starts_with( $url, 'https://' );
	return $ok ? $url : '';
}

function farol_fontes_render_video( array $s ): string {
	$title = (string) ( $s['title'] ?? '' );
	$embed = farol_fontes_safe_embed( (string) ( $s['embed']['url'] ?? '' ) );
	$ratio = preg_match( '#^\d+/\d+$#', $s['embed']['ratio'] ?? '' ) ? $s['embed']['ratio'] : '16/9';
	$by    = array_filter( array( $s['author']['name'] ?? '', $s['provider'] ?? '' ) );

	// Sem JS, o link leva ao vídeo na plataforma. Com JS, vira o player (sem cookies).
	return sprintf(
		'<a class="farol-fonte__player" href="%1$s" target="_blank" rel="noopener"%2$s data-title="%3$s" style="aspect-ratio:%4$s">
			%5$s
			<span class="farol-fonte__play" aria-hidden="true"></span>
			<span class="screen-reader-text">Reproduzir vídeo: %3$s</span>
		</a>
		<p class="farol-fonte__video-meta"><a href="%1$s" target="_blank" rel="noopener">%6$s</a>%7$s</p>',
		esc_url( $s['url'] ),
		$embed ? ' data-embed="' . esc_url( $embed ) . '"' : '',
		esc_attr( $title ?: 'vídeo' ),
		esc_attr( str_replace( '/', ' / ', $ratio ) ),
		farol_fontes_img( $s['image'] ?? null, 'farol-fonte__thumb', '' ) ?: '<span class="farol-fonte__thumb farol-fonte__thumb--empty"></span>',
		esc_html( $title ?: 'Assistir no ' . ( $s['provider'] ?? '' ) ),
		$by ? ' <span>' . esc_html( implode( ' · ', $by ) ) . '</span>' : ''
	);
}

function farol_fontes_render_card( array $s ): string {
	$title = $s['title'] ?: ( $s['text'] ? wp_trim_words( $s['text'], 14 ) : $s['url'] );
	$desc  = $s['title'] ? wp_trim_words( (string) ( $s['text'] ?? '' ), 32 ) : '';
	$site  = $s['site']['name'] ?? ( $s['provider'] ?? '' );
	$meta  = array_filter( array( $s['author']['name'] ?? '', farol_fontes_date( (string) ( $s['date'] ?? '' ) ) ) );

	return sprintf(
		'<a class="farol-fonte__link" href="%1$s" target="_blank" rel="noopener">
			<span class="farol-fonte__body">
				<span class="farol-fonte__site">%2$s%3$s</span>
				<strong class="farol-fonte__title">%4$s</strong>
				%5$s
				%6$s
			</span>
			%7$s
		</a>',
		esc_url( $s['url'] ),
		farol_fontes_img( $s['site']['icon'] ?? null, 'farol-fonte__icon', '' ),
		esc_html( $site ),
		esc_html( $title ),
		$desc ? '<span class="farol-fonte__desc">' . esc_html( $desc ) . '</span>' : '',
		$meta ? '<span class="farol-fonte__byline">' . implode( ' · ', array_map( fn( $m ) => str_starts_with( $m, '<time' ) ? $m : esc_html( $m ), $meta ) ) . '</span>' : '',
		farol_fontes_img( $s['image'] ?? null, 'farol-fonte__cover', '' )
	);
}
