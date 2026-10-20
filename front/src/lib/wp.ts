import { WP_API_URL, WP_API_TIMEOUT_MS } from 'astro:env/server';
import { FALLBACK_MENUS } from './site';
import type { PostSeo } from './seo';

export interface Image {
  url: string;
  /** Versão menor para cards e listas (cai no original se não houver). */
  thumb: string;
  alt: string;
  width?: number;
  height?: number;
}

export interface Category {
  id: number;
  name: string;
  slug: string;
  description: string;
}

/** Formato enxuto de uma postagem, já pronto para os templates. */
export interface Post {
  id: number;
  slug: string;
  title: string;
  excerpt: string;
  content: string;
  date: string;
  modified: string;
  author: { name: string; slug: string } | null;
  image: Image | null;
  categories: Category[];
  readingTime: number;
  /** Só na página da notícia (plugin farol-seo). */
  seo?: PostSeo;
}

export interface PostPage {
  posts: Post[];
  total: number;
  totalPages: number;
}

/** Item de menu já no formato do front (ver farol_menu_node no mu-plugin). */
export interface MenuItem {
  id: number;
  label: string;
  href: string;
  /** Slug da categoria quando o item é uma editoria (vira bloco na capa). */
  category: string | null;
  external: boolean;
  children: MenuItem[];
}

export interface PostQuery {
  page?: number;
  perPage?: number;
  category?: number;
  exclude?: number[];
  sticky?: boolean;
}

export class WPError extends Error {
  constructor(message: string, public status: number) {
    super(message);
  }
}

const EMBED = 'author,wp:featuredmedia,wp:term';
const FIELDS = [
  'id', 'slug', 'title', 'excerpt', 'content', 'date_gmt', 'modified_gmt',
  'farol_reading_time', '_links', '_embedded',
].join(',');
const HIDDEN_CATEGORIES = new Set(['sem-categoria', 'uncategorized']);

export async function request(path: string, params: Record<string, string | number> = {}) {
  const url = new URL(`${WP_API_URL.replace(/\/$/, '')}${path}`);
  for (const [key, value] of Object.entries(params)) url.searchParams.set(key, String(value));

  const res = await fetch(url, {
    headers: { Accept: 'application/json' },
    signal: AbortSignal.timeout(WP_API_TIMEOUT_MS),
  });
  if (!res.ok) throw new WPError(`WordPress respondeu ${res.status} em ${url.pathname}`, res.status);
  return res;
}

function normalize(raw: any): Post {
  const media = raw._embedded?.['wp:featuredmedia']?.[0];
  const author = raw._embedded?.author?.[0];
  const terms: any[] = (raw._embedded?.['wp:term'] ?? []).flat();
  const sizes = media?.media_details?.sizes ?? {};

  return {
    id: raw.id,
    slug: raw.slug,
    title: raw.title?.rendered ?? '',
    excerpt: raw.excerpt?.rendered ?? '',
    content: raw.content?.rendered ?? '',
    date: `${raw.date_gmt}Z`,
    modified: `${raw.modified_gmt}Z`,
    author: author?.name ? { name: author.name, slug: author.slug } : null,
    image: media?.source_url
      ? {
          url: media.source_url,
          thumb: (sizes.medium_large ?? sizes.large ?? sizes.full)?.source_url ?? media.source_url,
          alt: media.alt_text ?? '',
          width: media.media_details?.width,
          height: media.media_details?.height,
        }
      : null,
    categories: terms
      .filter((t) => t.taxonomy === 'category' && !HIDDEN_CATEGORIES.has(t.slug))
      .map((t) => ({ id: t.id, name: t.name, slug: t.slug, description: '' })),
    readingTime: raw.farol_reading_time ?? 1,
    seo: raw.farol_seo ?? undefined,
  };
}

