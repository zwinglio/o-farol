# Deploy

Duas máquinas, código só via git:

```
 GitHub (zwinglio/o-farol)
   │  git pull (deploy/cms.sh)            │  git pull + build (deploy/front.sh)
   ▼                                      ▼
 VPS do CMS — cms.ofarol.com.br        Máquina do front — ofarol.com.br
 nginx → PHP-FPM → WordPress           nginx → Bun (Astro SSR, 127.0.0.1:4321)
 MariaDB local · cron do WP            releases/ com troca atômica e rollback
```

Os exemplos usam `ofarol.com.br` / `cms.ofarol.com.br`, Ubuntu 24.04, PHP 8.3 e o usuário de sistema `ofarol`. Troque onde for diferente.

> **Regras do jogo:** nada é editado direto no servidor. Em produção o painel do WordPress **não instala nem atualiza** plugins, temas ou o core (`DISALLOW_FILE_MODS`): tudo entra pelo repositório. O `deploy/cms.sh` se recusa a rodar se houver alteração local.

---

## 1. VPS do CMS

### 1.1 Pacotes

```bash
sudo apt update && sudo apt install -y nginx mariadb-server git make unzip rsync curl \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-xml php8.3-mbstring \
  php8.3-intl php8.3-gd php8.3-zip php8.3-opcache certbot python3-certbot-nginx
sudo mysql_secure_installation
```

`php8.3-xml` é obrigatório: o analisador de SEO/GEO usa `DOMDocument`.

Ajustes do PHP em `/etc/php/8.3/fpm/conf.d/99-ofarol.ini`:

```ini
expose_php = Off
memory_limit = 256M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 120
opcache.enable = 1
opcache.validate_timestamps = 1
```

### 1.2 Usuário e código

```bash
sudo adduser --disabled-password --gecos "" ofarol
sudo mkdir -p /srv/ofarol && sudo chown ofarol:www-data /srv/ofarol
sudo -u ofarol git clone https://github.com/zwinglio/o-farol.git /srv/ofarol
# Se o repositório for privado: use uma deploy key (somente leitura) em ~ofarol/.ssh

# O PHP (www-data) lê o código; só escreve em uploads.
sudo chown -R ofarol:www-data /srv/ofarol
sudo chmod -R g+rX,o-rwx /srv/ofarol
sudo install -d -o www-data -g www-data -m 2775 /srv/ofarol/wordpress/wp-content/uploads
```

### 1.3 Banco

```bash
sudo mariadb -e "
  CREATE DATABASE ofarol CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'ofarol'@'localhost' IDENTIFIED BY 'SENHA-FORTE-AQUI';
  GRANT ALL PRIVILEGES ON ofarol.* TO 'ofarol'@'localhost';"
```

### 1.4 `.env`

Fica em `/srv/ofarol/.env`, **fora da pasta pública** (o `wp-config.php` procura um nível acima de `wordpress/`).

```bash
sudo -u ofarol cp /srv/ofarol/wordpress/.env.example /srv/ofarol/.env
sudo chown ofarol:www-data /srv/ofarol/.env && sudo chmod 640 /srv/ofarol/.env
# Gerar cada uma das 8 chaves:
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

| Variável | Produção |
|---|---|
| `WP_ENVIRONMENT_TYPE` | `production` |
| `WP_DEBUG` | `false` |
| `DB_*` | os do passo 1.3 |
| `WP_HOME`, `WP_SITEURL` | `https://cms.ofarol.com.br` |
| `FAROL_FRONTEND_URL` | `https://ofarol.com.br` |
| `FAROL_CORS_ORIGINS` | `https://ofarol.com.br,https://www.ofarol.com.br` |
| `FAROL_TRUST_PROXY` | `false` (nginx com certificado no próprio VPS) |
| `OPENROUTER_API_KEY` | sua chave (recomendado aqui, não no painel) |
| 8 chaves (`AUTH_KEY`…) | geradas acima, entre aspas |

Em produção o WordPress **não sobe** (erro 503) se faltar alguma chave, se alguma tiver menos de 32 caracteres ou se `DB_PASSWORD` estiver vazio.

