#!/usr/bin/env bash
# Atualiza o core do WordPress (pt_BR) dentro de wordpress/, preservando
# wp-config.php, .htaccess e wp-content/ (exceto traduções do core).
# Uso: make wp-core-update   (depois: testar, commitar e fazer deploy)
#
# Segurança: só atualiza para versão MAIOR que a instalada e confere o
# SHA-1 oficial do pacote antes de tocar em qualquer arquivo.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP="$ROOT/wordpress"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

current="$(php -r 'include $argv[1]; echo $wp_version;' "$WP/wp-includes/version.php")"

# offers[] da API: a primeira oferta com locale pt_BR é a versão atual em português.
read -r latest url < <(
  curl -fsS 'https://api.wordpress.org/core/version-check/1.7/?locale=pt_BR' | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    foreach ($d["offers"] ?? [] as $o) {
      if (($o["locale"] ?? "") === "pt_BR") { echo $o["version"], " ", $o["download"], PHP_EOL; exit; }
    }
    exit(1);'
)

echo "Instalado: $current · Mais recente: $latest"
if ! php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$latest" "$current"; then
  echo "Nada a fazer: a versão disponível não é maior que a instalada."
  exit 0
fi

curl -fsSLo "$TMP/wp.zip" "$url"
expected="$(curl -fsS "$url.sha1")"
actual="$(sha1sum "$TMP/wp.zip" | cut -d' ' -f1)"
if [ "$expected" != "$actual" ]; then
  echo "✗ SHA-1 não confere (esperado $expected, obtido $actual). Nada foi alterado."
  exit 1
fi

unzip -q "$TMP/wp.zip" -d "$TMP"
NEW="$TMP/wordpress"
pkg="$(php -r 'include $argv[1]; echo $wp_version;' "$NEW/wp-includes/version.php")"
[ "$pkg" = "$latest" ] || { echo "✗ O pacote baixado é $pkg, não $latest. Nada foi alterado."; exit 1; }

# Core: substitui wp-admin e wp-includes inteiros (remove arquivos que saíram).
rsync -a --delete "$NEW/wp-admin/" "$WP/wp-admin/"
rsync -a --delete "$NEW/wp-includes/" "$WP/wp-includes/"
# Arquivos da raiz (wp-config.php e .htaccess não vêm no pacote).
find "$NEW" -maxdepth 1 -type f -exec cp {} "$WP/" \;
# Traduções do core; plugins e temas do pacote ficam de fora de propósito.
rsync -a "$NEW/wp-content/languages/" "$WP/wp-content/languages/"

echo "✓ WordPress $current → $latest. Rode 'make wp ARGS=\"core verify-checksums\"' e 'make dev', confira e commite."
