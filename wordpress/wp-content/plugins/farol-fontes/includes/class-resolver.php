<?php
/**
 * Transforma uma URL num "snapshot" da fonte: texto, autor, data, imagens
 * e dados do player — guardado no bloco para a citação sobreviver ao original.
 *
 * Formato devolvido (atributo `source` do bloco):
 *   platform  youtube|vimeo|tiktok|x|bluesky|mastodon|web
 *   provider  nome exibido ("YouTube", "X", "Agência Brasil"…)
 *   kind      video|post|page
 *   url       link canônico
 *   title, text, date (ISO 8601, ou só AAAA-MM-DD quando a plataforma não dá a hora), duration (s)
 *   author    { name, handle, url, avatar: { id, url } }
 *   image     { id, url, width, height }
 *   embed     { url, ratio }   — player, carregado só no clique
 *   site      { name, icon: { id, url } }
 *   fetched   ISO 8601
 *
 * @package FarolFontes
 */

defined( 'ABSPATH' ) || exit;

final class Farol_Fontes_Resolver {

	const UA = 'Mozilla/5.0 (compatible; OFarol/1.0; +https://ofarol.com.br)';

	/** Plataformas reconhecidas pela URL; o resto é tratado como site (Open Graph). */
	const PATTERNS = array(
		'youtube'  => '#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?.*v=|shorts/|live/|embed/)|youtu\.be/)([\w-]{11})#i',
		'vimeo'    => '#^https?://(?:www\.)?vimeo\.com/(?:.*/)?(\d+)#i',
		'tiktok'   => '#^https?://(?:www\.)?tiktok\.com/@([\w.-]+)/video/(\d+)#i',
		'x'        => '#^https?://(?:www\.|mobile\.)?(?:x|twitter)\.com/(\w{1,15})/status/(\d+)#i',
		'bluesky'  => '#^https?://bsky\.app/profile/([^/]+)/post/([\w]+)#i',
		'mastodon' => '#^https?://([\w.-]+\.[a-z]{2,})/@([\w.]+)(?:@[\w.-]+)?/(\d+)#i',
	);

	private int $post_id;

	public function __construct( int $post_id = 0 ) {
		$this->post_id = $post_id;
	}

	/** @return array|WP_Error */
	public function resolve( string $url ) {
		$url = trim( $url );
		// Só o formato aqui; a checagem de segurança (IPs internos etc.) é do wp_safe_remote_get.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'farol_fontes_url', 'URL inválida.', array( 'status' => 400 ) );
		}

		foreach ( self::PATTERNS as $platform => $re ) {
			if ( preg_match( $re, $url, $m ) ) {
				$source = $this->{$platform}( $url, $m );
				if ( ! is_wp_error( $source ) ) {
					return $this->finish( $source );
				}
				// Mastodon é heurístico (qualquer domínio): se a API não responder, trata como site.
				if ( 'mastodon' !== $platform ) {
					return $source;
				}
			}
		}

