# O Farol

Blog de notícias independente, assinado por **Samuel Zwinglio** — comunicador e entusiasta de tecnologia. Linha editorial de esquerda, declarada.

Este repositório reúne as duas partes do projeto, que rodam em máquinas separadas:

```
o-farol/
├── wordpress/   CMS headless (WordPress 7.1.3 pt_BR) → VPS do CMS
└── front/       Site público em Astro + Bun (SSR)    → máquina do front
```

## Como as peças conversam

```
 Leitor ──► front (Astro/Bun)  ──── REST API (server-side) ────►  wordpress (PHP + MySQL)
            ofarol.com.br                                         cms.ofarol.com.br
            .env: WP_API_URL                                       .env: DB_*, FAROL_FRONTEND_URL,
                                                                         FAROL_CORS_ORIGINS
 Samuel ──────────────────────────── /wp-admin ─────────────────►  (escreve e publica aqui)
```

- **Samuel escreve no WordPress** (`cms.ofarol.com.br/wp-admin`). O WordPress não renderiza o site: ele só guarda o conteúdo e expõe a REST API (`/wp-json`).
- **O Astro renderiza as páginas no servidor** a cada requisição (SSR), buscando os dados na API. Uma notícia publicada aparece no site na hora, sem rebuild. Um `Cache-Control` curto (`CACHE_MAX_AGE`, padrão 60 s) permite colocar uma CDN na frente sem segurar notícia velha.
- Como o Astro busca os dados **no servidor**, o navegador do leitor nunca chama o CMS. O **CORS** (`FAROL_CORS_ORIGINS`) só importa para chamadas feitas no navegador (busca, contadores etc., no futuro) e já vem fechado numa lista de origens.
- Quem abrir `cms.ofarol.com.br` direto no navegador é redirecionado ao site público; os links "Ver post" do painel também apontam para o front.

## Rodando localmente

Precisa de PHP 8.1+ (com `mysqli`), MariaDB/MySQL rodando e Bun. O `Makefile` da raiz cuida do resto — `make` lista todos os comandos.

```bash
make env     # cria wordpress/.env e front/.env para localhost (chaves geradas na hora)
make db      # cria banco e usuário 'ofarol' — usa `sudo mariadb`; troque com DB_ROOT="mariadb -uroot -p"
make setup   # baixa o WP-CLI, instala o WordPress, ativa o tema, links permanentes, editorias, menus e o front
make seed    # (opcional) notícias de exemplo com imagens — `make unseed` apaga
make dev     # CMS em :8080 e front em :4321, juntos
```

- Painel: <http://localhost:8080/wp-admin> — usuário `samuel`, senha `farol` (mude com `ADMIN_USER`/`ADMIN_PASSWORD` no `make setup`).
- Site: <http://localhost:4321>
- O CMS local roda no servidor embutido do PHP (`php -S`) com `scripts/wp-router.php`, que imita o `.htaccess`.
- WP-CLI à mão: `make wp ARGS="plugin list"`.

## `wordpress/` — o CMS

WordPress oficial em português, mais o código do projeto:

| Caminho | O que é |
|---|---|
| `wp-config.php` | Lê tudo do `.env` (banco, URLs, chaves, integração). Não tem segredo nenhum versionado. |
| `.env.example` | Modelo do `.env`. |
| `wp-content/mu-plugins/farol-core.php` | Núcleo headless (com `farol-core/settings.php` e `farol-core/ai.php`): página Configurações → O Farol, cliente de IA (OpenRouter), CORS restrito, endpoint público de menus (`/wp-json/farol/v1/menus/<posição>`), permalinks apontando para o Astro, redirecionamento do front do WP, campo `farol_reading_time` na API, desliga XML-RPC e esconde dados de usuários. É *must-use*: sempre ativo. |
| `wp-content/themes/farol-headless/` | Tema vazio (o WordPress exige um tema ativo). O `theme.json` deixa o editor de blocos com a mesma tipografia e paleta do front. |
| `.htaccess` | Regras do WordPress + bloqueio de `.env`, logs e dotfiles (Apache). |

