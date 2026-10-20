import type { APIRoute } from 'astro';
import { PUBLIC_SITE_URL } from 'astro:env/client';
import { getPosts, plain } from '../lib/wp';
import { getSiteSeo, xml } from '../lib/seo';

const cdata = (s: string) => `<![CDATA[${s.replace(/]]>/g, ']]]]><![CDATA[>')}]]>`;

/** RSS com o texto completo: leitores de feed e agregadores (inclusive de IA). */
export const GET: APIRoute = async () => {
  const [{ posts }, site] = await Promise.all([getPosts({ perPage: 30 }), getSiteSeo()]);
  const url = (p: string) => new URL(p, PUBLIC_SITE_URL).href;

  const body = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>${xml(site.name)}</title>
    <link>${url('/')}</link>
    <description>${xml(site.description)}</description>
    <language>pt-BR</language>
    <atom:link href="${url('/rss.xml')}" rel="self" type="application/rss+xml" />
    ${posts[0] ? `<lastBuildDate>${new Date(posts[0].modified).toUTCString()}</lastBuildDate>` : ''}
${posts.map((p) => `    <item>
      <title>${xml(plain(p.title))}</title>
      <link>${url(`/noticias/${p.slug}`)}</link>
      <guid isPermaLink="true">${url(`/noticias/${p.slug}`)}</guid>
      <pubDate>${new Date(p.date).toUTCString()}</pubDate>
      ${p.author ? `<dc:creator>${xml(p.author.name)}</dc:creator>` : ''}
      ${p.categories.map((c) => `<category>${xml(c.name)}</category>`).join('')}
      <description>${xml(plain(p.excerpt))}</description>
      <content:encoded>${cdata((p.image ? `<p><img src="${xml(p.image.url)}" alt="${xml(p.image.alt)}" /></p>` : '') + p.content)}</content:encoded>
    </item>`).join('\n')}
  </channel>
</rss>
`;
  return new Response(body, {
    headers: { 'Content-Type': 'application/rss+xml; charset=utf-8', 'Cache-Control': 'public, max-age=0, s-maxage=300' },
  });
};