### 1.5 nginx e HTTPS

```bash
sudo certbot certonly --nginx -d cms.ofarol.com.br
sudo cp /srv/ofarol/deploy/nginx/cms.conf /etc/nginx/sites-available/ofarol-cms
sudo ln -s /etc/nginx/sites-available/ofarol-cms /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

O `deploy/nginx/cms.conf` já:
- bloqueia `.env`, `.git`, logs, `xmlrpc.php` e `readme.html`;
- impede PHP em `uploads/` e chamadas diretas a PHP de plugins e temas;
- esconde `src/` e `node_modules/` dos plugins;
- limita tentativas em `wp-login.php` a 6 por minuto por IP;
- envia HSTS e os cabeçalhos de segurança.

### 1.6 Instalar o WordPress

```bash
sudo -iu ofarol
cd /srv/ofarol
mkdir -p .bin && curl -fsSLo .bin/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
alias wp='php /srv/ofarol/.bin/wp-cli.phar --path=/srv/ofarol/wordpress'

wp core install --url=https://cms.ofarol.com.br --title="O Farol" --locale=pt_BR --skip-email \
  --admin_user=SEU_USUARIO --admin_email=voce@exemplo.com --prompt=admin_password
# A instalação cria "Olá, mundo!" e "Página de exemplo": sem isto, o "Olá, mundo!" vira a manchete do site.
wp post delete $(wp post list --post_type=post,page --post_status=any --format=ids) --force
wp theme activate farol-headless
wp plugin activate farol-seo farol-fontes farol-social
wp rewrite structure '/%postname%/'
wp option update timezone_string America/Sao_Paulo
make categories menus WP_CLI_BUILD=wp-cli
exit
```

- **Não use** `admin` como usuário. Use senha longa e única.
- O nome de exibição (Usuários → Perfil) é a assinatura das matérias.
- Em produção use o WP-CLI estável (`wp-cli.phar`). O nightly do `Makefile` só é necessário no PHP 8.5 da máquina local.

### 1.7 Cron (obrigatório)

Leitores nunca visitam o CMS, então o WP-Cron "por visita" vem desligado em produção (`DISABLE_WP_CRON`). Sem o cron do sistema, **posts agendados não são publicados**.

```bash
sudo crontab -u ofarol -e
```
```cron
*/2 * * * * cd /srv/ofarol && php .bin/wp-cli.phar --path=wordpress cron event run --due-now --quiet
```

### 1.8 Permissão para o deploy recarregar o PHP

```bash
echo 'ofarol ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm' | sudo tee /etc/sudoers.d/ofarol-cms
sudo chmod 440 /etc/sudoers.d/ofarol-cms
```

E rode o deploy com `PHP_FPM_SERVICE=php8.3-fpm ./deploy/cms.sh`. Sem a variável, o script não recarrega o PHP-FPM; o OPcache revalida os arquivos sozinho a cada ~2 s.

### 1.9 Firewall

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

O MariaDB escuta só em `127.0.0.1` (padrão do Ubuntu): não abra a porta 3306.

### 1.10 Primeiro deploy

```bash
sudo -u ofarol PHP_FPM_SERVICE=php8.3-fpm /srv/ofarol/deploy/cms.sh
```

### 1.11 Variante: CloudPanel

Com o CloudPanel, os passos 1.1, 1.5, 1.8 e 1.9 são feitos pelo painel. O resto muda assim:

| Item | No CloudPanel |
|---|---|
| Site | *Add Site → Create a PHP Site*, PHP 8.3+ (testado até 8.5) |
| Usuário | O usuário do site (ex.: `cms-farol`); ele não tem `sudo` |
| Código | Clone em `~/htdocs/<domínio>` (ver abaixo) |
| Pasta pública | *Settings → Root Directory*: `htdocs/<domínio>/wordpress` |
| `.env` | `~/htdocs/<domínio>/.env`, fora da pasta pública |
| Banco | Aba *Databases* do site |
| HTTPS | Aba *SSL/TLS → Let's Encrypt* |
| Cron | Aba *Cron Jobs* do site (comando do passo 1.7, com `php8.5` se for o caso) |
| nginx | Aba *Vhost* (ver regras abaixo) |
| Deploy | `PHP=php8.5 ./deploy/cms.sh` (com o binário do PHP da versão do site) |

Clonando só o necessário (a pasta `front/` fica de fora):

```bash
cd ~/htdocs/<domínio>          # precisa estar vazia
git clone --filter=blob:none --no-checkout https://github.com/zwinglio/o-farol.git .
git sparse-checkout set --cone wordpress deploy scripts
git checkout main
```

Regras para colar no *Vhost*, dentro do bloco `server { … }` que atende o 443, antes do `location /`:

```nginx
location ~ /\.(?!well-known) { deny all; }
location ~* \.(log|sql|sh|bak|md)$ { deny all; }
location ~* ^/(wp-config\.php|wp-config-sample\.php|readme\.html|license\.txt)$ { deny all; }
location = /xmlrpc.php { deny all; }
location ~* ^/wp-content/uploads/.*\.(php|phtml|phar)$ { deny all; }
location ~* ^/wp-content/plugins/[^/]+/(src|node_modules)/ { deny all; }
location ~* ^/wp-content/plugins/[^/]+/(package\.json|bun\.lock)$ { deny all; }
```

O limite de tentativas de login do `deploy/nginx/cms.conf` exige acesso root ao nginx. No CloudPanel, use o *Basic Auth* do painel ou um plugin de limite de login.

---

## 2. Máquina do front

### 2.1 Pacotes e Bun

```bash
sudo apt update && sudo apt install -y nginx git curl unzip certbot python3-certbot-nginx
# Bun em /usr/local (o serviço roda com ProtectHome e não enxerga ~/.bun)
curl -fsSL https://bun.sh/install | sudo BUN_INSTALL=/usr/local bash
bun --version
```

### 2.2 Usuário, código e `.env`

```bash
sudo adduser --disabled-password --gecos "" ofarol
sudo mkdir -p /srv/ofarol /srv/ofarol-front/releases
sudo chown -R ofarol:ofarol /srv/ofarol /srv/ofarol-front
sudo -u ofarol git clone https://github.com/zwinglio/o-farol.git /srv/ofarol
sudo -u ofarol cp /srv/ofarol/front/.env.example /srv/ofarol-front/.env
sudo chmod 600 /srv/ofarol-front/.env
```

| Variável | Produção |
|---|---|
| `PUBLIC_SITE_URL` | `https://ofarol.com.br` (lido **no build**; também define o domínio confiável atrás do proxy) |
| `WP_API_URL` | `https://cms.ofarol.com.br/wp-json` |
| `HOST` / `PORT` | `127.0.0.1` / `4321` |
| `CACHE_MAX_AGE` | `60` |

