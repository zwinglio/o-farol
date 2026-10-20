<?php
/**
 * Configurações gerais: aba "SEO e GEO" em Configurações → O Farol.
 *
 * @package FarolSEO
 */

defined( 'ABSPATH' ) || exit;

/** Robôs de buscadores com IA (respondem perguntas citando a fonte). */
const FAROL_SEO_AI_SEARCH_BOTS = array( 'OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'DuckAssistBot', 'MistralAI-User' );

/** Robôs que coletam texto para treinar modelos. */
const FAROL_SEO_AI_TRAINING_BOTS = array( 'GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Meta-ExternalAgent', 'Bytespider', 'Amazonbot', 'cohere-training-data-crawler' );

function farol_seo_defaults(): array {
	return array(
		'description'           => get_bloginfo( 'description' ),
		'default_image'         => '',
		'logo'                  => '',
		'x_handle'              => '',
		'same_as'               => '',
		'contact_email'         => '',
		'founding_date'         => '',
		'publishing_principles' => '/sobre',
		'google_verification'   => '',
		'bing_verification'     => '',
		'news_publication'      => get_bloginfo( 'name' ),
		'ai_search'             => true,
		'ai_training'           => true,
		'llms_intro'            => '',
	);
}

function farol_seo_settings(): array {
	return wp_parse_args( (array) get_option( 'farol_seo_settings', array() ), farol_seo_defaults() );
}

add_action(
	'admin_init',
	function () {
		register_setting(
			'farol_seo',
			'farol_seo_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => function ( $in ) {
					$in = (array) $in;
					return array(
						'description'           => sanitize_textarea_field( $in['description'] ?? '' ),
						'default_image'         => esc_url_raw( $in['default_image'] ?? '' ),
						'logo'                  => esc_url_raw( $in['logo'] ?? '' ),
						'x_handle'              => ltrim( sanitize_text_field( $in['x_handle'] ?? '' ), '@' ),
						'same_as'               => implode( "\n", array_filter( array_map( 'esc_url_raw', preg_split( '/\R/', (string) ( $in['same_as'] ?? '' ) ) ) ) ),
						'contact_email'         => sanitize_email( $in['contact_email'] ?? '' ),
						'founding_date'         => preg_match( '/^\d{4}(-\d{2}-\d{2})?$/', $in['founding_date'] ?? '' ) ? $in['founding_date'] : '',
						'publishing_principles' => sanitize_text_field( $in['publishing_principles'] ?? '' ),
						'google_verification'   => sanitize_text_field( $in['google_verification'] ?? '' ),
						'bing_verification'     => sanitize_text_field( $in['bing_verification'] ?? '' ),
						'news_publication'      => sanitize_text_field( $in['news_publication'] ?? '' ),
						'ai_search'             => ! empty( $in['ai_search'] ),
						'ai_training'           => ! empty( $in['ai_training'] ),
						'llms_intro'            => sanitize_textarea_field( $in['llms_intro'] ?? '' ),
					);
				},
			)
		);
	}
);

add_filter(
	'farol_settings_tabs',
	fn( $tabs ) => $tabs + array(
		'seo' => array( 'label' => 'SEO e GEO', 'order' => 10, 'render' => 'farol_seo_render_settings' ),
	)
);

