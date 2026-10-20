/**
 * Imagens para redes — painel do editor.
 *
 * Desenha as artes num <canvas> no próprio navegador a partir do título,
 * da editoria e da imagem destacada. JavaScript puro (pacotes wp.*), sem build.
 *
 * Para criar um formato novo, acrescente em FORMATS e escreva a função de desenho.
 * Textos da arte ficam em `texts` (hoje só título e editoria; descrição e
 * hashtags geradas por IA entram aqui no futuro).
 */
(function (wp) {
  const { createElement: h, Fragment, useEffect, useRef, useState } = wp.element;
  const { registerPlugin } = wp.plugins;
  const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editor;
  const { PanelBody, TextareaControl, FocalPointPicker, Button, Modal, Notice } = wp.components;
  const { useSelect, useDispatch, dispatch } = wp.data;
  const { decodeEntities } = wp.htmlEntities;

  const CONFIG = window.farolSocial || { siteName: 'O Farol', host: '' };
  const SIDEBAR = 'farol-social-sidebar';

  /* ------------------------------------------------------------------
   * Identidade (espelha front/src/styles/global.css)
   * ------------------------------------------------------------------ */

  const C = { ink: '#16171a', muted: '#5b5e66', line: '#e3e4e8', accent: '#c4161c', paper: '#ffffff' };
  const SERIF = "Charter, 'Bitstream Charter', 'Iowan Old Style', 'Source Serif Pro', Georgia, serif";
  const SANS = "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

  // Mesmo farol do cabeçalho do site (viewBox 24×24).
  const LIGHTHOUSE = new Path2D('M9 22h6l-1-12h-4zM10 8h4l-.5-3h-3zM12 2v1');
  const RAYS = new Path2D('M16 6.5l5-2M16 8.5l5 2M8 6.5l-5-2M8 8.5l-5 2');

  /* ------------------------------------------------------------------
   * Formatos
   * ------------------------------------------------------------------ */

  const FORMATS = {
    feed: { label: 'Feed', ratio: '4:5', width: 1080, height: 1350, hint: 'Instagram, Facebook, Threads e X', draw: drawFeed },
    stories: { label: 'Stories', ratio: '9:16', width: 1080, height: 1920, hint: 'Stories do Instagram e status do WhatsApp', draw: drawStories },
  };

  /* ------------------------------------------------------------------
   * Desenho
   * ------------------------------------------------------------------ */

  function drawLogo(ctx, x, y, size, color, withName = true) {
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(size / 24, size / 24);
    ctx.lineWidth = 1.8;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.strokeStyle = color;
    ctx.stroke(LIGHTHOUSE);
    ctx.strokeStyle = C.accent;
    ctx.stroke(RAYS);
    ctx.restore();
    if (!withName) return size;
    ctx.save();
    ctx.fillStyle = color;
    ctx.font = `700 ${Math.round(size * 0.95)}px ${SERIF}`;
    ctx.textBaseline = 'middle';
    ctx.fillText(CONFIG.siteName, x + size * 1.2, y + size / 2 + size * 0.04);
    const width = size * 1.2 + ctx.measureText(CONFIG.siteName).width;
    ctx.restore();
    return width;
  }

  /** Recorta a imagem como object-fit: cover, respeitando o ponto de foco. */
  function drawCover(ctx, img, x, y, w, h, focal) {
    const scale = Math.max(w / img.naturalWidth, h / img.naturalHeight);
    const sw = w / scale;
    const sh = h / scale;
    const clamp = (v, max) => Math.min(Math.max(v, 0), max);
    const sx = clamp(focal.x * img.naturalWidth - sw / 2, img.naturalWidth - sw);
    const sy = clamp(focal.y * img.naturalHeight - sh / 2, img.naturalHeight - sh);
    ctx.drawImage(img, sx, sy, sw, sh, x, y, w, h);
  }

  /** Sem foto: fundo escuro com o farol grande e apagado. */
  function drawNoImage(ctx, x, y, w, h) {
    ctx.fillStyle = C.ink;
    ctx.fillRect(x, y, w, h);
    ctx.save();
    ctx.globalAlpha = 0.08;
    const size = Math.min(w, h) * 0.8;
    drawLogo(ctx, x + (w - size) / 2, y + (h - size) / 2, size, '#ffffff', false);
    ctx.restore();
  }

  /** Quebra o texto em linhas que cabem em maxWidth (palavras longas demais são cortadas). */
  function wrap(ctx, text, maxWidth) {
    const lines = [];
    let line = '';
    for (const word of text.split(/\s+/).filter(Boolean)) {
      const test = line ? `${line} ${word}` : word;
      if (ctx.measureText(test).width <= maxWidth) {
        line = test;
      } else {
        if (line) lines.push(line);
        line = word;
        while (ctx.measureText(line).width > maxWidth && line.length > 1) {
          let cut = line.length - 1;
          while (cut > 1 && ctx.measureText(line.slice(0, cut) + '-').width > maxWidth) cut--;
          lines.push(line.slice(0, cut) + '-');
          line = line.slice(cut);
        }
      }
    }
    if (line) lines.push(line);
    return lines;
  }

  /** Maior fonte (entre max e min) em que o título cabe na caixa; no limite, corta com reticências. */
  function fitTitle(ctx, text, { maxWidth, maxHeight, max, min, lineHeight, weight = 700, family = SERIF }) {
    for (let size = max; size >= min; size -= 2) {
      ctx.font = `${weight} ${size}px ${family}`;
      const lines = wrap(ctx, text, maxWidth);
      if (lines.length * size * lineHeight <= maxHeight) return { size, lines };
    }
    ctx.font = `${weight} ${min}px ${family}`;
    const fit = Math.max(1, Math.floor(maxHeight / (min * lineHeight)));
    const lines = wrap(ctx, text, maxWidth);
    if (lines.length > fit) {
      lines.length = fit;
      let last = lines[fit - 1];
      while (last && ctx.measureText(last + '…').width > maxWidth) last = last.replace(/\s*\S+$/, '');
      lines[fit - 1] = last + '…';
    }
    return { size: min, lines };
  }

  function drawLines(ctx, lines, x, y, size, lineHeight) {
    lines.forEach((line, i) => ctx.fillText(line, x, y + size + i * size * lineHeight));
  }

  function kickerText(ctx, text, x, y, size, color) {
    ctx.save();
    ctx.font = `800 ${size}px ${SANS}`;
    ctx.fillStyle = color;
    ctx.letterSpacing = `${Math.round(size * 0.1)}px`;
    ctx.fillText(text.toUpperCase(), x, y);
    ctx.restore();
  }

  /** Feed 4:5 — foto em cima, título no papel branco, assinatura do site embaixo. */
  function drawFeed(ctx, { width: W, height: H }, { texts, image, focal }) {
    const pad = 72;
    const photoH = 760;

    ctx.fillStyle = C.paper;
    ctx.fillRect(0, 0, W, H);
    if (image) drawCover(ctx, image, 0, 0, W, photoH, focal);
    else drawNoImage(ctx, 0, 0, W, photoH);
    ctx.fillStyle = C.accent;
    ctx.fillRect(0, photoH, W, 10);

    let top = photoH + 10 + 64;
    if (texts.kicker) {
      kickerText(ctx, texts.kicker, pad, top + 26, 30, C.accent);
      top += 56;
    }

    const footerY = H - 116;
    const { size, lines } = fitTitle(ctx, texts.title, { maxWidth: W - pad * 2, maxHeight: footerY - top - 40, max: 76, min: 44, lineHeight: 1.12 });
    ctx.fillStyle = C.ink;
    ctx.textBaseline = 'alphabetic';
    drawLines(ctx, lines, pad, top - size * 0.12, size, 1.12);

    ctx.fillStyle = C.line;
    ctx.fillRect(pad, footerY, W - pad * 2, 2);
    drawLogo(ctx, pad, footerY + 30, 48, C.ink);
    if (CONFIG.host) {
      ctx.font = `500 28px ${SANS}`;
      ctx.fillStyle = C.muted;
      ctx.textAlign = 'right';
      ctx.textBaseline = 'middle';
      ctx.fillText(CONFIG.host, W - pad, footerY + 56);
      ctx.textAlign = 'left';
    }
  }

  /** Stories 9:16 — foto em tela cheia, degradê escuro e título em branco. */
  function drawStories(ctx, { width: W, height: H }, { texts, image, focal }) {
    const pad = 80;
    if (image) drawCover(ctx, image, 0, 0, W, H, focal);
    else drawNoImage(ctx, 0, 0, W, H);

    const top = ctx.createLinearGradient(0, 0, 0, 360);
    top.addColorStop(0, 'rgba(10,10,12,0.55)');
    top.addColorStop(1, 'rgba(10,10,12,0)');
    ctx.fillStyle = top;
    ctx.fillRect(0, 0, W, 360);

    const bottom = ctx.createLinearGradient(0, H * 0.38, 0, H);
    bottom.addColorStop(0, 'rgba(10,10,12,0)');
    bottom.addColorStop(0.45, 'rgba(10,10,12,0.78)');
    bottom.addColorStop(1, 'rgba(10,10,12,0.95)');
    ctx.fillStyle = bottom;
    ctx.fillRect(0, H * 0.38, W, H * 0.62);

    // Sombra discreta: mantém logo e título legíveis sobre fotos claras.
    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,0.45)';
    ctx.shadowBlur = 18;
    drawLogo(ctx, pad, 120, 56, '#ffffff');
    ctx.restore();

    // Título ancorado embaixo; editoria logo acima dele.
    const titleBottom = H - 330;
    const { size, lines } = fitTitle(ctx, texts.title, { maxWidth: W - pad * 2, maxHeight: 640, max: 92, min: 56, lineHeight: 1.1 });
    const blockH = lines.length * size * 1.1;
    const titleTop = titleBottom - blockH;

    if (texts.kicker) {
      ctx.fillStyle = C.accent;
      ctx.fillRect(pad, titleTop - 92, 72, 8);
      kickerText(ctx, texts.kicker, pad, titleTop - 34, 34, '#ffffff');
    }

    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,0.35)';
    ctx.shadowBlur = 24;
    ctx.fillStyle = '#ffffff';
    ctx.textBaseline = 'alphabetic';
    drawLines(ctx, lines, pad, titleTop - size * 0.1, size, 1.1);
    ctx.restore();

    if (CONFIG.host) {
      ctx.font = `600 34px ${SANS}`;
      const label = `Leia em ${CONFIG.host}`;
      const w = ctx.measureText(label).width + 64;
      const y = H - 210;
      ctx.fillStyle = 'rgba(255,255,255,0.14)';
      ctx.beginPath();
      ctx.roundRect(pad, y, w, 76, 38);
      ctx.fill();
      ctx.fillStyle = '#ffffff';
      ctx.textBaseline = 'middle';
      ctx.fillText(label, pad + 32, y + 39);
    }
  }

  /* ------------------------------------------------------------------
   * Dados do post
   * ------------------------------------------------------------------ */

  const imageCache = new Map();
  function loadImage(url) {
    if (!url) return Promise.resolve(null);
    if (!imageCache.has(url)) {
      imageCache.set(url, new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous'; // necessário quando as imagens vierem de um CDN/S3
        img.onload = () => resolve(img);
        img.onerror = () => resolve(null);
        img.src = url;
      }));
    }
    return imageCache.get(url);
  }

  function usePostData() {
    return useSelect((select) => {
      const editor = select('core/editor');
      const core = select('core');
      const meta = editor.getEditedPostAttribute('meta') || {};
      const mediaId = editor.getEditedPostAttribute('featured_media');
      const media = mediaId ? core.getMedia(mediaId, { context: 'view' }) : null;
      const category = (editor.getEditedPostAttribute('categories') || [])
        .map((id) => core.getEntityRecord('taxonomy', 'category', id))
        .find((c) => c && !['sem-categoria', 'uncategorized'].includes(c.slug));

      const postTitle = decodeEntities(editor.getEditedPostAttribute('title') || '').trim();
      return {
        slug: editor.getEditedPostAttribute('slug') || 'noticia',
        postTitle,
        customTitle: meta._farol_social_title || '',
        focal: meta._farol_social_focal || { x: 0.5, y: 0.5 },
        imageUrl: media?.source_url || '',
        hasImage: !!mediaId,
        texts: {
          title: (meta._farol_social_title || postTitle || 'Título da notícia').trim(),
          kicker: category ? decodeEntities(category.name) : '',
        },
      };
    }, []);
  }

  /* ------------------------------------------------------------------
   * Componentes
   * ------------------------------------------------------------------ */

  /** A cópia da barra lateral tem id fixo (usado por Baixar/Copiar); a ampliada não. */
  function Artwork({ id, data, className, main = true }) {
    const ref = useRef(null);
    const format = FORMATS[id];
    const key = JSON.stringify([data.texts, data.focal, data.imageUrl]);

    useEffect(() => {
      let cancelled = false;
      const timer = setTimeout(async () => {
        const image = await loadImage(data.imageUrl);
        const canvas = ref.current;
        if (cancelled || !canvas) return;
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, format.width, format.height);
        format.draw(ctx, format, { texts: data.texts, image, focal: data.focal });
      }, 120);
      return () => {
        cancelled = true;
        clearTimeout(timer);
      };
    }, [key]);

    return h('canvas', {
      ref,
      id: main ? `farol-social-${id}` : undefined,
      className: `farol-social__canvas ${className || ''}`,
      width: format.width,
      height: format.height,
      'aria-label': `Arte ${format.label} (${format.ratio})`,
    });
  }

  const toBlob = (canvas) => new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
  const notice = (status, msg) => dispatch('core/notices').createNotice(status, msg, { type: 'snackbar', id: 'farol-social' });

  async function download(id, slug) {
    const blob = await toBlob(document.getElementById(`farol-social-${id}`));
    const url = URL.createObjectURL(blob);
    const a = Object.assign(document.createElement('a'), { href: url, download: `${slug}-${id}.png` });
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  async function copy(id) {
    try {
      const blob = await toBlob(document.getElementById(`farol-social-${id}`));
      await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
      notice('success', `Arte ${FORMATS[id].label} copiada. Cole onde quiser.`);
    } catch (e) {
      notice('error', 'Não foi possível copiar. Use “Baixar PNG”.');
    }
  }

  function Actions({ id, slug, onZoom }) {
    return h('div', { className: 'farol-social__actions' },
      h(Button, { variant: 'primary', size: 'compact', icon: 'download', onClick: () => download(id, slug) }, 'Baixar PNG'),
      h(Button, { variant: 'secondary', size: 'compact', onClick: () => copy(id) }, 'Copiar'),
      onZoom ? h(Button, { variant: 'tertiary', size: 'compact', icon: 'search', label: 'Ampliar', onClick: onZoom }) : null,
    );
  }

  function Sidebar() {
    const data = usePostData();
    const { editPost } = useDispatch('core/editor');
    const [zoom, setZoom] = useState(null);
    const setMeta = (patch) => editPost({ meta: patch });

    return h(Fragment, null,
      h(PluginSidebarMoreMenuItem, { target: SIDEBAR, icon: 'format-image' }, 'Imagens para redes'),
      h(PluginSidebar, { name: SIDEBAR, title: 'Imagens para redes', icon: 'format-image' },
        h(PanelBody, { title: 'Conteúdo da arte', initialOpen: true },
          h(TextareaControl, {
            __nextHasNoMarginBottom: true,
            label: 'Título na imagem',
            help: data.customTitle ? h(Button, { variant: 'link', onClick: () => setMeta({ _farol_social_title: '' }) }, 'Voltar ao título da notícia') : 'Por padrão, o título da notícia. Ajuste aqui sem mudar a matéria.',
            rows: 3,
            value: data.customTitle,
            placeholder: data.postTitle,
            onChange: (v) => setMeta({ _farol_social_title: v }),
          }),
          data.hasImage
            ? h('div', { className: 'farol-social__focal' },
                data.imageUrl
                  ? h(FocalPointPicker, {
                      __nextHasNoMarginBottom: true,
                      label: 'Foco da foto',
                      help: 'Clique no ponto que não pode ser cortado.',
                      url: data.imageUrl,
                      value: data.focal,
                      onChange: (v) => setMeta({ _farol_social_focal: { x: Number(v.x), y: Number(v.y) } }),
                    })
                  : null)
            : h(Notice, { status: 'warning', isDismissible: false, className: 'farol-social__notice' }, 'Defina a imagem destacada do post para ela aparecer na arte.'),
        ),
        Object.entries(FORMATS).map(([id, f]) =>
          h(PanelBody, { key: id, title: `${f.label} · ${f.ratio}`, initialOpen: true },
            h('p', { className: 'farol-social__hint' }, `${f.width}×${f.height} px — ${f.hint}.`),
            h('button', { type: 'button', className: 'farol-social__preview', onClick: () => setZoom(id), 'aria-label': `Ampliar arte ${f.label}` },
              h(Artwork, { id, data })),
            h(Actions, { id, slug: data.slug, onZoom: () => setZoom(id) }),
          ),
        ),
      ),
      zoom ? h(Modal, { title: `${FORMATS[zoom].label} · ${FORMATS[zoom].ratio}`, onRequestClose: () => setZoom(null), className: 'farol-social__modal', size: 'large' },
        h('div', { className: 'farol-social__zoom' }, h(Artwork, { id: zoom, data, className: 'is-zoom', main: false })),
        h(Actions, { id: zoom, slug: data.slug }),
      ) : null,
    );
  }

  registerPlugin('farol-social', { render: Sidebar });
})(window.wp);
