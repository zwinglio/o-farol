# O Farol — tarefas de desenvolvimento local (PHP + MariaDB/MySQL + Bun).
# `make` sozinho lista os comandos.

SHELL := /bin/bash
.DEFAULT_GOAL := help

WP_DIR    := wordpress
FRONT_DIR := front
CMS_HOST  ?= localhost
CMS_PORT  ?= 8080
FRONT_PORT?= 4321

PHP     ?= php
BUN     ?= bun
# Cliente com permissão para criar bancos/usuários. No MariaDB do Ubuntu/Debian
# o root entra por socket, daí o sudo. Ex.: make db DB_ROOT="mariadb -uroot -p"
DB_ROOT ?= sudo mariadb

# O WP-CLI estável (2.12) ainda despeja avisos de "Deprecated" no PHP 8.5;
# o nightly já roda limpo. Troque por WP_CLI_BUILD=wp-cli quando sair a 3.0.
WP_CLI_BUILD ?= wp-cli-nightly
WP_CLI_PHAR  := .bin/$(WP_CLI_BUILD).phar
WP           := $(PHP) $(WP_CLI_PHAR) --path=$(WP_DIR)

# Dados do admin local (só para o `make setup`)
ADMIN_USER     ?= samuel
ADMIN_PASSWORD ?= farol
ADMIN_EMAIL    ?= admin@ofarol.local

# Lê uma chave do .env do CMS (sem `include`, que faria o make tentar gerá-lo).
env_get = $(shell sed -n 's/^$(1)=//p' $(WP_DIR)/.env 2>/dev/null | sed -e 's/[[:space:]]\{1,\}\#.*//' -e 's/^"\(.*\)"$$/\1/' | tail -n1)
DB_NAME     ?= $(or $(call env_get,DB_NAME),ofarol)
DB_USER     ?= $(or $(call env_get,DB_USER),ofarol)
DB_PASSWORD ?= $(or $(call env_get,DB_PASSWORD),ofarol)

.PHONY: help
help: ## Lista os comandos
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------------
#  Primeira vez: make env → make db → make setup → make dev
# ---------------------------------------------------------------------

.PHONY: env
env: $(WP_DIR)/.env $(FRONT_DIR)/.env ## Cria os .env locais (não sobrescreve)

$(WP_DIR)/.env:
	@sed -e 's#^WP_ENVIRONMENT_TYPE=.*#WP_ENVIRONMENT_TYPE=local#' \
	     -e 's#^WP_DEBUG=.*#WP_DEBUG=true#' \
	     -e 's#^DB_PASSWORD=.*#DB_PASSWORD=ofarol#' \
	     -e 's#^DB_HOST=.*#DB_HOST=127.0.0.1#' \
	     -e 's#^WP_HOME=.*#WP_HOME=http://$(CMS_HOST):$(CMS_PORT)#' \
	     -e 's#^WP_SITEURL=.*#WP_SITEURL=http://$(CMS_HOST):$(CMS_PORT)#' \
	     -e 's#^FAROL_FRONTEND_URL=.*#FAROL_FRONTEND_URL=http://localhost:$(FRONT_PORT)#' \
	     -e 's#^FAROL_CORS_ORIGINS=.*#FAROL_CORS_ORIGINS=http://localhost:$(FRONT_PORT)#' \
	     $(WP_DIR)/.env.example > $@
	@$(PHP) scripts/salts.php $@
	@echo "✓ $@ criado"

$(FRONT_DIR)/.env:
	@sed -e 's#^PUBLIC_SITE_URL=.*#PUBLIC_SITE_URL=http://localhost:$(FRONT_PORT)#' \
	     -e 's#^WP_API_URL=.*#WP_API_URL=http://$(CMS_HOST):$(CMS_PORT)/wp-json#' \
	     -e 's#^CACHE_MAX_AGE=.*#CACHE_MAX_AGE=0#' \
	     -e 's#^HOST=.*#HOST=localhost#' \
	     -e 's#^PORT=.*#PORT=$(FRONT_PORT)#' \
	     $(FRONT_DIR)/.env.example > $@
	@echo "✓ $@ criado"

