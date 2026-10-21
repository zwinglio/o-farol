<?php
/**
 * Blocos do e-mail: o montador do painel guarda a campanha (ou o template)
 * como uma lista de blocos em JSON, e aqui eles viram HTML com estilos
 * inline — o que os clientes de e-mail entendem — e a versão em texto.
 *
 * Tipos: heading, text, posts (matérias escolhidas), latest (matérias
 * recentes, resolvidas na hora de montar), image, button, divider, spacer.
 *
 * Marcadores aceitos nos textos, títulos e links: {{site}}, {{manchete}}
 * (título da primeira matéria), {{data}} e, no e-mail de confirmação,
 * {{link_confirmacao}}.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

const FAROL_NEWSLETTER_BLOCK_TYPES = array( 'heading', 'text', 'posts', 'latest', 'image', 'button', 'divider', 'spacer' );

/**
 * Limpa os blocos vindos do painel (JSON ou array).
 *
 * @param string|array $blocks
 * @return array[]
 */
function farol_newsletter_blocks_sanitize( string|array $blocks ): array {
	if ( is_string( $blocks ) ) {
		$blocks = json_decode( $blocks, true );
	}
	$out = array();
	foreach ( (array) $blocks as $b ) {
		$type = is_array( $b ) ? ( $b['type'] ?? '' ) : '';
		if ( ! in_array( $type, FAROL_NEWSLETTER_BLOCK_TYPES, true ) ) {
			continue;
		}
		$out[] = match ( $type ) {
			'heading' => array( 'type' => $type, 'text' => sanitize_text_field( $b['text'] ?? '' ), 'size' => 'md' === ( $b['size'] ?? '' ) ? 'md' : 'lg' ),
			'text'    => array( 'type' => $type, 'html' => wp_kses_post( (string) ( $b['html'] ?? '' ) ) ),
			'posts'   => array( 'type' => $type, 'ids' => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $b['ids'] ?? array() ) ) ) ) ), 'feature' => ! empty( $b['feature'] ) ),
			'latest'  => array( 'type' => $type, 'count' => min( FAROL_NEWSLETTER_MAX_POSTS, max( 1, (int) ( $b['count'] ?? 6 ) ) ), 'feature' => ! empty( $b['feature'] ) ),
			'image'   => array( 'type' => $type, 'url' => esc_url_raw( (string) ( $b['url'] ?? '' ) ), 'alt' => sanitize_text_field( $b['alt'] ?? '' ), 'link' => farol_newsletter_sanitize_link( (string) ( $b['link'] ?? '' ) ) ),
			'button'  => array( 'type' => $type, 'label' => sanitize_text_field( $b['label'] ?? '' ), 'url' => farol_newsletter_sanitize_link( (string) ( $b['url'] ?? '' ) ) ),
			default   => array( 'type' => $type ),
		};
	}
	return $out;
}

/** Link de bloco: URL de verdade ou um marcador ({{link_confirmacao}}…). */
function farol_newsletter_sanitize_link( string $url ): string {
	$url = trim( $url );
	return preg_match( '/^\{\{[a-z_]+\}\}$/', $url ) ? $url : esc_url_raw( $url );
}

/**
 * Matérias que os blocos vão mostrar, na ordem. Em "latest", $ctx['since']
 * (timestamp) limita às publicadas depois dele — é o resumo automático; sem
 * ele, são as mais recentes. $ctx['fallback'] = true usa as mais recentes
 * quando não há nada novo (prévia e teste). "latest" pula as matérias já
 * escolhidas à mão nos blocos "posts".
 *
 * @return array<int, WP_Post[]> índice do bloco => matérias
 */
