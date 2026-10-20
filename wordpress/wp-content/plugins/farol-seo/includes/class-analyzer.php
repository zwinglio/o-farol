<?php
/**
 * Análise de texto: valores automáticos (título, descrição, palavra-chave,
 * resumo) e os scores de SEO e GEO. Fonte única — usada pelo editor (via
 * REST, ao vivo) e ao salvar o post (scores na lista de posts).
 *
 * @package FarolSEO
 */

defined( 'ABSPATH' ) || exit;

final class Farol_SEO_Analyzer {

	const TITLE_MAX       = 60;
	const DESCRIPTION_MAX = 160;

	/** Palavras vazias do português, ignoradas na escolha da palavra-chave. */
	const STOPWORDS = 'a à ao aos as às até após com como contra da das de dela dele deles depois do dos e é ela elas ele eles em entre era essa esse esta está estão este eu foi foram há isso isto já la lhe lo mais mas me mesmo meu minha muito na nas nem no nos nós num numa o os ou para pela pelas pelo pelos por porque qual quando que quem se sem ser será seu seus sua suas são só também te tem têm ter teu tua um uma umas uns vai vão você vocês sobre ainda segundo disse diz afirmou pode podem deve devem cada outro outra outros outras todo toda todos todas onde assim bem então porém pois desde durante ano anos dia dias vez vezes nesta neste nessa nesse novo nova está estava eram sido tinha';

	private array $input;
	private array $doc;

	/**
	 * @param array $input title, content (HTML/blocos), excerpt, slug, meta (overrides),
	 *                     has_image, image_width, categories (slugs), author_bio, author_name.
	 */
	public function __construct( array $input ) {
		$this->input = wp_parse_args(
			$input,
			array(
				'title'       => '',
				'content'     => '',
				'excerpt'     => '',
				'slug'        => '',
				'meta'        => array(),
				'has_image'   => false,
				'image_width' => 0,
				'categories'  => array(),
				'author_name' => '',
				'author_bio'  => '',
			)
		);
		$this->doc   = self::parse( (string) $this->input['content'] );
	}

	/* ------------------------------------------------------------------
	 * Saída
	 * ------------------------------------------------------------------ */

	public function run(): array {
		$auto     = $this->auto();
		$resolved = array(
			'title'       => $this->meta( 'title' ) ?: $auto['title'],
			'description' => $this->meta( 'description' ) ?: $auto['description'],
			'keyword'     => $this->meta( 'keyword' ) ?: $auto['keyword'],
			'summary'     => $this->meta( 'summary' ) ? self::lines( $this->meta( 'summary' ) ) : $auto['summary'],
		);

		return array(
			'auto'     => $auto,
			'resolved' => $resolved,
			'seo'      => self::score( $this->seo_checks( $resolved ) ),
			'geo'      => self::score( $this->geo_checks( $resolved ) ),
			'doc'      => array(
				'lead'    => $this->doc['paragraphs'][0] ?? '',
				'sources' => $this->doc['sources'],
			),
			'stats'    => array(
				'words'       => $this->doc['words'],
				'sentences'   => count( $this->doc['sentences'] ),
				'paragraphs'  => count( $this->doc['paragraphs'] ),
				'readability' => $this->flesch(),
			),
		);
	}

	/** Valores automáticos, usados quando o campo está vazio. */
	public function auto(): array {
		$title   = self::plain( $this->input['title'] );
		$excerpt = self::plain( $this->input['excerpt'] );
		$lead    = $this->doc['paragraphs'][0] ?? '';

		return array(
			'title'       => $title,
			'description' => self::truncate( $excerpt ?: $lead, self::DESCRIPTION_MAX - 5 ),
			'keyword'     => $this->suggest_keyword(),
			'summary'     => $this->suggest_summary(),
		);
	}

	/* ------------------------------------------------------------------
	 * Checagens de SEO
	 * ------------------------------------------------------------------ */

