<?php
/**
 * Preenche as chaves de segurança vazias de um .env do WordPress.
 * Uso: php scripts/salts.php wordpress/.env
 */

$file = $argv[1] ?? '';
if ( ! is_file( $file ) ) {
	fwrite( STDERR, "Arquivo não encontrado: $file\n" );
	exit( 1 );
}

$keys  = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
$env   = file_get_contents( $file );
$count = 0;

foreach ( $keys as $key ) {
	$salt = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
	$env  = preg_replace( "/^$key=[ \t]*$/m", "$key=\"$salt\"", $env, 1, $n );
	$count += $n;
}

file_put_contents( $file, $env );
echo "$count chave(s) gerada(s) em $file\n";