function farol_newsletter_blocks_posts( array $blocks, array $ctx = array() ): array {
	$out    = array();
	$picked = array();
	foreach ( $blocks as $i => $b ) {
		if ( 'posts' === $b['type'] ) {
			$out[ $i ] = array_values(
				array_filter(
					array_map( 'get_post', $b['ids'] ),
					fn( $p ) => $p && 'publish' === $p->post_status
				)
			);
			$picked = array_merge( $picked, wp_list_pluck( $out[ $i ], 'ID' ) );
		}
	}
	foreach ( $blocks as $i => $b ) {
		if ( 'latest' === $b['type'] ) {
			$args = array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => $b['count'],
				'post__not_in'     => $picked,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			);
			$posts = get_posts(
				empty( $ctx['since'] ) ? $args : $args + array( 'date_query' => array( array( 'column' => 'post_date_gmt', 'after' => gmdate( 'Y-m-d H:i:s', (int) $ctx['since'] ) ) ) )
			);
			$out[ $i ] = $posts || empty( $ctx['fallback'] ) ? $posts : get_posts( $args );
			$picked    = array_merge( $picked, wp_list_pluck( $out[ $i ], 'ID' ) );
		}
	}
	ksort( $out );
	return $out;
}

/** Troca os marcadores ({{site}}…) pelos valores, escapados para HTML se $html. */
function farol_newsletter_vars( string $str, array $vars, bool $html = false ): string {
	$map = array();
	foreach ( $vars as $k => $v ) {
		$map[ '{{' . $k . '}}' ] = $html ? esc_html( $v ) : $v;
	}
	return strtr( $str, $map );
}

/**
 * Monta um e-mail a partir de assunto, pré-visualização e blocos.
 *
 * @param object|array $source subject, preheader, blocks (array ou JSON).
 * @param array        $ctx    since, fallback, vars (marcadores extras).
 * @return array{subject: string, preheader: string, html: string, text: string, posts: WP_Post[]}
 */
function farol_newsletter_compose( object|array $source, array $ctx = array() ): array {
	$source = (object) $source;
	$blocks = farol_newsletter_blocks_sanitize( $source->blocks ?? array() );
	$found  = farol_newsletter_blocks_posts( $blocks, $ctx );
	$posts  = array_merge( array(), ...array_values( $found ) );

	$vars = array_merge(
		array(
			'site'     => get_bloginfo( 'name' ),
			'manchete' => $posts ? html_entity_decode( get_the_title( $posts[0] ), ENT_QUOTES, 'UTF-8' ) : '',
			'data'     => wp_date( 'd/m/Y' ),
		),
		(array) ( $ctx['vars'] ?? array() )
	);

	$html = '';
	$text = '';
	foreach ( $blocks as $i => $b ) {
		$m     = farol_newsletter_block_markup( $b, $found[ $i ] ?? array(), $vars );
		$html .= $m['html'];
		$text .= $m['text'];
	}

	$subject   = trim( farol_newsletter_vars( (string) ( $source->subject ?? '' ), $vars ) );
	$preheader = trim( farol_newsletter_vars( (string) ( $source->preheader ?? '' ), $vars ) );
	if ( '' === $preheader ) {
		$preheader = count( $posts ) > 1 ? sprintf( 'E mais %d matérias', count( $posts ) - 1 ) : ( $vars['manchete'] ?: $subject );
	}

	return array(
		'subject'   => $subject ?: $vars['site'] . ': newsletter',
		'preheader' => $preheader,
		'html'      => $html,
		'text'      => trim( preg_replace( "/\n{3,}/", "\n\n", $text ) ) . "\n",
		'posts'     => $posts,
	);
}

/**
 * HTML e texto de um bloco.
 *
 * @param WP_Post[] $posts Matérias resolvidas (posts/latest).
 * @return array{html: string, text: string}
 */
