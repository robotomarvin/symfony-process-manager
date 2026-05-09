# Contributing

This repository follows a strict, test-driven style. Contributions are expected to preserve the existing architecture boundaries and keep the codebase highly unit-testable.

## Code Rules

### Boundaries

- `src/Command` contains entrypoints only (Symfony Console commands and minimal wiring).
- Domain and infrastructure code lives outside `src/Command` in dedicated namespaces/directories (feature-oriented).
- Classes follow the Single Responsibility Principle: one class, one reason to change.

### Dependency Inversion

- Any class with non-trivial logic depends on interfaces (ports), not concrete implementations.
- External effects are behind interfaces (time, process execution, scheduling/event loop, HTTP, output streams, metrics sinks).
- Logic classes do not instantiate collaborators (no `new` for other services inside logic); composition happens in Symfony DI.

### PHP Conventions

- `declare(strict_types=1);` is required in every PHP file.
- Prefer `final` classes and `readonly` dependencies by default.
- Interfaces use the `Interface` suffix.
- PSR-4 autoloading is used; namespaces mirror directory structure.

### Documentation

- `README.md` is kept up to date with any changes to configuration, runtime behavior, metrics, or public commands/endpoints.

## Testing Rules

- Every class that contains logic has a unit test.
- Unit tests are the default; end-to-end tests exist only for integration boundaries (Symfony wiring, signals, HTTP endpoints, real subprocess behavior).
- Unit tests do not rely on wall-clock delays; time is controlled via injected abstractions.
- Tests are hermetic and clean up after themselves; no reliance on committed/generated runtime artifacts.

## Development Environment

The repository ships a PHP 8.5 Docker image and a `Makefile` that wraps all common tasks. `vendor/` is kept in a named Docker volume — no host writes, no macOS bind-mount slowness.

```bash
make help        # list all available targets
make build       # build the Docker image
make up          # start app only (detached)
make monitoring  # start app + prometheus + grafana (detached)
make down        # stop all containers
make shell       # open an interactive shell in the app container
make install     # run composer install inside the container
```

### Services

| Service    | Profile      | Host Port (default) | Description                                   |
|------------|--------------|---------------------|-----------------------------------------------|
| app        | _(default)_  | ephemeral (0)       | Process Manager — health (`/`) + metrics (`/metrics`) |
| prometheus | `monitoring` | ephemeral (0)       | Prometheus — scrapes `app:9100/metrics`       |
| grafana    | `monitoring` | ephemeral (0)       | Grafana — pre-configured Prometheus datasource |

`prometheus` and `grafana` only start when the `monitoring` profile is active (via `make monitoring`). Ports default to `0` (OS-assigned ephemeral). Fix them when you need stable URLs:

```bash
PM_HOST_PORT=9100 PROMETHEUS_HOST_PORT=9090 GRAFANA_HOST_PORT=3000 make monitoring
curl http://localhost:9100/metrics
# Grafana opens the Process Manager dashboard directly (anonymous, no login)
open http://localhost:3000
```

The provisioned **Symfony Process Manager** dashboard ships rows for stats, messages, worker lifecycle, **autoscaler** (target vs current workers, pool utilization, busy/idle stack, scale events, skipped decisions by reason), and worker liveness. See `spec/metrics.md` for the panel-to-metric mapping.

### Generating Demo Traffic

The dashboard panels need sustained, mixed traffic to come alive. Four scenario presets ship with the fixture app — each mixes async (static pool) and scalable (autoscaled pool) traffic:

```bash
make demo-steady    # ~4 msg/s mixed, ~5% failures, 60s
make demo-burst     # 100-msg bursts every 30s, ~90s
make demo-ramp      # 1 → 10 msg/s linear ramp over 60s
make demo-failures  # 5 msg/s with 30% handler failures, 60s
```

These exec into the running `app` container, so `make monitoring` (or `make up`) must be active first. For custom durations:

```bash
make shell
php tests/Fixtures/app/bin/console fixture:load --scenario=ramp --duration=180
```

## Quality Gates

The following must pass before a change is considered ready:

```bash
make cs         # php-cs-fixer check
make check      # PHPStan + PHPUnit
```

Helpful locally:

```bash
make cs-fix     # auto-fix coding standards
make analyse    # PHPStan only
make test       # PHPUnit only (E2E tests bind HTTP to 127.0.0.1:0 inside the container)
```
