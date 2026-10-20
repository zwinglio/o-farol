<?php
/**
 * Envio automático ao R2 e troca de URLs.
 *
 * Cada anexo enviado ganha o meta _farol_r2 = { files: [caminhos relativos já
 * no R2], remote_only?: bool }. A chave no bucket é o mesmo caminho relativo
 * de wp-content/uploads (ex.: 2026/10/foto-1024x576.jpg), então qualquer URL
 * derivada (tamanhos, srcset) só troca a base.
 *
 * @package FarolMidia
 */

defined( 'ABSPATH' ) || exit;

/** Arquivos nunca mudam de conteúdo com o mesmo nome no WordPress: cache "para sempre". */
const FAROL_MIDIA_CACHE = 'public, max-age=31536000, immutable';

function farol_midia_enabled(): bool {
	return defined( 'FAROL_R2_BUCKET' ) && FAROL_R2_BUCKET && FAROL_R2_ACCESS_KEY_ID && FAROL_R2_SECRET_ACCESS_KEY && FAROL_R2_PUBLIC_URL
		&& ( FAROL_R2_ACCOUNT_ID || FAROL_R2_ENDPOINT );
}

function farol_midia_client(): ?Farol_R2_Client {
	static $client = null;
	if ( null === $client && farol_midia_enabled() ) {
		$endpoint = FAROL_R2_ENDPOINT ?: 'https://' . FAROL_R2_ACCOUNT_ID . '.r2.cloudflarestorage.com';
		$parts    = wp_parse_url( $endpoint );
		$host     = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$client   = new Farol_R2_Client( $host, FAROL_R2_BUCKET, FAROL_R2_ACCESS_KEY_ID, FAROL_R2_SECRET_ACCESS_KEY, 'auto', true, $parts['scheme'] ?? 'https' );
	}
	return $client;
}

/** URL pública de um caminho relativo. */
function farol_midia_url( string $rel ): string {
	return FAROL_R2_PUBLIC_URL . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $rel, '/' ) ) ) );
}

function farol_midia_state( int $id ): array {
	$state = get_post_meta( $id, '_farol_r2', true );
	return is_array( $state ) ? $state + array( 'files' => array() ) : array( 'files' => array() );
}

function farol_midia_is_offloaded( int $id ): bool {
	$file = (string) get_post_meta( $id, '_wp_attached_file', true );
	return '' !== $file && in_array( $file, farol_midia_state( $id )['files'], true );
}

/** Caminhos relativos de todos os arquivos de um anexo (original, tamanhos, original sem "-scaled"). */
function farol_midia_files( int $id, ?array $meta = null ): array {
	$file = (string) get_post_meta( $id, '_wp_attached_file', true );
	if ( '' === $file ) {
		return array();
	}
	$meta  = $meta ?? (array) wp_get_attachment_metadata( $id );
	$dir   = '.' === dirname( $file ) ? '' : dirname( $file ) . '/';
	$files = array( $file );
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$files[] = $dir . $size['file'];
		}
	}
	if ( ! empty( $meta['original_image'] ) ) {
		$files[] = $dir . $meta['original_image'];
	}
	return array_values( array_unique( $files ) );
}

/**
 * Envia ao R2 os arquivos do anexo que ainda não estão lá.
 *
 * @return true|WP_Error
 */