	private function seo_checks( array $r ): array {
		$kw       = self::norm( (string) $r['keyword'] );
		$title    = self::norm( $r['title'] );
		$desc     = self::norm( $r['description'] );
		$lead     = self::norm( $this->doc['paragraphs'][0] ?? '' );
		$words    = $this->doc['words'];
		$suffix   = mb_strlen( ' · ' . get_bloginfo( 'name' ) );
		$title_l  = mb_strlen( $r['title'] ) + $suffix;
		$desc_l   = mb_strlen( $r['description'] );
		$slug     = (string) $this->input['slug'];
		$checks   = array();

		if ( '' === $kw ) {
			$checks[] = self::check( 'keyword', 'Palavra-chave', 'bad', 3, 'Defina a palavra-chave: o termo que o leitor digitaria para achar esta notícia.' );
		} else {
			$checks[] = $this->meta( 'keyword' )
				? self::check( 'keyword', 'Palavra-chave', 'good', 1, "Palavra-chave: “{$r['keyword']}”." )
				: self::check( 'keyword', 'Palavra-chave', 'ok', 1, "Sugestão automática: “{$r['keyword']}”. Confirme ou troque." );

			$pos = mb_strpos( $title, $kw );
			$checks[] = false === $pos
				? self::check( 'kw_title', 'Palavra-chave no título', 'bad', 3, 'O título SEO não contém a palavra-chave.' )
				: ( $pos <= mb_strlen( $title ) / 2
					? self::check( 'kw_title', 'Palavra-chave no título', 'good', 3, 'A palavra-chave aparece no começo do título.' )
					: self::check( 'kw_title', 'Palavra-chave no título', 'ok', 3, 'A palavra-chave está no título, mas no fim. Traga-a para o início.' ) );

			$checks[] = str_contains( $desc, $kw )
				? self::check( 'kw_description', 'Palavra-chave na descrição', 'good', 2, 'A descrição contém a palavra-chave.' )
				: self::check( 'kw_description', 'Palavra-chave na descrição', 'bad', 2, 'Inclua a palavra-chave na meta descrição.' );

			$checks[] = str_contains( $lead, $kw )
				? self::check( 'kw_lead', 'Palavra-chave no lide', 'good', 2, 'A palavra-chave aparece no primeiro parágrafo.' )
				: self::check( 'kw_lead', 'Palavra-chave no lide', 'bad', 2, 'Use a palavra-chave no primeiro parágrafo.' );

			$slug_words = array_filter( explode( '-', $slug ) );
			$kw_words   = array_diff( array_filter( explode( '-', sanitize_title( $kw ) ) ), array_map( 'remove_accents', self::stopwords() ) );
			$in_slug    = $kw_words && ! array_diff( $kw_words, $slug_words );
			$checks[]   = $in_slug
				? self::check( 'kw_slug', 'Palavra-chave na URL', 'good', 1, 'A URL contém a palavra-chave.' )
				: self::check( 'kw_slug', 'Palavra-chave na URL', 'ok', 1, 'Considere incluir a palavra-chave na URL (slug).' );

			if ( $words >= 100 ) {
				$density  = $this->density( $kw );
				$pct      = number_format_i18n( $density, 1 );
				$checks[] = $density > 3
					? self::check( 'kw_density', 'Densidade', 'bad', 1, "Palavra-chave em {$pct}% do texto: parece repetição forçada." )
					: ( $density >= 0.5
						? self::check( 'kw_density', 'Densidade', 'good', 1, "Palavra-chave em {$pct}% do texto." )
						: self::check( 'kw_density', 'Densidade', 'ok', 1, "Palavra-chave em {$pct}% do texto. Use-a mais vezes, com naturalidade." ) );
			}

			if ( $this->doc['headings'] ) {
				$kw_any   = array_filter( explode( ' ', $kw ), fn( $w ) => mb_strlen( $w ) > 3 );
				$hit      = false;
				foreach ( $this->doc['headings'] as $h ) {
					foreach ( $kw_any as $w ) {
						$hit = $hit || str_contains( self::norm( $h['text'] ), $w );
					}
				}
				$checks[] = $hit
					? self::check( 'kw_heading', 'Palavra-chave em intertítulo', 'good', 1, 'Um intertítulo retoma o tema da palavra-chave.' )
					: self::check( 'kw_heading', 'Palavra-chave em intertítulo', 'ok', 1, 'Retome a palavra-chave (ou parte dela) em algum intertítulo.' );
			}
		}

		$checks[] = $title_l >= 35 && $title_l <= self::TITLE_MAX
			? self::check( 'title_length', 'Tamanho do título', 'good', 2, "{$title_l} caracteres com o nome do site — cabe inteiro no Google." )
			: ( $title_l >= 25 && $title_l <= 70
				? self::check( 'title_length', 'Tamanho do título', 'ok', 2, "{$title_l} caracteres com o nome do site. O ideal é entre 35 e 60." )
				: self::check( 'title_length', 'Tamanho do título', 'bad', 2, "{$title_l} caracteres com o nome do site — " . ( $title_l > 70 ? 'vai ser cortado no Google.' : 'curto demais.' ) ) );

		$checks[] = $desc_l >= 120 && $desc_l <= self::DESCRIPTION_MAX
			? self::check( 'description_length', 'Tamanho da descrição', 'good', 2, "{$desc_l} caracteres." )
			: ( $desc_l >= 70 && $desc_l <= 180
				? self::check( 'description_length', 'Tamanho da descrição', 'ok', 2, "{$desc_l} caracteres. O ideal é entre 120 e 160." )
				: self::check( 'description_length', 'Tamanho da descrição', 'bad', 2, 0 === $desc_l ? 'Sem descrição: escreva a linha fina ou a meta descrição.' : "{$desc_l} caracteres. O ideal é entre 120 e 160." ) );

		$checks[] = $words >= 300
			? self::check( 'content_length', 'Tamanho do texto', 'good', 2, "{$words} palavras." )
			: ( $words >= 150
				? self::check( 'content_length', 'Tamanho do texto', 'ok', 2, "{$words} palavras. Notas curtas funcionam, mas 300+ ranqueiam melhor." )
				: self::check( 'content_length', 'Tamanho do texto', 'bad', 2, "{$words} palavras. Texto muito curto para ranquear." ) );

		if ( ! $this->input['has_image'] ) {
			$checks[] = self::check( 'featured_image', 'Imagem destacada', 'bad', 2, 'Defina a imagem destacada: ela aparece no Google Notícias, Discover e redes.' );
		} elseif ( $this->input['image_width'] && $this->input['image_width'] < 1200 ) {
			$checks[] = self::check( 'featured_image', 'Imagem destacada', 'ok', 2, "A imagem tem {$this->input['image_width']}px de largura; o Google Discover pede no mínimo 1200px." );
		} else {
			$checks[] = self::check( 'featured_image', 'Imagem destacada', 'good', 2, 'Imagem destacada definida.' );
		}

		if ( $this->doc['images'] ) {
			$missing  = count( array_filter( $this->doc['images'], fn( $alt ) => '' === trim( $alt ) ) );
			$checks[] = 0 === $missing
				? self::check( 'image_alt', 'Texto alternativo', 'good', 1, 'Todas as imagens do texto têm descrição (alt).' )
				: self::check( 'image_alt', 'Texto alternativo', 'bad', 1, "{$missing} imagem(ns) sem texto alternativo." );
		}

		$checks[] = $this->doc['internal_links'] > 0
			? self::check( 'internal_links', 'Links internos', 'good', 1, "{$this->doc['internal_links']} link(s) para outras matérias do Farol." )
			: self::check( 'internal_links', 'Links internos', 'ok', 1, 'Linke pelo menos uma matéria relacionada do Farol.' );

		if ( $words > 350 ) {
			$checks[] = $this->doc['headings']
				? self::check( 'subheadings', 'Intertítulos', 'good', 1, count( $this->doc['headings'] ) . ' intertítulo(s).' )
				: self::check( 'subheadings', 'Intertítulos', 'bad', 1, 'Texto longo sem intertítulos: divida em seções.' );
		}

		$cats     = array_diff( (array) $this->input['categories'], array( 'sem-categoria', 'uncategorized' ) );
		$checks[] = $cats
			? self::check( 'category', 'Editoria', 'good', 1, 'Editoria definida.' )
			: self::check( 'category', 'Editoria', 'bad', 1, 'Escolha uma editoria (categoria).' );

		$slug_l   = mb_strlen( $slug );
		$checks[] = '' === $slug || $slug_l <= 60
			? self::check( 'slug_length', 'URL', 'good', 1, '' === $slug ? 'A URL será gerada a partir do título.' : 'URL curta e legível.' )
			: self::check( 'slug_length', 'URL', $slug_l <= 90 ? 'ok' : 'bad', 1, "A URL tem {$slug_l} caracteres. Encurte para até 60." );

		$checks[] = '' !== trim( self::plain( $this->input['excerpt'] ) )
			? self::check( 'excerpt', 'Linha fina', 'good', 1, 'Linha fina (resumo) preenchida.' )
			: self::check( 'excerpt', 'Linha fina', 'ok', 1, 'Escreva a linha fina no campo Resumo: ela abre a matéria e alimenta a descrição.' );

		return $checks;
	}

