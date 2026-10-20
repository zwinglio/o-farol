<?php
/**
 * Geração de SEO/GEO com IA (cliente em mu-plugins/farol-core/ai.php).
 *
 * POST /farol-seo/v1/generate  { post_id, fields[], title, content, excerpt, categories, keyword }
 * → { values: { keyword, title, description, summary[], og_title, og_description }, model }
 *
 * @package FarolSEO
 */

defined( 'ABSPATH' ) || exit;

/** Campos que a IA sabe gerar. */
const FAROL_SEO_AI_FIELDS = array( 'keyword', 'title', 'description', 'summary', 'og_title', 'og_description' );

/** Limite de texto enviado ao modelo (caracteres), para controlar custo. */
const FAROL_SEO_AI_MAX_TEXT = 14000;

/**
 * Gera os campos pedidos a partir do texto.
 *
 * @param array $fields Subconjunto de FAROL_SEO_AI_FIELDS.
 * @param array $ctx    title, excerpt, content (HTML), section, keyword (atual).
 * @return array|WP_Error
 */
function farol_seo_ai_generate( array $fields, array $ctx, array $options = array() ) {
	if ( ! function_exists( 'farol_ai_chat' ) ) {
		return new WP_Error( 'farol_seo_ai', 'O núcleo de IA (farol-core) não está carregado.' );
	}

	$fields = array_values( array_intersect( FAROL_SEO_AI_FIELDS, $fields ) );
	if ( ! $fields ) {
		return new WP_Error( 'farol_seo_ai_fields', 'Nenhum campo válido pedido.', array( 'status' => 400 ) );
	}

	$text = Farol_SEO_Analyzer::plain( preg_replace( '/<!--.*?-->/s', '', (string) $ctx['content'] ) );
	if ( Farol_SEO_Analyzer::count_words( $text ) < 40 ) {
		return new WP_Error( 'farol_seo_ai_short', 'Escreva pelo menos 40 palavras antes de gerar com IA.', array( 'status' => 400 ) );
	}

	$site      = get_bloginfo( 'name' );
	$title_max = Farol_SEO_Analyzer::TITLE_MAX - mb_strlen( " · {$site}" );
	$keyword   = in_array( 'keyword', $fields, true ) ? '' : trim( (string) ( $ctx['keyword'] ?? '' ) );
	$kw_ref    = $keyword ? "“{$keyword}”" : 'a palavra-chave';

	$specs = array(
		'keyword'        => 'string. Palavra-chave principal: 1 a 4 palavras, o termo que um leitor digitaria no Google para achar esta notícia. Prefira o assunto central ou o nome próprio mais importante. Mantenha acentos e maiúsculas de nomes próprios.',
		'title'          => "string. Título SEO com no máximo {$title_max} caracteres. Comece por {$kw_ref} ou coloque-a o mais perto possível do início. Informativo, sem caça-clique, sem ponto final, sem o nome do site.",
		'description'    => "string. Meta descrição com 120 a 155 caracteres. Inclua {$kw_ref}. Resuma o fato principal com o dado mais relevante. Frase completa, voz ativa.",
		'summary'        => 'array de 2 a 4 strings. Pontos principais da notícia. Cada ponto é uma frase completa e autossuficiente (compreensível fora de contexto), factual, com até 30 palavras, trazendo os números, nomes, lugares e datas do texto. Sem opinião. Se o texto tiver poucos fatos, gere menos pontos — nunca preencha com comentários.',
		'og_title'       => 'string. Título para redes sociais com até 70 caracteres. Pode ser mais atraente que o título SEO, sem caça-clique nem exagero.',
		'og_description' => 'string. Descrição para redes sociais com até 200 caracteres. Destaque o que muda para o leitor.',
	);

	$wanted = '';
	foreach ( $fields as $f ) {
		$wanted .= "- \"{$f}\": {$specs[$f]}\n";
	}

	$system = "Você é editor de SEO e GEO do {$site}, portal de notícias independente em português do Brasil. "
		. 'Regras: use apenas fatos presentes no texto — nunca invente números, nomes, datas ou falas. '
		. 'Tom jornalístico, sóbrio, sem sensacionalismo e sem adjetivos de opinião. '
		. 'Escreva sobre o fato, nunca sobre o texto: não mencione "o texto", "a reportagem", o portal nem a falta de informações. '
		. 'Respeite os limites de caracteres. '
		. 'Responda somente com um objeto JSON válido contendo exatamente as chaves pedidas.';

	$user = "Gere as chaves abaixo:\n{$wanted}"
		. ( $keyword ? "\nPalavra-chave já definida: {$keyword}\n" : '' )
		. "\nTÍTULO: " . Farol_SEO_Analyzer::plain( (string) $ctx['title'] )
		. "\nLINHA FINA: " . Farol_SEO_Analyzer::plain( (string) $ctx['excerpt'] )
		. ( ! empty( $ctx['section'] ) ? "\nEDITORIA: {$ctx['section']}" : '' )
		. "\n\nTEXTO:\n" . mb_substr( $text, 0, FAROL_SEO_AI_MAX_TEXT );

	$reply = farol_ai_chat(
		array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		),
		$options + array( 'json' => true, 'temperature' => 0.2, 'max_tokens' => 1500, 'reasoning' => 'off', 'timeout' => 60 )
	);
	if ( is_wp_error( $reply ) ) {
		return $reply;
	}

	// Alguns modelos embrulham o JSON em ```json … ```.
	$json = preg_match( '/\{.*\}/s', $reply, $m ) ? $m[0] : $reply;
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'farol_seo_ai_json', 'A IA respondeu num formato inesperado. Tente de novo.' );
	}

	$clean = fn( $s ) => trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ), " \"'“”" );
	$out   = array();
	foreach ( $fields as $f ) {
		if ( ! isset( $data[ $f ] ) ) {
			continue;
		}
		$out[ $f ] = match ( $f ) {
			'summary'        => array_values( array_filter( array_map( $clean, (array) $data[ $f ] ) ) ),
			'title'          => farol_seo_ai_fit_title( $clean( $data[ $f ] ), $title_max + 5 ),
			'description'    => Farol_SEO_Analyzer::truncate( $clean( $data[ $f ] ), Farol_SEO_Analyzer::DESCRIPTION_MAX ),
			'og_title'       => farol_seo_ai_fit_title( $clean( $data[ $f ] ), 80 ),
			'og_description' => Farol_SEO_Analyzer::truncate( $clean( $data[ $f ] ), 220 ),
			default          => $clean( $data[ $f ] ),
		};
	}

	if ( ! $out ) {
		return new WP_Error( 'farol_seo_ai_empty', 'A IA não devolveu os campos pedidos. Tente de novo.' );
	}
	return $out;
}

