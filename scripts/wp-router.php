<?php
/**
 * Router do servidor embutido do PHP (`make cms`), só para desenvolvimento.
 * Imita o .htaccess: bloqueia arquivos sensíveis, serve estáticos e manda
 * o resto para o index.php (links permanentes e /wp-json).
 */

$root = $_SERVER['DOCUMENT_ROOT'];
$path = urldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

if ( preg_match( '#/\.(?!well-known)|\.log$|wp-config\.php$#', $path ) ) {
	http_response_code( 404 );
	return true;
}

$file = realpath( $root . $path );
if ( $file && str_starts_with( $file, realpath( $root ) ) ) {
	if ( is_file( $file ) ) {
		if ( str_ends_with( $file, '.php' ) ) {
			chdir( dirname( $file ) );
			$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $path;
			require $file;
			return true;
		}
		return false; // estático: o próprio servidor entrega
	}
	if ( is_dir( $file ) && is_file( $file . '/index.php' ) ) {
		// Como o mod_dir do Apache: /wp-admin → /wp-admin/. Sem isso, os links
		// relativos do painel (edit.php…) resolvem para a raiz do site.
		if ( ! str_ends_with( $path, '/' ) ) {
			$query = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY );
			header( 'Location: ' . $path . '/' . ( $query ? "?$query" : '' ), true, 301 );
			return true;
		}
		chdir( $file );
		$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = rtrim( $path, '/' ) . '/index.php';
		require $file . '/index.php';
		return true;
	}
}

$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require $root . '/index.php';
