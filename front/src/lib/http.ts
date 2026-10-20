import type { AstroGlobal } from 'astro';
import { CACHE_MAX_AGE } from 'astro:env/server';

/** Permite que CDN/proxy guardem a página por alguns segundos sem segurar notícias velhas. */
export function cachePublic(Astro: AstroGlobal) {
  Astro.response.headers.set(
    'Cache-Control',
    `public, max-age=0, s-maxage=${CACHE_MAX_AGE}, stale-while-revalidate=${CACHE_MAX_AGE * 5}`,
  );
}
