import { WP_API_URL, WP_API_TIMEOUT_MS } from 'astro:env/server';

/** Resultado de uma chamada à newsletter: ok, recusada pelo CMS (4xx) ou CMS fora do ar. */
export type NewsletterResult = 'ok' | 'invalid' | 'error';

async function call(route: string, body: Record<string, string>): Promise<NewsletterResult> {
  try {
    const res = await fetch(`${WP_API_URL.replace(/\/$/, '')}/farol-newsletter/v1${route}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(WP_API_TIMEOUT_MS),
    });
    if (res.ok) return 'ok';
    return res.status >= 400 && res.status < 500 ? 'invalid' : 'error';
  } catch {
    return 'error';
  }
}

export const subscribe = (email: string, source: string) => call('/subscribe', { email, source });
export const confirm = (token: string) => call('/confirm', { token });
export const unsubscribe = (token: string) => call('/unsubscribe', { token });

/** Token dos links de confirmar/sair (32 caracteres hex). */
export const validToken = (token: string | null): token is string => !!token && /^[a-f0-9]{32}$/.test(token);
