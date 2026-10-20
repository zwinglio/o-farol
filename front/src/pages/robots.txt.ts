import type { APIRoute } from 'astro';
import { PUBLIC_SITE_URL } from 'astro:env/client';
import { getSiteSeo } from '../lib/seo';

/** Robôs de IA liberados ou bloqueados conforme Configurações → SEO e GEO. */
export const GET: APIRoute = async () => {
  const { ai } = await getSiteSeo();
  const url = (p: string) => new URL(p, PUBLIC_SITE_URL).href;
  const block = [...(ai.search ? [] : ai.search_bots), ...(ai.training ? [] : ai.training_bots)];

  const lines = [
    'User-agent: *',
    'Allow: /',
    '',
    ...block.flatMap((bot) => [`User-agent: ${bot}`, 'Disallow: /', '']),
    `Sitemap: ${url('/sitemap.xml')}`,
    `Sitemap: ${url('/news-sitemap.xml')}`,
    '',
  ];

  return new Response(lines.join('\n'), {
    headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'public, max-age=3600' },
  });
};
