.PHONY: up down restart build logs shell migrate env bootstrap

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

build:
	docker compose up -d --build

logs:
	docker compose logs -f

shell:
	docker compose exec app bash

migrate:
	docker compose exec app php yii migrate --interactive=0

env:
	@if [ ! -f www/.env ]; then cp www/.env.example www/.env; echo ".env created from .env.example"; else echo ".env already exists"; fi

bootstrap:
	@if [ ! -f www/.env ]; then cp www/.env.example www/.env; echo ".env created from .env.example"; fi
	docker compose up -d --build
	sleep 5
	docker compose exec app php yii migrate --interactive=0
	sudo chmod -R 775 www/runtime www/web/assets
	sudo chown -R www-data:www-data www/runtime www/web/assets