function farol_newsletter_block_markup( array $b, array $posts, array $vars ): array {
	switch ( $b['type'] ) {
		case 'heading':
			$t = farol_newsletter_vars( $b['text'], $vars );
			if ( '' === $t ) {
				break;
			}
			$size = 'md' === $b['size'] ? 20 : 26;
			return array(
				'html' => '<p style="margin:20px 0 10px;font-family:Georgia,\'Times New Roman\',serif;font-size:' . $size . 'px;line-height:1.25;font-weight:700;color:#16171a;">' . esc_html( $t ) . '</p>',
				'text' => mb_strtoupper( $t ) . "\n\n",
			);

		case 'text':
			if ( '' === trim( wp_strip_all_tags( $b['html'] ) ) && ! str_contains( $b['html'], '<img' ) ) {
				break;
			}
			$html = strtr(
				wpautop( $b['html'] ),
				array(
					'<p>'   => '<p style="margin:0 0 14px;">',
					'<img ' => '<img style="max-width:100%;height:auto;" ',
				)
			);
			$html = farol_newsletter_vars( $html, $vars, true );
			$text = wp_strip_all_tags( preg_replace( array( '#<br\s*/?>#i', '#</(p|li|h[1-6]|blockquote)>#i' ), array( "\n", "\n\n" ), $b['html'] ) );
			return array(
				'html' => '<div style="margin:0 0 8px;">' . $html . '</div>',
				'text' => farol_newsletter_vars( html_entity_decode( trim( $text ), ENT_QUOTES, 'UTF-8' ), $vars ) . "\n\n",
			);

		case 'posts':
		case 'latest':
			$html = '';
			$text = '';
			foreach ( $posts as $j => $post ) {
				$m     = farol_newsletter_post_markup( $post, $b['feature'] && 0 === $j, $j > 0 );
				$html .= $m['html'];
				$text .= $m['text'];
			}
			return array( 'html' => $html, 'text' => $text );

		case 'image':
			if ( ! $b['url'] ) {
				break;
			}
			$img  = '<img src="' . esc_url( $b['url'] ) . '" alt="' . esc_attr( $b['alt'] ) . '" width="544" style="display:block;width:100%;max-width:544px;height:auto;border:0;">';
			$link = farol_newsletter_vars( $b['link'], $vars );
			return array(
				'html' => '<div style="margin:8px 0 16px;">' . ( $link ? '<a href="' . esc_url( $link ) . '">' . $img . '</a>' : $img ) . '</div>',
				'text' => $b['alt'] ? "[{$b['alt']}]\n\n" : '',
			);

		case 'button':
			$url   = farol_newsletter_vars( $b['url'], $vars );
			$label = farol_newsletter_vars( $b['label'], $vars );
			if ( ! $url || ! $label ) {
				break;
			}
			return array(
				'html' => farol_newsletter_button( $url, $label ),
				'text' => "{$label}: {$url}\n\n",
			);

		case 'divider':
			return array( 'html' => '<hr style="border:0;border-top:1px solid #e3e4e8;margin:20px 0;">', 'text' => "---\n\n" );

		case 'spacer':
			return array( 'html' => '<div style="height:24px;line-height:24px;">&nbsp;</div>', 'text' => "\n" );
	}
	return array( 'html' => '', 'text' => '' );
}

/**
 * Card de uma matéria no e-mail. Em destaque leva foto e título maior;
 * $border separa do card de cima.
 *
 * @return array{html: string, text: string}
 */
function farol_newsletter_post_markup( WP_Post $post, bool $feature, bool $border ): array {
	$url   = get_permalink( $post );
	$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	$dek   = wp_strip_all_tags( has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 30 ) );
	$cat   = get_the_category( $post->ID )[0] ?? null;
	$image = $feature ? get_the_post_thumbnail_url( $post, 'medium_large' ) : '';

	$html = '<div style="padding:18px 0;' . ( $border ? 'border-top:1px solid #e3e4e8;' : '' ) . '">';
	if ( $image ) {
		$html .= '<a href="' . esc_url( $url ) . '"><img src="' . esc_url( $image ) . '" alt="" width="544" style="display:block;width:100%;max-width:544px;height:auto;margin:0 0 14px;border:0;"></a>';
	}
	if ( $cat ) {
		$html .= '<p style="margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#c4161c;">' . esc_html( $cat->name ) . '</p>';
	}
	$size  = $feature ? 24 : 19;
	$html .= '<p style="margin:0 0 6px;font-family:Georgia,\'Times New Roman\',serif;font-size:' . $size . 'px;line-height:1.25;font-weight:700;"><a href="' . esc_url( $url ) . '" style="color:#16171a;text-decoration:none;">' . esc_html( $title ) . '</a></p>';
	if ( $dek ) {
		$html .= '<p style="margin:0;color:#5b5e66;font-size:15px;">' . esc_html( $dek ) . '</p>';
	}
	$html .= '</div>';

	return array( 'html' => $html, 'text' => "{$title}\n{$url}\n\n" );
}
