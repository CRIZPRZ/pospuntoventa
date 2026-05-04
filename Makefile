.PHONY: up down build restart logs sh artisan composer npm migrate seed fresh test setup

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

restart:
	docker compose down
	docker compose up -d

logs:
	docker compose logs -f

sh:
	docker compose exec app bash

artisan:
	docker compose exec app php artisan $(filter-out $@,$(MAKECMDGOALS))

composer:
	docker compose exec app composer $(filter-out $@,$(MAKECMDGOALS))

npm:
	docker compose exec app npm $(filter-out $@,$(MAKECMDGOALS))

migrate:
	docker compose exec app php artisan migrate --force

seed:
	docker compose exec app php artisan db:seed --force

fresh:
	docker compose exec app php artisan migrate:fresh --seed --force

test:
	docker compose exec app ./vendor/bin/phpunit

setup:
	docker compose up -d --build
	docker compose exec app composer install
	docker compose exec app php artisan migrate --force
	docker compose exec app php artisan db:seed --force
	@echo "✅ Ventas POS listo en http://localhost:8080"