### Configurações do app

Ficam em **Configurações → O Farol**, uma página com abas:

| Aba | Vem de | O que tem |
|---|---|---|
| SEO e GEO | plugin `farol-seo` | Identidade, redes, verificação nos buscadores, política para robôs de IA |
| IA | mu-plugin `farol-core` | Chave do OpenRouter e modelo |

**IA (OpenRouter).** Só dois modelos podem ser escolhidos: **GPT-6 Luna** (`openai/gpt-6-luna`, padrão) e **DeepSeek V4.1 Flash** (`deepseek/deepseek-v4.1-flash`). A chave pode ser cadastrada na aba ou na variável `OPENROUTER_API_KEY` do `.env`; a do `.env` tem prioridade e é a recomendada em produção. A chave salva no painel nunca é exibida de volta (só os 4 últimos caracteres) e não é carregada em toda requisição (`autoload` desligado). O botão *Testar conexão* valida a chave e faz uma chamada mínima ao modelo.

No código, os recursos usam `farol_ai_chat( $messages, $options )`, que devolve o texto da resposta ou um `WP_Error`. Para criar uma aba nova, use o filtro `farol_settings_tabs`.

Plugins próprios ficam em `wp-content/plugins/`:

| Plugin | O que faz |
|---|---|
| `farol-seo` | SEO e GEO. Detalhes abaixo. |
| `farol-fontes` | Bloco **Fonte**: cita posts, vídeos e matérias de outros veículos. Detalhes abaixo. |
| `farol-social` | **Imagens para redes**: artes de feed e stories com título, editoria e foto. Detalhes abaixo. |
| `farol-midia` | **Mídia no Cloudflare R2**: imagens e vídeos no bucket, servidos pelo CDN; vídeos enviados direto do navegador. Detalhes abaixo. |
| `farol-newsletter` | **Newsletter**: inscrição com confirmação por e-mail, resumo automático das matérias e descadastro com um clique. Detalhes abaixo. |

### Plugin `farol-midia`: imagens e vídeos no R2

Com as variáveis `R2_*` no `.env` do CMS, cada upload (e todos os tamanhos que o WordPress gera) vai para o bucket, e as URLs passam a apontar para o domínio público dele — na API, no front, no RSS, nas artes para redes e nas imagens do bloco Fonte. Sem as variáveis, nada muda: a mídia fica local.

- **Vídeos:** *Mídia → Enviar vídeo*. O navegador lê duração e dimensões, captura um quadro como capa e envia o arquivo **direto ao R2 em partes de 16 MB** (4 em paralelo, com novas tentativas), sem passar pelo PHP. Até 20 GB. No editor: bloco **Vídeo → Biblioteca de mídia**.
- **Exclusão:** apagar da biblioteca apaga do bucket.
- **Cópia local:** `R2_KEEP_LOCAL=true` mantém os arquivos também no servidor; depois de validar, `false` + `wp farol-midia prune-local`.
- **Comandos:** `wp farol-midia status | test | sync | rewrite-content | prune-local`.
- **Sem SDK:** a assinatura S3 (SigV4) é própria, testada contra os exemplos oficiais da AWS. `R2_ENDPOINT` permite apontar para um S3 local (ex.: MinIO) em testes.

Passo a passo do Cloudflare (bucket, domínio, CORS e token) em [DEPLOY.md](DEPLOY.md), seção 2.7.

### Plugin `farol-newsletter`: newsletter

