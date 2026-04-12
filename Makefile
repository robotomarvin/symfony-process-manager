COMPOSE     = APP_UID=$(shell id -u) APP_GID=$(shell id -g) docker compose
COMPOSE_MON = $(COMPOSE) --profile monitoring
RUN         = $(COMPOSE) run --rm --remove-orphans

.PHONY: help build up monitoring down shell install test cs cs-fix analyse check

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?##' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?##"}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Build the Docker image
	$(COMPOSE) build

up: ## Start the process manager (detached)
	$(COMPOSE) up --build -d

monitoring: ## Start the process manager + prometheus + grafana (detached)
	$(COMPOSE_MON) up --build -d

down: ## Stop and remove all containers
	$(COMPOSE_MON) down

shell: ## Open an interactive shell in the app container
	$(RUN) app bash

install: ## Install Composer dependencies into the vendor volume
	$(RUN) app composer install

test: install ## Run PHPUnit (E2E tests bind HTTP to 127.0.0.1:0)
	$(RUN) -e PM_HTTP_HOST=127.0.0.1 -e PM_HTTP_PORT=0 app composer test

cs: install ## Check coding standards (php-cs-fixer)
	$(RUN) app composer cs

cs-fix: install ## Auto-fix coding standards
	$(RUN) app composer cs-fix

analyse: install ## Run PHPStan static analysis
	$(RUN) app composer analyse

check: install analyse test ## Run all quality gates (analyse + test)
