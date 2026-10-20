<?php
/**
 * IA via OpenRouter: configuração (aba "IA") e um cliente mínimo para os
 * recursos do app usarem — farol_ai_chat().
 *
 * @package OFarol
 */

defined( 'ABSPATH' ) || exit;

const FAROL_AI_ENDPOINT = 'https://openrouter.ai/api/v1';

/**
 * Modelos permitidos (id no OpenRouter => descrição). Só estes podem ser escolhidos.
 */
function farol_ai_models(): array {
	return array(
		'openai/gpt-6-luna'            => array(
			'label' => 'GPT-6 Luna',
			'note'  => 'OpenAI. Contexto de 1 milhão de tokens. US$ 0,10 por milhão de tokens de entrada e US$ 0,50 por milhão de saída.',
		),
		'deepseek/deepseek-v4.1-flash' => array(
			'label' => 'DeepSeek V4.1 Flash',
			'note'  => 'DeepSeek. Acompanha a versão mais recente da V4.1. Contexto de 1 milhão de tokens; o mais barato.',
		),
	);
}

function farol_ai_settings(): array {
	return wp_parse_args(
		(array) get_option( 'farol_ai_settings', array() ),
		array(
			'api_key' => '',
			'model'   => 'openai/gpt-6-luna',
		)
	);
}

/** Chave do .env (OPENROUTER_API_KEY) tem prioridade sobre a do painel. */
function farol_ai_key_from_env(): bool {
	return defined( 'FAROL_OPENROUTER_API_KEY' ) && '' !== FAROL_OPENROUTER_API_KEY;
}

function farol_ai_api_key(): string {
	return farol_ai_key_from_env() ? FAROL_OPENROUTER_API_KEY : (string) farol_ai_settings()['api_key'];
}

function farol_ai_model(): string {
	$model = farol_ai_settings()['model'];
	return isset( farol_ai_models()[ $model ] ) ? $model : (string) array_key_first( farol_ai_models() );
}

function farol_ai_enabled(): bool {
	return '' !== farol_ai_api_key();
}

/**
 * Conversa com o modelo configurado.
 *
 * @param array $messages Lista de array( 'role' => 'system'|'user'|'assistant', 'content' => '...' ).
 * @param array $options  temperature, max_tokens (inclui o raciocínio do modelo), json (bool: pede
 *                        resposta em JSON), reasoning ('off' desliga o raciocínio; 'low'|'medium'|'high'
 *                        ajustam o esforço), model (sobrescreve o configurado), timeout.
 * @return string|WP_Error Texto da resposta.
 */
function farol_ai_chat( array $messages, array $options = array() ) {
	if ( ! farol_ai_enabled() ) {
		return new WP_Error( 'farol_ai_disabled', 'IA não configurada: cadastre a chave do OpenRouter em Configurações → O Farol → IA.' );
	}

	$body = array(
		'model'       => isset( $options['model'], farol_ai_models()[ $options['model'] ] ) ? $options['model'] : farol_ai_model(),
		'messages'    => $messages,
		'temperature' => $options['temperature'] ?? 0.3,
	);
	if ( isset( $options['max_tokens'] ) ) {
		$body['max_tokens'] = (int) $options['max_tokens'];
	}
	if ( ! empty( $options['json'] ) ) {
		$body['response_format'] = array( 'type' => 'json_object' );
	}
	if ( ! empty( $options['reasoning'] ) ) {
		// Tarefas de extração/reescrita não ganham nada com raciocínio: só custo e latência.
		$body['reasoning'] = 'off' === $options['reasoning']
			? array( 'enabled' => false )
			: array( 'effort' => $options['reasoning'], 'exclude' => true );
	}

	$res = farol_ai_request( 'POST', '/chat/completions', $body, (int) ( $options['timeout'] ?? 60 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$content = $res['choices'][0]['message']['content'] ?? null;
	if ( is_string( $content ) && '' !== trim( $content ) ) {
		return trim( $content );
	}
	return 'length' === ( $res['choices'][0]['finish_reason'] ?? '' )
		? new WP_Error( 'farol_ai_length', 'A resposta do modelo foi cortada (limite de tokens). Tente de novo.' )
		: new WP_Error( 'farol_ai_empty', 'O modelo não retornou texto.' );
}

/** Chamada crua à API do OpenRouter. */
function farol_ai_request( string $method, string $path, ?array $body = null, int $timeout = 30 ) {
	$res = wp_remote_request(
		FAROL_AI_ENDPOINT . $path,
		array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . farol_ai_api_key(),
				'Content-Type'  => 'application/json',
				// Identificação do app no painel de uso do OpenRouter.
				'HTTP-Referer'  => function_exists( 'farol_frontend_url' ) && farol_frontend_url() ? farol_frontend_url() : home_url(),
				'X-Title'       => get_bloginfo( 'name' ),
			),
			'body'    => null === $body ? null : wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$code = wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code >= 400 || ! is_array( $data ) ) {
		$message = $data['error']['message'] ?? "HTTP {$code}";
		return new WP_Error( 'farol_ai_http', "OpenRouter: {$message}", array( 'status' => $code ) );
	}
	return $data;
}

/* ---------------------------------------------------------------------
 * Aba "IA" em Configurações → O Farol
 * --------------------------------------------------------------------- */

add_filter(
	'farol_settings_tabs',
	fn( $tabs ) => $tabs + array(
		'ia' => array( 'label' => 'IA', 'order' => 90, 'render' => 'farol_ai_render_settings' ),
	)
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'farol_ai',
			'farol_ai_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => function ( $in ) {
					$in      = (array) $in;
					$current = farol_ai_settings();
					$key     = trim( (string) ( $in['api_key'] ?? '' ) );

					// Campo vazio mantém a chave atual; "remover" apaga.
					if ( ! empty( $in['remove_key'] ) ) {
						$key = '';
					} elseif ( '' === $key ) {
						$key = $current['api_key'];
					} elseif ( ! preg_match( '/^sk-or-[A-Za-z0-9_-]{20,}$/', $key ) ) {
						add_settings_error( 'farol_ai_settings', 'farol_ai_key', 'A chave não parece do OpenRouter (começa com sk-or-). Ela não foi salva.' );
						$key = $current['api_key'];
					}

					$model = (string) ( $in['model'] ?? '' );
					return array(
						'api_key' => $key,
						'model'   => isset( farol_ai_models()[ $model ] ) ? $model : $current['model'],
					);
				},
			)
		);
	}
);

