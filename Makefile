.DEFAULT_GOAL := help

.PHONY: help install check serve migrate setup reset sqlite mysql mariadb redis valkey down

help:
	@awk 'BEGIN {FS = ":.*## "; printf "Providentia backend targets:\\n"} /^[a-zA-Z_-]+:.*## / {printf "  %-14s %s\\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install the exact Composer dependency graph
	composer install --no-interaction

check: ## Run all quality, architecture, contract, and test checks
	composer check
	bash tests/structural/verify.sh

serve: ## Run the local SQLite HTTP server
	composer serve

migrate: ## Apply pending database migrations
	composer migrate

setup: ## Start MySQL/Redis/Mailpit, migrate, seed and provision a developer
	./scripts/setup-development.sh

reset: ## Print the explicit destructive reset command
	@echo "Run ./scripts/reset-development.sh --confirm-destroy-local-data"

sqlite: ## Start application and Valkey with server-side SQLite
	docker compose --profile sqlite --profile valkey up --build --wait

mysql: ## Start application, MySQL, and Redis
	docker compose --profile mysql --profile redis up --build --wait

mariadb: ## Start application, MariaDB, and Valkey
	docker compose --profile mariadb --profile valkey up --build --wait

redis: ## Start Redis queue broker
	docker compose --profile redis up -d --wait redis

valkey: ## Start Valkey queue broker
	docker compose --profile valkey up -d --wait valkey

down: ## Stop all Compose profiles and retain named volumes
	docker compose --profile sqlite --profile mysql --profile mariadb --profile redis --profile valkey down
