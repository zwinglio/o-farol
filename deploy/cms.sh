#!/usr/bin/env bash
# Deploy do CMS (WordPress). Roda NO VPS do CMS, dentro do clone do repositório:
#   ./deploy/cms.sh
# Variáveis opcionais: PHP_FPM_SERVICE (padrão php8.3-fpm), BRANCH (padrão main).
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
BRANCH="${BRANCH:-main}"
WP_CLI="$REPO/.bin/wp-cli.phar"
WP=(php "$WP_CLI" --path="$REPO/wordpress" --quiet)

say() { printf '\n\033[1;34m→ %s\033[0m\n' "$*"; }
cd "$REPO"

[ -f "$REPO/.env" ] || { echo "Falta $REPO/.env (copie de wordpress/.env.example e preencha)."; exit 1; }
[ -n "$(git status --porcelain --untracked-files=no)" ] && { echo "Há alterações locais no servidor; o deploy só aceita código vindo do git."; git status --short; exit 1; }

say "Atualizando código ($BRANCH)"
git fetch --quiet origin "$BRANCH"
git merge --ff-only "origin/$BRANCH"
echo "Versão: $(git log -1 --format='%h %s')"

say "Checando sintaxe do PHP"
make --no-print-directory lint

if [ ! -f "$WP_CLI" ]; then
  say "Baixando WP-CLI"
  mkdir -p "$(dirname "$WP_CLI")"
  curl -fsSLo "$WP_CLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi

say "Conferindo integridade do core"
"${WP[@]}" core verify-checksums

say "Atualizando banco e plugins"
"${WP[@]}" core update-db
"${WP[@]}" plugin activate farol-seo farol-fontes farol-social
"${WP[@]}" cache flush

say "Recarregando PHP-FPM (limpa o OPcache)"
sudo systemctl reload "$PHP_FPM_SERVICE"

say "Teste de saúde"
HOME_URL="$(grep -E '^WP_HOME=' "$REPO/.env" | cut -d= -f2- | tr -d '"'"'")"
if curl -fsS -o /dev/null --max-time 15 "$HOME_URL/wp-json/"; then
  echo "✓ API respondendo em $HOME_URL"
else
  echo "✗ $HOME_URL/wp-json/ não respondeu — confira o nginx e o PHP-FPM."
  exit 1
fi
