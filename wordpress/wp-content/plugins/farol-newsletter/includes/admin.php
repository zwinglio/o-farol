<?php
/**
 * Menu "Newsletter" no painel: Campanhas (lista, montador em blocos, envio e
 * agendamento), Listas, Inscritos, Templates e Automação (o resumo
 * programado e as configurações de envio).
 *
 * @package FarolNewsletter
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		add_menu_page( 'Newsletter', 'Newsletter', 'manage_options', 'farol-newsletter', 'farol_newsletter_page_campaigns', 'dashicons-email-alt', 26 );
		add_submenu_page( 'farol-newsletter', 'Campanhas', 'Campanhas', 'manage_options', 'farol-newsletter', 'farol_newsletter_page_campaigns' );
		add_submenu_page( 'farol-newsletter', 'Nova campanha', 'Nova campanha', 'manage_options', 'farol-newsletter-new', 'farol_newsletter_page_new' );
		add_submenu_page( 'farol-newsletter', 'Listas', 'Listas', 'manage_options', 'farol-newsletter-lists', 'farol_newsletter_page_lists' );
		add_submenu_page( 'farol-newsletter', 'Inscritos', 'Inscritos', 'manage_options', 'farol-newsletter-subscribers', 'farol_newsletter_page_subscribers' );
		add_submenu_page( 'farol-newsletter', 'Templates', 'Templates', 'manage_options', 'farol-newsletter-templates', 'farol_newsletter_page_templates' );
		add_submenu_page( 'farol-newsletter', 'Automação', 'Automação', 'manage_options', 'farol-newsletter-automation', 'farol_newsletter_render_settings' );
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( ! str_contains( (string) $hook, 'farol-newsletter' ) ) {
			return;
		}
		$base = plugin_dir_url( dirname( __DIR__ ) . '/farol-newsletter.php' );
		wp_register_script( 'farol-newsletter-admin', $base . 'assets/newsletter-admin.js', array( 'jquery', 'jquery-ui-sortable', 'wp-api-fetch' ), FAROL_NEWSLETTER_VERSION, true );
		wp_enqueue_style( 'farol-newsletter-admin', $base . 'assets/newsletter-admin.css', array(), FAROL_NEWSLETTER_VERSION );
	}
);

/**
 * Liga o montador na página: editor, biblioteca de mídia e os dados que o
 * JS precisa (blocos iniciais, templates, matérias recentes).
 */
function farol_newsletter_builder_assets( array $blocks, string $kind, array $templates = array() ): void {
	wp_enqueue_editor();
	wp_enqueue_media();
	wp_enqueue_script( 'farol-newsletter-admin' );

	$recent = array_map(
		fn( $p ) => array( 'id' => $p->ID, 'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ), 'date' => get_the_date( 'd/m/Y', $p ) ),
		farol_newsletter_recent_posts( 20 )
	);
	$titles = array();
	foreach ( $blocks as $b ) {
		foreach ( $b['ids'] ?? array() as $id ) {
			$titles[ $id ] = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
		}
	}
	wp_localize_script(
		'farol-newsletter-admin',
		'farolNL',
		array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'farol_newsletter_render' ),
			'kind'      => $kind,
			'blocks'    => $blocks,
			'templates' => array_map(
				fn( $t ) => array( 'id' => (int) $t->id, 'subject' => $t->subject, 'preheader' => $t->preheader, 'blocks' => farol_newsletter_template_blocks( $t ) ),
				$templates
			),
			'posts'     => $recent,
			'titles'    => (object) $titles,
		)
	);
}

