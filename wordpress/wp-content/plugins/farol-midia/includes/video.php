<?php
/**
 * Vídeos: o navegador envia direto ao R2 em partes (URLs pré-assinadas).
 * O PHP só autoriza, conclui e registra o anexo — nada de upload_max_filesize.
 *
 *   POST /farol-midia/v1/video/start    { name, type, size }          → { key, uploadId, partSize, urls[] }
 *   POST /farol-midia/v1/video/complete { uploadId, parts[], … }       → anexo (formato da biblioteca)
 *   POST /farol-midia/v1/video/abort    { uploadId }
 *
 * @package FarolMidia
 */

defined( 'ABSPATH' ) || exit;

const FAROL_MIDIA_VIDEO_TYPES = array( 'video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v' );
const FAROL_MIDIA_PART_SIZE   = 16 * MB_IN_BYTES;          // mínimo do S3: 5 MB (exceto a última)
const FAROL_MIDIA_VIDEO_MAX   = 20 * GB_IN_BYTES;
const FAROL_MIDIA_URL_TTL     = 6 * HOUR_IN_SECONDS;       // conexões lentas precisam de tempo

/** Upload em andamento, amarrado ao usuário que o iniciou. */
function farol_midia_pending( string $upload_id ): ?array {
	$pending = get_transient( 'farol_midia_up_' . md5( $upload_id ) );
	return is_array( $pending ) && (int) $pending['user'] === get_current_user_id() ? $pending : null;
}

add_action(
	'rest_api_init',
	function () {
		$can = fn() => current_user_can( 'upload_files' ) && farol_midia_enabled();

		register_rest_route(
			'farol-midia/v1',
			'/video/start',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'args'                => array(
					'name' => array( 'type' => 'string', 'required' => true ),
					'type' => array( 'type' => 'string', 'required' => true, 'enum' => FAROL_MIDIA_VIDEO_TYPES ),
					'size' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => FAROL_MIDIA_VIDEO_MAX ),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$ext  = array( 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/x-m4v' => 'm4v' )[ $r['type'] ];
					$base = sanitize_title( pathinfo( (string) $r['name'], PATHINFO_FILENAME ) ) ?: 'video';
					// Mesmo esquema de pastas dos uploads (AAAA/MM) e sufixo aleatório: nome nunca colide.
					$key  = wp_upload_dir()['subdir'] . '/' . mb_substr( $base, 0, 60 ) . '-' . strtolower( wp_generate_password( 6, false ) ) . ".{$ext}";
					$key  = ltrim( $key, '/' );

					$client    = farol_midia_client();
					$upload_id = $client->create_multipart( $key, $r['type'], FAROL_MIDIA_CACHE );
					if ( is_wp_error( $upload_id ) ) {
						return $upload_id;
					}

					$parts = (int) ceil( $r['size'] / FAROL_MIDIA_PART_SIZE );
					$urls  = array();
					for ( $n = 1; $n <= $parts; $n++ ) {
						$urls[] = $client->presign( 'PUT', $key, FAROL_MIDIA_URL_TTL, array( 'partNumber' => $n, 'uploadId' => $upload_id ) );
					}

					set_transient(
						'farol_midia_up_' . md5( $upload_id ),
						array( 'key' => $key, 'user' => get_current_user_id(), 'type' => $r['type'], 'size' => (int) $r['size'], 'parts' => $parts ),
						FAROL_MIDIA_URL_TTL
					);

					return rest_ensure_response( array( 'key' => $key, 'uploadId' => $upload_id, 'partSize' => FAROL_MIDIA_PART_SIZE, 'urls' => $urls ) );
				},
			)
		);

		register_rest_route(
			'farol-midia/v1',
			'/video/complete',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'args'                => array(
					'uploadId' => array( 'type' => 'string', 'required' => true ),
					'parts'    => array( 'type' => 'array', 'required' => true ),
					'title'    => array( 'type' => 'string', 'default' => '' ),
					'width'    => array( 'type' => 'integer', 'default' => 0 ),
					'height'   => array( 'type' => 'integer', 'default' => 0 ),
					'duration' => array( 'type' => 'number', 'default' => 0 ),
					'poster'   => array( 'type' => 'integer', 'default' => 0 ),
				),
				'callback'            => function ( WP_REST_Request $r ) {
					$pending = farol_midia_pending( (string) $r['uploadId'] );
					if ( ! $pending ) {
						return new WP_Error( 'farol_midia_upload', 'Upload não encontrado ou expirado.', array( 'status' => 404 ) );
					}

					$parts = array_map(
						fn( $p ) => array( 'number' => (int) ( $p['number'] ?? 0 ), 'etag' => (string) ( $p['etag'] ?? '' ) ),
						(array) $r['parts']
					);
					if ( count( $parts ) !== $pending['parts'] || in_array( '', array_column( $parts, 'etag' ), true ) ) {
						return new WP_Error( 'farol_midia_upload', 'Faltam partes do vídeo.', array( 'status' => 400 ) );
					}

					$client = farol_midia_client();
					$done   = $client->complete_multipart( $pending['key'], (string) $r['uploadId'], $parts );
					if ( is_wp_error( $done ) ) {
						return $done;
					}
					delete_transient( 'farol_midia_up_' . md5( (string) $r['uploadId'] ) );

					$title = sanitize_text_field( (string) $r['title'] ) ?: pathinfo( $pending['key'], PATHINFO_FILENAME );
					$id    = wp_insert_attachment(
						array(
							'post_title'     => $title,
							'post_mime_type' => $pending['type'],
							'post_status'    => 'inherit',
							'guid'           => farol_midia_url( $pending['key'] ),
						),
						false,
						0,
						true
					);
					if ( is_wp_error( $id ) ) {
						return $id;
					}

					// O vídeo só existe no R2: o WordPress não deve procurar um arquivo local.
					update_post_meta( $id, '_wp_attached_file', $pending['key'] );
					update_post_meta( $id, '_farol_r2', array( 'files' => array( $pending['key'] ), 'remote_only' => true, 'at' => time() ) );

					$seconds = (int) round( (float) $r['duration'] );
					wp_update_attachment_metadata(
						$id,
						array_filter(
							array(
								'filesize'         => $pending['size'],
								'mime_type'        => $pending['type'],
								'fileformat'       => pathinfo( $pending['key'], PATHINFO_EXTENSION ),
								'width'            => (int) $r['width'],
								'height'           => (int) $r['height'],
								'length'           => $seconds,
								'length_formatted' => $seconds ? ( $seconds >= 3600 ? gmdate( 'G:i:s', $seconds ) : gmdate( 'i:s', $seconds ) ) : '',
							)
						)
					);

					// Capa (frame capturado no navegador e enviada pela biblioteca normal).
					if ( $r['poster'] && 'attachment' === get_post_type( (int) $r['poster'] ) ) {
						set_post_thumbnail( $id, (int) $r['poster'] );
					}

					return rest_ensure_response( wp_prepare_attachment_for_js( $id ) );
				},
			)
		);

		register_rest_route(
			'farol-midia/v1',
			'/video/abort',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'args'                => array( 'uploadId' => array( 'type' => 'string', 'required' => true ) ),
				'callback'            => function ( WP_REST_Request $r ) {
					$pending = farol_midia_pending( (string) $r['uploadId'] );
					if ( $pending ) {
						farol_midia_client()->abort_multipart( $pending['key'], (string) $r['uploadId'] );
						delete_transient( 'farol_midia_up_' . md5( (string) $r['uploadId'] ) );
					}
					return rest_ensure_response( array( 'aborted' => (bool) $pending ) );
				},
			)
		);
	}
);