- **Inscrição:** formulário no rodapé, no fim das matérias e em `/newsletter`. Funciona sem JavaScript: o front recebe o POST e chama o WordPress (`farol-newsletter/v1`). Um campo escondido barra robôs.
- **Confirmação (double opt-in):** a pessoa só entra na lista depois de clicar no link do e-mail (`/newsletter/confirmar`). No máximo um e-mail de confirmação a cada 10 minutos por endereço.
- **Edições:** resumo com as matérias publicadas desde a última edição, diário ou semanal (dia e hora em *Configurações → O Farol → Newsletter*), ou enviado na hora pelo botão. Sem matéria nova, não sai. O envio é em lotes pelo cron (~40 s por execução), retomando de onde parou.
- **Descadastro:** link no fim de cada edição (`/newsletter/sair`, pede um clique para confirmar) e cabeçalho `List-Unsubscribe` de um clique para Gmail e outros.
- **Inscritos:** tabela própria (`wp_farol_newsletter`), exportação em CSV e exclusão definitiva de um e-mail (pedidos de LGPD) na mesma aba.
- **Envio:** `SMTP_*` e `MAIL_FROM*` no `.env` do CMS valem para todos os e-mails do WordPress. Passo a passo em [DEPLOY.md](DEPLOY.md), seção 2.8.

### Plugin `farol-social`: imagens para redes

No editor do post, o ícone de imagem no topo abre o painel **Imagens para redes**, com duas artes desenhadas no próprio navegador:

| Formato | Tamanho | Para |
|---|---|---|
| Feed 4:5 | 1080×1350 | Instagram, Facebook, Threads e X |
| Stories 9:16 | 1080×1920 | Stories do Instagram e status do WhatsApp |

- **Conteúdo:** título da notícia, editoria e imagem destacada, com a marca e o endereço do site. O *Título na imagem* pode ser ajustado só para a arte, sem mexer na matéria. O *Foco da foto* define o ponto que não pode ser cortado. Os dois ajustes ficam salvos no post.
- **Ações:** *Baixar PNG*, *Copiar* (para colar direto) e ampliar.
- **Sem foto destacada:** fundo escuro com o farol.
- **Novo formato:** basta acrescentar em `FORMATS`, em `assets/editor.js`. Descrição e hashtags com IA entram depois, no objeto `texts`.
- **Com a mídia no R2,** o bucket precisa liberar CORS para o domínio do CMS (DEPLOY.md, seção 2.7). Sem isso, o navegador bloqueia exportar o canvas.

### Plugin `farol-fontes`: bloco Fonte

Cole um link sozinho num parágrafo (ou insira o bloco **Fonte**): o servidor busca e **guarda uma cópia**, com texto, autor, data e imagens baixadas para a biblioteca de mídia. Se o original for apagado, a citação continua na matéria.

| Origem | Formato padrão | O que é copiado |
|---|---|---|
| X | Post | Texto, autor, @, data (dia). O X não informa avatar nem mídia: dá para completar à mão |
| Bluesky | Post | Tudo: texto, autor, avatar, imagem, data e hora |
| Mastodon (qualquer instância) | Post | Tudo; retoots citam o post original |
| YouTube, Vimeo | Vídeo | Título, canal, miniatura em alta; Vimeo também a data |
| TikTok | Vídeo | Só o ID e o @ (o TikTok não informa mais título nem miniatura): complete à mão |
| Qualquer site | Cartão | Título, resumo, imagem, veículo, ícone, autor e data (Open Graph e JSON-LD) |

- **Formatos:** Post, Vídeo ou Cartão, trocados pela barra do bloco. A barra também permite **buscar de novo** e **trocar o link**.
- **Cópia editável:** o painel lateral *Cópia da fonte* corrige ou completa título, texto, autor, @, data, veículo e imagem. Se a busca falhar, *Criar cartão e preencher à mão*.
- **Contexto:** legenda opcional embaixo do bloco.
- **Sem scripts de terceiros:** o HTML é estático. Vídeos mostram só a miniatura; o player (YouTube sem cookies, Vimeo com `dnt`, TikTok) carrega quando o leitor clica.
- **SEO e GEO:** as fontes entram em `geo.sources` (plataforma, tipo, autor, data) e no JSON-LD como `citation` (`SocialMediaPosting`, `VideoObject`, `CreativeWork`). No checklist, contam como fontes externas e os posts citados como citações.
- **Instagram e Facebook** ficam de fora por enquanto (exigem app da Meta).