	/* ------------------------------------------------------------------
	 * Checagens de GEO (citabilidade em buscadores com IA)
	 * ------------------------------------------------------------------ */

	private function geo_checks( array $r ): array {
		$checks = array();
		$words  = $this->doc['words'];
		$lead_w = self::count_words( $this->doc['paragraphs'][0] ?? '' );

		$checks[] = $this->meta( 'summary' )
			? self::check( 'summary', 'Resumo para IA', 'good', 2, 'Pontos principais definidos por você.' )
			: ( $r['summary']
				? self::check( 'summary', 'Resumo para IA', 'ok', 2, 'Pontos principais gerados automaticamente. Revise e ajuste.' )
				: self::check( 'summary', 'Resumo para IA', 'bad', 2, 'Escreva 2 a 4 pontos principais da notícia.' ) );

		$checks[] = 0 === $lead_w
			? self::check( 'lead', 'Lide direto', 'bad', 2, 'Comece com um parágrafo que responda o quê, quem, quando e onde.' )
			: ( $lead_w <= 60
				? self::check( 'lead', 'Lide direto', 'good', 2, "Primeiro parágrafo com {$lead_w} palavras: direto ao ponto." )
				: self::check( 'lead', 'Lide direto', $lead_w <= 90 ? 'ok' : 'bad', 2, "Primeiro parágrafo com {$lead_w} palavras. Enxugue para até 60: é o trecho que as IAs mais citam." ) );

		$domains  = count( $this->doc['external_domains'] );
		$checks[] = $domains >= 2
			? self::check( 'sources', 'Fontes linkadas', 'good', 3, "{$domains} fontes externas diferentes." )
			: self::check( 'sources', 'Fontes linkadas', 1 === $domains ? 'ok' : 'bad', 3, 1 === $domains ? 'Uma fonte externa. Linke documentos, dados oficiais ou outras fontes primárias.' : 'Nenhuma fonte linkada. IAs priorizam textos que citam fontes primárias.' );

		$numbers  = $this->doc['numbers'];
		$checks[] = $numbers >= 3
			? self::check( 'statistics', 'Dados e números', 'good', 2, "{$numbers} dados numéricos (valores, datas, percentuais)." )
			: self::check( 'statistics', 'Dados e números', $numbers ? 'ok' : 'bad', 2, 'Inclua dados concretos — números, percentuais, valores. Aumentam a chance de citação.' );

		$checks[] = $this->doc['quotes'] > 0
			? self::check( 'quotes', 'Citações', 'good', 2, "{$this->doc['quotes']} citação(ões) de fontes." )
			: self::check( 'quotes', 'Citações', 'ok', 2, 'Inclua a fala de uma fonte (entre aspas ou bloco de citação).' );

		if ( $words >= 50 ) {
			$f        = $this->flesch();
			$checks[] = $f >= 50
				? self::check( 'readability', 'Legibilidade', 'good', 2, "Índice Flesch {$f}: fácil de ler." )
				: self::check( 'readability', 'Legibilidade', $f >= 30 ? 'ok' : 'bad', 2, "Índice Flesch {$f}: " . ( $f >= 30 ? 'leitura média. Frases e palavras mais curtas ajudam.' : 'difícil. Encurte frases e troque termos técnicos.' ) );

			$avg      = $this->avg_sentence();
			$long     = count( array_filter( $this->doc['sentences'], fn( $s ) => self::count_words( $s ) > 35 ) );
			$checks[] = $avg <= 22 && 0 === $long
				? self::check( 'sentences', 'Frases', 'good', 1, "Média de {$avg} palavras por frase." )
				: self::check( 'sentences', 'Frases', $avg <= 28 ? 'ok' : 'bad', 1, "Média de {$avg} palavras por frase" . ( $long ? "; {$long} frase(s) com mais de 35." : '.' ) );

			$max      = max( array_map( array( self::class, 'count_words' ), $this->doc['paragraphs'] ) ?: array( 0 ) );
			$checks[] = $max <= 90
				? self::check( 'paragraphs', 'Parágrafos', 'good', 1, 'Parágrafos curtos.' )
				: self::check( 'paragraphs', 'Parágrafos', $max <= 140 ? 'ok' : 'bad', 1, "O maior parágrafo tem {$max} palavras. Divida parágrafos acima de 90." );
		}

		if ( $words >= 400 ) {
			$questions = count( array_filter( $this->doc['headings'], fn( $h ) => str_ends_with( trim( $h['text'] ), '?' ) ) );
			$checks[]  = $questions
				? self::check( 'questions', 'Intertítulos em pergunta', 'good', 1, "{$questions} intertítulo(s) em forma de pergunta." )
				: self::check( 'questions', 'Intertítulos em pergunta', 'ok', 1, 'Intertítulos como perguntas (“O que muda para quem…?”) casam com as buscas feitas a IAs.' );

			$checks[] = $this->doc['lists'] > 0
				? self::check( 'structure', 'Listas e tabelas', 'good', 1, 'O texto tem lista ou tabela.' )
				: self::check( 'structure', 'Listas e tabelas', 'ok', 1, 'Uma lista ou tabela (cronologia, números-chave) facilita a extração da informação.' );
		}

		$checks[] = '' !== trim( (string) $this->input['author_bio'] )
			? self::check( 'author', 'Autoria', 'good', 2, 'Autor com nome e minibiografia.' )
			: self::check( 'author', 'Autoria', 'ok', 2, 'Preencha a biografia do autor em Usuários → Perfil. Autoria clara conta como sinal de confiança.' );

		return $checks;
	}

