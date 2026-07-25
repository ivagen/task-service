.PHONY: up down restart build logs shell migrate test test-integration coverage analyse composer cs-fix cs-check env bootstrap prod-build ps openapi

# Override to layer in the auth-service network overlay, e.g.
#   make up COMPOSE_FILES="-f docker-compose.yml -f docker-compose.auth-network.yml"
COMPOSE_FILES ?= -f docker-compose.yml
COMPOSE = docker compose $(COMPOSE_FILES)

env:
	@if [ ! -f .env ]; then cp .env.example .env; echo ".env created from .env.example"; else echo ".env already exists"; fi

up: env
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart

build: env
	$(COMPOSE) up -d --build

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f

shell:
	$(COMPOSE) exec app bash

migrate:
	$(COMPOSE) exec app php yii migrate --interactive=0

test:
	$(COMPOSE) exec app vendor/bin/phpunit --testsuite Feature

# Runs the real migrations against the stack's MySQL, on a separate database.
test-integration:
	$(COMPOSE) exec -T mysql mysql -uroot -p$${DB_ROOT_PASSWORD:-root} \
		-e "CREATE DATABASE IF NOT EXISTS task_service_test CHARACTER SET utf8mb4"
	$(COMPOSE) exec \
		-e TEST_DB_DSN="mysql:host=mysql;port=3306;dbname=task_service_test" \
		-e TEST_DB_USER=root \
		-e TEST_DB_PASSWORD=$${DB_ROOT_PASSWORD:-root} \
		-e TEST_REDIS_HOST=redis \
		-e TEST_REDIS_PORT=6379 \
		-e TEST_REDIS_DB=15 \
		app vendor/bin/phpunit

coverage:
	$(COMPOSE) exec app composer test:coverage

analyse:
	$(COMPOSE) exec app composer analyse

openapi:
	npx --yes @redocly/cli@2 lint

composer:
	$(COMPOSE) exec app composer update --no-interaction --prefer-dist

cs-fix:
	$(COMPOSE) exec app composer cs:fix

cs-check:
	$(COMPOSE) exec app composer cs:check

# vendor/ and runtime/ live in named volumes owned by the container, so no
# host-side chmod/chown (and no sudo) is needed. mysql/redis are gated by
# healthchecks, so no sleep either.
bootstrap: env
	$(COMPOSE) up -d --build
	$(COMPOSE) exec app composer install --no-interaction --prefer-dist
	$(COMPOSE) exec app php yii migrate --interactive=0
	@echo "Ready: http://localhost:$$(grep -E '^APP_HOST_PORT=' .env | cut -d= -f2)/api/v1/health"

prod-build: env
	docker compose -f docker-compose.prod.yml up -d --build
	docker compose -f docker-compose.prod.yml exec app php yii migrate --interactive=0