function farol_seo_render_settings(): void {
	$s     = farol_seo_settings();
	$name  = 'farol_seo_settings';
	$field = function ( string $key, string $label, string $help = '', string $type = 'text' ) use ( $s, $name ) {
		printf( '<tr><th scope="row"><label for="fs-%1$s">%2$s</label></th><td>', esc_attr( $key ), esc_html( $label ) );
		if ( 'textarea' === $type ) {
			printf( '<textarea id="fs-%1$s" name="%2$s[%1$s]" rows="4" class="large-text">%3$s</textarea>', esc_attr( $key ), esc_attr( $name ), esc_textarea( $s[ $key ] ) );
		} elseif ( 'checkbox' === $type ) {
			printf( '<label><input type="checkbox" id="fs-%1$s" name="%2$s[%1$s]" value="1" %3$s> Permitir</label>', esc_attr( $key ), esc_attr( $name ), checked( $s[ $key ], true, false ) );
		} else {
			printf( '<input type="%4$s" id="fs-%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text">', esc_attr( $key ), esc_attr( $name ), esc_attr( $s[ $key ] ), esc_attr( $type ) );
		}
		if ( $help ) {
			printf( '<p class="description">%s</p>', wp_kses( $help, array( 'code' => array(), 'strong' => array() ) ) );
		}
		echo '</td></tr>';
	};
	?>
	<p>Valores gerais do site. Cada notícia tem seus próprios ajustes no painel <strong>SEO e GEO</strong> do editor — preenchidos automaticamente a partir do texto.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'farol_seo' ); ?>

			<h2 class="title">Identidade</h2>
			<table class="form-table" role="presentation">
				<?php
				$field( 'description', 'Descrição do site', 'Usada na capa e quando uma página não tem descrição própria. Ideal: 120 a 160 caracteres.', 'textarea' );
				$field( 'logo', 'Logo (URL)', 'Imagem quadrada ou horizontal, PNG/JPG, mínimo 112×112 px. Aparece nos resultados de notícia do Google.', 'url' );
				$field( 'default_image', 'Imagem social padrão (URL)', 'Usada ao compartilhar páginas sem imagem destacada. 1200×630 px.', 'url' );
				$field( 'founding_date', 'Fundação', 'Ano (<code>2026</code>) ou data (<code>2026-10-19</code>).' );
				$field( 'publishing_principles', 'Linha editorial (caminho)', 'Página que explica princípios e linha editorial. Sinal de confiança para Google e IAs. Ex.: <code>/sobre</code>.' );
				$field( 'contact_email', 'E-mail da redação', '', 'email' );
				?>
			</table>

			<h2 class="title">Redes</h2>
			<table class="form-table" role="presentation">
				<?php
				$field( 'x_handle', 'Usuário no X', 'Sem @.' );
				$field( 'same_as', 'Perfis oficiais', 'Uma URL por linha (Instagram, YouTube, X, Bluesky…). Ajuda buscadores e IAs a reconhecer o veículo como entidade.', 'textarea' );
				?>
			</table>

			<h2 class="title">Buscadores</h2>
			<table class="form-table" role="presentation">
				<?php
				$field( 'news_publication', 'Nome no Google Notícias', 'Usado no sitemap de notícias.' );
				$field( 'google_verification', 'Google Search Console', 'Só o código do <code>content</code> da meta tag de verificação.' );
				$field( 'bing_verification', 'Bing Webmaster', 'Só o código do <code>content</code> da meta tag de verificação.' );
				?>
			</table>

			<h2 class="title">Inteligência artificial (GEO)</h2>
			<table class="form-table" role="presentation">
				<?php
				$field( 'ai_search', 'Buscadores com IA', 'ChatGPT Search, Perplexity, Claude e afins buscam e <strong>citam com link</strong>. Recomendado permitir. Robôs: <code>' . implode( '</code>, <code>', FAROL_SEO_AI_SEARCH_BOTS ) . '</code>.', 'checkbox' );
				$field( 'ai_training', 'Treinamento de modelos', 'Coleta de texto para treinar IAs, sem link de volta. Decisão editorial. Robôs: <code>' . implode( '</code>, <code>', FAROL_SEO_AI_TRAINING_BOTS ) . '</code>.', 'checkbox' );
				$field( 'llms_intro', 'Apresentação para IAs', 'Texto extra no topo do <code>/llms.txt</code>: quem somos, linha editorial, como citar.', 'textarea' );
				?>
			</table>

			<?php submit_button(); ?>
		</form>
	<?php
}
