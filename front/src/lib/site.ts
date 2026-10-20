import type { MenuItem } from './wp';

/** Identidade do portal. */
export const SITE = {
  name: 'O Farol',
  tagline: 'Jornalismo independente',
  byline: 'Por Samuel Zwinglio',
  description: 'Portal de notícias independente: política, Parnamirim, Brasil, mundo e tecnologia com olhar crítico e compromisso com os fatos.',
};

const item = (label: string, href: string, category: string | null = null): MenuItem => ({
  id: 0, label, href, category, external: false, children: [],
});

/**
 * Menus de reserva, usados só se o CMS não responder. Os de verdade são
 * editados no WordPress em Aparência → Menus (`make menus` cria os iniciais).
 */
export const FALLBACK_MENUS: Record<string, MenuItem[]> = {
  principal: [
    item('Início', '/'),
    item('Últimas', '/ultimas'),
    item('Política', '/politica', 'politica'),
    item('Parnamirim', '/parnamirim', 'parnamirim'),
    item('Brasil', '/brasil', 'brasil'),
    item('Mundo', '/mundo', 'mundo'),
    item('Tecnologia', '/tecnologia', 'tecnologia'),
  ],
  institucional: [item('Sobre', '/sobre'), item('Contato', '/contato')],
};
