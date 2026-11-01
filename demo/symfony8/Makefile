# Makefile for Messenger Monitor Kit Bundle Demo - Symfony 8
# FrankenPHP + Caddyfile. Run from this directory: make up, make install, etc.

COMPOSE_FILE := docker-compose.yml
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down restart build install shell logs setup db-schema seed cache-clear update-bundle ensure-up test test-e2e demo-screenshots

help:
	@echo "Messenger Monitor Kit Bundle Demo - Symfony 8"
	@echo ""
	@echo "  make up           Start containers (FrankenPHP on port \$${PORT:-8094})"
	@echo "  make down         Stop containers"
	@echo "  make build        Build image (no cache)"
	@echo "  make install      Install dependencies in container"
	@echo "  make setup        install + schema + seed"
	@echo "  make shell        Open shell in PHP container"
	@echo "  make logs         Show container logs"
	@echo "  make db-schema    Update schema"
	@echo "  make seed         Dispatch demo messages and run a worker (idempotent)"
	@echo "  make cache-clear  Clear Symfony cache"
	@echo "  make update-bundle Update bundle from path repo"
	@echo "  make test-e2e     Playwright happy path (official Playwright image)"
	@echo "  make demo-screenshots Refresh docs/images/demo/*.webp"
	@echo ""

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@echo "Waiting…"
	$(COMPOSE) up -d
	@echo "Installing…"
	@sleep 5
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction || true
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8094; \
	code=000; \
	i=0; \
	while [ $$i -lt 90 ]; do \
		code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$$PORT 2>/dev/null || echo "000"); \
		if [ "$$code" = "200" ] || [ "$$code" = "302" ]; then break; fi; \
		i=$$((i+1)); sleep 2; \
	done; \
	if [ "$$code" != "200" ] && [ "$$code" != "302" ]; then \
		echo "Demo not ready (HTTP $$code)"; \
		$(COMPOSE) logs --tail=80 db $(SERVICE_PHP) || true; \
		exit 1; \
	fi; \
	echo "Demo started at: http://localhost:$$PORT"

down:
	$(COMPOSE) down

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container (demo $(COMPOSE))..."; \
		$(COMPOSE) up -d; \
		sleep 5; \
		$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction; \
	fi

install: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction

setup: install db-schema seed
	@echo "Demo ready. App: http://localhost:$${PORT:-8094}  Monitor: http://localhost:$${PORT:-8094}/admin/messenger"

db-schema: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists --no-interaction || true
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:schema:update --force --no-interaction
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console messenger:setup-transports --no-interaction

seed: ensure-up db-schema
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console app:demo:seed --no-interaction

shell: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) sh

logs:
	$(COMPOSE) logs -f

cache-clear: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console cache:clear --env=dev --no-warmup || true

update-bundle: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer update nowo-tech/messenger-monitor-kit-bundle --no-interaction

# REQ-MAKE-003: the demo has no PHPUnit suite (tests/ only holds the bootstrap); lint the wiring and templates instead.
test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console lint:container
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console lint:twig templates

# REQ-DEMO-013: Playwright e2e and README screenshots (official Playwright image, nothing to install on the host).
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.63.0-jammy

test-e2e: ensure-up seed
	@mkdir -p e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2); PORT=$${PORT:-8094}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=chromium'

demo-screenshots: ensure-up seed
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2); PORT=$${PORT:-8094}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=screenshots && node scripts/to-webp.mjs /screenshots'
	@echo "Screenshots written under docs/images/demo/ (WebP)"