		$source = $this->web( $url );
		return is_wp_error( $source ) ? $source : $this->finish( $source );
	}

	/* ------------------------------------------------------------------
	 * Plataformas
	 * ------------------------------------------------------------------ */

	private function youtube( string $url, array $m ) {
		$id    = $m[1];
		$o     = $this->json( 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( "https://www.youtube.com/watch?v={$id}" ) );
		$short = str_contains( $url, '/shorts/' );
		$start = preg_match( '/[?&#]t=(\d+)/', $url, $t ) ? (int) $t[1] : 0;

		// maxresdefault nem sempre existe; hqdefault sempre.
		$thumb = "https://i.ytimg.com/vi/{$id}/maxresdefault.jpg";
		if ( 200 !== wp_remote_retrieve_response_code( wp_safe_remote_head( $thumb, array( 'timeout' => 8 ) ) ) ) {
			$thumb = "https://i.ytimg.com/vi/{$id}/hqdefault.jpg";
		}

		return array(
			'platform' => 'youtube',
			'provider' => 'YouTube',
			'kind'     => 'video',
			'url'      => $short ? "https://www.youtube.com/shorts/{$id}" : "https://www.youtube.com/watch?v={$id}",
			'title'    => $o['title'] ?? '',
			'author'   => array( 'name' => $o['author_name'] ?? '', 'url' => $o['author_url'] ?? '' ),
			'image'    => array( 'remote' => $thumb ),
			'embed'    => array(
				'url'   => "https://www.youtube-nocookie.com/embed/{$id}?autoplay=1&rel=0" . ( $start ? "&start={$start}" : '' ),
				'ratio' => $short ? '9/16' : '16/9',
			),
		);
	}

	private function vimeo( string $url, array $m ) {
		$o = $this->json( 'https://vimeo.com/api/oembed.json?width=1280&url=' . rawurlencode( $url ) );
		if ( ! $o ) {
			return new WP_Error( 'farol_fontes_vimeo', 'Vídeo do Vimeo não encontrado ou privado.' );
		}
		$id = $o['video_id'] ?? $m[1];

		return array(
			'platform' => 'vimeo',
			'provider' => 'Vimeo',
			'kind'     => 'video',
			'url'      => "https://vimeo.com/{$id}",
			'title'    => $o['title'] ?? '',
			'text'     => wp_trim_words( $o['description'] ?? '', 40 ),
			'date'     => $this->iso( $o['upload_date'] ?? '' ),
			'duration' => (int) ( $o['duration'] ?? 0 ),
			'author'   => array( 'name' => $o['author_name'] ?? '', 'url' => $o['author_url'] ?? '' ),
			'image'    => empty( $o['thumbnail_url'] ) ? null : array( 'remote' => preg_replace( '/_\d+x\d+$/', '_1280', $o['thumbnail_url'] ) ),
			'embed'    => array( 'url' => "https://player.vimeo.com/video/{$id}?autoplay=1&dnt=1", 'ratio' => ( $o['width'] ?? 16 ) . '/' . ( $o['height'] ?? 9 ) ),
		);
	}

	private function tiktok( string $url, array $m ) {
		[ , $user, $id ] = $m;
		// O oEmbed do TikTok hoje costuma vir sem título/autor/miniatura; usa o que vier.
		$o = $this->json( 'https://www.tiktok.com/oembed?url=' . rawurlencode( "https://www.tiktok.com/@{$user}/video/{$id}" ) ) ?: array();

		return array(
			'platform' => 'tiktok',
			'provider' => 'TikTok',
			'kind'     => 'video',
			'url'      => "https://www.tiktok.com/@{$user}/video/{$id}",
			'title'    => $o['title'] ?? '',
			'author'   => array( 'name' => $o['author_name'] ?? $user, 'handle' => $user, 'url' => "https://www.tiktok.com/@{$user}" ),
			'image'    => empty( $o['thumbnail_url'] ) ? null : array( 'remote' => $o['thumbnail_url'] ),
			'embed'    => array( 'url' => "https://www.tiktok.com/player/v1/{$id}?autoplay=1&rel=0", 'ratio' => '9/16' ),
		);
	}

	private function x( string $url, array $m ) {
		[ , $user, $id ] = $m;
		$canonical = "https://x.com/{$user}/status/{$id}";
		$o         = $this->json( 'https://publish.twitter.com/oembed?omit_script=1&dnt=1&url=' . rawurlencode( $canonical ) );
		if ( ! $o || empty( $o['html'] ) ) {
			return new WP_Error( 'farol_fontes_x', 'Post do X não encontrado (apagado, privado ou de conta suspensa).' );
		}

		// <blockquote><p>texto</p>&mdash; Nome (@user) <a href="…">March 21, 2006</a></blockquote>
		$text = preg_match( '#<p[^>]*>(.*?)</p>#s', $o['html'], $p ) ? $this->text( $p[1] ) : '';
		// O oEmbed só traz o dia ("March 21, 2006"): guarda sem hora para não mudar de dia no fuso.
		$day  = preg_match_all( '#<a [^>]*>([^<]+)</a>#', $o['html'], $a ) ? strtotime( end( $a[1] ) . ' UTC' ) : false;
		$date = $day ? gmdate( 'Y-m-d', $day ) : '';

		return array(
			'platform' => 'x',
			'provider' => 'X',
			'kind'     => 'post',
			'url'      => $canonical,
			'text'     => $text,
			'date'     => $date,
			'author'   => array( 'name' => $o['author_name'] ?? $user, 'handle' => $user, 'url' => $o['author_url'] ?? "https://x.com/{$user}" ),
		);
	}

	private function bluesky( string $url, array $m ) {
		[ , $actor, $rkey ] = $m;
		$api = 'https://public.api.bsky.app/xrpc/';
		$did = str_starts_with( $actor, 'did:' ) ? $actor : ( $this->json( $api . 'com.atproto.identity.resolveHandle?handle=' . rawurlencode( $actor ) )['did'] ?? '' );
		$res = $did ? $this->json( $api . 'app.bsky.feed.getPosts?uris=' . rawurlencode( "at://{$did}/app.bsky.feed.post/{$rkey}" ) ) : null;
		$p   = $res['posts'][0] ?? null;
		if ( ! $p ) {
			return new WP_Error( 'farol_fontes_bluesky', 'Post do Bluesky não encontrado.' );
		}

		$embed = $p['embed'] ?? array();
		if ( 'app.bsky.embed.recordWithMedia#view' === ( $embed['$type'] ?? '' ) ) {
			$embed = $embed['media'];
		}
		$image = match ( $embed['$type'] ?? '' ) {
			'app.bsky.embed.images#view'   => array(
				'remote' => $embed['images'][0]['fullsize'] ?? '',
				'alt'    => $embed['images'][0]['alt'] ?? '',
			),
			'app.bsky.embed.video#view'    => array( 'remote' => $embed['thumbnail'] ?? '' ),
			'app.bsky.embed.external#view' => array( 'remote' => $embed['external']['thumb'] ?? '' ),
			default                        => null,
		};

		$handle = $p['author']['handle'];
		return array(
			'platform' => 'bluesky',
			'provider' => 'Bluesky',
			'kind'     => 'post',
			'url'      => "https://bsky.app/profile/{$handle}/post/{$rkey}",
			'text'     => (string) ( $p['record']['text'] ?? '' ),
			'date'     => $this->iso( $p['record']['createdAt'] ?? '' ),
			'author'   => array(
				'name'   => $p['author']['displayName'] ?? $handle,
				'handle' => $handle,
				'url'    => "https://bsky.app/profile/{$handle}",
				'avatar' => empty( $p['author']['avatar'] ) ? null : array( 'remote' => $p['author']['avatar'] ),
			),
			'image'    => empty( $image['remote'] ) ? null : $image,
		);
	}

	private function mastodon( string $url, array $m ) {
		[ , $host, , $id ] = $m;
		$s = $this->json( "https://{$host}/api/v1/statuses/{$id}" );
		if ( ! $s || empty( $s['account'] ) ) {
			return new WP_Error( 'farol_fontes_mastodon', 'Não é uma instância Mastodon.' );
		}
		$s     = $s['reblog'] ?? $s; // retoot: cita o original
		$media = $s['media_attachments'][0] ?? null;

		return array(
			'platform' => 'mastodon',
			'provider' => 'Mastodon',
			'kind'     => 'post',
			'url'      => $s['url'] ?? $url,
			'text'     => $this->text( $s['content'] ?? '' ),
			'date'     => $this->iso( $s['created_at'] ?? '' ),
			'author'   => array(
				'name'   => $s['account']['display_name'] ?: $s['account']['username'],
				'handle' => $s['account']['acct'] . ( str_contains( $s['account']['acct'], '@' ) ? '' : "@{$host}" ),
				'url'    => $s['account']['url'] ?? '',
				'avatar' => empty( $s['account']['avatar_static'] ) ? null : array( 'remote' => $s['account']['avatar_static'] ),
			),
			'image'    => $media ? array( 'remote' => 'image' === $media['type'] ? $media['url'] : ( $media['preview_url'] ?? '' ), 'alt' => $media['description'] ?? '' ) : null,
		);
	}

	/** Qualquer site: Open Graph, Twitter Cards e JSON-LD. */
	private function web( string $url ) {
		$res = wp_safe_remote_get( $url, array( 'timeout' => 12, 'user-agent' => self::UA, 'limit_response_size' => 1572864 ) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'farol_fontes_fetch', 'Não foi possível acessar o link: ' . $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 ) {
			return new WP_Error( 'farol_fontes_fetch', "O site respondeu com erro {$code}." );
		}

		$html = (string) wp_remote_retrieve_body( $res );
		$meta = array();
		if ( preg_match_all( '#<meta\s[^>]*>#i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '#(?:property|name|itemprop)=["\']([^"\']+)["\']#i', $tag, $k ) && preg_match( '#content=["\']([^"\']*)["\']#i', $tag, $v ) ) {
					$meta[ strtolower( $k[1] ) ] ??= html_entity_decode( $v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
		}

		// JSON-LD de notícia (headline, datePublished, author).
		$ld = array();
		if ( preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $json ) {
				$data  = json_decode( trim( $json ), true );
				$nodes = isset( $data['@graph'] ) ? $data['@graph'] : ( array_is_list( (array) $data ) ? (array) $data : array( $data ) );
				foreach ( $nodes as $node ) {
					$type = (array) ( $node['@type'] ?? array() );
					if ( array_intersect( $type, array( 'NewsArticle', 'Article', 'ReportageNewsArticle', 'AnalysisNewsArticle', 'BlogPosting', 'OpinionNewsArticle' ) ) ) {
						$ld = $node;
						break 2;
					}
				}
			}
		}

		$title = $meta['og:title'] ?? $meta['twitter:title'] ?? $ld['headline'] ?? ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $t ) ? $this->text( $t[1] ) : '' );
		$author = $ld['author'] ?? null;
		if ( isset( $author[0] ) ) {
			$author = $author[0];
		}

		$host  = preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$image = $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? '';
		$icon  = $this->icon( $html );

		return array(
			'platform' => 'web',
			'provider' => $meta['og:site_name'] ?? ( is_array( $ld['publisher'] ?? null ) ? ( $ld['publisher']['name'] ?? '' ) : '' ) ?: $host,
			'kind'     => 'page',
			'url'      => $this->absolute( $meta['og:url'] ?? $url, $url ),
			'title'    => $this->text( $title ),
			'text'     => $this->text( $meta['og:description'] ?? $meta['description'] ?? $meta['twitter:description'] ?? $ld['description'] ?? '' ),
			'date'     => $this->iso( $meta['article:published_time'] ?? $ld['datePublished'] ?? '' ),
			'author'   => array( 'name' => is_array( $author ) ? (string) ( $author['name'] ?? '' ) : (string) ( $meta['author'] ?? '' ) ),
			'image'    => $image ? array( 'remote' => $this->absolute( $image, $url ) ) : null,
			'site'     => array( 'name' => $meta['og:site_name'] ?? $host, 'icon' => array( 'remote' => $this->absolute( $icon, $url ) ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Finalização: imagens locais e campos padrão
	 * ------------------------------------------------------------------ */

	private function finish( array $s ): array {
		$s = array_merge(
			array( 'title' => '', 'text' => '', 'date' => '', 'duration' => 0, 'author' => array(), 'image' => null, 'embed' => null, 'site' => null ),
			$s
		);
		$s['author'] = array_merge( array( 'name' => '', 'handle' => '', 'url' => '', 'avatar' => null ), (array) $s['author'] );
		$s['site']   = $s['site'] ?? array( 'name' => $s['provider'], 'icon' => null );

		// Imagens de terceiros são copiadas para a biblioteca: URLs de CDN expiram
		// e hotlink expõe o IP do leitor à plataforma.
		$s['image']           = $this->localize( $s['image'], $s['title'] ?: $s['provider'] );
		$s['author']['avatar'] = $this->localize( $s['author']['avatar'], $s['author']['name'] );
		$s['site']['icon']    = $this->localize( $s['site']['icon'], $s['site']['name'] );
		$s['fetched']         = gmdate( 'c' );

		return $s;
	}

	private function localize( ?array $img, string $label ): ?array {
		if ( empty( $img['remote'] ) ) {
			return null;
		}
		$att = farol_fontes_sideload( $img['remote'], $this->post_id, $label );
		if ( ! $att ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $att ) ?: array();
		if ( ! empty( $img['alt'] ) ) {
			update_post_meta( $att, '_wp_attachment_image_alt', sanitize_text_field( $img['alt'] ) );
		}
		return array(
			'id'     => $att,
			'url'    => wp_get_attachment_url( $att ),
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
			'alt'    => (string) ( $img['alt'] ?? '' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------ */

	/** Melhor ícone declarado: apple-touch-icon (PNG grande) > icon PNG > qualquer icon > /favicon.ico. */
	private function icon( string $html ): string {
		$best = array( 0, '/favicon.ico' );
		if ( preg_match_all( '#<link\s[^>]*>#i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( ! preg_match( '#rel=["\']([^"\']+)#i', $tag, $rel ) || ! preg_match( '#href=["\']([^"\']+)#i', $tag, $href ) ) {
					continue;
				}
				$rel   = strtolower( $rel[1] );
				$score = str_contains( $rel, 'apple-touch-icon' ) ? 3 : ( preg_match( '/\bicon\b/', $rel ) ? ( preg_match( '/\.png|image\/png/i', $tag ) ? 2 : 1 ) : 0 );
				if ( $score > $best[0] && ! preg_match( '/\.svg(\?|$)/i', $href[1] ) ) {
					$best = array( $score, html_entity_decode( $href[1] ) );
				}
			}
		}
		return $best[1];
	}

	private function json( string $url ): ?array {
		$res = wp_safe_remote_get( $url, array( 'timeout' => 10, 'user-agent' => self::UA, 'redirection' => 3 ) );
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 400 ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $data ) ? $data : null;
	}

	/** HTML → texto, preservando quebras de parágrafo e de linha. */
	private function text( string $html ): string {
		$html = preg_replace( array( '#<br\s*/?>#i', '#</p>\s*<p[^>]*>#i' ), array( "\n", "\n\n" ), $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( "/[ \t]+/", ' ', $text ) );
	}

	private function iso( string $date ): string {
		$ts = $date ? strtotime( $date ) : false;
		return $ts ? gmdate( 'c', $ts ) : '';
	}

	private function absolute( string $href, string $base ): string {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		$p = wp_parse_url( $base );
		if ( str_starts_with( $href, '//' ) ) {
			return ( $p['scheme'] ?? 'https' ) . ':' . $href;
		}
		$root = ( $p['scheme'] ?? 'https' ) . '://' . $p['host'];
		return str_starts_with( $href, '/' ) ? $root . $href : $root . '/' . ltrim( dirname( $p['path'] ?? '/' ), '/' ) . '/' . $href;
	}
}