	/* ------------------------------------------------------------------
	 * Sugestões automáticas
	 * ------------------------------------------------------------------ */

	/**
	 * Palavra-chave sugerida: o termo (1 ou 2 palavras) mais forte considerando
	 * frequência no texto, presença no título e nomes próprios/siglas — que em
	 * notícia costumam ser o assunto ("Farmácia Popular", "SUS", "Parnamirim").
	 */
	private function suggest_keyword(): string {
		$stop  = array_flip( self::stopwords() );
		$uni   = array();
		$bi    = array();
		$forms = array();

		$sources = array(
			array( self::plain( $this->input['title'] ), true ),
			array( $this->doc['text'], false ),
		);

		foreach ( $sources as [ $text, $is_title ] ) {
			foreach ( self::sentences( $text ) ?: array( $text ) as $sentence ) {
				$prev = null;
				foreach ( self::tokens( $sentence ) as $i => $t ) {
					$n = self::norm( $t );
					if ( isset( $stop[ $n ] ) || mb_strlen( $n ) < 3 || is_numeric( $n ) ) {
						$prev = null;
						continue;
					}
					// Maiúscula fora do início da frase = nome próprio ou sigla.
					$proper = $i > 0 && preg_match( '/^\p{Lu}/u', $t );
					$forms[ $n ] ??= $proper ? $t : $n;

					$uni[ $n ] = ( $uni[ $n ] ?? 0 ) + ( $is_title ? 2 : 1 ) + ( $proper ? 1 : 0 );

					if ( $prev ) {
						$pair             = $prev['n'] . ' ' . $n;
						$both             = $prev['proper'] && $proper;
						$bi[ $pair ]      = ( $bi[ $pair ] ?? 0 ) + ( $is_title ? 3 : 1.5 ) + ( $both ? 3 : 0 );
						$forms[ $pair ] ??= $both ? $prev['t'] . ' ' . $t : $pair;
					}
					$prev = array( 'n' => $n, 't' => $t, 'proper' => $proper );
				}
			}
		}

		if ( ! $uni ) {
			return '';
		}
		arsort( $uni );
		arsort( $bi );
		$top_uni = array_key_first( $uni );
		$top_bi  = array_key_first( $bi );

		$best = $top_bi && $bi[ $top_bi ] >= 0.8 * $uni[ $top_uni ] ? $top_bi : $top_uni;
		return (string) $forms[ $best ];
	}

