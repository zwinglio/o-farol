// @ts-check
import { defineConfig, envField } from 'astro/config';
import node from '@astrojs/node';
import { loadEnv } from 'vite';

// O front roda como servidor (SSR): cada notícia publicada no WordPress
// aparece no site sem precisar de rebuild.

// Domínio público, lido do .env no build. Atrás do nginx o Astro recebe
// http://127.0.0.1; com allowedDomains ele passa a confiar em
// X-Forwarded-Host/Proto apenas para este domínio — necessário para a
// checagem de origem dos formulários e para Astro.url corretos.
const env = loadEnv(process.env.NODE_ENV ?? 'production', process.cwd(), '');
const site = new URL(env.PUBLIC_SITE_URL || 'http://localhost:4321');

export default defineConfig({
  output: 'server',
  adapter: node({ mode: 'standalone' }),
  trailingSlash: 'never',
  security: {
    checkOrigin: true,
    allowedDomains: [{ hostname: site.hostname, protocol: site.protocol.replace(':', '') }],
  },
  env: {
    schema: {
      PUBLIC_SITE_URL: envField.string({ context: 'client', access: 'public', url: true }),
      // "secret" = lidas do ambiente a cada execução (trocar o .env + reiniciar basta).
      // As "public" de servidor seriam gravadas no código durante o build.
      WP_API_URL: envField.string({ context: 'server', access: 'secret', url: true }),
      WP_API_TIMEOUT_MS: envField.number({ context: 'server', access: 'secret', default: 8000 }),
      CACHE_MAX_AGE: envField.number({ context: 'server', access: 'secret', default: 60 }),
    },
  },
});