function farol_midia_upload( int $id, ?array $meta = null ) {
	$client = farol_midia_client();
	if ( ! $client ) {
		return new WP_Error( 'farol_midia_off', 'R2 não configurado.' );
	}

	$base  = wp_get_upload_dir()['basedir'];
	$state = farol_midia_state( $id );
	$sent  = array();

	foreach ( farol_midia_files( $id, $meta ) as $rel ) {
		if ( in_array( $rel, $state['files'], true ) ) {
			continue;
		}
		$path = "{$base}/{$rel}";
		if ( ! is_file( $path ) ) {
			continue; // tamanho ainda não gerado (o WP cria os tamanhos aos poucos)
		}
		$type = wp_check_filetype( $path )['type'] ?: 'application/octet-stream';
		$res  = $client->put_file( $rel, $path, $type, FAROL_MIDIA_CACHE );
		if ( is_wp_error( $res ) ) {
			update_post_meta( $id, '_farol_r2', $state );
			update_post_meta( $id, '_farol_r2_error', $res->get_error_message() );
			return $res;
		}
		$state['files'][] = $rel;
		$sent[]           = $path;
	}

	$state['at'] = time();
	update_post_meta( $id, '_farol_r2', $state );
	delete_post_meta( $id, '_farol_r2_error' );

	if ( ! FAROL_R2_KEEP_LOCAL ) {
		array_map( 'wp_delete_file', $sent );
	}
	return true;
}

/*
 * Envio automático. O WordPress salva os metadados várias vezes enquanto gera
 * os tamanhos; cada chamada envia o que ficou pronto (idempotente).
 */
add_filter(
	'wp_update_attachment_metadata',
	function ( $meta, $id ) {
		if ( farol_midia_enabled() && is_array( $meta ) && empty( farol_midia_state( (int) $id )['remote_only'] ) ) {
			$res = farol_midia_upload( (int) $id, $meta );
			if ( is_wp_error( $res ) ) {
				error_log( "farol-midia: anexo {$id} não foi para o R2: " . $res->get_error_message() );
			}
		}
		return $meta;
	},
	20,
	2
);

/* URLs: anexo inteiro no R2 → endereço público do bucket. */
add_filter(
	'wp_get_attachment_url',
	function ( $url, $id ) {
		if ( farol_midia_enabled() && farol_midia_is_offloaded( (int) $id ) ) {
			return farol_midia_url( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		}
		return $url;
	},
	20,
	2
);

add_filter(
	'wp_calculate_image_srcset',
	function ( $sources, $size_array, $image_src, $image_meta, $id ) {
		if ( ! is_array( $sources ) || ! farol_midia_enabled() || ! farol_midia_is_offloaded( (int) $id ) ) {
			return $sources;
		}
		$local = wp_get_upload_dir()['baseurl'] . '/';
		foreach ( $sources as &$source ) {
			if ( str_starts_with( $source['url'], $local ) ) {
				$source['url'] = farol_midia_url( substr( $source['url'], strlen( $local ) ) );
			}
		}
		return $sources;
	},
	20,
	5
);

/* Sem a cópia local, o editor de imagens do WordPress lê o arquivo do R2. */
add_filter(
	'load_image_to_edit_path',
	function ( $path, $id ) {
		if ( is_string( $path ) && ! is_file( $path ) && farol_midia_enabled() && farol_midia_is_offloaded( (int) $id ) ) {
			return wp_get_attachment_url( $id );
		}
		return $path;
	},
	20,
	2
);

/* Excluir da biblioteca também apaga do R2. */
add_action(
	'delete_attachment',
	function ( $id ) {
		$client = farol_midia_client();
		if ( ! $client ) {
			return;
		}
		foreach ( farol_midia_state( (int) $id )['files'] as $rel ) {
			$res = $client->delete( $rel );
			if ( is_wp_error( $res ) ) {
				error_log( "farol-midia: não apagou {$rel} do R2: " . $res->get_error_message() );
			}
		}
	}
);

/**
 * Apaga as cópias locais de um anexo que já está inteiro no R2.
 *
 * @return int Quantidade de arquivos apagados.
 */
function farol_midia_prune_local( int $id ): int {
	$base  = wp_get_upload_dir()['basedir'];
	$state = farol_midia_state( $id );
	$count = 0;
	foreach ( farol_midia_files( $id ) as $rel ) {
		if ( in_array( $rel, $state['files'], true ) && is_file( "{$base}/{$rel}" ) ) {
			wp_delete_file( "{$base}/{$rel}" );
			++$count;
		}
	}
	return $count;
}
