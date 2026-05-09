COMPOSE     = APP_UID=$(shell id -u) APP_GID=$(shell id -g) docker compose
COMPOSE_MON = $(COMPOSE) --profile monitoring
RUN         = $(COMPOSE) run --rm --remove-orphans
EXEC        = $(COMPOSE) exec
LOAD        = $(EXEC) app php tests/Fixtures/app/bin/console fixture:load

.PHONY: help build up monitoring down shell install test cs cs-fix analyse check \
        demo-steady demo-burst demo-ramp demo-failures

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?##' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?##"}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Build the Docker image
	$(COMPOSE) build

up: ## Start the process manager (detached)
	$(COMPOSE) up --build -d
	@printf '\nServices:\n'
	@app_port=$$($(COMPOSE) port app 9100 2>/dev/null | cut -d: -f2); \
	printf '  \033[36mApp:\033[0m        http://localhost:%s/metrics\n' "$${app_port:-?}"

monitoring: ## Start the process manager + prometheus + grafana (detached)
	$(COMPOSE_MON) up --build -d
	@printf '\nServices:\n'
	@grafana_port=$$($(COMPOSE_MON) port grafana 3000 2>/dev/null | cut -d: -f2); \
	prom_port=$$($(COMPOSE_MON) port prometheus 9090 2>/dev/null | cut -d: -f2); \
	app_port=$$($(COMPOSE_MON) port app 9100 2>/dev/null | cut -d: -f2); \
	printf '  \033[36mGrafana:\033[0m    http://localhost:%s\n' "$${grafana_port:-?}"; \
	printf '  \033[36mPrometheus:\033[0m http://localhost:%s\n' "$${prom_port:-?}"; \
	printf '  \033[36mApp:\033[0m        http://localhost:%s/metrics\n' "$${app_port:-?}"

down: ## Stop and remove all containers
	$(COMPOSE_MON) down

shell: ## Open an interactive shell in the app container
	$(RUN) app bash

install: ## Install Composer dependencies into the vendor volume
	mkdir -p $(HOME)/.composer/cache
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

demo-steady: ## Steady ~4 msg/s mixed traffic for 60s (needs 'make monitoring' running)
	$(LOAD) --scenario=steady

demo-burst: ## Burst 100 msgs every 30s for ~90s (needs 'make monitoring' running)
	$(LOAD) --scenario=burst

demo-ramp: ## Linear ramp 1->10 msg/s over 60s (needs 'make monitoring' running)
	$(LOAD) --scenario=ramp

demo-failures: ## 5 msg/s with 30% handler failures for 60s (needs 'make monitoring' running)
	$(LOAD) --scenario=failures
