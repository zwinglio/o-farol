<?php
/**
 * Dados resolvidos para o <head> do front e JSON-LD (schema.org).
 *
 * @package FarolSEO
 */

defined( 'ABSPATH' ) || exit;

/** URL pública (Astro) de um caminho. */
function farol_seo_url( string $path = '/' ): string {
	$base = function_exists( 'farol_frontend_url' ) && farol_frontend_url() ? farol_frontend_url() : home_url();
	return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
}

function farol_seo_absolute( string $url ): string {
	return '' === $url || preg_match( '#^https?://#', $url ) ? $url : farol_seo_url( $url );
}

/** NewsMediaOrganization do veículo (publisher). */
function farol_seo_organization(): array {
	$s    = farol_seo_settings();
	$org  = array(
		'@type' => 'NewsMediaOrganization',
		'@id'   => farol_seo_url( '/#organizacao' ),
		'name'  => get_bloginfo( 'name' ),
		'url'   => farol_seo_url( '/' ),
	);
	if ( $s['logo'] ) {
		$org['logo'] = array( '@type' => 'ImageObject', 'url' => $s['logo'] );
	}
	if ( $s['same_as'] ) {
		$org['sameAs'] = preg_split( '/\R/', $s['same_as'] );
	}
	if ( $s['founding_date'] ) {
		$org['foundingDate'] = $s['founding_date'];
	}
	if ( $s['publishing_principles'] ) {
		$org['publishingPrinciples'] = farol_seo_absolute( $s['publishing_principles'] );
	}
	if ( $s['contact_email'] ) {
		$org['contactPoint'] = array( '@type' => 'ContactPoint', 'contactType' => 'Redação', 'email' => $s['contact_email'] );
	}
	return $org;
}

/** Dados do site para a capa, robots.txt, llms.txt e sitemaps. */
function farol_seo_site(): array {
	$s = farol_seo_settings();

	return array(
		'name'             => get_bloginfo( 'name' ),
		'description'      => $s['description'],
		'locale'           => 'pt_BR',
		'image'            => $s['default_image'],
		'logo'             => $s['logo'],
		'x_handle'         => $s['x_handle'],
		'news_publication' => $s['news_publication'] ?: get_bloginfo( 'name' ),
		'verification'     => array( 'google' => $s['google_verification'], 'bing' => $s['bing_verification'] ),
		'ai'               => array(
			'search'        => (bool) $s['ai_search'],
			'training'      => (bool) $s['ai_training'],
			'search_bots'   => FAROL_SEO_AI_SEARCH_BOTS,
			'training_bots' => FAROL_SEO_AI_TRAINING_BOTS,
		),
		'llms_intro'       => $s['llms_intro'],
		'schema'           => array(
			array(
				'@context'        => 'https://schema.org',
				'@type'           => 'WebSite',
				'@id'             => farol_seo_url( '/#site' ),
				'name'            => get_bloginfo( 'name' ),
				'url'             => farol_seo_url( '/' ),
				'description'     => $s['description'],
				'inLanguage'      => 'pt-BR',
				'publisher'       => array( '@id' => farol_seo_url( '/#organizacao' ) ),
			),
			array( '@context' => 'https://schema.org' ) + farol_seo_organization(),
		),
	);
}

/**
 * farol_seo_for_post() com cache no próprio post. A análise (DOM, blocos) é
 * cara e o endpoint é público: sem cache, /wp/v2/posts?per_page=100 viraria
 * um jeito barato de ocupar o servidor. O cache vale até o post mudar, as
 * configurações mudarem ou passarem 12 h (editoria/autor renomeados).
 */
function farol_seo_for_post_cached( WP_Post $post ): array {
	$sig    = md5(
		implode(
			'|',
			array( $post->post_modified_gmt, FAROL_SEO_VERSION, wp_json_encode( farol_seo_settings() ), get_bloginfo( 'name' ), farol_seo_url( '/' ), (int) floor( time() / ( 12 * HOUR_IN_SECONDS ) ) )
		)
	);
	$cached = get_post_meta( $post->ID, '_farol_seo_cache', true );
	if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $sig ) {
		return $cached['data'];
	}
	$data = farol_seo_for_post( $post );
	update_post_meta( $post->ID, '_farol_seo_cache', wp_slash( array( 'sig' => $sig, 'data' => $data ) ) );
	return $data;
}

/**
 * Tudo que o front precisa para o <head> de uma notícia: título, descrição,
 * canonical, robots, Open Graph, resumo e JSON-LD — já com os automáticos
 * aplicados onde o editor não preencheu nada.
 */
