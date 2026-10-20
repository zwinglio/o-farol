import { defineMiddleware } from 'astro:middleware';
import { WP_API_URL } from 'astro:env/server';

/**
 * Cabeçalhos de segurança em todas as respostas do front.
 *
 * A CSP é restrita de propósito: scripts só do próprio site (o player de
 * vídeo do bloco Fonte é nosso), iframes só dos players permitidos e
 * imagens do CMS e de qualquer HTTPS (no futuro, S3/CDN).
 * HSTS fica no nginx (DEPLOY.md), que é quem termina o HTTPS.
 */
const CMS = new URL(WP_API_URL).origin;

const CSP = [
  "default-src 'self'",
  "script-src 'self' 'unsafe-inline'",
  "style-src 'self' 'unsafe-inline'",
  `img-src 'self' data: https: ${CMS}`,
  "font-src 'self'",
  "connect-src 'self'",
  'frame-src https://www.youtube-nocookie.com https://player.vimeo.com https://www.tiktok.com',
  "frame-ancestors 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "object-src 'none'",
].join('; ');

export const onRequest = defineMiddleware(async (_context, next) => {
  const response = await next();
  const h = response.headers;
  h.set('Content-Security-Policy', CSP);
  h.set('X-Content-Type-Options', 'nosniff');
  h.set('Referrer-Policy', 'strict-origin-when-cross-origin');
  h.set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');
  h.set('Cross-Origin-Opener-Policy', 'same-origin');
  return response;
});
