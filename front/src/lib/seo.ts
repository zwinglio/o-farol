/**
 * SEO/GEO vindos do plugin farol-seo: dados do site, do <head> de cada
 * notícia e índices leves de posts para sitemaps, RSS e llms.txt.
 */
import { request } from './wp';
import { SITE } from './site';

export interface SeoImage {
  url: string;
  width: number;
  height: number;
  alt: string;
}

/** Campo `farol_seo` de uma notícia, já resolvido no WordPress. */
export interface PostSeo {
  /** Busca tradicional: o que vai no <head>. */
  seo: {
    title: string;
    description: string;
    canonical: string;
    robots: string;
    keyword: string;
    keywords: string[];
    og: { title: string; description: string; image: SeoImage | null };
  };
  /** Buscadores com IA: fatos, fontes e contexto para citar a matéria. */
  geo: {
    summary: string[];
    lead: string;
    sources: { url: string; domain: string; label: string }[];
    author: { name: string; bio: string; url: string };
    publisher: string;
    section: string | null;
    tags: string[];
    language: string;
    published: string;
    modified: string;
    word_count: number;
    reading_time: number;
    citation: string;
  };
  /** JSON-LD pronto (NewsArticle + BreadcrumbList). */
  schema: Record<string, unknown>[];
}

export interface SiteSeo {
  name: string;
  description: string;
  image: string;
  logo: string;
  x_handle: string;
  news_publication: string;
  verification: { google: string; bing: string };
  ai: { search: boolean; training: boolean; search_bots: string[]; training_bots: string[] };
  llms_intro: string;
  schema: Record<string, unknown>[];
}

const FALLBACK_SITE: SiteSeo = {
  name: SITE.name,
  description: SITE.description,
  image: '',
  logo: '',
  x_handle: '',
  news_publication: SITE.name,
  verification: { google: '', bing: '' },
  ai: { search: true, training: true, search_bots: [], training_bots: [] },
  llms_intro: '',
  schema: [],
};

let siteCache: { at: number; data: SiteSeo } | null = null;

/** Configurações gerais de SEO. Nunca lança: cai no último valor bom ou no padrão. */
export async function getSiteSeo(): Promise<SiteSeo> {
  if (siteCache && Date.now() - siteCache.at < 5 * 60 * 1000) return siteCache.data;
  try {
    const data: SiteSeo = await (await request('/farol-seo/v1/site')).json();
    data.description ||= SITE.description;
    data.news_publication ||= data.name;
    siteCache = { at: Date.now(), data };
    return data;
  } catch (err) {
    console.error('[seo:site]', err instanceof Error ? err.message : err);
    return siteCache?.data ?? FALLBACK_SITE;
  }
}

export interface PostIndexItem {
  slug: string;
  title: string;
  excerpt: string;
  date: string;
  modified: string;
}

/** Lista leve (sem conteúdo nem embeds) para sitemaps e llms.txt. */
export async function getPostIndex({ after, limit = 1000 }: { after?: Date; limit?: number } = {}): Promise<PostIndexItem[]> {
  const out: PostIndexItem[] = [];
  const perPage = Math.min(100, limit);
  for (let page = 1; out.length < limit; page++) {
    const params: Record<string, string | number> = {
      page, per_page: perPage, _fields: 'slug,title,excerpt,date_gmt,modified_gmt', orderby: 'date', order: 'desc',
    };
    if (after) params.after = after.toISOString();
    const res = await request('/wp/v2/posts', params).catch(() => null);
    if (!res) break;
    const items: any[] = await res.json();
    out.push(...items.map((p) => ({
      slug: p.slug,
      title: p.title?.rendered ?? '',
      excerpt: p.excerpt?.rendered ?? '',
      date: `${p.date_gmt}Z`,
      modified: `${p.modified_gmt}Z`,
    })));
    if (page >= Number(res.headers.get('X-WP-TotalPages') ?? 1)) break;
  }
  return out.slice(0, limit);
}

/** Escapa texto para XML (sitemaps e RSS). */
export const xml = (s: string) =>
  s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');

/** JSON-LD seguro dentro de <script>: impede que "</script>" no texto feche a tag. */
export const jsonLd = (data: unknown) => JSON.stringify(data).replace(/</g, '\\u003c');