function farol_seo_for_post( WP_Post $post ): array {
	$s        = farol_seo_settings();
	$meta     = farol_seo_meta( $post->ID );
	$analysis = ( new Farol_SEO_Analyzer( farol_seo_input( $post ) ) )->run();
	$r        = $analysis['resolved'];
	$url      = farol_seo_url( '/noticias/' . $post->post_name );
	$canon    = $meta['canonical'] ?: $url;

	$image_id = (int) $meta['og_image'] ?: (int) get_post_thumbnail_id( $post );
	$image    = null;
	if ( $image_id && ( $src = wp_get_attachment_image_src( $image_id, 'full' ) ) ) {
		$image = array(
			'url'    => $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
		);
	} elseif ( $s['default_image'] ) {
		$image = array( 'url' => $s['default_image'], 'width' => 1200, 'height' => 630, 'alt' => '' );
	}

	$cats     = array_values( array_filter( get_the_category( $post->ID ), fn( $c ) => ! in_array( $c->slug, array( 'sem-categoria', 'uncategorized' ), true ) ) );
	$section  = $cats[0] ?? null;
	$tags     = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
	$keywords = array_values( array_unique( array_filter( array_merge( array( $r['keyword'] ), $tags ) ) ) );
	$author   = get_userdata( (int) $post->post_author );

	$person = array( '@type' => 'Person', 'name' => $author ? $author->display_name : get_bloginfo( 'name' ) );
	if ( $author && $author->description ) {
		$person['description'] = $author->description;
	}
	if ( $s['publishing_principles'] ) {
		$person['url'] = farol_seo_absolute( $s['publishing_principles'] );
	}

	$publisher = farol_seo_organization();

	$article = array(
		'@context'            => 'https://schema.org',
		'@type'               => 'NewsArticle',
		'@id'                 => $url . '#materia',
		'url'                 => $url,
		'mainEntityOfPage'    => $canon,
		'headline'            => mb_substr( Farol_SEO_Analyzer::plain( $post->post_title ), 0, 110 ),
		'description'         => $r['description'],
		'datePublished'       => get_post_time( 'c', true, $post ),
		'dateModified'        => get_post_modified_time( 'c', true, $post ),
		'author'              => array( $person ),
		'publisher'           => $publisher,
		'inLanguage'          => 'pt-BR',
		'isAccessibleForFree' => true,
		'wordCount'           => $analysis['stats']['words'],
	);
	if ( $image ) {
		$article['image'] = array( '@type' => 'ImageObject', 'url' => $image['url'], 'width' => $image['width'], 'height' => $image['height'] );
	}
	if ( $section ) {
		$article['articleSection'] = $section->name;
	}
	if ( $keywords ) {
		$article['keywords'] = implode( ', ', $keywords );
	}
	if ( $r['summary'] ) {
		$article['abstract'] = implode( ' ', $r['summary'] );
	}

	// Fontes citadas com o bloco Fonte (plugin farol-fontes): ricas e tipadas.
	$fontes  = function_exists( 'farol_fontes_from_content' ) ? farol_fontes_from_content( $post->post_content ) : array();
	$sources = array();
	foreach ( $fontes as $f ) {
		$sources[ $f['url'] ] = array(
			'url'      => $f['url'],
			'domain'   => preg_replace( '/^www\./', '', (string) wp_parse_url( $f['url'], PHP_URL_HOST ) ),
			'label'    => $f['title'] ?: ( $f['author']['name'] ?? '' ) ?: $f['provider'],
			'platform' => $f['platform'],
			'type'     => $f['kind'],
			'author'   => $f['author']['name'] ?? '',
			'date'     => $f['date'] ?? '',
		);
	}
	// Links comuns no texto completam a lista.
	foreach ( $analysis['doc']['sources'] as $link ) {
		$sources[ $link['url'] ] ??= $link + array( 'platform' => 'web', 'type' => 'link', 'author' => '', 'date' => '' );
	}
	$sources = array_values( $sources );
	if ( $fontes ) {
		$article['citation'] = array_map( 'farol_fontes_schema', $fontes );
	}

	$crumbs = array( array( 'Início', farol_seo_url( '/' ) ) );
	if ( $section ) {
		$crumbs[] = array( $section->name, farol_seo_url( '/' . $section->slug ) );
	}
	$crumbs[] = array( Farol_SEO_Analyzer::plain( $post->post_title ), $url );

	$breadcrumb = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => array_map(
			fn( $c, $i ) => array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] ),
			$crumbs,
			array_keys( $crumbs )
		),
	);

	$published = get_post_time( 'c', true, $post );
	$modified  = get_post_modified_time( 'c', true, $post );
	$title     = Farol_SEO_Analyzer::plain( $post->post_title );
	$date_abnt = wp_date( 'j M. Y', get_post_time( 'U', true, $post ) );

	return array(
		// Busca tradicional: o que vai no <head>.
		'seo'    => array(
			'title'       => $r['title'],
			'description' => $r['description'],
			'canonical'   => $canon,
			'robots'      => $meta['noindex'] ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
			'keyword'     => $r['keyword'],
			'keywords'    => $keywords,
			'og'          => array(
				'title'       => $meta['og_title'] ?: $r['title'],
				'description' => $meta['og_description'] ?: $r['description'],
				'image'       => $image,
			),
		),
		// Buscadores com IA: fatos, fontes e contexto para citar a matéria.
		'geo'    => array(
			'summary'      => $r['summary'],
			'lead'         => $analysis['doc']['lead'],
			'sources'      => $sources,
			'author'       => array(
				'name' => $person['name'],
				'bio'  => $person['description'] ?? '',
				'url'  => $person['url'] ?? '',
			),
			'publisher'    => get_bloginfo( 'name' ),
			'section'      => $section ? $section->name : null,
			'tags'         => $tags,
			'language'     => 'pt-BR',
			'published'    => $published,
			'modified'     => $modified,
			'word_count'   => $analysis['stats']['words'],
			'reading_time' => max( 1, (int) ceil( $analysis['stats']['words'] / 200 ) ),
			'citation'     => sprintf( '%s. %s, %s. Disponível em: %s.', $title, get_bloginfo( 'name' ), $date_abnt, $canon ),
		),
		// JSON-LD pronto (NewsArticle + BreadcrumbList).
		'schema' => array( $article, $breadcrumb ),
	);
}
