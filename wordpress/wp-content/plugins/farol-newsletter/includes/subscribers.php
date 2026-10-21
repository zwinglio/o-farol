<?php
/**
 * Inscritos: tabela própria, inscrição com confirmação (double opt-in) e
 * descadastro. Cada inscrito tem um token aleatório usado nos links de
 * confirmar e sair — o e-mail nunca aparece em URL. Em quais listas cada um
 * está fica em includes/lists.php.
 *
 * Situações: pending (aguardando confirmação), active, unsubscribed. Sair
 * vale para todas as listas.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

const FAROL_NEWSLETTER_DB_VERSION = '4';

function farol_newsletter_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'farol_newsletter';
}

function farol_newsletter_campaigns_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'farol_newsletter_campaigns';
}

function farol_newsletter_install(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table = farol_newsletter_table();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			token char(32) NOT NULL,
			source varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			confirmed_at datetime NULL,
			unsubscribed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			UNIQUE KEY token (token),
			KEY status (status)
		) {$wpdb->get_charset_collate()};"
	);

	$campaigns = farol_newsletter_campaigns_table();
	dbDelta(
		"CREATE TABLE {$campaigns} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			html longtext NULL,
			text longtext NULL,
			meta longtext NULL,
			kind varchar(20) NOT NULL DEFAULT 'custom',
			status varchar(20) NOT NULL DEFAULT 'draft',
			scheduled_at datetime NULL,
			last_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sent int(11) NOT NULL DEFAULT 0,
			failed int(11) NOT NULL DEFAULT 0,
			total int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY scheduled_at (scheduled_at)
		) {$wpdb->get_charset_collate()};"
	);

	$lists = farol_newsletter_lists_table();
	dbDelta(
		"CREATE TABLE {$lists} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			slug varchar(190) NOT NULL DEFAULT '',
			description text NULL,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$wpdb->get_charset_collate()};"
	);

	$members = farol_newsletter_members_table();
	dbDelta(
		"CREATE TABLE {$members} (
			list_id bigint(20) unsigned NOT NULL,
			subscriber_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (list_id,subscriber_id),
			KEY subscriber_id (subscriber_id)
		) {$wpdb->get_charset_collate()};"
	);

	$templates = farol_newsletter_templates_table();
	dbDelta(
		"CREATE TABLE {$templates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(64) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT 'campaign',
			subject varchar(255) NOT NULL DEFAULT '',
			preheader varchar(255) NOT NULL DEFAULT '',
			blocks longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY kind (kind)
		) {$wpdb->get_charset_collate()};"
	);

	// Primeira lista: "Geral", padrão do site, com todo mundo que já existia.
	if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $lists ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$geral = farol_newsletter_list_save( array( 'name' => 'Geral', 'slug' => 'geral', 'description' => 'Lista principal: quem se inscreve pelo site entra aqui.', 'is_default' => 1 ) );
		if ( is_int( $geral ) && $geral ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (list_id, subscriber_id, created_at) SELECT %d, id, created_at FROM %i', $members, $geral, $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
	farol_newsletter_templates_seed();

	// Migra um envio em andamento do formato antigo (opção) para a tabela.
	$legacy = get_option( 'farol_newsletter_campaign' );
	if ( $legacy && ! empty( $legacy['subject'] ) ) {
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$campaigns,
			array(
				'name'        => $legacy['subject'],
				'subject'     => $legacy['subject'],
				'html'        => $legacy['html'] ?? '',
				'text'        => $legacy['text'] ?? '',
				'kind'        => 'digest',
				'status'      => 'sending',
				'last_id'    => (int) ( $legacy['cursor'] ?? 0 ),
				'sent'        => (int) ( $legacy['sent'] ?? 0 ),
				'failed'      => (int) ( $legacy['failed'] ?? 0 ),
				'total'       => (int) ( $legacy['total'] ?? 0 ),
				'created_at'  => farol_newsletter_now(),
				'started_at'  => gmdate( 'Y-m-d H:i:s', (int) ( $legacy['started'] ?? time() ) ),
			)
		);
		delete_option( 'farol_newsletter_campaign' );
		wp_schedule_single_event( time(), 'farol_newsletter_batch' );
	}
	delete_option( 'farol_newsletter_log' );
	update_option( 'farol_newsletter_db', FAROL_NEWSLETTER_DB_VERSION );
}

// Cobre deploys que só trocam os arquivos (sem reativar o plugin).
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'farol_newsletter_db' ) !== FAROL_NEWSLETTER_DB_VERSION ) {
			farol_newsletter_install();
		}
	}
);

function farol_newsletter_now(): string {
	return current_time( 'mysql', true );
}

function farol_newsletter_find( string $field, string $value ): ?object {
	global $wpdb;
	$field = in_array( $field, array( 'email', 'token' ), true ) ? $field : 'email';
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s', farol_newsletter_table(), $field, $value ) ) ?: null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Inscreve (ou reinscreve) e manda o e-mail de confirmação. A resposta é a
 * mesma para quem já está inscrito, para não revelar quem está na lista.
 */
