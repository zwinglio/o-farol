<?php
/**
 * Listas: grupos de inscritos (ex.: "Geral", "Parnamirim", "Eleições").
 * A confirmação e o descadastro valem para a pessoa inteira; a lista só diz
 * em quais envios ela entra. Uma campanha vai para a união das listas
 * escolhidas, sem repetir quem está em mais de uma.
 *
 * Listas "padrão" recebem quem se inscreve pelo site sem escolher lista.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

function farol_newsletter_lists_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'farol_newsletter_lists';
}

function farol_newsletter_members_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'farol_newsletter_list_members';
}

/**
 * Todas as listas, com quantos ativos cada uma tem.
 *
 * @return object[] id, name, slug, description, is_default, active
 */
function farol_newsletter_lists(): array {
	global $wpdb;
	return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT l.*, ( SELECT COUNT(*) FROM %i m JOIN %i s ON s.id = m.subscriber_id WHERE m.list_id = l.id AND s.status = 'active' ) AS active
			FROM %i l ORDER BY l.name",
			farol_newsletter_members_table(),
			farol_newsletter_table(),
			farol_newsletter_lists_table()
		)
	);
}

function farol_newsletter_list_get( int $id ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', farol_newsletter_lists_table(), $id ) ) ?: null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** Cria ou atualiza uma lista. Retorna o id. */
function farol_newsletter_list_save( array $data, int $id = 0 ): int|WP_Error {
	global $wpdb;
	$name = trim( sanitize_text_field( $data['name'] ?? '' ) );
	if ( '' === $name ) {
		return new WP_Error( 'farol_newsletter_list', 'Dê um nome para a lista.' );
	}
	$slug = sanitize_title( $data['slug'] ?? '' ) ?: sanitize_title( $name );
	$dup  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s AND id <> %d', farol_newsletter_lists_table(), $slug, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $dup ) {
		return new WP_Error( 'farol_newsletter_list', "Já existe uma lista com o identificador \"{$slug}\"." );
	}
	$fields = array(
		'name'        => substr( $name, 0, 190 ),
		'slug'        => substr( $slug, 0, 190 ),
		'description' => sanitize_textarea_field( $data['description'] ?? '' ),
		'is_default'  => empty( $data['is_default'] ) ? 0 : 1,
	);
	if ( $id ) {
		$wpdb->update( farol_newsletter_lists_table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $id;
	}
	$wpdb->insert( farol_newsletter_lists_table(), $fields + array( 'created_at' => farol_newsletter_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->insert_id;
}

/** Apaga a lista (os inscritos continuam, só saem dela). */
function farol_newsletter_list_delete( int $id ): bool {
	global $wpdb;
	$wpdb->delete( farol_newsletter_members_table(), array( 'list_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (bool) $wpdb->delete( farol_newsletter_lists_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** @return int[] Listas padrão (para quem se inscreve sem escolher). */
function farol_newsletter_default_list_ids(): array {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE is_default = 1', farol_newsletter_lists_table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Ids de listas a partir de slugs vindos do site. Slugs desconhecidos são
 * ignorados; sem nenhum válido, valem as listas padrão.
 *
 * @param string[] $slugs
 * @return int[]
 */
function farol_newsletter_list_ids_from_slugs( array $slugs ): array {
	global $wpdb;
	$slugs = array_values( array_filter( array_map( 'sanitize_title', $slugs ) ) );
	if ( ! $slugs ) {
		return farol_newsletter_default_list_ids();
	}
	$in  = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE slug IN ({$in})", farol_newsletter_lists_table(), ...$slugs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $ids ? array_map( 'intval', $ids ) : farol_newsletter_default_list_ids();
}

/** @param int[] $ids Só ids de listas que existem. */
function farol_newsletter_valid_list_ids( array $ids ): array {
	$known = wp_list_pluck( farol_newsletter_lists(), 'id' );
	return array_values( array_intersect( array_unique( array_map( 'intval', $ids ) ), array_map( 'intval', $known ) ) );
}

/**
 * Coloca inscritos em listas (ignora quem já está).
 *
 * @param int[] $subscriber_ids
 * @param int[] $list_ids
 */
function farol_newsletter_members_add( array $subscriber_ids, array $list_ids ): void {
	global $wpdb;
	$now = farol_newsletter_now();
	foreach ( array_map( 'intval', $list_ids ) as $list ) {
		foreach ( array_map( 'intval', $subscriber_ids ) as $sub ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (list_id, subscriber_id, created_at) VALUES (%d, %d, %s)', farol_newsletter_members_table(), $list, $sub, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}

/**
 * Tira inscritos de listas.
 *
 * @param int[] $subscriber_ids
 * @param int[] $list_ids
 */
function farol_newsletter_members_remove( array $subscriber_ids, array $list_ids ): void {
	global $wpdb;
	foreach ( array_map( 'intval', $list_ids ) as $list ) {
		foreach ( array_map( 'intval', $subscriber_ids ) as $sub ) {
			$wpdb->delete( farol_newsletter_members_table(), array( 'list_id' => $list, 'subscriber_id' => $sub ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}

/**
 * Nomes das listas de cada inscrito, para a tabela do painel.
 *
 * @param int[] $subscriber_ids
 * @return array<int, string[]> subscriber_id => nomes
 */
function farol_newsletter_members_names( array $subscriber_ids ): array {
	global $wpdb;
	$subscriber_ids = array_map( 'intval', $subscriber_ids );
	if ( ! $subscriber_ids ) {
		return array();
	}
	$in   = implode( ',', array_fill( 0, count( $subscriber_ids ), '%d' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.subscriber_id, l.name FROM %i m JOIN %i l ON l.id = m.list_id WHERE m.subscriber_id IN ({$in}) ORDER BY l.name", farol_newsletter_members_table(), farol_newsletter_lists_table(), ...$subscriber_ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ (int) $r->subscriber_id ][] = $r->name;
	}
	return $out;
}

/**
 * Trecho SQL "inscrito está numa destas listas" (vazio = todos).
 *
 * @param int[] $list_ids
 * @return array{0: string, 1: array} SQL e argumentos para prepare().
 */
function farol_newsletter_audience_sql( array $list_ids ): array {
	$list_ids = array_map( 'intval', $list_ids );
	if ( ! $list_ids ) {
		return array( '', array() );
	}
	$in = implode( ',', array_fill( 0, count( $list_ids ), '%d' ) );
	return array( " AND EXISTS ( SELECT 1 FROM %i m WHERE m.subscriber_id = s.id AND m.list_id IN ({$in}) )", array_merge( array( farol_newsletter_members_table() ), $list_ids ) );
}

/**
 * Quantos ativos recebem um envio para estas listas (sem repetir).
 *
 * @param int[] $list_ids Vazio = todos os ativos.
 */
function farol_newsletter_audience_count( array $list_ids ): int {
	global $wpdb;
	[ $sql, $args ] = farol_newsletter_audience_sql( $list_ids );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i s WHERE s.status = 'active'{$sql}", farol_newsletter_table(), ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
