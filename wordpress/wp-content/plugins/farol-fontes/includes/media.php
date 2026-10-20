<?php
/**
 * Cópia local das imagens das fontes (miniaturas, avatares, favicons).
 *
 * @package FarolFontes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Baixa uma imagem remota para a biblioteca de mídia, uma única vez por URL.
 * Aceita URLs sem extensão (CDNs do Bluesky, Mastodon etc.), detectando o tipo real.
 *
 * @return int ID do anexo, ou 0 se falhar.
 */
function farol_fontes_sideload( string $url, int $post_id, string $label ): int {
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_farol_fonte_src', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $url, 15 );
	if ( is_wp_error( $tmp ) ) {
		return 0;
	}
	if ( filesize( $tmp ) > 10 * MB_IN_BYTES ) {
		wp_delete_file( $tmp );
		return 0;
	}

	$mime = wp_get_image_mime( $tmp );
	$ext  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico' )[ $mime ] ?? null;
	if ( ! $ext ) {
		wp_delete_file( $tmp );
		return 0;
	}

	$name = sanitize_title( mb_substr( $label ?: 'fonte', 0, 50 ) ) . '-' . substr( md5( $url ), 0, 8 ) . '.' . $ext;

	// O PHP identifica .ico como image/vnd.microsoft.icon e o WordPress só aceita
	// image/x-icon: libera esse caso apenas durante este upload.
	$allow_ico = fn( $data, $file, $filename ) => 'ico' === $ext && empty( $data['type'] )
		? array( 'ext' => 'ico', 'type' => 'image/x-icon', 'proper_filename' => false )
		: $data;
	add_filter( 'wp_check_filetype_and_ext', $allow_ico, 10, 3 );
	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id, $label ?: null );
	remove_filter( 'wp_check_filetype_and_ext', $allow_ico, 10 );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
		return 0;
	}

	update_post_meta( $id, '_farol_fonte_src', esc_url_raw( $url ) );
	return (int) $id;
}