### 2.3 Serviço

```bash
sudo cp /srv/ofarol/deploy/systemd/ofarol-front.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable ofarol-front
echo 'ofarol ALL=(root) NOPASSWD: /usr/bin/systemctl restart ofarol-front' | sudo tee /etc/sudoers.d/ofarol-front
sudo chmod 440 /etc/sudoers.d/ofarol-front
```

### 2.4 nginx e HTTPS

```bash
sudo certbot certonly --nginx -d ofarol.com.br -d www.ofarol.com.br
sudo cp /srv/ofarol/deploy/nginx/front.conf /etc/nginx/sites-available/ofarol-front
sudo ln -s /etc/nginx/sites-available/ofarol-front /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

O nginx repassa `Host`, `X-Forwarded-Host` e `X-Forwarded-Proto`. O Astro só confia neles para o domínio de `PUBLIC_SITE_URL`; se o domínio não bater, o formulário de contato responde 403.

### 2.5 Primeiro deploy

```bash
sudo -u ofarol /srv/ofarol/deploy/front.sh
```

### 2.6 Variante: CloudPanel

O front pode ficar no mesmo servidor do CMS, como **outro site** (outro usuário de sistema, isolado do CMS).

1. **Add Site → Create a Node.js Site.** Domínio `farol.zwinglio.com`, qualquer Node.js LTS (só serve para o pm2) e *App Port* `3000`. Anote o usuário do site (ex.: `farol`). Depois, aba *SSL/TLS → Let's Encrypt*.
2. **Como o usuário do site** (`ssh farol@servidor`):

```bash
# Bun e pm2
curl -fsSL https://bun.sh/install | bash && source ~/.bashrc
npm install -g pm2

