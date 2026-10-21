<?php
/**
 * Inscritos: tabela própria, inscrição com confirmação (double opt-in) e
 * descadastro. Cada inscrito tem um token aleatório usado nos links de
 * confirmar e sair — o e-mail nunca aparece em URL.
 *
 * Situações: pending (aguardando confirmação), active, unsubscribed.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

const FAROL_NEWSLETTER_DB_VERSION = '1';

function farol_newsletter_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'farol_newsletter';
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
function farol_newsletter_subscribe( string $email, string $source = '' ): true|WP_Error {
	global $wpdb;
	$email = strtolower( trim( sanitize_email( $email ) ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'farol_newsletter_email', 'E-mail inválido.', array( 'status' => 400 ) );
	}

	$row = farol_newsletter_find( 'email', $email );
	if ( $row && 'active' === $row->status ) {
		return true;
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
	return (bool) $wpdb->delete( farol_newsletter_table(), array( 'email' => strtolower( trim( $email ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
 * Próximos inscritos ativos depois de um id (envio em lotes).
 *
 * @return object[]
 */
function farol_newsletter_active_after( int $after, int $limit ): array {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT id, email, token FROM %i WHERE status = 'active' AND id > %d ORDER BY id LIMIT %d", farol_newsletter_table(), $after, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