/** Área do montador: os blocos, a barra de adicionar e o campo com o JSON. */
function farol_newsletter_builder( array $blocks, string $kind = 'campaign' ): void {
	$types = array(
		'heading' => 'Título',
		'text'    => 'Texto',
		'posts'   => 'Matérias escolhidas',
		'latest'  => 'Matérias recentes',
		'image'   => 'Imagem',
		'button'  => 'Botão',
		'divider' => 'Divisor',
		'spacer'  => 'Espaço',
	);
	?>
	<div id="farol-nl-blocks" class="farol-nl-blocks" data-kind="<?php echo esc_attr( $kind ); ?>"></div>
	<p class="farol-nl-blocks-empty description" hidden>Nenhum bloco ainda. Adicione o primeiro abaixo.</p>
	<div class="farol-nl-addbar">
		<span>Adicionar bloco:</span>
		<?php foreach ( $types as $type => $label ) : ?>
			<button type="button" class="button" data-add="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</div>
	<input type="hidden" name="blocks" id="farol-nl-blocks-input" value="<?php echo esc_attr( wp_json_encode( $blocks ) ); ?>">
	<p class="description farol-nl-vars">
		Marcadores nos textos, títulos e links: <code>{{site}}</code>, <code>{{manchete}}</code> (título da primeira matéria), <code>{{data}}</code><?php echo 'confirmation' === $kind ? ', <code>{{link_confirmacao}}</code> (o link de confirmar — use no botão)' : ''; ?>.
		O rodapé com o link de descadastro entra sozinho.
	</p>
	<?php
}

/** Coluna da prévia ao vivo (fica fixa ao lado do montador). */
function farol_newsletter_builder_preview( bool $audience = false ): void {
	?>
	<div class="farol-nl-compose-preview">
		<div class="farol-nl-preview-head">
			<strong>Prévia</strong>
			<?php if ( $audience ) : ?>
				<span id="farol-nl-audience" class="farol-nl-audience"></span>
			<?php endif; ?>
			<div class="farol-nl-preview-subject"><span>Assunto:</span> <span id="farol-nl-preview-subject">—</span></div>
		</div>
		<iframe id="farol-nl-preview" title="Prévia do e-mail"></iframe>
	</div>
	<?php
}

/** Caixas de seleção das listas, com quantos ativos cada uma tem. */
function farol_newsletter_list_checkboxes( array $checked, string $name = 'list_ids[]' ): void {
	$lists = farol_newsletter_lists();
	if ( ! $lists ) {
		echo '<p>Nenhuma lista ainda. <a href="' . esc_url( farol_newsletter_admin_url( 'farol-newsletter-lists' ) ) . '">Criar lista</a>.</p>';
		return;
	}
	echo '<fieldset class="farol-nl-lists">';
	foreach ( $lists as $l ) {
		printf(
			'<label><input type="checkbox" name="%s" value="%d"%s> %s <span class="description">(%s ativos)</span></label>',
			esc_attr( $name ),
			(int) $l->id,
			checked( in_array( (int) $l->id, array_map( 'intval', $checked ), true ), true, false ),
			esc_html( $l->name ),
			esc_html( number_format_i18n( (int) $l->active ) )
		);
	}
	echo '</fieldset>';
}

/** URL de prévia do HTML do e-mail (admin-post, abre em aba nova). */
function farol_newsletter_preview_url( array $args = array() ): string {
	return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'farol_newsletter_preview' ), $args ), admin_url( 'admin-post.php' ) ), 'farol_newsletter_preview' );
}

function farol_newsletter_admin_url( string $page = 'farol-newsletter', array $args = array() ): string {
	return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
}

/** Mostra o recado guardado pela última ação (transient por usuário). */
function farol_newsletter_render_notice(): void {
	$notice = get_transient( 'farol_newsletter_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'farol_newsletter_notice_' . get_current_user_id() );
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
	}
}

/** Data GMT do banco → formato local do site. */
function farol_newsletter_date( ?string $gmt ): string {
	if ( ! $gmt || str_starts_with( $gmt, '0000' ) ) {
		return '—';
	}
	return wp_date( 'd/m/Y H:i', strtotime( $gmt . ' +0000' ) );
}

/* ---------------------------------------------------------------- *
 *  Campanhas
 * ---------------------------------------------------------------- */

