/**
 * Mídia → Enviar vídeo.
 *
 * 1. Lê duração/dimensões e captura um quadro como capa (no navegador).
 * 2. POST /farol-midia/v1/video/start → URLs pré-assinadas, uma por parte.
 * 3. Envia as partes direto ao R2 (4 em paralelo, até 3 tentativas cada).
 * 4. Envia a capa pela biblioteca normal e conclui (/video/complete).
 */
(function (wp, config) {
  const root = document.getElementById('farol-video-app');
  if (!root) return;

  const CONCURRENCY = 4;
  const RETRIES = 3;
  const el = (tag, attrs = {}, ...children) => {
    const node = Object.assign(document.createElement(tag), attrs);
    children.flat().forEach((c) => c != null && node.append(c));
    return node;
  };
  const human = (bytes) => {
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
    return `${bytes.toFixed(i ? 1 : 0)} ${u[i]}`;
  };

  /* ---------- Interface ---------- */
  const input = el('input', { type: 'file', accept: config.types.join(','), hidden: true });
  const drop = el('label', { className: 'farol-video__drop', tabIndex: 0 },
    el('strong', {}, 'Arraste o vídeo aqui ou clique para escolher'),
    el('span', {}, `MP4, WebM ou MOV — até ${human(config.maxBytes)}`),
    input);
  const preview = el('div', { className: 'farol-video__preview', hidden: true });
  const title = el('input', { type: 'text', className: 'regular-text', placeholder: 'Título do vídeo (opcional)' });
  const sendBtn = el('button', { type: 'button', className: 'button button-primary', textContent: 'Enviar vídeo', disabled: true });
  const cancelBtn = el('button', { type: 'button', className: 'button', textContent: 'Cancelar', hidden: true });
  const bar = el('progress', { max: 100, value: 0, hidden: true });
  const status = el('p', { className: 'farol-video__status', role: 'status' });
  const result = el('div', { className: 'farol-video__result' });

  root.append(drop, preview,
    el('p', {}, el('label', {}, 'Título ', title)),
    el('p', { className: 'farol-video__actions' }, sendBtn, cancelBtn),
    bar, status, result);

  let file = null;
  let meta = { width: 0, height: 0, duration: 0 };
  let posterBlob = null;
  let state = null; // upload em andamento

  const say = (text, kind = '') => { status.textContent = text; status.dataset.kind = kind; };

  /* ---------- Escolha do arquivo ---------- */
  input.addEventListener('change', () => input.files[0] && choose(input.files[0]));
  ['dragover', 'dragenter'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
  drop.addEventListener('drop', (e) => { e.preventDefault(); e.dataTransfer.files[0] && choose(e.dataTransfer.files[0]); });
  drop.addEventListener('keydown', (e) => (e.key === 'Enter' || e.key === ' ') && input.click());

  async function choose(f) {
    result.replaceChildren();
    if (!config.types.includes(f.type)) return say(`Formato não aceito (${f.type || 'desconhecido'}). Use MP4, WebM ou MOV.`, 'error');
    if (f.size > config.maxBytes) return say(`Arquivo grande demais (${human(f.size)}).`, 'error');
    file = f;
    title.value = title.value || f.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ');
    say('Lendo o vídeo…');
    try {
      ({ meta, posterBlob } = await inspect(f));
    } catch (e) {
      meta = { width: 0, height: 0, duration: 0 };
      posterBlob = null;
    }
    const mins = Math.floor(meta.duration / 60);
    const secs = String(Math.round(meta.duration % 60)).padStart(2, '0');
    preview.replaceChildren(
      posterBlob ? el('img', { src: URL.createObjectURL(posterBlob), alt: 'Capa capturada do vídeo' }) : '',
      el('div', {},
        el('strong', {}, f.name),
        el('span', {}, [human(f.size), meta.duration ? `${mins}:${secs}` : '', meta.width ? `${meta.width}×${meta.height}` : ''].filter(Boolean).join(' · ')),
        el('span', {}, posterBlob ? 'Capa: quadro capturado do próprio vídeo.' : 'Sem capa (o navegador não conseguiu ler o vídeo).')));
    preview.hidden = false;
    sendBtn.disabled = false;
    say('Pronto para enviar.');
  }

  /** Duração, dimensões e um quadro (10% do vídeo, no máx. 3 s) como JPEG. */
  function inspect(f) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(f);
      const video = el('video', { muted: true, preload: 'metadata', src: url });
      video.playsInline = true;
      const fail = () => { URL.revokeObjectURL(url); reject(new Error('leitura')); };
      video.onerror = fail;
      video.onloadedmetadata = () => { video.currentTime = Math.min(3, (video.duration || 0) * 0.1); };
      video.onseeked = () => {
        const scale = Math.min(1, 1280 / (video.videoWidth || 1280));
        const canvas = el('canvas', { width: Math.round(video.videoWidth * scale), height: Math.round(video.videoHeight * scale) });
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
          URL.revokeObjectURL(url);
          resolve({ meta: { width: video.videoWidth, height: video.videoHeight, duration: video.duration || 0 }, posterBlob: blob });
        }, 'image/jpeg', 0.85);
      };
      setTimeout(fail, 15000);
    });
  }

  /* ---------- Envio ---------- */
  function putPart(url, blob, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      state.xhrs.add(xhr);
      xhr.open('PUT', url);
      xhr.upload.onprogress = (e) => onProgress(e.loaded);
      xhr.onload = () => {
        state.xhrs.delete(xhr);
        const etag = xhr.getResponseHeader('ETag');
        if (xhr.status >= 200 && xhr.status < 300 && etag) resolve(etag);
        else if (xhr.status >= 200 && xhr.status < 300) reject(new Error('O R2 não expôs o cabeçalho ETag: confira a política de CORS do bucket (ExposeHeaders: ETag).'));
        else reject(new Error(`HTTP ${xhr.status}`));
      };
      xhr.onerror = () => { state.xhrs.delete(xhr); reject(new Error('falha de rede (ou CORS do bucket não libera PUT deste domínio)')); };
      xhr.onabort = () => { state.xhrs.delete(xhr); reject(new Error('cancelado')); };
      xhr.send(blob);
    });
  }

  async function uploadParts(start) {
    const loaded = new Array(start.urls.length).fill(0);
    const parts = [];
    const begun = Date.now();
    const tick = () => {
      const done = loaded.reduce((a, b) => a + b, 0);
      const pct = Math.min(100, (done / file.size) * 100);
      const speed = done / Math.max(1, (Date.now() - begun) / 1000);
      bar.value = pct;
      say(`Enviando… ${pct.toFixed(0)}% (${human(done)} de ${human(file.size)}, ${human(speed)}/s)`);
    };

    let next = 0;
    async function worker() {
      while (next < start.urls.length && !state.cancelled) {
        const i = next++;
        const blob = file.slice(i * start.partSize, (i + 1) * start.partSize);
        for (let attempt = 1; ; attempt++) {
          try {
            const etag = await putPart(start.urls[i], blob, (n) => { loaded[i] = n; tick(); });
            parts.push({ number: i + 1, etag });
            break;
          } catch (e) {
            if (state.cancelled || attempt >= RETRIES || /ETag|CORS/.test(e.message)) throw e;
            loaded[i] = 0;
            await new Promise((r) => setTimeout(r, 1000 * attempt));
          }
        }
      }
    }
    await Promise.all(Array.from({ length: Math.min(CONCURRENCY, start.urls.length) }, worker));
    return parts;
  }

  async function uploadPoster() {
    if (!posterBlob) return 0;
    const form = new FormData();
    form.append('file', posterBlob, `${(title.value || 'video').slice(0, 60)}-capa.jpg`);
    form.append('title', `Capa: ${title.value || file.name}`);
    const media = await wp.apiFetch({ path: '/wp/v2/media', method: 'POST', body: form });
    return media.id;
  }

  sendBtn.addEventListener('click', async () => {
    if (!file) return;
    sendBtn.disabled = true;
    cancelBtn.hidden = false;
    bar.hidden = false;
    bar.value = 0;
    result.replaceChildren();
    state = { xhrs: new Set(), cancelled: false, uploadId: null };
    window.addEventListener('beforeunload', warn);
    try {
      say('Preparando…');
      const start = await wp.apiFetch({ path: '/farol-midia/v1/video/start', method: 'POST', data: { name: file.name, type: file.type, size: file.size } });
      state.uploadId = start.uploadId;
      const parts = await uploadParts(start);
      if (state.cancelled) return;
      say('Finalizando…');
      const poster = await uploadPoster().catch(() => 0);
      const att = await wp.apiFetch({
        path: '/farol-midia/v1/video/complete',
        method: 'POST',
        data: { uploadId: start.uploadId, parts, title: title.value, width: meta.width, height: meta.height, duration: meta.duration, poster },
      });
      bar.value = 100;
      say('Vídeo enviado.', 'success');
      showResult(att);
      file = null;
      preview.hidden = true;
      title.value = '';
    } catch (e) {
      if (!state.cancelled) {
        say(`Não foi possível enviar: ${e.message || e}`, 'error');
        if (state.uploadId) wp.apiFetch({ path: '/farol-midia/v1/video/abort', method: 'POST', data: { uploadId: state.uploadId } }).catch(() => {});
      }
      sendBtn.disabled = !file;
    } finally {
      cancelBtn.hidden = true;
      window.removeEventListener('beforeunload', warn);
    }
  });

  cancelBtn.addEventListener('click', async () => {
    if (!state) return;
    state.cancelled = true;
    state.xhrs.forEach((x) => x.abort());
    if (state.uploadId) await wp.apiFetch({ path: '/farol-midia/v1/video/abort', method: 'POST', data: { uploadId: state.uploadId } }).catch(() => {});
    say('Envio cancelado.');
    bar.hidden = true;
    sendBtn.disabled = !file;
  });

  function warn(e) { e.preventDefault(); e.returnValue = ''; }

  function showResult(att) {
    const copy = el('button', { type: 'button', className: 'button', textContent: 'Copiar endereço' });
    copy.addEventListener('click', () => navigator.clipboard.writeText(att.url).then(() => { copy.textContent = 'Copiado'; }));
    result.replaceChildren(
      el('video', { src: att.url, controls: true, preload: 'metadata', className: 'farol-video__player' }),
      el('p', {},
        el('a', { className: 'button button-primary', href: att.editLink, textContent: 'Abrir na biblioteca' }), ' ', copy),
      el('p', { className: 'description' }, 'No editor: bloco Vídeo → Biblioteca de mídia → escolha este vídeo.'));
  }
})(window.wp, window.farolVideo);
