<?php
/**
 * Editor (painel lateral) e lista de posts (colunas de score).
 *
 * @package FarolSEO
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'enqueue_block_editor_assets',
	function () {
		if ( 'post' !== get_current_screen()?->post_type ) {
			return;
		}
		$ver = FAROL_SEO_VERSION . '-' . filemtime( FAROL_SEO_DIR . '/assets/editor.js' );
		wp_enqueue_script(
			'farol-seo-editor',
			FAROL_SEO_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-block-editor', 'wp-compose' ),
			$ver,
			true
		);
		wp_enqueue_style( 'farol-seo-editor', FAROL_SEO_URL . 'assets/editor.css', array(), $ver );

		$ai = function_exists( 'farol_ai_enabled' ) && farol_ai_enabled();
		wp_add_inline_script(
			'farol-seo-editor',
			'window.farolSeoConfig = ' . wp_json_encode(
				array(
					'ai' => array(
						'enabled'     => $ai,
						'model'       => $ai ? ( farol_ai_models()[ farol_ai_model() ]['label'] ?? '' ) : '',
						'settingsUrl' => function_exists( 'farol_settings_url' ) ? farol_settings_url( 'ia' ) : '',
					),
				)
			) . ';',
			'before'
		);
	}
);

/** Classe de cor do score: bom ≥ 80, ok ≥ 50, fraco < 50. */
function farol_seo_grade( int $score ): string {
	return $score >= 80 ? 'good' : ( $score >= 50 ? 'ok' : 'bad' );
}

add_filter(
	'manage_post_posts_columns',
	function ( array $cols ) {
		$out = array();
		foreach ( $cols as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['farol_seo'] = 'SEO';
				$out['farol_geo'] = 'GEO';
			}
		}
		return $out;
	}
);

add_action(
	'manage_post_posts_custom_column',
	function ( string $col, int $post_id ) {
		if ( ! in_array( $col, array( 'farol_seo', 'farol_geo' ), true ) ) {
			return;
		}
		$score = get_post_meta( $post_id, 'farol_seo' === $col ? '_farol_seo_score' : '_farol_geo_score', true );
		if ( '' === $score ) {
			echo '<span class="farol-score-badge">—</span>';
			return;
		}
		printf( '<span class="farol-score-badge is-%s">%d</span>', esc_attr( farol_seo_grade( (int) $score ) ), (int) $score );
	},
	10,
	2
);

add_filter(
	'manage_edit-post_sortable_columns',
	fn( $cols ) => $cols + array( 'farol_seo' => 'farol_seo', 'farol_geo' => 'farol_geo' )
);

add_action(
	'pre_get_posts',
	function ( WP_Query $q ) {
		if ( ! is_admin() || ! $q->is_main_query() ) {
			return;
		}
		$by = $q->get( 'orderby' );
		if ( in_array( $by, array( 'farol_seo', 'farol_geo' ), true ) ) {
			$q->set( 'meta_key', 'farol_seo' === $by ? '_farol_seo_score' : '_farol_geo_score' );
			$q->set( 'orderby', 'meta_value_num' );
		}
	}
);

add_action(
	'admin_head-edit.php',
	function () {
		echo '<style>
			.column-farol_seo, .column-farol_geo { width: 4.5em; text-align: center; }
			.farol-score-badge { display: inline-block; min-width: 2.4em; padding: 2px 6px; border-radius: 999px; font-weight: 600; text-align: center; background: #f0f0f1; color: #50575e; }
			.farol-score-badge.is-good { background: #d7f2df; color: #0a5c2a; }
			.farol-score-badge.is-ok { background: #fcf0d4; color: #7a4c00; }
			.farol-score-badge.is-bad { background: #fbe2e2; color: #8a1c1c; }
		</style>';
	}
);

add_filter(
	'plugin_action_links_farol-seo/farol-seo.php',
	fn( $links ) => array_merge( array( '<a href="' . esc_url( admin_url( 'options-general.php?page=farol&tab=seo' ) ) . '">Configurações</a>' ), $links )
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Recalcula os scores de SEO/GEO de todos os posts publicados.
	 *
	 * ## EXAMPLES
	 *
	 *     wp farol-seo rescore
	 */
	WP_CLI::add_command(
		'farol-seo rescore',
		function () {
			$ids = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft', 'future', 'pending' ), 'numberposts' => -1, 'fields' => 'ids' ) );
			foreach ( $ids as $id ) {
				$result = ( new Farol_SEO_Analyzer( farol_seo_input( get_post( $id ) ) ) )->run();
				update_post_meta( $id, '_farol_seo_score', $result['seo']['score'] );
				update_post_meta( $id, '_farol_geo_score', $result['geo']['score'] );
			}
			WP_CLI::success( count( $ids ) . ' post(s) recalculado(s).' );
		}
	);
}