function farol_newsletter_page_campaigns(): void {
	$campaigns  = farol_newsletter_campaigns();
	$list_names = wp_list_pluck( farol_newsletter_lists(), 'name', 'id' );
	$quota      = farol_newsletter_quota_left();
	$limit     = farol_newsletter_daily_limit();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Campanhas</h1>
		<a href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-new' ) ); ?>" class="page-title-action">Nova campanha</a>
		<hr class="wp-header-end">
		<?php farol_newsletter_render_notice(); ?>

		<p>
			<?php if ( $limit ) : ?>
				Limite diário: <strong><?php echo esc_html( farol_newsletter_sent_today() . ' / ' . $limit ); ?></strong> e-mails usados hoje.
				A fila pausa ao bater o limite e continua no dia seguinte — configure em <code>NEWSLETTER_DAILY_LIMIT</code> no <code>.env</code>.
			<?php else : ?>
				Sem limite diário configurado (<code>NEWSLETTER_DAILY_LIMIT</code> no <code>.env</code>).
			<?php endif; ?>
		</p>

		<table class="widefat striped">
			<thead><tr>
				<th>Campanha</th><th>Tipo</th><th>Listas</th><th>Situação</th><th>Agendada</th><th>Progresso</th><th>Ações</th>
			</tr></thead>
			<tbody>
			<?php if ( ! $campaigns ) : ?>
				<tr><td colspan="7">Nenhuma campanha ainda.</td></tr>
			<?php endif; ?>
			<?php foreach ( $campaigns as $c ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $c->name ?: $c->subject ); ?></strong><br>
						<small><?php echo esc_html( $c->subject ); ?></small>
					</td>
					<td><?php echo 'digest' === $c->kind ? 'Resumo automático' : 'Manual'; ?></td>
					<td>
						<?php
						$ids = farol_newsletter_campaign_list_ids( $c );
						echo $ids ? esc_html( implode( ', ', array_intersect_key( $list_names, array_flip( $ids ) ) ) ) : ( array_key_exists( 'list_ids', farol_newsletter_campaign_meta( $c ) ) ? '—' : 'Todos' );
						?>
					</td>
					<td><?php echo esc_html( farol_newsletter_status_label( $c->status ) ); ?></td>
					<td><?php echo esc_html( farol_newsletter_date( $c->scheduled_at ) ); ?></td>
					<td>
						<?php
						if ( in_array( $c->status, array( 'sending', 'sent', 'cancelled' ), true ) ) {
							printf( '%d de ~%d', (int) $c->sent, (int) $c->total );
							echo $c->failed ? esc_html( sprintf( ' · %d falharam', $c->failed ) ) : '';
						} else {
							echo '—';
						}
						?>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( farol_newsletter_preview_url( array( 'id' => $c->id ) ) ); ?>" target="_blank" rel="noopener">Prévia</a>
						<?php if ( 'draft' === $c->status ) : ?>
							<a class="button button-small" href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-new', array( 'id' => $c->id ) ) ); ?>">Editar</a>
						<?php endif; ?>
						<?php if ( in_array( $c->status, array( 'draft', 'scheduled', 'cancelled' ), true ) ) : ?>
							<?php farol_newsletter_action_button( 'campaign_send', 'Enviar agora', 'primary', '', array( 'id' => $c->id ) ); ?>
						<?php endif; ?>
						<?php if ( in_array( $c->status, array( 'scheduled', 'sending' ), true ) ) : ?>
							<?php farol_newsletter_action_button( 'campaign_cancel', 'Cancelar', 'secondary', 'Cancelar esta campanha?', array( 'id' => $c->id ) ); ?>
						<?php endif; ?>
						<?php if ( 'sending' !== $c->status ) : ?>
							<?php farol_newsletter_action_button( 'campaign_delete', 'Apagar', 'delete', 'Apagar esta campanha?', array( 'id' => $c->id ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">O envio vai em lotes pelo cron (~<?php echo (int) FAROL_NEWSLETTER_BATCH; ?> por vez, um lote por minuto) para os inscritos ativos das listas escolhidas.</p>
	</div>
	<?php
}

/** Tela de criar/editar campanha manual: campos, montador e prévia ao vivo. */
function farol_newsletter_page_new(): void {
	$id = (int) ( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$c  = $id ? farol_newsletter_campaign_get( $id ) : null;
	if ( $id && ! $c ) {
		wp_die( 'Campanha não encontrada.' );
	}
	if ( $c && 'draft' !== $c->status ) {
		wp_safe_redirect( farol_newsletter_admin_url() );
		exit;
	}

	$meta = $c ? farol_newsletter_campaign_meta( $c ) : array();
	if ( isset( $meta['blocks'] ) ) {
		$blocks = farol_newsletter_blocks_sanitize( (array) $meta['blocks'] );
	} elseif ( $c ) {
		// Rascunho da versão anterior (texto de abertura + matérias).
		$blocks = farol_newsletter_blocks_sanitize(
			array(
				array( 'type' => 'text', 'html' => (string) ( $meta['intro'] ?? '' ) ),
				array( 'type' => 'posts', 'ids' => (array) ( $meta['post_ids'] ?? array() ), 'feature' => true ),
			)
		);
	} else {
		$blocks = array(
			array( 'type' => 'text', 'html' => '' ),
			array( 'type' => 'posts', 'ids' => array(), 'feature' => true ),
		);
	}
	$subject   = (string) ( $meta['subject_raw'] ?? $c->subject ?? '' );
	$preheader = (string) ( $meta['preheader_raw'] ?? '' );
	$lists     = $c ? (array) ( $meta['list_ids'] ?? farol_newsletter_default_list_ids() ) : farol_newsletter_default_list_ids();
	$templates = farol_newsletter_templates( 'campaign' );
	farol_newsletter_builder_assets( $blocks, 'campaign', $templates );
	?>
	<div class="wrap">
		<h1><?php echo $c ? 'Editar campanha' : 'Nova campanha'; ?></h1>
		<?php farol_newsletter_render_notice(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="farol-nl-form">
			<input type="hidden" name="action" value="farol_newsletter_campaign_save">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="template_id" id="farol-nl-template-id" value="<?php echo (int) ( $meta['template_id'] ?? 0 ); ?>">
			<?php wp_nonce_field( 'farol_newsletter_campaign_save' ); ?>

			<div class="farol-nl-compose">
				<div class="farol-nl-compose-form">
					<div class="farol-nl-panel">
						<p class="farol-nl-row">
							<label for="farol-nl-start">Começar de um template</label>
							<select id="farol-nl-start">
								<option value="">— escolha —</option>
								<?php foreach ( $templates as $t ) : ?>
									<option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( $t->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="button" id="farol-nl-apply">Aplicar</button>
							<span class="description">Troca os blocos abaixo pelos do template.</span>
						</p>
						<p class="farol-nl-row">
							<label for="farol-nl-name">Nome interno</label>
							<input type="text" id="farol-nl-name" name="name" class="regular-text" value="<?php echo esc_attr( $c->name ?? '' ); ?>" placeholder="Ex.: Edição especial eleições">
							<span class="description">Só aparece no painel.</span>
						</p>
						<p class="farol-nl-row">
							<label for="farol-nl-subject">Assunto</label>
							<input type="text" id="farol-nl-subject" name="subject" class="large-text" required value="<?php echo esc_attr( $subject ); ?>">
						</p>
						<p class="farol-nl-row">
							<label for="farol-nl-preheader">Pré-visualização</label>
							<input type="text" id="farol-nl-preheader" name="preheader" class="large-text" value="<?php echo esc_attr( $preheader ); ?>" placeholder="Texto curto que aparece ao lado do assunto na caixa de entrada">
						</p>
						<div class="farol-nl-row">
							<span class="farol-nl-label">Enviar para</span>
							<?php farol_newsletter_list_checkboxes( $lists ); ?>
						</div>
					</div>

					<h2>Conteúdo</h2>
					<?php farol_newsletter_builder( $blocks ); ?>

					<div class="farol-nl-panel">
						<p class="farol-nl-row">
							<label for="farol-nl-when">Agendar para</label>
							<input type="datetime-local" id="farol-nl-when" name="when" value="<?php echo $c && $c->scheduled_at ? esc_attr( wp_date( 'Y-m-d\TH:i', strtotime( $c->scheduled_at . ' +0000' ) ) ) : ''; ?>">
							<span class="description">Opcional. Com uma data, o botão "Agendar" põe a campanha na fila para essa hora.</span>
						</p>
					</div>

					<p class="submit">
						<?php submit_button( 'Salvar rascunho', 'secondary', 'do_draft', false ); ?>
						<?php submit_button( 'Enviar teste para mim', 'secondary', 'do_test', false ); ?>
						<?php submit_button( 'Agendar', 'secondary', 'do_schedule', false ); ?>
						<?php submit_button( 'Enviar agora', 'primary', 'do_send', false, array( 'onclick' => "return confirm('Enviar agora para as listas marcadas?')" ) ); ?>
					</p>
				</div>
				<?php farol_newsletter_builder_preview( true ); ?>
			</div>
		</form>
	</div>
	<?php
}

/* ---------------------------------------------------------------- *
 *  Inscritos
 * ---------------------------------------------------------------- */

function farol_newsletter_page_subscribers(): void {
	$status  = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$list_id = (int) ( $_GET['list'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$per     = 50;

	$counts = farol_newsletter_counts();
	$lists  = farol_newsletter_lists();
	$total  = farol_newsletter_list_count( $status, $search, $list_id );
	$rows   = farol_newsletter_list( $status, $search, $per, ( $paged - 1 ) * $per, $list_id );
	$names  = farol_newsletter_members_names( wp_list_pluck( $rows, 'id' ) );
	$pages  = max( 1, (int) ceil( $total / $per ) );
	$filter = array_filter( array( 'status' => $status, 's' => $search, 'list' => $list_id ) );

	$labels = array( '' => 'Todos', 'active' => 'Ativos', 'pending' => 'Aguardando confirmação', 'unsubscribed' => 'Saíram' );
	$pt     = array( 'pending' => 'Pendente', 'active' => 'Ativo', 'unsubscribed' => 'Saiu' );
	?>
	<div class="wrap">
		<h1>Inscritos</h1>
		<?php farol_newsletter_render_notice(); ?>

		<p>
			<strong><?php echo esc_html( number_format_i18n( $counts['active'] ) ); ?></strong> ativos ·
			<?php echo esc_html( number_format_i18n( $counts['pending'] ) ); ?> aguardando confirmação ·
			<?php echo esc_html( number_format_i18n( $counts['unsubscribed'] ) ); ?> saíram
		</p>

		<h2>Adicionar manualmente</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
			<input type="hidden" name="action" value="farol_newsletter_add">
			<?php wp_nonce_field( 'farol_newsletter_add' ); ?>
			<input type="email" name="email" class="regular-text" placeholder="email@exemplo.com" required>
			<?php submit_button( 'Inscrever', 'secondary', 'submit', false ); ?>
			<?php farol_newsletter_list_checkboxes( farol_newsletter_default_list_ids() ); ?>
			<p class="description">Entra direto como ativo, sem e-mail de confirmação. Use só para quem pediu para entrar na lista.</p>
		</form>
		<p><?php farol_newsletter_action_button( 'export', 'Exportar CSV' ); ?></p>

		<ul class="subsubsub">
			<?php foreach ( $labels as $key => $label ) : ?>
				<li><a href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-subscribers', array_filter( array( 'status' => $key, 's' => $search, 'list' => $list_id ) ) ) ); ?>"<?php echo $key === $status ? ' class="current"' : ''; ?>><?php echo esc_html( $label ); ?></a><?php echo 'unsubscribed' !== $key ? ' |' : ''; ?></li>
			<?php endforeach; ?>
		</ul>

		<form method="get" style="margin:8px 0;clear:both;">
			<input type="hidden" name="page" value="farol-newsletter-subscribers">
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<select name="list" aria-label="Lista">
				<option value="">Todas as listas</option>
				<?php foreach ( $lists as $l ) : ?>
					<option value="<?php echo (int) $l->id; ?>" <?php selected( $list_id, (int) $l->id ); ?>><?php echo esc_html( $l->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar e-mail">
			<?php submit_button( 'Filtrar', 'secondary', 'submit', false ); ?>
		</form>

		<?php // Os checkboxes da tabela apontam para este form (atributo form=), porque cada linha já tem o próprio form de apagar. ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="farol-nl-bulk" class="farol-nl-bulk" onsubmit="return this.op.value !== 'delete' || confirm('Apagar de vez os inscritos marcados?')">
			<input type="hidden" name="action" value="farol_newsletter_bulk">
			<?php wp_nonce_field( 'farol_newsletter_bulk' ); ?>
			<select name="op" aria-label="Ação em lote">
				<option value="">Ações em lote</option>
				<option value="list_add">Adicionar à lista…</option>
				<option value="list_remove">Remover da lista…</option>
				<option value="delete">Apagar de vez</option>
			</select>
			<select name="list_id" aria-label="Lista">
				<option value="">— lista —</option>
				<?php foreach ( $lists as $l ) : ?>
					<option value="<?php echo (int) $l->id; ?>"><?php echo esc_html( $l->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( 'Aplicar', 'secondary', 'submit', false ); ?>
		</form>

		<table class="widefat striped">
			<thead><tr>
				<td class="check-column"><input type="checkbox" form="farol-nl-bulk" onclick="document.querySelectorAll('.farol-nl-check').forEach(c => c.checked = this.checked)" aria-label="Marcar todos"></td>
				<th>E-mail</th><th>Situação</th><th>Listas</th><th>Origem</th><th>Inscrito em</th><th>Confirmado em</th><th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="8">Ninguém aqui com este filtro.</td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<th scope="row" class="check-column"><input type="checkbox" class="farol-nl-check" name="ids[]" value="<?php echo (int) $row->id; ?>" form="farol-nl-bulk" aria-label="<?php echo esc_attr( $row->email ); ?>"></th>
					<td><?php echo esc_html( $row->email ); ?></td>
					<td><?php echo esc_html( $pt[ $row->status ] ?? $row->status ); ?></td>
					<td><?php echo esc_html( implode( ', ', $names[ (int) $row->id ] ?? array() ) ?: '—' ); ?></td>
					<td><?php echo esc_html( $row->source ); ?></td>
					<td><?php echo esc_html( farol_newsletter_date( $row->created_at ) ); ?></td>
					<td><?php echo esc_html( farol_newsletter_date( $row->confirmed_at ) ); ?></td>
					<td>
						<?php farol_newsletter_action_button( 'delete', 'Apagar', 'delete', 'Apagar este e-mail da lista de vez?', array( 'email' => $row->email ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<p class="tablenav"><span class="pagination-links">
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<?php if ( $i === $paged ) : ?>
						<strong><?php echo (int) $i; ?></strong>
					<?php else : ?>
						<a href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-subscribers', $filter + array( 'paged' => $i ) ) ); ?>"><?php echo (int) $i; ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</span></p>
		<?php endif; ?>
	</div>
	<?php
}

/* ---------------------------------------------------------------- *
 *  Listas
 * ---------------------------------------------------------------- */

function farol_newsletter_page_lists(): void {
	$lists = farol_newsletter_lists();
	$edit  = farol_newsletter_list_get( (int) ( $_GET['edit'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wrap">
		<h1>Listas</h1>
		<?php farol_newsletter_render_notice(); ?>
		<p>Cada campanha escolhe para quais listas vai; quem está em mais de uma recebe uma vez só. Sair da newsletter tira a pessoa de todas.</p>

		<div class="farol-nl-lists-page">
			<div>
				<table class="widefat striped">
					<thead><tr><th>Lista</th><th>Identificador</th><th>Padrão</th><th>Ativos</th><th>Ações</th></tr></thead>
					<tbody>
					<?php if ( ! $lists ) : ?>
						<tr><td colspan="5">Nenhuma lista ainda.</td></tr>
					<?php endif; ?>
					<?php foreach ( $lists as $l ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $l->name ); ?></strong><?php echo $l->description ? '<br><small>' . esc_html( $l->description ) . '</small>' : ''; ?></td>
							<td><code><?php echo esc_html( $l->slug ); ?></code></td>
							<td><?php echo $l->is_default ? 'Sim' : '—'; ?></td>
							<td><a href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-subscribers', array( 'list' => $l->id, 'status' => 'active' ) ) ); ?>"><?php echo esc_html( number_format_i18n( (int) $l->active ) ); ?></a></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-lists', array( 'edit' => $l->id ) ) ); ?>">Editar</a>
								<?php farol_newsletter_action_button( 'list_delete', 'Apagar', 'delete', 'Apagar a lista? Os inscritos continuam cadastrados, só saem dela.', array( 'id' => $l->id ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="farol-nl-panel">
				<h2><?php echo $edit ? 'Editar lista' : 'Nova lista'; ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="farol_newsletter_list_save">
					<input type="hidden" name="id" value="<?php echo (int) ( $edit->id ?? 0 ); ?>">
					<?php wp_nonce_field( 'farol_newsletter_list_save' ); ?>
					<p><label>Nome<br><input type="text" name="name" class="widefat" required value="<?php echo esc_attr( $edit->name ?? '' ); ?>"></label></p>
					<p><label>Identificador<br><input type="text" name="slug" class="widefat" value="<?php echo esc_attr( $edit->slug ?? '' ); ?>" placeholder="gerado a partir do nome"></label>
						<span class="description">Usado pelo formulário do site para inscrever nesta lista (<code>lists: ["identificador"]</code>).</span></p>
					<p><label>Descrição<br><textarea name="description" class="widefat" rows="3"><?php echo esc_textarea( $edit->description ?? '' ); ?></textarea></label></p>
					<p><label><input type="checkbox" name="is_default" value="1" <?php checked( ! empty( $edit->is_default ) ); ?>> Padrão: quem se inscreve pelo site sem escolher lista entra nesta</label></p>
					<p>
						<?php submit_button( $edit ? 'Salvar lista' : 'Criar lista', 'primary', 'submit', false ); ?>
						<?php if ( $edit ) : ?>
							<a class="button" href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-lists' ) ); ?>">Cancelar</a>
						<?php endif; ?>
					</p>
				</form>
			</div>
		</div>
	</div>
	<?php
}

/* ---------------------------------------------------------------- *
 *  Templates
 * ---------------------------------------------------------------- */

function farol_newsletter_page_templates(): void {
	$id  = (int) ( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$new = sanitize_key( $_GET['new'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( $id || $new ) {
		farol_newsletter_page_template_edit( $id ? farol_newsletter_template_get( $id ) : null );
		return;
	}
	$templates = farol_newsletter_templates();
	$digest    = farol_newsletter_digest_template();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Templates</h1>
		<a href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-templates', array( 'new' => 'campaign' ) ) ); ?>" class="page-title-action">Novo template</a>
		<hr class="wp-header-end">
		<?php farol_newsletter_render_notice(); ?>
		<p>Pontos de partida para as campanhas, o resumo automático e o e-mail de confirmação. Os de fábrica podem ser editados e restaurados, mas não apagados.</p>

		<table class="widefat striped">
			<thead><tr><th>Template</th><th>Tipo</th><th>Assunto</th><th>Atualizado</th><th>Ações</th></tr></thead>
			<tbody>
			<?php foreach ( $templates as $t ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $t->name ); ?></strong>
						<?php if ( $t->slug ) : ?>
							<span class="farol-nl-badge">de fábrica</span>
						<?php endif; ?>
						<?php if ( $digest && (int) $digest->id === (int) $t->id ) : ?>
							<span class="farol-nl-badge is-active">resumo automático</span>
						<?php endif; ?>
					</td>
					<td><?php echo 'confirmation' === $t->kind ? 'Confirmação de inscrição' : 'Campanha'; ?></td>
					<td><code><?php echo esc_html( $t->subject ); ?></code></td>
					<td><?php echo esc_html( farol_newsletter_date( $t->updated_at ) ); ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-templates', array( 'id' => $t->id ) ) ); ?>">Editar</a>
						<a class="button button-small" href="<?php echo esc_url( farol_newsletter_preview_url( array( 'template' => $t->id ) ) ); ?>" target="_blank" rel="noopener">Prévia</a>
						<?php if ( 'campaign' === $t->kind ) : ?>
							<?php farol_newsletter_action_button( 'template_duplicate', 'Duplicar', 'secondary', '', array( 'id' => $t->id ) ); ?>
						<?php endif; ?>
						<?php if ( $t->slug ) : ?>
							<?php farol_newsletter_action_button( 'template_reset', 'Restaurar padrão', 'secondary', 'Voltar este template ao conteúdo de fábrica? As mudanças feitas nele se perdem.', array( 'id' => $t->id ) ); ?>
						<?php else : ?>
							<?php farol_newsletter_action_button( 'template_delete', 'Apagar', 'delete', 'Apagar este template?', array( 'id' => $t->id ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/** Editar (ou criar) um template, com o mesmo montador das campanhas. */
function farol_newsletter_page_template_edit( ?object $t ): void {
	if ( ! $t && isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wp_die( 'Template não encontrado.' );
	}
	$kind   = $t ? $t->kind : 'campaign';
	$blocks = $t ? farol_newsletter_template_blocks( $t ) : array( array( 'type' => 'text', 'html' => '' ), array( 'type' => 'latest', 'count' => 6, 'feature' => true ) );
	farol_newsletter_builder_assets( $blocks, $kind );
	?>
	<div class="wrap">
		<h1><?php echo $t ? 'Editar template: ' . esc_html( $t->name ) : 'Novo template'; ?></h1>
		<p><a href="<?php echo esc_url( farol_newsletter_admin_url( 'farol-newsletter-templates' ) ); ?>">← Templates</a></p>
		<?php farol_newsletter_render_notice(); ?>
		<?php if ( 'confirmation' === $kind ) : ?>
			<div class="notice notice-info inline"><p>Este é o e-mail que chega para quem se inscreve no site. Deixe um botão com o link <code>{{link_confirmacao}}</code>; o rodapé também traz o link, para o caso de o botão não abrir.</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="farol-nl-form">
			<input type="hidden" name="action" value="farol_newsletter_template_save">
			<input type="hidden" name="id" value="<?php echo (int) ( $t->id ?? 0 ); ?>">
			<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
			<?php wp_nonce_field( 'farol_newsletter_template_save' ); ?>

			<div class="farol-nl-compose">
				<div class="farol-nl-compose-form">
					<div class="farol-nl-panel">
						<p class="farol-nl-row">
							<label for="farol-nl-name">Nome</label>
							<input type="text" id="farol-nl-name" name="name" class="regular-text" required value="<?php echo esc_attr( $t->name ?? '' ); ?>">
						</p>
						<p class="farol-nl-row">
							<label for="farol-nl-subject">Assunto</label>
							<input type="text" id="farol-nl-subject" name="subject" class="large-text" value="<?php echo esc_attr( $t->subject ?? '{{site}}: {{manchete}}' ); ?>">
						</p>
						<p class="farol-nl-row">
							<label for="farol-nl-preheader">Pré-visualização</label>
							<input type="text" id="farol-nl-preheader" name="preheader" class="large-text" value="<?php echo esc_attr( $t->preheader ?? '' ); ?>" placeholder="Vazio: &quot;E mais N matérias&quot; ou o assunto">
						</p>
					</div>

					<h2>Conteúdo</h2>
					<?php farol_newsletter_builder( $blocks, $kind ); ?>

					<p class="submit">
						<?php submit_button( 'Salvar template', 'primary', 'do_save', false ); ?>
						<?php submit_button( 'Salvar e enviar teste para mim', 'secondary', 'do_test', false ); ?>
					</p>
				</div>
				<?php farol_newsletter_builder_preview(); ?>
			</div>
		</form>
	</div>
	<?php
}
