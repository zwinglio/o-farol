import type { APIRoute } from 'astro';
import { PUBLIC_SITE_URL } from 'astro:env/client';
import { getCategories, getMenu, plain } from '../lib/wp';
import { getPostIndex, getSiteSeo } from '../lib/seo';

/**
 * /llms.txt (llmstxt.org): mapa em Markdown para modelos de linguagem —
 * quem somos, editorias e últimas notícias com links canônicos.
 */
export const GET: APIRoute = async () => {
  const [site, menu, categories, posts] = await Promise.all([
    getSiteSeo(),
    getMenu('principal'),
    getCategories().catch(() => []),
    getPostIndex({ limit: 50 }),
  ]);
  const url = (p: string) => new URL(p, PUBLIC_SITE_URL).href;
  const sections = menu.filter((m) => m.category);

  const text = [
    `# ${site.name}`,
    '',
    `> ${site.description}`,
    '',
    site.llms_intro,
    site.llms_intro ? '' : null,
    `Portal de notícias independente em português do Brasil. Ao citar, use o título da matéria, "${site.name}" e o link canônico.`,
    '',
    '## Sobre',
    '',
    `- [Sobre e expediente](${url('/sobre')}): quem somos e linha editorial`,
    `- [Contato](${url('/contato')}): fale com a redação`,
    `- [Newsletter](${url('/newsletter')}): as principais notícias por e-mail`,
    `- [RSS](${url('/rss.xml')}): feed com o texto completo das notícias`,
    '',
    '## Editorias',
    '',
    ...sections.map((m) => {
      const desc = plain(categories.find((c) => c.slug === m.category)?.description ?? '');
      return `- [${m.label}](${url(m.href)})${desc ? `: ${desc}` : ''}`;
    }),
    '',
    '## Últimas notícias',
    '',
    ...posts.map((p) => `- [${plain(p.title)}](${url(`/noticias/${p.slug}`)}): ${plain(p.excerpt)}`),
    '',
  ].filter((l) => l !== null);

  return new Response(text.join('\n'), {
    headers: { 'Content-Type': 'text/markdown; charset=utf-8', 'Cache-Control': 'public, max-age=0, s-maxage=600' },
  });
};