/* Página Mídia → Enviar vídeo. */
add_action(
	'admin_menu',
	function () {
		$hook = add_media_page( 'Enviar vídeo', 'Enviar vídeo', 'upload_files', 'farol-video', 'farol_midia_render_video_page' );
		add_action(
			"admin_print_scripts-{$hook}",
			function () {
				$dir = plugin_dir_path( __DIR__ );
				wp_enqueue_script( 'farol-video-upload', plugins_url( 'assets/video-upload.js', __DIR__ ), array( 'wp-api-fetch' ), filemtime( "{$dir}assets/video-upload.js" ), true );
				wp_enqueue_style( 'farol-video-upload', plugins_url( 'assets/video-upload.css', __DIR__ ), array(), filemtime( "{$dir}assets/video-upload.css" ) );
				wp_add_inline_script(
					'farol-video-upload',
					'window.farolVideo = ' . wp_json_encode(
						array(
							'types'    => FAROL_MIDIA_VIDEO_TYPES,
							'maxBytes' => FAROL_MIDIA_VIDEO_MAX,
							'library'  => admin_url( 'upload.php' ),
						)
					) . ';',
					'before'
				);
			}
		);
	}
);

function farol_midia_render_video_page(): void {
	echo '<div class="wrap"><h1>Enviar vídeo</h1>';
	if ( ! farol_midia_enabled() ) {
		echo '<div class="notice notice-warning"><p>O armazenamento no R2 não está configurado (variáveis <code>R2_*</code> no <code>.env</code>). Vídeos dependem dele.</p></div></div>';
		return;
	}
	?>
	<p class="description">O vídeo vai direto do seu navegador para o armazenamento (Cloudflare R2), em partes — arquivos grandes não passam pelo servidor. Depois, no editor, use o bloco <strong>Vídeo → Biblioteca de mídia</strong>.</p>
	<div id="farol-video-app" class="farol-video"></div>
	</div>
	<?php
}