	private function suggest_summary(): array {
		$out   = array();
		$paras = $this->doc['paragraphs'];
		if ( ! $paras ) {
			return $out;
		}

		$first = self::sentences( $paras[0] )[0] ?? '';
		if ( $first ) {
			$out[] = $first;
		}

		// Frases com dados ou falas de fontes: as mais "citáveis".
		foreach ( $this->doc['sentences'] as $s ) {
			if ( count( $out ) >= 3 ) {
				break;
			}
			$w = self::count_words( $s );
			if ( in_array( $s, $out, true ) || $w < 6 || $w > 35 ) {
				continue;
			}
			if ( preg_match( '/\d|[“"«]/u', $s ) ) {
				$out[] = $s;
			}
		}

		// Completa com a abertura dos parágrafos seguintes.
		foreach ( array_slice( $paras, 1 ) as $p ) {
			if ( count( $out ) >= 3 ) {
				break;
			}
			$s = self::sentences( $p )[0] ?? '';
			if ( $s && ! in_array( $s, $out, true ) && self::count_words( $s ) <= 35 ) {
				$out[] = $s;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Métricas
	 * ------------------------------------------------------------------ */

	/**
	 * Flesch adaptado ao português (Martins et al., 1996):
	 * 248,835 − 1,015 × (palavras/frase) − 84,6 × (sílabas/palavra).
	 */
	public function flesch(): int {
		$words = self::tokens( $this->doc['text'] );
		$sents = max( 1, count( $this->doc['sentences'] ) );
		if ( ! $words ) {
			return 0;
		}
		$syll = 0;
		foreach ( $words as $w ) {
			$syll += max( 1, preg_match_all( '/[aeiouáéíóúâêôãõàüy]+/iu', $w ) );
		}
		$score = 248.835 - 1.015 * ( count( $words ) / $sents ) - 84.6 * ( $syll / count( $words ) );
		return (int) max( 0, min( 100, round( $score ) ) );
	}

	private function avg_sentence(): int {
		$s = $this->doc['sentences'];
		return $s ? (int) round( array_sum( array_map( array( self::class, 'count_words' ), $s ) ) / count( $s ) ) : 0;
	}

	private function density( string $kw ): float {
		$text  = ' ' . implode( ' ', array_map( array( self::class, 'norm' ), self::tokens( $this->doc['text'] ) ) ) . ' ';
		$hits  = substr_count( $text, ' ' . $kw . ' ' );
		$words = max( 1, $this->doc['words'] );
		return $hits * count( explode( ' ', $kw ) ) / $words * 100;
	}

	/* ------------------------------------------------------------------
	 * Parsing
	 * ------------------------------------------------------------------ */

	/** Extrai do HTML o que as checagens usam. */
	public static function parse( string $html ): array {
		$html = preg_replace( '/<!--.*?-->/s', '', $html );
		$out  = array(
			'text'             => '',
			'words'            => 0,
			'paragraphs'       => array(),
			'sentences'        => array(),
			'headings'         => array(),
			'images'           => array(),
			'internal_links'   => 0,
			'external_domains' => array(),
			'sources'          => array(),
			'quotes'           => 0,
			'lists'            => 0,
			'numbers'          => 0,
		);
		if ( '' === trim( $html ) ) {
			return $out;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<!doctype html><html><head><meta charset="utf-8"></head><body>' . mb_encode_numericentity( $html, array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' ) . '</body></html>'
		);
		libxml_clear_errors();

		foreach ( $dom->getElementsByTagName( 'p' ) as $p ) {
			// Parágrafos dentro de citações não contam como texto corrido.
			if ( $p->parentNode && in_array( strtolower( $p->parentNode->nodeName ), array( 'blockquote', 'figcaption', 'li' ), true ) ) {
				continue;
			}
			$t = self::plain( $p->textContent );
			if ( '' !== $t ) {
				$out['paragraphs'][] = $t;
			}
		}

		foreach ( array( 'h2', 'h3', 'h4' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $h ) {
				$out['headings'][] = array( 'level' => (int) $tag[1], 'text' => self::plain( $h->textContent ) );
			}
		}

		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$out['images'][] = (string) $img->getAttribute( 'alt' );
		}

		$own = array_filter(
			array(
				wp_parse_url( home_url(), PHP_URL_HOST ),
				function_exists( 'farol_frontend_url' ) && farol_frontend_url() ? wp_parse_url( farol_frontend_url(), PHP_URL_HOST ) : null,
			)
		);
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( '' === $href || str_starts_with( $href, '#' ) || str_starts_with( $href, 'mailto:' ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || in_array( $host, $own, true ) ) {
				++$out['internal_links'];
			} else {
				$domain                             = preg_replace( '/^www\./', '', strtolower( $host ) );
				$out['external_domains'][ $domain ] = true;
				$out['sources'][ $href ]            = array(
					'url'    => $href,
					'domain' => $domain,
					'label'  => self::plain( $a->textContent ) ?: $domain,
				);
			}
		}
		$out['external_domains'] = array_keys( $out['external_domains'] );
		$out['sources']          = array_values( $out['sources'] );

		$out['quotes'] = $dom->getElementsByTagName( 'blockquote' )->length;
		$out['lists']  = $dom->getElementsByTagName( 'ul' )->length + $dom->getElementsByTagName( 'ol' )->length + $dom->getElementsByTagName( 'table' )->length;

		$body        = $dom->getElementsByTagName( 'body' )->item( 0 );
		$out['text'] = self::plain( $body ? $body->textContent : '' );
		// Falas entre aspas com pelo menos 5 palavras também são citações.
		$out['quotes'] += preg_match_all( '/[“"«]([^”"»]{25,})[”"»]/u', implode( ' ', $out['paragraphs'] ) );
		$out['numbers'] = preg_match_all( '/(?:R\$\s?)?\d[\d.,]*\s?(?:%|mil|milhões|milhão|bilhões|bilhão)?/u', implode( ' ', $out['paragraphs'] ) );
		$out['words']   = count( self::tokens( $out['text'] ) );

		foreach ( $out['paragraphs'] as $p ) {
			array_push( $out['sentences'], ...self::sentences( $p ) );
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------ */

	private function meta( string $key ): string {
		return trim( (string) ( $this->input['meta'][ $key ] ?? '' ) );
	}

	private static function check( string $id, string $label, string $status, int $weight, string $message ): array {
		return compact( 'id', 'label', 'status', 'weight', 'message' );
	}

	private static function score( array $checks ): array {
		$total = 0;
		$got   = 0;
		foreach ( $checks as $c ) {
			$total += $c['weight'];
			$got   += $c['weight'] * array( 'good' => 1, 'ok' => 0.5, 'bad' => 0 )[ $c['status'] ];
		}
		$score = $total ? (int) round( $got / $total * 100 ) : 0;

		// Mostra primeiro o que precisa de atenção.
		usort( $checks, fn( $a, $b ) => array( 'bad' => 0, 'ok' => 1, 'good' => 2 )[ $a['status'] ] <=> array( 'bad' => 0, 'ok' => 1, 'good' => 2 )[ $b['status'] ] ?: $b['weight'] <=> $a['weight'] );

		return array( 'score' => $score, 'checks' => $checks );
	}

	public static function plain( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** Minúsculas e sem pontuação, para comparações. */
	public static function norm( string $text ): string {
		$text = mb_strtolower( self::plain( $text ) );
		$text = preg_replace( '/[^\p{L}\p{N}\s-]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	public static function tokens( string $text ): array {
		preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text, $m );
		return $m[0];
	}

	public static function count_words( string $text ): int {
		return count( self::tokens( $text ) );
	}

	public static function sentences( string $text ): array {
		$parts = preg_split( '/(?<=[.!?…])["”»]?\s+(?=[\p{Lu}"“«\d—–-])/u', trim( $text ) );
		return array_values( array_filter( array_map( 'trim', $parts ) ) );
	}

	public static function truncate( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );
		$cut = preg_replace( '/\s+\S*$/u', '', $cut );
		return rtrim( $cut, " ,;:—–-" ) . '…';
	}

	public static function lines( string $text ): array {
		return array_values( array_filter( array_map( fn( $l ) => trim( ltrim( trim( $l ), '-•*' ) ), preg_split( '/\R/u', $text ) ) ) );
	}

	private static function stopwords(): array {
		static $list = null;
		return $list ??= explode( ' ', self::STOPWORDS );
	}
}