// Não carregar a chave em toda requisição.
add_action( 'add_option_farol_ai_settings', fn() => wp_set_option_autoload( 'farol_ai_settings', false ) );

/** "Testar conexão": valida a chave (sem custo) e faz uma chamada mínima ao modelo. */
add_action(
	'admin_post_farol_ai_test',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.', 403 );
		}
		check_admin_referer( 'farol_ai_test' );

		$key = farol_ai_request( 'GET', '/key' );
		if ( is_wp_error( $key ) ) {
			$result = array( 'error', 'Chave recusada. ' . $key->get_error_message() );
		} else {
			$started = microtime( true );
			$reply   = farol_ai_chat( array( array( 'role' => 'user', 'content' => 'Responda apenas: ok' ) ), array( 'max_tokens' => 5, 'timeout' => 30 ) );
			$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );
			$label   = farol_ai_models()[ farol_ai_model() ]['label'];
			$usage   = $key['data']['usage'] ?? null;
			$limit   = $key['data']['limit'] ?? null;
			$credit  = null === $usage ? '' : sprintf( ' Uso da chave: US$ %s%s.', number_format_i18n( (float) $usage, 4 ), null === $limit ? '' : ' de US$ ' . number_format_i18n( (float) $limit, 2 ) );

			$result = is_wp_error( $reply )
				? array( 'error', "Chave válida, mas o modelo {$label} falhou: " . $reply->get_error_message() )
				: array( 'success', "Conexão ok. {$label} respondeu em {$ms} ms.{$credit}" );
		}

		set_transient( 'farol_ai_test_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( farol_settings_url( 'ia' ) );
		exit;
	}
);

function farol_ai_render_settings(): void {
	$s      = farol_ai_settings();
	$env    = farol_ai_key_from_env();
	$key    = farol_ai_api_key();
	$masked = $key ? 'sk-or-…' . substr( $key, -4 ) : '';
	$test   = get_transient( 'farol_ai_test_' . get_current_user_id() );
	if ( $test ) {
		delete_transient( 'farol_ai_test_' . get_current_user_id() );
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $test[0] ), esc_html( $test[1] ) );
	}
	?>
	<p>Recursos de IA do app (como sugestões no editor) usam o <a href="https://openrouter.ai" target="_blank" rel="noopener">OpenRouter</a>. O custo é cobrado na sua conta de lá, por uso.</p>

	<form method="post" action="options.php">
		<?php settings_fields( 'farol_ai' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="farol-ai-key">Chave da API</label></th>
				<td>
					<?php if ( $env ) : ?>
						<p><code><?php echo esc_html( $masked ); ?></code> — definida no <code>.env</code> (<code>OPENROUTER_API_KEY</code>). Para trocar, edite o arquivo no servidor.</p>
					<?php else : ?>
						<input type="password" id="farol-ai-key" name="farol_ai_settings[api_key]" class="regular-text" autocomplete="off" spellcheck="false"
							placeholder="<?php echo esc_attr( $masked ? "Salva: {$masked} — deixe vazio para manter" : 'sk-or-v1-…' ); ?>">
						<?php if ( $masked ) : ?>
							<p><label><input type="checkbox" name="farol_ai_settings[remove_key]" value="1"> Remover a chave salva</label></p>
						<?php endif; ?>
						<p class="description">Crie em <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a>. Dica: defina um limite de gasto na própria chave. Em produção, prefira a variável <code>OPENROUTER_API_KEY</code> no <code>.env</code>.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Modelo</th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">Modelo</legend>
						<?php foreach ( farol_ai_models() as $id => $model ) : ?>
							<p>
								<label>
									<input type="radio" name="farol_ai_settings[model]" value="<?php echo esc_attr( $id ); ?>" <?php checked( farol_ai_model(), $id ); ?>>
									<strong><?php echo esc_html( $model['label'] ); ?></strong> <code><?php echo esc_html( $id ); ?></code>
								</label>
								<br><span class="description" style="margin-left:24px"><?php echo esc_html( $model['note'] ); ?></span>
							</p>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<hr>
	<h2 class="title">Status</h2>
	<p>
		<?php echo $key ? '✅ Chave configurada.' : '⚪ Nenhuma chave configurada — os recursos de IA ficam desligados.'; ?>
		Modelo em uso: <strong><?php echo esc_html( farol_ai_models()[ farol_ai_model() ]['label'] ); ?></strong>.
	</p>
	<?php if ( $key ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="farol_ai_test">
			<?php wp_nonce_field( 'farol_ai_test' ); ?>
			<?php submit_button( 'Testar conexão', 'secondary', 'submit', false ); ?>
			<span class="description">Valida a chave e faz uma chamada mínima ao modelo (custo desprezível).</span>
		</form>
	<?php endif; ?>
	<?php
}
