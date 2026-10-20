<?php
/**
 * Configuração do WordPress d'O Farol.
 *
 * Nada sensível fica aqui: tudo vem do arquivo .env (ou de variáveis de
 * ambiente reais do servidor, que têm prioridade). O .env é procurado
 * primeiro um nível acima desta pasta — fora da raiz pública, o lugar
 * recomendado em produção — e depois nesta mesma pasta.
 *
 * @package OFarol
 */

/**
 * Carrega pares CHAVE=valor de um arquivo .env sem sobrescrever variáveis
 * que já existam no ambiente.
 */
function farol_load_env( string $file ): void {
	if ( ! is_readable( $file ) ) {
		return;
	}

	foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] || ! str_contains( $line, '=' ) ) {
			continue;
		}

		[ $key, $value ] = array_map( 'trim', explode( '=', $line, 2 ) );
		$key             = preg_replace( '/^export\s+/', '', $key );

		if ( preg_match( '/^([\'"])(.*)\1$/s', $value, $m ) ) {
			$value = $m[2];
		} else {
			$value = trim( preg_replace( '/\s+#.*$/', '', $value ) );
		}

		if ( false === getenv( $key ) ) {
			putenv( "$key=$value" );
			$_ENV[ $key ] = $value;
		}
	}
}

/** Lê uma variável de ambiente, com valor padrão. */
function farol_env( string $key, $fallback = null ) {
	$value = getenv( $key );
	if ( false === $value || '' === $value ) {
		return $fallback;
	}

	return match ( strtolower( $value ) ) {
		'true'  => true,
		'false' => false,
		default => $value,
	};
}

foreach ( array( dirname( __DIR__ ) . '/.env', __DIR__ . '/.env' ) as $farol_env_file ) {
	if ( is_readable( $farol_env_file ) ) {
		farol_load_env( $farol_env_file );
		break;
	}
}

// ** Banco de dados ** //
define( 'DB_NAME', farol_env( 'DB_NAME', 'ofarol' ) );
define( 'DB_USER', farol_env( 'DB_USER', 'ofarol' ) );
define( 'DB_PASSWORD', farol_env( 'DB_PASSWORD', '' ) );
define( 'DB_HOST', farol_env( 'DB_HOST', 'localhost' ) );
define( 'DB_CHARSET', farol_env( 'DB_CHARSET', 'utf8mb4' ) );
define( 'DB_COLLATE', farol_env( 'DB_COLLATE', '' ) );

$table_prefix = farol_env( 'DB_PREFIX', 'wp_' );

// ** Chaves de autenticação — gere em https://api.wordpress.org/secret-key/1.1/salt/ ** //
$farol_production = 'production' === farol_env( 'WP_ENVIRONMENT_TYPE', 'production' );
$farol_missing    = array();
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $farol_salt ) {
	$farol_value = (string) farol_env( $farol_salt, '' );
	if ( strlen( $farol_value ) < 32 ) {
		$farol_missing[] = $farol_salt;
	}
	// Fora de produção, uma chave aleatória por processo evita valores previsíveis.
	define( $farol_salt, $farol_value ?: bin2hex( random_bytes( 32 ) ) );
}
if ( '' === DB_PASSWORD ) {
	$farol_missing[] = 'DB_PASSWORD';
}

// Em produção, não sobe com chaves fracas ou ausentes: sessões seriam forjáveis.
if ( $farol_production && $farol_missing ) {
	error_log( 'O Farol: configuração insegura no .env — faltando ou curto demais: ' . implode( ', ', $farol_missing ) );
	http_response_code( 503 );
	exit( 'Configuração incompleta do servidor.' );
}

// ** URLs ** //
if ( farol_env( 'WP_HOME' ) ) {
	define( 'WP_HOME', rtrim( farol_env( 'WP_HOME' ), '/' ) );
	define( 'WP_SITEURL', rtrim( farol_env( 'WP_SITEURL', farol_env( 'WP_HOME' ) ), '/' ) );
}

// Atrás de proxy reverso que termina o TLS (Cloudflare, Traefik…). Só ative com
// FAROL_TRUST_PROXY=true: senão qualquer cliente poderia mandar o cabeçalho.
if ( farol_env( 'FAROL_TRUST_PROXY', false ) === true && 'https' === ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) {
	$_SERVER['HTTPS'] = 'on';
}

// ** O Farol (lidos pelo mu-plugin farol-core) ** //
define( 'FAROL_FRONTEND_URL', rtrim( (string) farol_env( 'FAROL_FRONTEND_URL', '' ), '/' ) );
define( 'FAROL_CORS_ORIGINS', (string) farol_env( 'FAROL_CORS_ORIGINS', '' ) );
// Se definida, tem prioridade sobre a chave salva em Configurações → O Farol → IA.
define( 'FAROL_OPENROUTER_API_KEY', (string) farol_env( 'OPENROUTER_API_KEY', '' ) );

// ** Ambiente e depuração ** //
define( 'WP_ENVIRONMENT_TYPE', farol_env( 'WP_ENVIRONMENT_TYPE', 'production' ) );
define( 'WP_DEBUG', (bool) farol_env( 'WP_DEBUG', false ) );
define( 'WP_DEBUG_LOG', (bool) farol_env( 'WP_DEBUG', false ) );
define( 'WP_DEBUG_DISPLAY', false );

// ** Endurecimento ** //
define( 'DISALLOW_FILE_EDIT', true );
define( 'WP_POST_REVISIONS', (int) farol_env( 'WP_POST_REVISIONS', 20 ) );
// Em produção o código vem só do git (DEPLOY.md): o painel não instala nem
// atualiza plugins, temas ou o core — senão o servidor diverge do repositório.
define( 'DISALLOW_FILE_MODS', (bool) farol_env( 'DISALLOW_FILE_MODS', $farol_production ) );
define( 'AUTOMATIC_UPDATER_DISABLED', DISALLOW_FILE_MODS );
// Headless: leitores nunca visitam o CMS, então o WP-Cron "por visita" não
// dispararia (posts agendados não sairiam). Em produção, roda pelo cron do
// sistema — ver DEPLOY.md.
define( 'DISABLE_WP_CRON', (bool) farol_env( 'DISABLE_WP_CRON', $farol_production ) );
if ( $farol_production ) {
	define( 'FORCE_SSL_ADMIN', true );
}

/* Isto é tudo, pode parar de editar! :) */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