export async function getPosts({ page = 1, perPage = 10, category, exclude, sticky }: PostQuery = {}): Promise<PostPage> {
  const params: Record<string, string | number> = { page, per_page: perPage, _embed: EMBED, _fields: FIELDS };
  if (category) params.categories = category;
  if (exclude?.length) params.exclude = exclude.join(',');
  if (sticky !== undefined) params.sticky = String(sticky);

  try {
    const res = await request('/wp/v2/posts', params);
    return {
      posts: (await res.json()).map(normalize),
      total: Number(res.headers.get('X-WP-Total') ?? 0),
      totalPages: Number(res.headers.get('X-WP-TotalPages') ?? 0),
    };
  } catch (err) {
    // Página além da última: o WP responde 400 (rest_post_invalid_page_number).
    if (err instanceof WPError && err.status === 400) return { posts: [], total: 0, totalPages: 0 };
    throw err;
  }
}

export async function getPostBySlug(slug: string): Promise<Post | null> {
  const res = await request('/wp/v2/posts', { slug, _embed: EMBED, _fields: `${FIELDS},farol_seo` });
  const [raw] = await res.json();
  return raw ? normalize(raw) : null;
}

// Categorias e menus mudam pouco: ficam em memória por alguns minutos.
let categoryCache: { at: number; items: Category[] } | null = null;
const CATEGORY_TTL = 5 * 60 * 1000;
const menuCache = new Map<string, { at: number; items: MenuItem[] }>();
const MENU_TTL = 60 * 1000;

export async function getCategories(): Promise<Category[]> {
  if (categoryCache && Date.now() - categoryCache.at < CATEGORY_TTL) return categoryCache.items;

  const res = await request('/wp/v2/categories', { per_page: 100, _fields: 'id,name,slug,description' });
  const items: Category[] = (await res.json()).filter((c: Category) => !HIDDEN_CATEGORIES.has(c.slug));
  categoryCache = { at: Date.now(), items };
  return items;
}

export async function getCategory(slug: string): Promise<Category | null> {
  return (await getCategories()).find((c) => c.slug === slug) ?? null;
}

/**
 * Menu de uma posição registrada no tema ("principal", "institucional").
 * Nunca lança: se o CMS falhar, usa o último menu bom ou o de reserva.
 */
export async function getMenu(location: string): Promise<MenuItem[]> {
  const cached = menuCache.get(location);
  if (cached && Date.now() - cached.at < MENU_TTL) return cached.items;

  try {
    const res = await request(`/farol/v1/menus/${location}`);
    const items: MenuItem[] = await res.json();
    menuCache.set(location, { at: Date.now(), items });
    return items;
  } catch (err) {
    console.error(`[menu:${location}]`, err instanceof Error ? err.message : err);
    return cached?.items ?? FALLBACK_MENUS[location] ?? [];
  }
}

/** Remove tags HTML e decodifica entidades simples (para <title> e meta tags). */
export function plain(html: string): string {
  return html
    .replace(/<[^>]*>/g, '')
    .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
    .replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCodePoint(parseInt(n, 16)))
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\s+/g, ' ')
    .trim();
}

const TZ = 'America/Sao_Paulo';
const dateFormat = new Intl.DateTimeFormat('pt-BR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: TZ });
const dateTimeFormat = new Intl.DateTimeFormat('pt-BR', {
  day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: TZ,
});
const shortFormat = new Intl.DateTimeFormat('pt-BR', { day: 'numeric', month: 'short', timeZone: TZ });

export const formatDate = (iso: string) => dateFormat.format(new Date(iso));

/** "19/10/2026 às 14:05" — padrão de portal para data de publicação. */
export const formatDateTime = (iso: string) => dateTimeFormat.format(new Date(iso)).replace(', ', ' às ');

/** "há 12 min", "há 3 h" nas últimas 24 h; depois "19 de out.". */
export function formatAgo(iso: string, now = Date.now()): string {
  const minutes = Math.round((now - new Date(iso).getTime()) / 60000);
  if (minutes < 1) return 'agora';
  if (minutes < 60) return `há ${minutes} min`;
  if (minutes < 24 * 60) return `há ${Math.floor(minutes / 60)} h`;
  return shortFormat.format(new Date(iso));
}

/** Lê o número de página de rotas `[...rest]` ("" → 1, "pagina/3" → 3). Inválido → null. */
export function parsePage(rest: string | undefined): number | null {
  if (!rest) return 1;
  const match = /^pagina\/(\d+)$/.exec(rest);
  const page = match ? Number(match[1]) : NaN;
  return page >= 1 ? page : null;
}
