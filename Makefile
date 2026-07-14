SHELL := /usr/bin/env bash

.DEFAULT_GOAL := help

.PHONY: help verify-planning show-goal install dev dev-mobile dev-demo stop typecheck phpstan test backup-smoke e2e visual build contracts routes smoke-entur

help:
	@printf '%s\n' \
	  'make install          Install exact PHP/JS dependencies and Chromium' \
	  'make dev              Run the normal local app with real Entur data' \
	  'make dev-mobile       Run the real app for a phone on this trusted LAN' \
	  'make dev-demo         Run an isolated deterministic fake-data stack' \
	  'make stop             Stop local FjordPulse processes started by make dev' \
	  'make typecheck        Run strict TypeScript checks' \
	  'make phpstan          Run PHPStan at maximum level' \
	  'make test             Run contract, PHP, and frontend unit/integration tests' \
	  'make backup-smoke     Prove encrypted SurrealDB backup and isolated restore' \
	  'make e2e              Run fixture and clean-stack browser black-box tests' \
	  'make visual           Run deterministic visual regression tests' \
	  'make build            Produce and validate the production build' \
	  'make verify-planning  Verify the consolidated planning corpus' \
	  'make smoke-entur      Probe the live backend-only Entur adapters'

verify-planning:
	@bash scripts/verify-planning-inputs.sh

show-goal:
	@cat GOAL.md

install:
	@./tools/php --version
	@./tools/composer --version
	@./tools/surreal version
	@./tools/restic version
	@./tools/composer --working-dir=backend install --no-interaction --no-progress --prefer-dist
	@npm ci --no-audit --no-fund
	@npm --prefix frontend ci --no-audit --no-fund
	@PLAYWRIGHT_BROWSERS_PATH="$(CURDIR)/.tools/playwright" npx playwright install chromium
	@test -f .env || cp .env.example .env

dev:
	@bash scripts/dev.sh real

dev-mobile:
	@FJORDPULSE_DEV_LAN=true bash scripts/dev.sh real

dev-demo:
	@bash scripts/dev.sh demo

stop:
	@bash scripts/stop.sh

typecheck:
	@npm --prefix frontend run typecheck

phpstan:
	@cd backend && PATH="$(CURDIR)/tools:$$PATH" ../tools/composer phpstan

contracts:
	@npm run contracts

test: contracts
	@cd backend && COMPOSER_PROCESS_TIMEOUT=600 PATH="$(CURDIR)/tools:$$PATH" ../tools/composer test
	@npm --prefix frontend test
	@$(MAKE) --no-print-directory backup-smoke

backup-smoke:
	@bash scripts/test-backup-restore.sh

e2e:
	@PLAYWRIGHT_BROWSERS_PATH="$(CURDIR)/.tools/playwright" npm run e2e

visual:
	@PLAYWRIGHT_BROWSERS_PATH="$(CURDIR)/.tools/playwright" npm run visual

build: typecheck contracts
	@npm --prefix frontend run build
	@node scripts/audit-production-truth.mjs
	@./tools/composer --working-dir=backend validate --no-check-publish
	@npm run infra
	@FRONTEND_DIST="$(CURDIR)/frontend/dist" BACKEND_WEBROOT="$(CURDIR)/backend/webroot" ./tools/frankenphp adapt --config infra/Caddyfile --adapter caddyfile >/dev/null
	@test -s frontend/dist/index.html

routes:
	@./backend/bin/cake routes

smoke-entur:
	@cd backend && RUN_ENTUR_SMOKE=1 PATH="$(CURDIR)/tools:$$PATH" ../tools/php vendor/bin/phpunit --group external
