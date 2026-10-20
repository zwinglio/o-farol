import type { APIRoute } from 'astro';
import { PUBLIC_SITE_URL } from 'astro:env/client';
import { plain } from '../lib/wp';
import { getPostIndex, getSiteSeo, xml } from '../lib/seo';

/** Sitemap do Google Notícias: só o que foi publicado nas últimas 48 h (máx. 1000). */
export const GET: APIRoute = async () => {
  const [posts, site] = await Promise.all([
    getPostIndex({ after: new Date(Date.now() - 48 * 3600 * 1000), limit: 1000 }),
    getSiteSeo(),
  ]);
  const url = (p: string) => new URL(p, PUBLIC_SITE_URL).href;

  const body = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
${posts.map((p) => `  <url>
    <loc>${xml(url(`/noticias/${p.slug}`))}</loc>
    <news:news>
      <news:publication><news:name>${xml(site.news_publication)}</news:name><news:language>pt</news:language></news:publication>
      <news:publication_date>${p.date}</news:publication_date>
      <news:title>${xml(plain(p.title))}</news:title>
    </news:news>
  </url>`).join('\n')}
</urlset>
`;
  return new Response(body, {
    headers: { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'public, max-age=0, s-maxage=300' },
  });
};