.PHONY: db
db: ## Cria banco e usuário no MariaDB/MySQL local (usa DB_ROOT)
	@echo "→ Criando banco '$(DB_NAME)' e usuário '$(DB_USER)' com: $(DB_ROOT)"
	@$(DB_ROOT) -e "\
		CREATE DATABASE IF NOT EXISTS \`$(DB_NAME)\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
		CREATE USER IF NOT EXISTS '$(DB_USER)'@'localhost' IDENTIFIED BY '$(DB_PASSWORD)'; \
		CREATE USER IF NOT EXISTS '$(DB_USER)'@'127.0.0.1' IDENTIFIED BY '$(DB_PASSWORD)'; \
		GRANT ALL PRIVILEGES ON \`$(DB_NAME)\`.* TO '$(DB_USER)'@'localhost'; \
		GRANT ALL PRIVILEGES ON \`$(DB_NAME)\`.* TO '$(DB_USER)'@'127.0.0.1'; \
		FLUSH PRIVILEGES;"
	@echo "✓ Banco pronto"

$(WP_CLI_PHAR):
	@mkdir -p $(dir $@)
	@echo "→ Baixando WP-CLI"
	@curl -fsSLo $@ https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/$(WP_CLI_BUILD).phar
	@$(PHP) $@ --version

.PHONY: setup
setup: env $(WP_CLI_PHAR) install ## Instala o WordPress local e o front (após `make db`)
	@if $(WP) core is-installed 2>/dev/null; then \
		echo "• WordPress já instalado"; \
	else \
		$(WP) core install --url=http://$(CMS_HOST):$(CMS_PORT) --title="O Farol" \
			--admin_user=$(ADMIN_USER) --admin_password=$(ADMIN_PASSWORD) \
			--admin_email=$(ADMIN_EMAIL) --locale=pt_BR --skip-email; \
	fi
	@$(WP) theme activate farol-headless
	@$(WP) plugin activate farol-seo farol-fontes farol-social farol-midia farol-newsletter --quiet
	@$(WP) rewrite structure '/%postname%/' --quiet
	@$(WP) option update timezone_string America/Sao_Paulo --quiet
	@$(WP) user update $(ADMIN_USER) --display_name="Samuel Zwinglio" --first_name=Samuel --last_name=Zwinglio --quiet
	@$(MAKE) --no-print-directory menus
	@echo "✓ Pronto. Painel: http://$(CMS_HOST):$(CMS_PORT)/wp-admin ($(ADMIN_USER) / $(ADMIN_PASSWORD))"

.PHONY: install
install: ## Instala as dependências do front
	@cd $(FRONT_DIR) && $(BUN) install

# ---------------------------------------------------------------------
#  Dia a dia
# ---------------------------------------------------------------------

.PHONY: dev
dev: ## Sobe CMS e front juntos (Ctrl+C encerra os dois)
	@$(MAKE) --no-print-directory -j2 cms front

.PHONY: cms
cms: ## Sobe só o WordPress (php -S, porta 8080)
	@echo "→ CMS em http://$(CMS_HOST):$(CMS_PORT)/wp-admin"
	@PHP_CLI_SERVER_WORKERS=4 $(PHP) -S $(CMS_HOST):$(CMS_PORT) -t $(WP_DIR) scripts/wp-router.php

.PHONY: front
front: ## Sobe só o front (astro dev, porta 4321)
	@cd $(FRONT_DIR) && $(BUN) run dev --port $(FRONT_PORT)

.PHONY: categories
categories: $(WP_CLI_PHAR) ## Cria as editorias (Política, Parnamirim, Brasil, Mundo, Tecnologia)
	@for c in "Política:politica" "Parnamirim:parnamirim" "Brasil:brasil" "Mundo:mundo" "Tecnologia:tecnologia"; do \
		$(WP) term get category "$${c#*:}" --by=slug --field=term_id >/dev/null 2>&1 \
			|| $(WP) term create category "$${c%%:*}" --slug="$${c#*:}" --porcelain >/dev/null; \
	done
	@echo "✓ Editorias: Política, Parnamirim, Brasil, Mundo, Tecnologia"

.PHONY: menus
menus: categories ## Cria os menus Principal e Institucional (só se ainda não existirem)
	@if $(WP) menu list --format=ids | grep -q .; then \
		echo "• Já existem menus — edite em Aparência → Menus"; \
	else \
		m=$$($(WP) menu create "Principal" --porcelain); \
		$(WP) menu item add-custom $$m "Início" "/" --quiet; \
		$(WP) menu item add-custom $$m "Últimas" "/ultimas" --quiet; \
		for s in politica brasil mundo tecnologia; do \
			$(WP) menu item add-term $$m category $$($(WP) term get category $$s --by=slug --field=term_id) --quiet; \
		done; \
		$(WP) menu location assign $$m principal; \
		m=$$($(WP) menu create "Institucional" --porcelain); \
		$(WP) menu item add-custom $$m "Sobre" "/sobre" --quiet; \
		$(WP) menu item add-custom $$m "Contato" "/contato" --quiet; \
		$(WP) menu location assign $$m institucional; \
		echo "✓ Menus criados: Principal e Institucional"; \
	fi

.PHONY: seed
seed: categories ## Cria notícias de exemplo com imagens (ONLY=parnamirim limita a uma editoria)
	@ADMIN_USER=$(ADMIN_USER) SEED_ONLY=$(ONLY) $(WP) eval-file scripts/seed.php

.PHONY: unseed
unseed: $(WP_CLI_PHAR) ## Apaga as notícias e imagens de exemplo
	@ids=$$($(WP) post list --post_type=post,attachment --post_status=any --meta_key=_farol_seed --format=ids); \
		[ -n "$$ids" ] && $(WP) post delete $$ids --force --quiet; true
	@echo "✓ Exemplos removidos"

.PHONY: wp-core-update
wp-core-update: ## Atualiza o core do WordPress no repositório (produção não se autoatualiza)
	@scripts/update-wp-core.sh

.PHONY: rescore
rescore: $(WP_CLI_PHAR) ## Recalcula os scores de SEO/GEO de todos os posts
	@$(WP) farol-seo rescore

.PHONY: fontes
fontes: ## Compila o editor do bloco Fonte (plugin farol-fontes)
	@cd $(WP_DIR)/wp-content/plugins/farol-fontes && $(BUN) install && $(BUN) run build
	@$(MAKE) --no-print-directory fontes-css

.PHONY: fontes-css
fontes-css: ## Copia o CSS do bloco Fonte do front para o editor
	@cp $(FRONT_DIR)/src/styles/fontes.css $(WP_DIR)/wp-content/plugins/farol-fontes/assets/fonte.css
	@echo "✓ CSS do bloco Fonte sincronizado"

.PHONY: wp
wp: $(WP_CLI_PHAR) ## Roda o WP-CLI. Ex.: make wp ARGS="plugin list"
	@$(WP) $(ARGS)

# ---------------------------------------------------------------------
#  Build e verificação
# ---------------------------------------------------------------------

.PHONY: build
build: ## Gera o build de produção do front
	@cd $(FRONT_DIR) && $(BUN) run build

.PHONY: start
start: build ## Roda o build do front com Bun (como em produção)
	@cd $(FRONT_DIR) && $(BUN) --env-file=.env ./dist/server/entry.mjs

.PHONY: lint
lint: ## Checa a sintaxe do PHP do projeto
	@for f in $(WP_DIR)/wp-config.php $(WP_DIR)/wp-content/mu-plugins/*.php $(WP_DIR)/wp-content/mu-plugins/farol-core/*.php $(WP_DIR)/wp-content/themes/farol-headless/*.php $(WP_DIR)/wp-content/plugins/farol-seo/*.php $(WP_DIR)/wp-content/plugins/farol-seo/includes/*.php $(WP_DIR)/wp-content/plugins/farol-fontes/*.php $(WP_DIR)/wp-content/plugins/farol-fontes/includes/*.php $(WP_DIR)/wp-content/plugins/farol-social/*.php $(WP_DIR)/wp-content/plugins/farol-midia/*.php $(WP_DIR)/wp-content/plugins/farol-midia/includes/*.php scripts/*.php; do \
		$(PHP) -l $$f >/dev/null || exit 1; \
	done
	@echo "✓ PHP ok"

.PHONY: clean
clean: ## Remove build e caches do front
	@rm -rf $(FRONT_DIR)/dist $(FRONT_DIR)/.astro
