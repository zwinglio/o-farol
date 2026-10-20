<?php
/**
 * Cliente mínimo da API S3 (Cloudflare R2), com assinatura AWS SigV4.
 * Sem SDK: só o que o plugin usa — enviar, apagar e consultar objetos,
 * URLs pré-assinadas e upload em partes (vídeos, direto do navegador).
 *
 * @package FarolMidia
 */

defined( 'ABSPATH' ) || exit;

final class Farol_R2_Client {

	const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * @param string $host       Host da API (R2: <conta>.r2.cloudflarestorage.com).
	 * @param bool   $path_style true = https://host/bucket/chave (R2); false = https://bucket.host/chave.
	 */
	public function __construct(
		private string $host,
		private string $bucket,
		private string $access_key,
		private string $secret_key,
		private string $region = 'auto',
		private bool $path_style = true,
		private string $scheme = 'https',
	) {}

	/* ------------------------------------------------------------------
	 * Operações
	 * ------------------------------------------------------------------ */

	/** Envia um arquivo local. @return true|WP_Error */
	public function put_file( string $key, string $path, string $content_type, string $cache_control = '' ) {
		$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $body ) {
			return new WP_Error( 'farol_r2_read', "Não foi possível ler {$path}." );
		}
		$headers = array_filter( array( 'content-type' => $content_type, 'cache-control' => $cache_control ) );
		$res     = $this->request( 'PUT', $key, array(), $headers, $body );
		return is_wp_error( $res ) ? $res : true;
	}

	/** @return true|WP_Error */
	public function delete( string $key ) {
		$res = $this->request( 'DELETE', $key );
		return is_wp_error( $res ) ? $res : true;
	}

	/** @return array|null|WP_Error Cabeçalhos do objeto, null se não existir. */
	public function head( string $key ) {
		$res = $this->request( 'HEAD', $key, array(), array(), '', array( 404 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return 404 === $res['code'] ? null : $res['headers'];
	}

	/** Inicia um upload em partes. @return string|WP_Error UploadId. */
	public function create_multipart( string $key, string $content_type, string $cache_control = '' ) {
		$headers = array_filter( array( 'content-type' => $content_type, 'cache-control' => $cache_control ) );
		$res     = $this->request( 'POST', $key, array( 'uploads' => '' ), $headers );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return preg_match( '#<UploadId>([^<]+)</UploadId>#', $res['body'], $m )
			? html_entity_decode( $m[1], ENT_XML1 )
			: new WP_Error( 'farol_r2_multipart', 'O R2 não devolveu o UploadId.' );
	}

	/**
	 * Conclui um upload em partes.
	 *
	 * @param array $parts Lista de array( 'number' => int, 'etag' => string ).
	 * @return true|WP_Error
	 */
	public function complete_multipart( string $key, string $upload_id, array $parts ) {
		usort( $parts, fn( $a, $b ) => $a['number'] <=> $b['number'] );
		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $p ) {
			$etag = '"' . trim( (string) $p['etag'], '"' ) . '"';
			$xml .= sprintf( '<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>', (int) $p['number'], htmlspecialchars( $etag, ENT_XML1 ) );
		}
		$xml .= '</CompleteMultipartUpload>';

		$res = $this->request( 'POST', $key, array( 'uploadId' => $upload_id ), array( 'content-type' => 'application/xml' ), $xml );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		// O S3 pode responder 200 com <Error> no corpo.
		return str_contains( $res['body'], '<Error>' )
			? new WP_Error( 'farol_r2_multipart', 'O R2 recusou concluir o upload: ' . wp_strip_all_tags( $res['body'] ) )
			: true;
	}

	/** @return true|WP_Error */
	public function abort_multipart( string $key, string $upload_id ) {
		$res = $this->request( 'DELETE', $key, array( 'uploadId' => $upload_id ), array(), '', array( 404 ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * URL pré-assinada (o navegador usa para enviar cada parte do vídeo).
	 *
	 * @param array $query Parâmetros extras (ex.: partNumber, uploadId).
	 */
	public function presign( string $method, string $key, int $expires, array $query = array(), ?int $now = null ): string {
		$now   = $now ?? time();
		$date  = gmdate( 'Ymd\THis\Z', $now );
		$scope = gmdate( 'Ymd', $now ) . "/{$this->region}/s3/aws4_request";

		$query = array_merge(
			$query,
			array(
				'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
				'X-Amz-Credential'    => "{$this->access_key}/{$scope}",
				'X-Amz-Date'          => $date,
				'X-Amz-Expires'       => (string) $expires,
				'X-Amz-SignedHeaders' => 'host',
			)
		);

		$canonical = implode(
			"\n",
			array( $method, $this->canonical_uri( $key ), $this->canonical_query( $query ), 'host:' . $this->host() . "\n", 'host', 'UNSIGNED-PAYLOAD' )
		);
		$signature = $this->signature( $canonical, $date, $scope, $now );

		return "{$this->scheme}://" . $this->host() . $this->canonical_uri( $key ) . '?' . $this->canonical_query( $query ) . '&X-Amz-Signature=' . $signature;
	}

	/* ------------------------------------------------------------------
	 * Requisição assinada (cabeçalho Authorization)
	 * ------------------------------------------------------------------ */

	/**
	 * @param int[] $ok_codes Códigos além de 2xx tratados como resposta válida.
	 * @return array{code:int,headers:array,body:string}|WP_Error
	 */
	public function request( string $method, string $key, array $query = array(), array $headers = array(), string $body = '', array $ok_codes = array(), ?int $now = null ) {
		$signed = $this->sign_headers( $method, $key, $query, $headers, $body, $now );
		$qs     = $this->canonical_query( $query );
		$url    = "{$this->scheme}://" . $this->host() . $this->canonical_uri( $key ) . ( '' === $qs ? '' : "?{$qs}" );

		$res = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $signed,
				'body'    => '' === $body ? null : $body,
				'timeout' => 120,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( ( $code < 200 || $code >= 300 ) && ! in_array( $code, $ok_codes, true ) ) {
			$msg = preg_match( '#<Message>([^<]+)</Message>#', (string) wp_remote_retrieve_body( $res ), $m ) ? $m[1] : "HTTP {$code}";
			return new WP_Error( 'farol_r2_http', "R2: {$msg}", array( 'status' => $code ) );
		}

		return array(
			'code'    => $code,
			'headers' => wp_remote_retrieve_headers( $res )->getAll(),
			'body'    => (string) wp_remote_retrieve_body( $res ),
		);
	}

	/** Cabeçalhos finais (com Authorization) de uma requisição. Público para os testes. */
	public function sign_headers( string $method, string $key, array $query, array $headers, string $body, ?int $now = null ): array {
		$now   = $now ?? time();
		$date  = gmdate( 'Ymd\THis\Z', $now );
		$scope = gmdate( 'Ymd', $now ) . "/{$this->region}/s3/aws4_request";
		$hash  = '' === $body ? self::EMPTY_HASH : hash( 'sha256', $body );

		$headers = array_change_key_case( $headers, CASE_LOWER ) + array(
			'host'                 => $this->host(),
			'x-amz-content-sha256' => $hash,
			'x-amz-date'           => $date,
		);
		ksort( $headers );

		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( preg_replace( '/\s+/', ' ', (string) $value ) ) . "\n";
		}
		$signed_names = implode( ';', array_keys( $headers ) );

		$canonical = implode( "\n", array( $method, $this->canonical_uri( $key ), $this->canonical_query( $query ), $canonical_headers, $signed_names, $hash ) );
		$signature = $this->signature( $canonical, $date, $scope, $now );

		$headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$scope}, SignedHeaders={$signed_names}, Signature={$signature}";
		unset( $headers['host'] ); // o cliente HTTP envia o próprio Host
		return $headers;
	}

	/* ------------------------------------------------------------------
	 * SigV4
	 * ------------------------------------------------------------------ */

	private function signature( string $canonical, string $date, string $scope, int $now ): string {
		$to_sign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . hash( 'sha256', $canonical );
		$k       = hash_hmac( 'sha256', gmdate( 'Ymd', $now ), 'AWS4' . $this->secret_key, true );
		$k       = hash_hmac( 'sha256', $this->region, $k, true );
		$k       = hash_hmac( 'sha256', 's3', $k, true );
		$k       = hash_hmac( 'sha256', 'aws4_request', $k, true );
		return hash_hmac( 'sha256', $to_sign, $k );
	}

	private function host(): string {
		return $this->path_style ? $this->host : "{$this->bucket}.{$this->host}";
	}

	private function canonical_uri( string $key ): string {
		$path = implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $key, '/' ) ) ) );
		return $this->path_style ? "/{$this->bucket}/{$path}" : "/{$path}";
	}

	private function canonical_query( array $query ): string {
		$pairs = array();
		foreach ( $query as $k => $v ) {
			$pairs[ rawurlencode( (string) $k ) ] = rawurlencode( (string) $v );
		}
		ksort( $pairs, SORT_STRING );
		return implode( '&', array_map( fn( $k, $v ) => "{$k}={$v}", array_keys( $pairs ), $pairs ) );
	}
}
