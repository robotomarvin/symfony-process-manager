COMPOSE = APP_UID=$(shell id -u) APP_GID=$(shell id -g) docker compose
RUN     = $(COMPOSE) run --rm

.PHONY: help build up down shell install test cs cs-fix analyse check

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?##' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?##"}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Build the Docker image
	$(COMPOSE) build

up: ## Start the process manager + monitoring stack (detached)
	$(COMPOSE) up --build -d app prometheus grafana

down: ## Stop and remove all containers
	$(COMPOSE) down

shell: ## Open an interactive shell in the app container
	$(RUN) app bash

install: ## Install Composer dependencies into the vendor volume
	$(RUN) app composer install

test: ## Run PHPUnit (E2E tests bind HTTP to 127.0.0.1:0)
	$(RUN) -e PM_HTTP_HOST=127.0.0.1 -e PM_HTTP_PORT=0 app ./vendor/bin/phpunit

cs: ## Check coding standards (php-cs-fixer)
	$(RUN) app ./vendor/bin/php-cs-fixer check

cs-fix: ## Auto-fix coding standards
	$(RUN) app ./vendor/bin/php-cs-fixer fix

analyse: ## Run PHPStan static analysis
	$(RUN) app ./vendor/bin/phpstan analyse

check: analyse test ## Run all quality gates (analyse + test)