# Código: só front/ e deploy/
git clone --filter=blob:none --no-checkout https://github.com/zwinglio/o-farol.git ~/o-farol
cd ~/o-farol && git sparse-checkout set --cone front deploy && git checkout main

# Releases e .env FORA de htdocs
mkdir -p ~/front/releases
cp front/.env.example ~/front/.env && chmod 600 ~/front/.env
nano ~/front/.env    # PUBLIC_SITE_URL, WP_API_URL, HOST=127.0.0.1, PORT=3000 (a App Port)

# Primeira release (falha no fim por não haver app para reiniciar ainda; tudo bem)
FRONT_BASE=~/front FRONT_RESTART=true ./deploy/front.sh || true

# Sobe o app no pm2 e grava a lista
pm2 start ~/front/current/dist/server/entry.mjs --name ofarol-front \
  --interpreter ~/.bun/bin/bun --interpreter-args "--env-file=$HOME/front/.env"
pm2 save
```

3. **Aba *Cron Jobs*** do site do front: `@reboot` com o comando `$(which pm2) resurrect` (cole o caminho completo que o `which pm2` mostrar). Assim o app volta sozinho depois de um reboot.
4. **Deploys seguintes:**

```bash
cd ~/o-farol && FRONT_BASE=~/front FRONT_RESTART="pm2 restart ofarol-front" ./deploy/front.sh
```

---

## 3. Depois do primeiro deploy

- [ ] **Configurações → O Farol → SEO e GEO**: descrição, logo, imagem social padrão, perfis oficiais, política para robôs de IA.
- [ ] **Usuários → Perfil**: nome de exibição e biografia (pesa no score GEO).
- [ ] **Aparência → Menus**: conferir *Principal* e *Institucional*.
- [ ] **Google Search Console** e **Bing Webmaster**: verificar o domínio e enviar `https://ofarol.com.br/sitemap.xml` e `/news-sitemap.xml`.
- [ ] Publicar um post de teste **agendado** para daqui a 5 minutos e confirmar que ele sai (testa o cron).
- [ ] A primeira notícia publicada vira a manchete; enquanto houver menos de 3 outras, a capa mostra o bloco "Sobre O Farol" ao lado. Use *Fixar no topo do blog* para escolher a manchete.
- [ ] Conferir:
  ```bash
  curl -sI https://ofarol.com.br | grep -iE 'strict|content-security'
  curl -s https://ofarol.com.br/robots.txt
  curl -s -o /dev/null -w '%{http_code}\n' https://cms.ofarol.com.br/.env   # 403 ou 404
  curl -s -o /dev/null -w '%{http_code}\n' https://cms.ofarol.com.br/xmlrpc.php  # 403
  ```

---

## 4. Dia a dia

| Mudou… | Rode |
|---|---|
| Plugins, tema, `wp-config.php` | `./deploy/cms.sh` no VPS do CMS |
| Front | `./deploy/front.sh` na máquina do front |
| Os dois | primeiro o CMS, depois o front |

- **Atualizar o WordPress:** na sua máquina, `make wp-core-update`, depois `make dev` para conferir, commit e deploy do CMS. O script baixa o pacote oficial em pt_BR, confere o SHA-1 e nunca rebaixa a versão. Acompanhe os lançamentos de segurança em wordpress.org/news.
- **Plugin de terceiros:** coloque a pasta em `wordpress/wp-content/plugins/`, commit, deploy e ative com `make wp ARGS="plugin activate …"` no servidor.
- **Bloco Fonte:** depois de mexer em `farol-fontes/src/`, rode `make fontes` e commite o `build/`.
- **Voltar o front:**
  ```bash
  ls -t /srv/ofarol-front/releases
  ln -sfn /srv/ofarol-front/releases/<anterior> /srv/ofarol-front/current
  sudo systemctl restart ofarol-front
  ```
  O `deploy/front.sh` já faz isso sozinho se a release nova não responder. No CloudPanel, troque o `systemctl restart` por `pm2 restart ofarol-front`.