O editor do bloco é React compilado com `@wordpress/scripts`. Depois de mexer em `src/`, rode `make fontes`. A pasta `build/` vai para o git, então o servidor não precisa de Node. O CSS do bloco mora em `front/src/styles/fontes.css`; `make fontes-css` copia para o editor.

### Plugin `farol-seo`: SEO e GEO

**No editor**, o painel *SEO e GEO* (ícone de gráfico no topo, ou o resumo na aba *Post*) mostra dois scores de 0 a 100:

- **SEO**, para Google e buscadores tradicionais: palavra-chave no título, na descrição, no lide e na URL; densidade; tamanho do título e da descrição; tamanho do texto; imagem destacada (≥ 1200 px para o Discover); texto alternativo; links internos; intertítulos; editoria; linha fina.
- **GEO**, para ser citado por buscadores com IA (ChatGPT, Perplexity, Gemini, Claude): resumo em pontos principais; lide direto (≤ 60 palavras); fontes externas linkadas; dados numéricos; citações; legibilidade (Flesch adaptado ao português); tamanho de frases e parágrafos; intertítulos em pergunta; listas/tabelas; autoria com biografia.

Faixas de score: verde ≥ 80, amarelo ≥ 50, vermelho abaixo disso. A análise atualiza enquanto você escreve e aparece também antes de publicar e na lista de posts (colunas SEO/GEO, ordenáveis).

**Automático, mas editável.** Palavra-chave, título SEO, meta descrição, resumo para IA, título, descrição e imagem para redes são gerados do texto. Ao escrever num campo, ele vira *Personalizado*; *Voltar ao automático* desfaz. Também há URL canônica e *noindex* em *Avançado*.

**Gerar com IA.** Com a chave do OpenRouter configurada, cada campo (palavra-chave, título SEO, meta descrição, pontos principais, título e descrição para redes) ganha o botão *✦ Gerar com IA*. No topo do painel, *✦ Gerar SEO e GEO com IA* preenche tudo numa chamada só. A IA lê o texto atual do editor (mesmo sem salvar), segue as mesmas regras do checklist e usa só fatos do texto. O resultado entra como *Personalizado*: dá para editar, voltar ao automático ou desfazer (Ctrl+Z). Antes de sobrescrever campos que você já editou, o painel pede confirmação. Cada geração leva cerca de 4 s e custa frações de centavo.

**Configurações → O Farol → aba SEO e GEO**: descrição do site, logo, imagem social padrão, perfis oficiais, verificação do Google e do Bing, nome no Google Notícias e a política para robôs de IA. Os robôs se dividem em dois grupos: buscadores com IA (citam com link) e treinamento de modelos (sem link).

**O que o front gera a partir disso:**

| Rota | Conteúdo |
|---|---|
| `<head>` das páginas | title, description, robots, canonical, Open Graph, Twitter e JSON-LD (`NewsArticle`, `BreadcrumbList`, `WebSite`, `NewsMediaOrganization`) |
| `/sitemap.xml` | Páginas, editorias e as últimas 1000 notícias |
| `/news-sitemap.xml` | Notícias das últimas 48 h (Google Notícias) |
| `/robots.txt` | Bloqueia os robôs de IA conforme as configurações |
| `/llms.txt` | Mapa do site em Markdown para modelos de linguagem |
| `/rss.xml` | Últimas 30 notícias com texto completo |

`make rescore` recalcula os scores de todos os posts (útil depois de mudar as regras do analisador).

**Na API:**

| Endpoint | Acesso | Retorna |
|---|---|---|
| `GET /wp/v2/posts?_fields=farol_seo` | público | `seo` (título, descrição, canonical, robots, palavra-chave, Open Graph), `geo` (pontos principais, lide, fontes citadas, autoria, datas, editoria, tamanho, citação pronta) e `schema` (JSON-LD) |
| `GET /farol-seo/v1/site` | público | Configurações gerais, política de IA e JSON-LD do site |
| `POST /farol-seo/v1/analyze` | login | Usado pelo painel do editor (scores e checklist ao vivo) |
| `POST /farol-seo/v1/generate` | login | Usado pelo painel do editor (geração com IA) |

