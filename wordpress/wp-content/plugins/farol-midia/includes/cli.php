<?php
/**
 * wp farol-midia status | test | sync | rewrite-content | prune-local
 *
 * @package FarolMidia
 */

defined( 'ABSPATH' ) || exit;

class Farol_Midia_CLI {

	/** Mostra a configuração e quantos anexos já estão no R2. */
	public function status() {
		$c = farol_midia_counts();
		WP_CLI::log( 'R2: ' . ( farol_midia_enabled() ? 'ativo · bucket ' . FAROL_R2_BUCKET . ' · ' . FAROL_R2_PUBLIC_URL : 'desligado' ) );
		WP_CLI::log( "Anexos no R2: {$c['offloaded']} de {$c['total']}" . ( $c['errors'] ? " · {$c['errors']} com erro" : '' ) );
		WP_CLI::log( 'Cópia local: ' . ( FAROL_R2_KEEP_LOCAL ? 'mantida' : 'não mantida' ) );
	}

	/** Grava, lê pelo domínio público, confere CORS e apaga um arquivo de teste. */
	public function test() {
		$fail = false;
		foreach ( farol_midia_selftest() as [ $ok, $msg ] ) {
			WP_CLI::log( ( $ok ? '✓ ' : '✗ ' ) . $msg );
			$fail = $fail || ! $ok;
		}
		$fail ? WP_CLI::error( 'Teste falhou.' ) : WP_CLI::success( 'R2 funcionando.' );
	}

	/**
	 * Envia ao R2 os anexos (e tamanhos) que ainda estão só no servidor.
	 *
	 * ## OPTIONS
	 * [--dry-run]
	 * : Só lista o que seria enviado.
	 */
	public function sync( $args, $assoc ) {
		if ( ! farol_midia_enabled() ) {
			WP_CLI::error( 'R2 não configurado no .env.' );
		}
		$ids  = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
		$done = 0;
		$fail = 0;
		foreach ( $ids as $id ) {
			$state = farol_midia_state( $id );
			if ( ! empty( $state['remote_only'] ) ) {
				continue;
			}
			$missing = array_diff( farol_midia_files( $id ), $state['files'] );
			if ( ! $missing ) {
				continue;
			}
			if ( isset( $assoc['dry-run'] ) ) {
				WP_CLI::log( "#{$id}: " . count( $missing ) . ' arquivo(s)' );
				continue;
			}
			$res = farol_midia_upload( $id );
			if ( is_wp_error( $res ) ) {
				++$fail;
				WP_CLI::warning( "#{$id}: " . $res->get_error_message() );
			} else {
				++$done;
				WP_CLI::log( "✓ #{$id} " . get_the_title( $id ) );
			}
		}
		WP_CLI::success( "{$done} anexo(s) enviados" . ( $fail ? ", {$fail} com erro (rode de novo)" : '' ) . '.' );
	}

	/**
	 * Troca, no texto dos posts, URLs locais de arquivos que já estão no R2.
	 * (Uploads novos já entram com o endereço do R2; isto é para conteúdo antigo.)
	 *
	 * ## OPTIONS
	 * [--dry-run]
	 * : Só conta quantos posts mudariam.
	 *
	 * @subcommand rewrite-content
	 */
	public function rewrite_content( $args, $assoc ) {
		global $wpdb;
		if ( ! farol_midia_enabled() ) {
			WP_CLI::error( 'R2 não configurado no .env.' );
		}
		$base  = wp_get_upload_dir()['baseurl'] . '/';
		$ids   = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_farol_r2'" );
		$pairs = array();
		foreach ( $ids as $id ) {
			foreach ( farol_midia_state( (int) $id )['files'] as $rel ) {
				$from           = $base . $rel;
				$to             = farol_midia_url( $rel );
				$pairs[ $from ] = $to;
				// Atributos de blocos guardam URLs em JSON, com "/" escapado.
				$pairs[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
			}
		}

		$posts = array();
		foreach ( $pairs as $from => $to ) {
			$like  = '%' . $wpdb->esc_like( $from ) . '%';
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", $like ) );
			if ( ! $found ) {
				continue;
			}
			$posts = array_merge( $posts, $found );
			if ( ! isset( $assoc['dry-run'] ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", $from, $to, $like ) );
			}
		}
		$posts = array_unique( $posts );

		if ( ! isset( $assoc['dry-run'] ) ) {
			foreach ( $posts as $id ) {
				clean_post_cache( (int) $id );
				delete_post_meta( (int) $id, '_farol_seo_cache' );
			}
		}
		WP_CLI::success( count( $posts ) . ' post(s) ' . ( isset( $assoc['dry-run'] ) ? 'seriam atualizados.' : 'atualizados.' ) );
	}

	/**
	 * Apaga do servidor as cópias de arquivos que já estão no R2.
	 *
	 * ## OPTIONS
	 * [--yes]
	 * : Confirma sem perguntar.
	 *
	 * @subcommand prune-local
	 */
	public function prune_local( $args, $assoc ) {
		global $wpdb;
		WP_CLI::confirm( 'Apagar do servidor as cópias locais de tudo que já está no R2?', $assoc );
		$total = 0;
		foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_farol_r2'" ) as $id ) {
			$total += farol_midia_prune_local( (int) $id );
		}
		WP_CLI::success( "{$total} arquivo(s) local(is) apagados." );
		if ( FAROL_R2_KEEP_LOCAL ) {
			WP_CLI::warning( 'R2_KEEP_LOCAL ainda está true: uploads novos continuarão gerando cópia local.' );
		}
	}
}

WP_CLI::add_command( 'farol-midia', 'Farol_Midia_CLI' );
