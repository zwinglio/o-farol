#!/usr/bin/env bash
# Deploy do front (Astro + Bun). Roda NA máquina do front, dentro do clone:
#   ./deploy/front.sh
#
# Cada deploy vira uma release em $FRONT_BASE/releases/<data>-<commit>; o build
# acontece antes da troca, o link "current" muda de uma vez e, se o site não
# responder, volta sozinho para a release anterior.
#
# Variáveis opcionais:
#   FRONT_BASE     pasta das releases e do .env (padrão /srv/ofarol-front)
#   FRONT_RESTART  comando (shell) que (re)inicia o app (padrão: sudo systemctl restart ofarol-front)
#                  CloudPanel/pm2: FRONT_RESTART="pm2 startOrRestart ~/o-farol/deploy/pm2.config.cjs && pm2 save"
#   KEEP_RELEASES  (3) · BRANCH (main)
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
BASE="${FRONT_BASE:-/srv/ofarol-front}"
RESTART="${FRONT_RESTART:-sudo systemctl restart ofarol-front}"
KEEP="${KEEP_RELEASES:-3}"
BRANCH="${BRANCH:-main}"
BUN="${BUN:-$(command -v bun || true)}"

say() { printf '\n\033[1;34m→ %s\033[0m\n' "$*"; }

[ -x "$BUN" ] || { echo "Bun não encontrado (instale em /usr/local/bin, ver DEPLOY.md)."; exit 1; }
[ -f "$BASE/.env" ] || { echo "Falta $BASE/.env (copie de front/.env.example e preencha)."; exit 1; }
PORT="$(grep -E '^PORT=' "$BASE/.env" | cut -d= -f2 || true)"; PORT="${PORT:-4321}"

say "Atualizando código ($BRANCH)"
cd "$REPO"
git fetch --quiet origin "$BRANCH"
git merge --ff-only "origin/$BRANCH"
REV="$(git rev-parse --short HEAD)"
RELEASE="$BASE/releases/$(date +%Y%m%d%H%M%S)-$REV"
echo "Versão: $(git log -1 --format='%h %s')"

say "Montando release $(basename "$RELEASE")"
mkdir -p "$RELEASE"
git archive HEAD front | tar -x -C "$RELEASE" --strip-components=1
ln -s "$BASE/.env" "$RELEASE/.env"
cd "$RELEASE"
"$BUN" install --frozen-lockfile
"$BUN" run build

# Release anterior (só se "current" já existir como link: no primeiro deploy não há).
PREVIOUS=""
if [ -L "$BASE/current" ]; then PREVIOUS="$(readlink -f "$BASE/current")"; fi

say "Publicando"
ln -sfn "$RELEASE" "$BASE/current"
bash -c "$RESTART"

say "Teste de saúde"
ok=0
for _ in $(seq 1 15); do
  if curl -fsS -o /dev/null --max-time 5 "http://127.0.0.1:$PORT/"; then ok=1; break; fi
  sleep 1
done
if [ "$ok" != 1 ]; then
  echo "✗ O site não respondeu."
  if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ] && [ "$PREVIOUS" != "$RELEASE" ]; then
    echo "  Voltando para $(basename "$PREVIOUS")."
    ln -sfn "$PREVIOUS" "$BASE/current"
    bash -c "$RESTART"
    rm -rf "$RELEASE"
  else
    echo "  Não há release anterior para voltar; a nova continua em $BASE/current."
  fi
  exit 1
fi
echo "✓ Front no ar ($(basename "$RELEASE"))"

say "Limpando releases antigas (mantém $KEEP)"
ls -1dt "$BASE"/releases/*/ | tail -n +"$((KEEP + 1))" | xargs -r rm -rf
