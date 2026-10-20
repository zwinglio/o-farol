/**
 * Painel "SEO e GEO" do editor de blocos.
 *
 * JavaScript puro, sem build: usa os pacotes globais do WordPress (wp.*).
 * A análise roda no PHP (Farol_SEO_Analyzer) via POST /farol-seo/v1/analyze,
 * chamada ~1 s depois que você para de digitar. A geração com IA usa
 * POST /farol-seo/v1/generate (modelo configurado em Configurações → O Farol → IA).
 */
(function (wp) {
  const { createElement: h, Fragment, useEffect, useRef } = wp.element;
  const { registerPlugin } = wp.plugins;
  const { PluginSidebar, PluginSidebarMoreMenuItem, PluginDocumentSettingPanel, PluginPrePublishPanel } = wp.editor;
  const { PanelBody, TextControl, TextareaControl, ToggleControl, Button, Spinner, Notice } = wp.components;
  const { useSelect, useDispatch, createReduxStore, register, dispatch, select } = wp.data;
  const { MediaUpload, MediaUploadCheck } = wp.blockEditor;
  const apiFetch = wp.apiFetch;

  const SIDEBAR = 'farol-seo-sidebar';
  const PREFIX = '_farol_seo_';
  const CONFIG = window.farolSeoConfig || { ai: { enabled: false } };
  const AI_FIELDS = ['keyword', 'title', 'description', 'summary', 'og_title', 'og_description'];

  /* ------------------------------------------------------------------
   * Estado compartilhado da análise
   * ------------------------------------------------------------------ */

  const STORE = 'farol/seo';
  register(
    createReduxStore(STORE, {
      reducer(state = { result: null, loading: false, error: null, generating: [] }, action) {
        switch (action.type) {
          case 'START': return { ...state, loading: true };
          case 'DONE': return { ...state, result: action.result, loading: false, error: null };
          case 'FAIL': return { ...state, loading: false, error: action.error };
          case 'GENERATING': return { ...state, generating: action.fields };
          default: return state;
        }
      },
      actions: {
        start: () => ({ type: 'START' }),
        done: (result) => ({ type: 'DONE', result }),
        fail: (error) => ({ type: 'FAIL', error }),
        setGenerating: (fields) => ({ type: 'GENERATING', fields }),
      },
      selectors: { get: (state) => state },
    }),
  );

  /** Reanalisa quando título, texto, resumo, slug, campos, imagem ou editoria mudam. */
  function Runner() {
    const post = useSelect((select) => {
      const e = select('core/editor');
      return {
        post_id: e.getCurrentPostId(),
        title: e.getEditedPostAttribute('title'),
        content: e.getEditedPostContent(),
        excerpt: e.getEditedPostAttribute('excerpt'),
        slug: e.getEditedPostAttribute('slug'),
        meta: e.getEditedPostAttribute('meta'),
        featured_media: e.getEditedPostAttribute('featured_media'),
        categories: e.getEditedPostAttribute('categories'),
        author: e.getEditedPostAttribute('author'),
      };
    }, []);
    const { start, done, fail } = useDispatch(STORE);
    const key = JSON.stringify(post);
    const seq = useRef(0);

    useEffect(() => {
      const n = ++seq.current;
      const timer = setTimeout(() => {
        start();
        apiFetch({ path: '/farol-seo/v1/analyze', method: 'POST', data: post })
          .then((r) => n === seq.current && done(r))
          .catch((e) => n === seq.current && fail(e.message || 'Não foi possível analisar.'));
      }, 900);
      return () => clearTimeout(timer);
    }, [key]);

    return null;
  }

  const useAnalysis = () => useSelect((select) => select(STORE).get(), []);

  function useMeta() {
    const meta = useSelect((select) => select('core/editor').getEditedPostAttribute('meta') || {}, []);
    const { editPost } = useDispatch('core/editor');
    return {
      get: (k) => meta[PREFIX + k],
      set: (k, v) => editPost({ meta: { [PREFIX + k]: v } }),
    };
  }

  /* ------------------------------------------------------------------
   * Geração com IA
   * ------------------------------------------------------------------ */

  /** Gera os campos pedidos a partir do texto atual (salvo ou não) e grava como personalizados. */
  async function generate(fields) {
    const e = select('core/editor');
    const meta = e.getEditedPostAttribute('meta') || {};
    const result = select(STORE).get().result;
    const notices = dispatch('core/notices');
    const { setGenerating } = dispatch(STORE);

    setGenerating(fields);
    try {
      const res = await apiFetch({
        path: '/farol-seo/v1/generate',
        method: 'POST',
        data: {
          post_id: e.getCurrentPostId(),
          fields,
          title: e.getEditedPostAttribute('title'),
          content: e.getEditedPostContent(),
          excerpt: e.getEditedPostAttribute('excerpt'),
          categories: e.getEditedPostAttribute('categories'),
          keyword: meta[PREFIX + 'keyword'] || result?.auto.keyword || '',
        },
      });
      const edits = {};
      for (const [k, v] of Object.entries(res.values)) {
        edits[PREFIX + k] = Array.isArray(v) ? v.join('\n') : v;
      }
      dispatch('core/editor').editPost({ meta: edits });
      notices.createNotice('success', `Gerado com ${res.model}. Revise antes de publicar.`, { type: 'snackbar', id: 'farol-seo-ai' });
    } catch (err) {
      notices.createNotice('error', err.message || 'Não foi possível gerar com IA.', { type: 'snackbar', id: 'farol-seo-ai' });
    } finally {
      setGenerating([]);
    }
  }

  const useGenerating = () => useSelect((s) => s(STORE).get().generating, []);

  /** Botão "Gerar com IA" de um campo. */
  function AIButton({ field }) {
    const generating = useGenerating();
    if (!CONFIG.ai.enabled) return null;
    const busy = generating.includes(field);
    return h(Button, {
      variant: 'link',
      className: 'farol-ai-link',
      disabled: generating.length > 0,
      onClick: () => generate([field]),
    }, busy ? h(Fragment, null, h(Spinner), ' gerando…') : '✦ Gerar com IA');
  }

  /** Botão geral: preenche todos os campos de SEO e GEO de uma vez. */
  function AIGenerateAll() {
    const generating = useGenerating();
    const meta = useMeta();
    const { result } = useAnalysis();

    if (!CONFIG.ai.enabled) {
      return h('p', { className: 'farol-muted farol-ai-off' },
        'Gere SEO e GEO com IA: ',
        h('a', { href: CONFIG.ai.settingsUrl }, 'configure a chave do OpenRouter'), '.');
    }

    const busy = generating.length > 1;
    const tooShort = result && result.stats.words < 40;
    const onClick = () => {
      const custom = AI_FIELDS.filter((f) => meta.get(f));
      if (custom.length && !window.confirm(`Substituir ${custom.length} campo(s) que você já personalizou?`)) return;
      generate(AI_FIELDS);
    };

    return h('div', { className: 'farol-ai-all' },
      h(Button, {
        variant: 'primary',
        className: 'farol-ai-all__button',
        isBusy: busy,
        disabled: generating.length > 0 || tooShort,
        onClick,
      }, busy ? 'Gerando com IA…' : '✦ Gerar SEO e GEO com IA'),
      h('p', { className: 'farol-muted' },
        tooShort
          ? 'Escreva pelo menos 40 palavras para gerar.'
          : `${CONFIG.ai.model} · preenche palavra-chave, títulos, descrições e pontos principais a partir do texto.`),
    );
  }

  /* ------------------------------------------------------------------
   * Peças
   * ------------------------------------------------------------------ */

  const grade = (s) => (s >= 80 ? 'good' : s >= 50 ? 'ok' : 'bad');
  const GRADE = { good: 'Bom', ok: 'Pode melhorar', bad: 'Precisa de atenção' };
  const truncate = (t, n) => (t && t.length > n ? t.slice(0, n - 1).replace(/\s+\S*$/, '') + '…' : t || '');

  function Ring({ label, score, hint }) {
    const g = score == null ? 'none' : grade(score);
    return h('div', { className: `farol-ring is-${g}`, style: { '--p': score ?? 0 }, title: hint },
      h('div', { className: 'farol-ring__circle' }, h('span', null, score ?? '–')),
      h('div', { className: 'farol-ring__label' }, h('strong', null, label), h('span', null, score == null ? '…' : GRADE[g])),
    );
  }

  function Scores({ compact }) {
    const { result, loading, error } = useAnalysis();
    return h('div', { className: `farol-scores${compact ? ' is-compact' : ''}` },
      h('div', { className: 'farol-scores__rings' },
        h(Ring, { label: 'SEO', score: result?.seo.score, hint: 'Google e buscadores tradicionais' }),
        h(Ring, { label: 'GEO', score: result?.geo.score, hint: 'Buscadores com IA (ChatGPT, Perplexity, Gemini…)' }),
      ),
      h('div', { className: 'farol-scores__status' },
        loading ? h(Fragment, null, h(Spinner), ' analisando…') : result ? `${result.stats.words} palavras · legibilidade ${result.stats.readability}` : null,
      ),
      error ? h(Notice, { status: 'error', isDismissible: false }, error) : null,
    );
  }

  function Counter({ length, min, max }) {
    const cls = length === 0 ? 'bad' : length < min || length > max ? 'ok' : 'good';
    return h('span', { className: `farol-counter is-${cls}` }, `${length} / ${min}–${max}`);
  }

  /** Campo que mostra o valor automático até você escrever o seu. */
  function AutoField({ k, label, auto, help, multiline, rows, count, ai = AI_FIELDS.includes(k) }) {
    const meta = useMeta();
    const generating = useGenerating();
    const value = meta.get(k) || '';
    const Control = multiline ? TextareaControl : TextControl;
    const shown = value || auto || '';
    return h('div', { className: 'farol-field' },
      h(Control, {
        __nextHasNoMarginBottom: true,
        __next40pxDefaultSize: !multiline || undefined,
        label,
        help,
        rows,
        value,
        disabled: generating.includes(k),
        placeholder: auto || '',
        onChange: (v) => meta.set(k, v),
      }),
      h('div', { className: 'farol-field__meta' },
        h('span', { className: `farol-mode${value ? ' is-custom' : ''}` }, value ? 'Personalizado' : 'Automático'),
        count ? h(Counter, { length: shown.length + (count.extra || 0), min: count.min, max: count.max }) : null,
        value
          ? h(Button, { variant: 'link', onClick: () => meta.set(k, '') }, 'Voltar ao automático')
          : auto ? h(Button, { variant: 'link', onClick: () => meta.set(k, auto) }, 'Editar sugestão') : null,
        ai ? h(AIButton, { field: k }) : null,
      ),
    );
  }

  function Snippet() {
    const { result } = useAnalysis();
    if (!result) return null;
    const r = result.resolved;
    return h('div', { className: 'farol-snippet', 'aria-label': 'Prévia no Google' },
      h('div', { className: 'farol-snippet__site' }, result.site_name),
      h('div', { className: 'farol-snippet__url' }, result.url),
      h('div', { className: 'farol-snippet__title' }, truncate(`${r.title} · ${result.site_name}`, 62)),
      h('div', { className: 'farol-snippet__desc' }, truncate(r.description, 160) || 'Sem descrição.'),
    );
  }

  function Checklist({ group }) {
    const { result } = useAnalysis();
    if (!result) return h(Spinner);
    return h('ul', { className: 'farol-checks' },
      result[group].checks.map((c) =>
        h('li', { key: c.id, className: `is-${c.status}` },
          h('span', { className: 'farol-checks__dot', 'aria-label': GRADE[c.status] }),
          h('div', null, h('strong', null, c.label), h('p', null, c.message)),
        ),
      ),
    );
  }

  function SocialImage() {
    const meta = useMeta();
    const id = meta.get('og_image') || 0;
    const media = useSelect((select) => (id ? select('core').getMedia(id) : null), [id]);
    return h(MediaUploadCheck, null,
      h(MediaUpload, {
        allowedTypes: ['image'],
        value: id,
        onSelect: (m) => meta.set('og_image', m.id),
        render: ({ open }) => h('div', { className: 'farol-field' },
          h('p', { className: 'farol-label' }, 'Imagem para redes'),
          media
            ? h('img', { className: 'farol-og-img', src: media.source_url, alt: '' })
            : h('p', { className: 'farol-muted' }, 'Usando a imagem destacada. Ideal: 1200×630 px.'),
          h('div', { className: 'farol-row' },
            h(Button, { variant: 'secondary', size: 'compact', onClick: open }, id ? 'Trocar imagem' : 'Usar outra imagem'),
            id ? h(Button, { variant: 'link', isDestructive: true, onClick: () => meta.set('og_image', 0) }, 'Voltar à destacada') : null,
          ),
        ),
      }),
    );
  }

  function openSidebar() {
    const editPost = dispatch('core/edit-post');
    if (editPost?.openGeneralSidebar) editPost.openGeneralSidebar(`farol-seo/${SIDEBAR}`);
    else dispatch('core/interface').enableComplementaryArea('core', `farol-seo/${SIDEBAR}`);
  }

  /* ------------------------------------------------------------------
   * Painéis
   * ------------------------------------------------------------------ */

  function Sidebar() {
    const { result } = useAnalysis();
    const meta = useMeta();
    const auto = result?.auto || {};
    const suffix = result ? ` · ${result.site_name}`.length : 0;
    const count = (group) => (result ? ` (${result[group].score})` : '');

    return h(Fragment, null,
      h(PluginSidebarMoreMenuItem, { target: SIDEBAR, icon: 'chart-bar' }, 'SEO e GEO'),
      h(PluginSidebar, { name: SIDEBAR, title: 'SEO e GEO', icon: 'chart-bar' },
        h(PanelBody, null, h(Scores), h(AIGenerateAll)),

        h(PanelBody, { title: 'Palavra-chave', initialOpen: true },
          h(AutoField, { k: 'keyword', label: 'Palavra-chave principal', auto: auto.keyword, help: 'O termo que o leitor digitaria para achar esta notícia.' }),
        ),

        h(PanelBody, { title: 'Aparência no Google', initialOpen: true },
          h(Snippet),
          h(AutoField, { k: 'title', label: 'Título SEO', auto: auto.title, count: { min: 35, max: 60, extra: suffix }, help: 'Conta com o nome do site no fim.' }),
          h(AutoField, { k: 'description', label: 'Meta descrição', auto: auto.description, multiline: true, rows: 3, count: { min: 120, max: 160 } }),
        ),

        h(PanelBody, { title: 'Resumo para IA (GEO)', initialOpen: false },
          h('p', { className: 'farol-muted' }, 'Os pontos principais da notícia, um por linha. Entram nos dados estruturados e no llms.txt — é o que buscadores com IA tendem a citar.'),
          h(AutoField, { k: 'summary', label: 'Pontos principais', auto: (auto.summary || []).join('\n'), multiline: true, rows: 6 }),
        ),

        h(PanelBody, { title: 'Redes sociais', initialOpen: false },
          h(AutoField, { k: 'og_title', label: 'Título para redes', auto: result?.resolved.title }),
          h(AutoField, { k: 'og_description', label: 'Descrição para redes', auto: result?.resolved.description, multiline: true, rows: 3 }),
          h(SocialImage),
        ),

        h(PanelBody, { title: 'Avançado', initialOpen: false },
          h(TextControl, {
            __nextHasNoMarginBottom: true, __next40pxDefaultSize: true,
            label: 'URL canônica', type: 'url',
            help: 'Só preencha se esta matéria for republicação de outro site.',
            value: meta.get('canonical') || '', placeholder: result?.url,
            onChange: (v) => meta.set('canonical', v),
          }),
          h('div', { style: { height: 16 } }),
          h(ToggleControl, {
            __nextHasNoMarginBottom: true,
            label: 'Esconder dos buscadores (noindex)',
            help: 'A página continua no ar, mas pede para não aparecer em buscas.',
            checked: !!meta.get('noindex'),
            onChange: (v) => meta.set('noindex', v),
          }),
        ),

        h(PanelBody, { title: `Checklist SEO${count('seo')}`, initialOpen: false }, h(Checklist, { group: 'seo' })),
        h(PanelBody, { title: `Checklist GEO${count('geo')}`, initialOpen: false }, h(Checklist, { group: 'geo' })),
      ),
    );
  }

  /** Resumo na aba "Post" da barra lateral padrão. */
  function DocumentPanel() {
    return h(PluginDocumentSettingPanel, { name: 'farol-seo-summary', title: 'SEO e GEO', className: 'farol-doc-panel' },
      h(Scores, { compact: true }),
      h(Button, { variant: 'secondary', onClick: openSidebar, className: 'farol-open' }, 'Abrir painel completo'),
    );
  }

  /** Revisão antes de publicar: scores e os principais pontos fracos. */
  function PrePublish() {
    const { result } = useAnalysis();
    const issues = result
      ? [...result.seo.checks, ...result.geo.checks].filter((c) => c.status === 'bad').sort((a, b) => b.weight - a.weight).slice(0, 4)
      : [];
    return h(PluginPrePublishPanel, { title: 'SEO e GEO', initialOpen: true },
      h(Scores, { compact: true }),
      issues.length
        ? h('ul', { className: 'farol-checks' }, issues.map((c) =>
            h('li', { key: c.id, className: 'is-bad' }, h('span', { className: 'farol-checks__dot' }), h('div', null, h('strong', null, c.label), h('p', null, c.message)))))
        : result ? h('p', { className: 'farol-muted' }, 'Nenhum problema grave. Bom trabalho.') : null,
      h(Button, { variant: 'link', onClick: openSidebar }, 'Ver tudo'),
    );
  }

  registerPlugin('farol-seo', {
    render: () => h(Fragment, null, h(Runner), h(Sidebar), h(DocumentPanel), h(PrePublish)),
  });
})(window.wp);
