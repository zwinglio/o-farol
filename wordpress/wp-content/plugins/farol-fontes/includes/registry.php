<?php
/**
 * Fontes de um post: lidas dos blocos Fonte, para o plugin de SEO/GEO
 * (geo.sources e citation no JSON-LD).
 *
 * @package FarolFontes
 */

defined( 'ABSPATH' ) || exit;

/** Snapshots de todos os blocos Fonte do conteúdo (inclusive aninhados). */
function farol_fontes_from_content( string $content ): array {
	$out  = array();
	$walk = function ( array $blocks ) use ( &$walk, &$out ) {
		foreach ( $blocks as $b ) {
			if ( 'farol/fonte' === $b['blockName'] && ! empty( $b['attrs']['source']['url'] ) ) {
				$out[ $b['attrs']['source']['url'] ] = $b['attrs']['source'];
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$walk( $b['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( $content ) );
	return array_values( $out );
}

/** Fonte → schema.org (para `citation` do NewsArticle). */
function farol_fontes_schema( array $s ): array {
	$type = array( 'post' => 'SocialMediaPosting', 'video' => 'VideoObject', 'page' => 'CreativeWork' )[ $s['kind'] ?? 'page' ] ?? 'CreativeWork';
	$node = array( '@type' => $type, 'url' => $s['url'] );

	$name = $s['title'] ?: wp_trim_words( (string) ( $s['text'] ?? '' ), 12 );
	if ( $name ) {
		$node[ 'VideoObject' === $type ? 'name' : 'headline' ] = $name;
	}
	if ( ! empty( $s['author']['name'] ) ) {
		$node['author'] = array_filter( array( '@type' => 'Person', 'name' => $s['author']['name'], 'url' => $s['author']['url'] ?? '' ) );
	}
	if ( ! empty( $s['date'] ) ) {
		$node[ 'VideoObject' === $type ? 'uploadDate' : 'datePublished' ] = $s['date'];
	}
	if ( 'VideoObject' === $type && ! empty( $s['image']['url'] ) ) {
		$node['thumbnailUrl'] = $s['image']['url'];
	}
	if ( ! empty( $s['provider'] ) ) {
		$node['publisher'] = array( '@type' => 'Organization', 'name' => $s['site']['name'] ?? $s['provider'] );
	}
	return $node;
}