- **Voltar o CMS:** `git revert` do commit problemático, push e `./deploy/cms.sh`.

---

## 5. Backup

No VPS do CMS, em `crontab -u ofarol -e`:

```cron
# 03:30 — banco e uploads, 14 dias de histórico
30 3 * * * mkdir -p ~/backups && cd /srv/ofarol && php .bin/wp-cli.phar --path=wordpress db export - --quiet | gzip > ~/backups/db-$(date +\%F).sql.gz && tar -czf ~/backups/uploads-$(date +\%F).tar.gz -C wordpress/wp-content uploads && find ~/backups -mtime +14 -delete
```

Copie `~/backups` para fora do VPS, por exemplo com `rclone` para um bucket. Quando o S3 entrar, este passo muda. Teste a restauração pelo menos uma vez.

---

## 6. Segurança

**Já está no código:**

| Camada | Proteção |
|---|---|
| Configuração | Segredos só no `.env`, fora da pasta pública; produção recusa chaves fracas ou ausentes; `X-Forwarded-Proto` só com `FAROL_TRUST_PROXY` |
| WordPress | Editor de arquivos e instalação de plugins desligados; XML-RPC, pingbacks e sitemaps do core desligados; erro de login genérico |
| Usuários | Login não vaza: `?author=N` redireciona antes do core; a API pública mostra só o nome (sem slug, link, bio nem hash de Gravatar); oEmbed sem autor |
| API | CORS restrito às origens do `.env`; endpoints de análise, IA e fontes exigem permissão de edição; `farol_seo` em cache (o endpoint público não vira vetor de carga) |
| Fontes | Downloads via `wp_safe_remote_get` (bloqueia IPs internos/SSRF), máximo de 10 MB por imagem; player só de YouTube, Vimeo e TikTok |
| Front | CSP, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `frame-ancestors 'none'`; checagem de origem em formulários; escuta só em `127.0.0.1` |
| nginx | HSTS, bloqueio de arquivos sensíveis e de PHP em uploads, limite de tentativas no login |
| systemd | Front sem privilégios, com sistema de arquivos somente leitura fora de `/srv/ofarol-front` |

**Recomendado configurar nos servidores:**

- [ ] SSH só por chave (`PasswordAuthentication no`) e sem login de root.
- [ ] `sudo apt install unattended-upgrades fail2ban` (atualizações de segurança do sistema e bloqueio de força bruta no SSH).
- [ ] Autenticação em dois fatores no WordPress: adicionar o plugin oficial [Two Factor](https://wordpress.org/plugins/two-factor/) ao repositório.
- [ ] Limite de gasto na chave do OpenRouter.
- [ ] Opcional: restringir `/wp-admin` e `/wp-login.php` por IP ou VPN no nginx, se você sempre publica de lugares conhecidos.

---

## 7. Problemas comuns

| Sintoma | Causa provável |
|---|---|
| CMS responde 503 "Configuração incompleta" | Chave ausente, curta ou `DB_PASSWORD` vazio no `.env`; o motivo exato vai para o log do PHP-FPM |
| Front mostra "Estamos com instabilidade" | Front não alcança `WP_API_URL`. Teste da máquina do front: `curl https://cms.ofarol.com.br/wp-json/wp/v2/posts?per_page=1` |
| Formulário de contato dá 403 | `PUBLIC_SITE_URL` diferente do domínio acessado (rebuild após mudar) |
| Post agendado não sai | Cron do passo 1.7 ausente |
| Painel mostra a versão antiga de um plugin após deploy | OPcache: espere alguns segundos ou `sudo systemctl reload php8.3-fpm` (no CloudPanel, *PHP Settings → Restart*) |
| Imagens para redes deixam de exportar após o S3 | O bucket precisa de CORS liberando `https://cms.ofarol.com.br` |
| `deploy/cms.sh` diz "Há alterações locais" | Alguém editou arquivos no servidor: `git diff` para ver e `git checkout -- .` para descartar |
