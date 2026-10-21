<?php
/**
 * Templates: assunto, pré-visualização e blocos prontos para começar uma
 * campanha, para o resumo automático e para o e-mail de confirmação.
 *
 * Dois vêm de fábrica e não podem ser apagados (só editados ou restaurados):
 * "Semanal" (resumo com as matérias recentes) e "Confirmação" (o e-mail do
 * double opt-in). Tipos: campaign | confirmation.
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

function farol_newsletter_templates_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'farol_newsletter_templates';
}

/** @return array<string, array> Templates de fábrica, por slug. */
function farol_newsletter_template_defaults(): array {
	return array(
		'semanal'     => array(
			'name'      => 'Semanal',
			'kind'      => 'campaign',
			'subject'   => '{{site}}: {{manchete}}',
			'preheader' => '',
			'blocks'    => array(
				array( 'type' => 'latest', 'count' => FAROL_NEWSLETTER_MAX_POSTS, 'feature' => true ),
			),
		),
		'confirmacao' => array(
			'name'      => 'Confirmação',
			'kind'      => 'confirmation',
			'subject'   => 'Confirme sua inscrição na newsletter do {{site}}',
			'preheader' => 'Confirme sua inscrição',
			'blocks'    => array(
				array( 'type' => 'text', 'html' => "<p>Olá!</p>\n<p>Recebemos um pedido para inscrever este e-mail na newsletter do {{site}}. Para começar a receber, confirme no botão abaixo.</p>" ),
				array( 'type' => 'button', 'label' => 'Confirmar inscrição', 'url' => '{{link_confirmacao}}' ),
			),
		),
	);
}

/** Cria os templates de fábrica que faltarem. */
function farol_newsletter_templates_seed(): void {
	global $wpdb;
	foreach ( farol_newsletter_template_defaults() as $slug => $t ) {
		if ( farol_newsletter_template_by_slug( $slug ) ) {
			continue;
		}
		// O texto de abertura da Automação (versões antigas) vira um bloco.
		$intro = 'semanal' === $slug ? trim( (string) ( get_option( 'farol_newsletter', array() )['intro'] ?? '' ) ) : '';
		if ( '' !== $intro ) {
			array_unshift( $t['blocks'], array( 'type' => 'text', 'html' => esc_html( $intro ) ) );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			farol_newsletter_templates_table(),
			array(
				'slug'       => $slug,
				'name'       => $t['name'],
				'kind'       => $t['kind'],
				'subject'    => $t['subject'],
				'preheader'  => $t['preheader'],
				'blocks'     => wp_json_encode( $t['blocks'] ),
				'created_at' => farol_newsletter_now(),
				'updated_at' => farol_newsletter_now(),
			)
		);
	}
}

function farol_newsletter_template_get( int $id ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', farol_newsletter_templates_table(), $id ) ) ?: null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function farol_newsletter_template_by_slug( string $slug ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', farol_newsletter_templates_table(), $slug ) ) ?: null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** @return object[] Templates de um tipo (vazio = todos). */
function farol_newsletter_templates( string $kind = '' ): array {
	global $wpdb;
	if ( $kind ) {
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE kind = %s ORDER BY slug = '' , name", farol_newsletter_templates_table(), $kind ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY kind, slug = '', name", farol_newsletter_templates_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** Template de confirmação em uso (o de fábrica, ou ele mesmo recriado). */
function farol_newsletter_confirmation_template(): object {
	$t = farol_newsletter_template_by_slug( 'confirmacao' );
	return $t ?: (object) farol_newsletter_template_defaults()['confirmacao'];
}

/** Cria ou atualiza um template. Retorna o id. */
function farol_newsletter_template_save( array $data, int $id = 0 ): int|WP_Error {
	global $wpdb;
	$current = $id ? farol_newsletter_template_get( $id ) : null;
	if ( $id && ! $current ) {
		return new WP_Error( 'farol_newsletter_404', 'Template não encontrado.' );
	}
	$name = trim( sanitize_text_field( $data['name'] ?? '' ) );
	if ( '' === $name ) {
		return new WP_Error( 'farol_newsletter_template', 'Dê um nome para o template.' );
	}
	$kind   = $current ? $current->kind : ( 'confirmation' === ( $data['kind'] ?? '' ) ? 'confirmation' : 'campaign' );
	$blocks = farol_newsletter_blocks_sanitize( $data['blocks'] ?? array() );
	if ( 'confirmation' === $kind && ! str_contains( wp_json_encode( $blocks ), '{{link_confirmacao}}' ) ) {
		return new WP_Error( 'farol_newsletter_template', 'O e-mail de confirmação precisa de um botão (ou link) com {{link_confirmacao}}.' );
	}

	$fields = array(
		'name'       => substr( $name, 0, 190 ),
		'subject'    => substr( sanitize_text_field( $data['subject'] ?? '' ), 0, 255 ),
		'preheader'  => substr( sanitize_text_field( $data['preheader'] ?? '' ), 0, 255 ),
		'blocks'     => wp_json_encode( $blocks ),
		'updated_at' => farol_newsletter_now(),
	);
	if ( $current ) {
		$wpdb->update( farol_newsletter_templates_table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $id;
	}
	$wpdb->insert( farol_newsletter_templates_table(), $fields + array( 'kind' => $kind, 'slug' => '', 'created_at' => farol_newsletter_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->insert_id;
}

/** Volta um template de fábrica ao conteúdo original. */
function farol_newsletter_template_reset( int $id ): bool {
	$t        = farol_newsletter_template_get( $id );
	$defaults = farol_newsletter_template_defaults();
	if ( ! $t || ! isset( $defaults[ $t->slug ] ) ) {
		return false;
	}
	return is_int( farol_newsletter_template_save( $defaults[ $t->slug ], $id ) );
}

/** Apaga um template criado no painel (os de fábrica ficam). */
function farol_newsletter_template_delete( int $id ): bool {
	global $wpdb;
	$t = farol_newsletter_template_get( $id );
	if ( ! $t || '' !== $t->slug ) {
		return false;
	}
	return (bool) $wpdb->delete( farol_newsletter_templates_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** @return array[] Blocos de um template. */
function farol_newsletter_template_blocks( object $t ): array {
	return farol_newsletter_blocks_sanitize( is_string( $t->blocks ) ? $t->blocks : (array) $t->blocks );
}