function farol_newsletter_subscribe( string $email, string $source = '', array $list_ids = array() ): true|WP_Error {
	global $wpdb;
	$email = strtolower( trim( sanitize_email( $email ) ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'farol_newsletter_email', 'E-mail inválido.', array( 'status' => 400 ) );
	}

	$list_ids = $list_ids ?: farol_newsletter_default_list_ids();
	$row      = farol_newsletter_find( 'email', $email );
	if ( $row ) {
		// Já cadastrado: as listas pedidas somam às que já tinha.
		farol_newsletter_members_add( array( (int) $row->id ), $list_ids );
		if ( 'active' === $row->status ) {
			return true;
		}
	}

	// No máximo um e-mail de confirmação a cada 10 minutos por endereço.
	$throttle = 'farol_nl_' . md5( $email );
	if ( get_transient( $throttle ) ) {
		return true;
	}

	if ( $row ) {
		$wpdb->update( farol_newsletter_table(), array( 'status' => 'pending', 'unsubscribed_at' => null ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$token = $row->token;
	} else {
		$token = bin2hex( random_bytes( 16 ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			farol_newsletter_table(),
			array(
				'email'      => $email,
				'status'     => 'pending',
				'token'      => $token,
				'source'     => substr( sanitize_key( $source ), 0, 40 ),
				'created_at' => farol_newsletter_now(),
			)
		);
		farol_newsletter_members_add( array( (int) $wpdb->insert_id ), $list_ids );
	}

	set_transient( $throttle, 1, 10 * MINUTE_IN_SECONDS );
	if ( ! farol_newsletter_send_confirmation( $email, $token ) ) {
		delete_transient( $throttle );
		return new WP_Error( 'farol_newsletter_mail', 'Não foi possível enviar o e-mail de confirmação.', array( 'status' => 502 ) );
	}
	return true;
}

/** Confirma a inscrição. Link antigo de quem já saiu não reativa. */
function farol_newsletter_confirm( string $token ): bool {
	global $wpdb;
	$row = farol_newsletter_find( 'token', $token );
	if ( ! $row || 'unsubscribed' === $row->status ) {
		return false;
	}
	if ( 'pending' === $row->status ) {
		$wpdb->update( farol_newsletter_table(), array( 'status' => 'active', 'confirmed_at' => farol_newsletter_now() ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	return true;
}

function farol_newsletter_unsubscribe( string $token ): bool {
	global $wpdb;
	$row = farol_newsletter_find( 'token', $token );
	if ( ! $row ) {
		return false;
	}
	if ( 'unsubscribed' !== $row->status ) {
		$wpdb->update( farol_newsletter_table(), array( 'status' => 'unsubscribed', 'unsubscribed_at' => farol_newsletter_now() ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	return true;
}

/** Apaga de vez (pedido de exclusão de dados, LGPD). */
function farol_newsletter_delete( string $email ): bool {
	global $wpdb;
	$row = farol_newsletter_find( 'email', strtolower( trim( $email ) ) );
	if ( ! $row ) {
		return false;
	}
	$wpdb->delete( farol_newsletter_members_table(), array( 'subscriber_id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (bool) $wpdb->delete( farol_newsletter_table(), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** Inscreve direto como ativo (cadastro feito pelo admin no painel). */
function farol_newsletter_add_manually( string $email, array $list_ids = array() ): true|WP_Error {
	global $wpdb;
	$email = strtolower( trim( sanitize_email( $email ) ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'farol_newsletter_email', 'E-mail inválido.' );
	}
	$list_ids = $list_ids ?: farol_newsletter_default_list_ids();
	$row      = farol_newsletter_find( 'email', $email );
	if ( $row ) {
		if ( 'active' !== $row->status ) {
			$wpdb->update( farol_newsletter_table(), array( 'status' => 'active', 'confirmed_at' => farol_newsletter_now(), 'unsubscribed_at' => null ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		farol_newsletter_members_add( array( (int) $row->id ), $list_ids );
		return true;
	}
	$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		farol_newsletter_table(),
		array(
			'email'        => $email,
			'status'       => 'active',
			'token'        => bin2hex( random_bytes( 16 ) ),
			'source'       => 'admin',
			'created_at'   => farol_newsletter_now(),
			'confirmed_at' => farol_newsletter_now(),
		)
	);
	if ( ! $ok ) {
		return new WP_Error( 'farol_newsletter_db', 'Não foi possível inscrever.' );
	}
	farol_newsletter_members_add( array( (int) $wpdb->insert_id ), $list_ids );
	return true;
}

/**
 * Filtro da tela de inscritos (situação, busca e lista).
 *
 * @return array{0: string, 1: array} SQL do WHERE e argumentos.
 */
function farol_newsletter_list_where( string $status, string $search, int $list_id ): array {
	global $wpdb;
	$where = '1=1';
	$args  = array();
	if ( in_array( $status, array( 'pending', 'active', 'unsubscribed' ), true ) ) {
		$where .= ' AND s.status = %s';
		$args[] = $status;
	}
	if ( '' !== $search ) {
		$where .= ' AND s.email LIKE %s';
		$args[] = '%' . $wpdb->esc_like( $search ) . '%';
	}
	if ( $list_id ) {
		[ $sql, $list_args ] = farol_newsletter_audience_sql( array( $list_id ) );
		$where .= $sql;
		$args   = array_merge( $args, $list_args );
	}
	return array( $where, $args );
}

/**
 * Lista inscritos para o painel, com filtro de situação, busca e lista.
 *
 * @return object[]
 */
function farol_newsletter_list( string $status = '', string $search = '', int $limit = 50, int $offset = 0, int $list_id = 0 ): array {
	global $wpdb;
	[ $where, $args ] = farol_newsletter_list_where( $status, $search, $list_id );
	return $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.email, s.status, s.source, s.created_at, s.confirmed_at, s.unsubscribed_at FROM %i s WHERE {$where} ORDER BY s.id DESC LIMIT %d OFFSET %d", farol_newsletter_table(), ...array_merge( $args, array( $limit, $offset ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** Total de inscritos com o mesmo filtro, para paginar. */
function farol_newsletter_list_count( string $status = '', string $search = '', int $list_id = 0 ): int {
	global $wpdb;
	[ $where, $args ] = farol_newsletter_list_where( $status, $search, $list_id );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i s WHERE {$where}", farol_newsletter_table(), ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** @return array{active: int, pending: int, unsubscribed: int} */
function farol_newsletter_counts(): array {
	global $wpdb;
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', farol_newsletter_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$counts = array( 'active' => 0, 'pending' => 0, 'unsubscribed' => 0 );
	foreach ( $rows as $r ) {
		$counts[ $r->status ] = (int) $r->n;
	}
	return $counts;
}

/**
 * Próximos inscritos ativos depois de um id (envio em lotes), só das listas
 * pedidas (vazio = todos os ativos).
 *
 * @param int[] $list_ids
 * @return object[]
 */
function farol_newsletter_active_after( int $after, int $limit, array $list_ids = array() ): array {
	global $wpdb;
	[ $sql, $args ] = farol_newsletter_audience_sql( $list_ids );
	return $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.email, s.token FROM %i s WHERE s.status = 'active' AND s.id > %d{$sql} ORDER BY s.id LIMIT %d", farol_newsletter_table(), $after, ...array_merge( $args, array( $limit ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