Os scores são ferramenta de redação: aparecem só no painel e na lista de posts, nunca na API pública.

### Instalação no VPS

Ver **[DEPLOY.md](DEPLOY.md)**.

## `front/` — o site

Astro 7 com o adapter Node em modo *standalone*, executado com **Bun**.

| Caminho | O que é |
|---|---|
| `src/lib/site.ts` | Nome, slogan e menus de reserva (usados só se o CMS cair). |
| `src/lib/wp.ts` | Cliente da REST API: posts, categorias, imagens, datas; trata erros/timeouts. |
| `src/pages/index.astro` | Capa: manchete + últimas + um bloco por editoria. |
| `src/pages/ultimas/[...rest].astro` | `/ultimas`, `/ultimas/pagina/2`… |
| `src/pages/[categoria]/[...rest].astro` | `/politica`, `/brasil`, `/mundo` (qualquer categoria do WP) e paginação. |
| `src/pages/noticias/[slug].astro` | A notícia, com "Leia também" da mesma editoria. |
| `src/pages/sobre.astro` | Sobre e expediente. |
| `src/pages/contato.astro` | Formulário de contato (ainda sem destino de envio). |
| `src/pages/404.astro`, `500.astro` | Não encontrado / CMS fora do ar. |
| `src/components/Story.astro` | Chamada de notícia em 4 formatos: `lead`, `card`, `row`, `compact`. |
| `src/styles/global.css` | Tokens (cores, fontes), tema claro e o estilo do texto vindo do Gutenberg. |

### Variáveis (`front/.env`)

| Variável | Para quê |
|---|---|
| `PUBLIC_SITE_URL` | URL pública do site (canonical, Open Graph). **Lida no build** — rebuild ao mudar. |
| `WP_API_URL` | Raiz da API do CMS, ex.: `https://cms.ofarol.com.br/wp-json`. Lida em runtime. |
| `WP_API_TIMEOUT_MS` | Tempo máximo esperando o CMS (padrão 8000). |
| `CACHE_MAX_AGE` | `s-maxage` das páginas, em segundos (padrão 60). |
| `HOST`, `PORT` | Onde o servidor escuta (padrão `0.0.0.0:4321`). |

### Desenvolvimento

```bash
cd front
cp .env.example .env     # aponte WP_API_URL para um WordPress acessível
bun install
bun run dev              # http://localhost:4321
```

### Produção

Ver **[DEPLOY.md](DEPLOY.md)**.

## Como a redação usa

- **Editorias** são as categorias do WordPress: Política, Parnamirim, Brasil, Mundo e Tecnologia (`make categories`). Toda categoria ganha página em `/<slug>`.
- **Menus** ficam em *Aparência → Menus*, em duas posições:
  - **Principal**: barra de editorias. Cada *categoria* desse menu vira um bloco na capa, na mesma ordem. Links personalizados (Início, Últimas) aparecem só na barra.
  - **Institucional**: links do topo e do rodapé (Sobre, Contato).
  - Alterações aparecem no site em até 1 minuto. Se o CMS cair, o front usa os menus de reserva de `front/src/lib/site.ts`.
- **Manchete da capa**: no editor, em *Post → Status*, marque **Fixar no topo do blog**. Sem post fixado, a manchete é a notícia mais recente com imagem.
- **Chapéu** = primeira categoria do post. **Linha fina** = o campo *Resumo* (também vira a descrição nas redes). **Imagem destacada** aparece na capa, nas listas e no topo da matéria.
- A data de atualização aparece sozinha quando o post é editado mais de uma hora depois de publicado.

## Próximos passos

- [ ] Pré-visualização de rascunhos no front (Application Passwords).
- [ ] Envio do formulário de contato.
- [ ] Páginas de categoria e autor, RSS e sitemap no front.
- [ ] Webhook de publicação para limpar o cache da CDN.
