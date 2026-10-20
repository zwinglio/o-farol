import type { APIRoute } from 'astro';
import { PUBLIC_SITE_URL } from 'astro:env/client';
import { getCategories } from '../lib/wp';
import { getPostIndex, xml } from '../lib/seo';

/** Sitemap geral: páginas fixas, editorias e as últimas 1000 notícias. */
export const GET: APIRoute = async () => {
  const [posts, categories] = await Promise.all([getPostIndex({ limit: 1000 }), getCategories().catch(() => [])]);
  const url = (p: string) => new URL(p, PUBLIC_SITE_URL).href;
  const lastmod = posts[0]?.modified;

  const entries = [
    { loc: url('/'), lastmod },
    { loc: url('/ultimas'), lastmod },
    ...categories.map((c) => ({ loc: url(`/${c.slug}`), lastmod })),
    { loc: url('/sobre') },
    { loc: url('/contato') },
    ...posts.map((p) => ({ loc: url(`/noticias/${p.slug}`), lastmod: p.modified })),
  ];

  const body = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${entries.map((e) => `  <url><loc>${xml(e.loc)}</loc>${e.lastmod ? `<lastmod>${e.lastmod}</lastmod>` : ''}</url>`).join('\n')}
</urlset>
`;
  return new Response(body, {
    headers: { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'public, max-age=0, s-maxage=600' },
  });
};