/**
 * Encaixa um título no limite sem reticências: corta na última palavra
 * inteira e não deixa conectivo solto no fim ("… de", "… e").
 */
function farol_seo_ai_fit_title( string $title, int $max ): string {
	$title = rtrim( $title, ' .' );
	if ( mb_strlen( $title ) <= $max ) {
		return $title;
	}
	$words = explode( ' ', mb_substr( $title, 0, $max + 1 ) );
	array_pop( $words ); // última palavra pode estar pela metade
	$weak = array( 'a', 'à', 'ao', 'as', 'às', 'com', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos', 'o', 'os', 'ou', 'para', 'pela', 'pelo', 'por', 'que', 'um', 'uma', 'sem', 'sobre' );
	while ( $words && in_array( mb_strtolower( end( $words ) ), $weak, true ) ) {
		array_pop( $words );
	}
	return rtrim( implode( ' ', $words ), ' ,;:—–-' );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'farol-seo/v1',
			'/generate',
			array(
				'methods'             => 'POST',
				'permission_callback' => fn( WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['post_id'] ),
				'args'                => array(
					'post_id' => array( 'type' => 'integer', 'required' => true ),
					'fields'  => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => FAROL_SEO_AI_FIELDS ), 'required' => true ),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$post = get_post( (int) $r['post_id'] );
					if ( ! $post ) {
						return new WP_Error( 'farol_seo_post', 'Post não encontrado.', array( 'status' => 404 ) );
					}

					$cat_ids = $r['categories'] ?? wp_get_post_categories( $post->ID );
					$section = '';
					foreach ( (array) $cat_ids as $id ) {
						$cat = get_category( (int) $id );
						if ( $cat && ! in_array( $cat->slug, array( 'sem-categoria', 'uncategorized' ), true ) ) {
							$section = $cat->name;
							break;
						}
					}

					$values = farol_seo_ai_generate(
						(array) $r['fields'],
						array(
							'title'   => $r['title'] ?? $post->post_title,
							'excerpt' => $r['excerpt'] ?? $post->post_excerpt,
							'content' => $r['content'] ?? $post->post_content,
							'section' => $section,
							'keyword' => (string) ( $r['keyword'] ?? '' ),
						)
					);
					if ( is_wp_error( $values ) ) {
						return $values;
					}

					return rest_ensure_response(
						array(
							'values' => $values,
							'model'  => farol_ai_models()[ farol_ai_model() ]['label'] ?? farol_ai_model(),
						)
					);
				},
			)
		);
	}
);
