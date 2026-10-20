<?php
/**
 * Campos por post. Todos começam vazios: vazio = usar o valor automático.
 *
 * @package FarolSEO
 */

defined( 'ABSPATH' ) || exit;

/** Campos editáveis no painel do editor: chave curta => tipo. */
function farol_seo_fields(): array {
	return array(
		'title'          => 'string',
		'description'    => 'string',
		'keyword'        => 'string',
		'summary'        => 'string',
		'canonical'      => 'string',
		'noindex'        => 'boolean',
		'og_title'       => 'string',
		'og_description' => 'string',
		'og_image'       => 'integer',
	);
}

add_action(
	'init',
	function () {
		foreach ( farol_seo_fields() as $key => $type ) {
			register_post_meta(
				'post',
				"_farol_seo_{$key}",
				array(
					'type'              => $type,
					'single'            => true,
					'default'           => 'boolean' === $type ? false : ( 'integer' === $type ? 0 : '' ),
					'show_in_rest'      => true,
					'sanitize_callback' => match ( $key ) {
						'canonical' => 'esc_url_raw',
						'summary'   => 'sanitize_textarea_field',
						'noindex'   => 'rest_sanitize_boolean',
						'og_image'  => 'absint',
						default     => 'sanitize_text_field',
					},
					'auth_callback'     => fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
				)
			);
		}

		// Scores calculados ao salvar (coluna na lista de posts). Não editáveis.
		foreach ( array( '_farol_seo_score', '_farol_geo_score' ) as $key ) {
			register_post_meta( 'post', $key, array( 'type' => 'integer', 'single' => true, 'show_in_rest' => false ) );
		}
	}
);

/** Overrides salvos de um post, sem o prefixo. */
function farol_seo_meta( int $post_id ): array {
	$out = array();
	foreach ( array_keys( farol_seo_fields() ) as $key ) {
		$out[ $key ] = get_post_meta( $post_id, "_farol_seo_{$key}", true );
	}
	return $out;
}

/**
 * Monta a entrada do analisador a partir de um post, permitindo sobrescrever
 * com o estado ainda não salvo do editor.
 */
function farol_seo_input( WP_Post $post, array $edits = array() ): array {
	$image_id = (int) ( $edits['featured_media'] ?? get_post_thumbnail_id( $post ) );
	$image    = $image_id ? wp_get_attachment_metadata( $image_id ) : null;

	$cat_ids = $edits['categories'] ?? wp_get_post_categories( $post->ID );
	$cats    = array_map( fn( $id ) => get_category( (int) $id )->slug ?? '', (array) $cat_ids );

	$author = get_userdata( (int) ( $edits['author'] ?? $post->post_author ) );

	return array(
		'title'       => $edits['title'] ?? $post->post_title,
		// Filtro para blocos dinâmicos (ex.: Fonte) entrarem na análise já renderizados.
		'content'     => apply_filters( 'farol_seo_content', $edits['content'] ?? $post->post_content ),
		'excerpt'     => $edits['excerpt'] ?? $post->post_excerpt,
		'slug'        => $edits['slug'] ?? $post->post_name,
		'meta'        => array_merge( farol_seo_meta( $post->ID ), (array) ( $edits['meta'] ?? array() ) ),
		'has_image'   => (bool) $image_id,
		'image_width' => (int) ( $image['width'] ?? 0 ),
		'categories'  => array_filter( $cats ),
		'author_name' => $author ? $author->display_name : '',
		'author_bio'  => $author ? $author->description : '',
	);
}

/** Recalcula e guarda os scores depois que post, termos e meta foram salvos. */
add_action(
	'wp_after_insert_post',
	function ( int $post_id, WP_Post $post ) {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		$result = ( new Farol_SEO_Analyzer( farol_seo_input( $post ) ) )->run();
		update_post_meta( $post_id, '_farol_seo_score', $result['seo']['score'] );
		update_post_meta( $post_id, '_farol_geo_score', $result['geo']['score'] );
	},
	10,
	2
);
