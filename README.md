# Symfony Process Manager

Symfony bundle that runs and supervises Symfony Messenger workers as subprocesses.

`pm:serve` starts an event loop that:

- spawns `messenger:consume` processes per configured transport
- restarts workers on exit (immediate restart for exit code 0, exponential backoff for non-zero exits)
- shuts down gracefully on SIGTERM
- exposes a small HTTP server for health and Prometheus metrics

## Requirements

- PHP 8.5+
- Symfony 7.4+

## Installation

```bash
composer require robotomarvin/symfony-process-manager
```

Enable the bundle (if not using a Symfony Flex recipe):

```php
// config/bundles.php

return [
    // ...
    SymfonyProcessManager\SymfonyProcessManagerBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/symfony_process_manager.yaml`:

```yaml
symfony_process_manager:
  shutdown_timeout: 30
  total_cap: null                # optional global ceiling on total workers
  autoscaler_interval_sec: 10    # how often the autoscaler evaluates strategies

  http_server:
    host: 127.0.0.1
    port: 9100

  transports:
    async:
      # Static pool (legacy form)
      processes: 2
      failure_limit: 3
      failure_window: 60
      backoff_base: 1
      backoff_max: 30
      poll_interval_ms: 200
      consume_args:
        memory_limit: 128
        time_limit: 300
        limit: null
        sleep: null
        queues: []
        extra: []
    priority:
      # Autoscaled pool
      autoscaler:
        min: 1
        max: 5
        priority: 10
        smoothing_window_sec: 30
        scale_up_cooldown_sec: 30
        scale_down_cooldown_sec: 300
        scale_up_step: 2
        scale_down_step: 1
        strategy:
          type: utilization        # 'fixed' | 'utilization' | 'service'
          target: 0.7
      consume_args:
        queues: ['priority']
```

### Top-Level Options

- `shutdown_timeout` (int seconds, default 30) — after SIGTERM is sent to workers, wait this many seconds before escalating to SIGKILL. Set to `0` to wait indefinitely.
- `total_cap` (int|null, default null) — optional global ceiling on the sum of workers across all pools. When set, a `PriorityArbiter` shares the cap across pools by priority.
- `autoscaler_interval_sec` (int seconds, default 10) — how often the autoscaler evaluates strategies and adjusts pool targets.

### Transport Options

Each entry under `transports` configures one `messenger:consume <transport>` pool. A transport must use **either** `processes` (static) **or** `autoscaler` (dynamic) — never both.

Static (legacy) options:

- `processes` (int, default 1)
- `failure_limit` (int, default 3)
- `failure_window` (int seconds, default 60)
- `backoff_base` (int seconds, default 1)
- `backoff_max` (int seconds, default 30)
- `poll_interval_ms` (int milliseconds, default 200)
- `consume_args`
  - `memory_limit` (int|null)
  - `time_limit` (int|null)
  - `limit` (int|null)
  - `sleep` (int|null)
  - `queues` (list<string>)
  - `extra` (list<string>) additional CLI flags

### Autoscaler Options

`transports.<name>.autoscaler` enables dynamic worker scaling for that pool.

- `min` (int, required) — lower bound; autoscaled pools start at this count
- `max` (int, required) — upper bound
- `priority` (int, default 0) — higher priorities are preferred under `total_cap` contention
- `smoothing_window_sec` (int, default 30) — EWMA time constant for `busy`/`idle`/`throughput` signals
- `scale_up_cooldown_sec` (int, default 30) — minimum seconds between successive scale-ups
- `scale_down_cooldown_sec` (int, default 300) — minimum seconds between successive scale-downs
- `scale_up_step` (int, default 2) — maximum workers added per evaluation
- `scale_down_step` (int, default 1) — maximum workers removed per evaluation
- `strategy.type` — one of:
  - `fixed` — always returns `min` workers (effectively pins the pool)
  - `utilization` — returns `ceil(busy / target)`; default `target` is `0.7`
  - `service` — references a custom strategy service via `strategy.id`; the service must implement `SymfonyProcessManager\Autoscaler\Strategy\ScalingStrategyInterface`

## Usage

In a Symfony application, you typically run:

```bash
php /path/to/your/app/bin/console pm:serve
```

In this repository (using the test fixture app), run:

```bash
php tests/Fixtures/app/bin/console pm:serve
```

This starts the HTTP server and begins supervising worker processes.

## HTTP Endpoints

- `GET /` returns `{"status":"ok"}`
- `GET /metrics` returns Prometheus text format

## Output Behavior

Worker output is forwarded to the parent process stdout/stderr.

- JSON log lines are enriched with `extra.worker_id`.
- Non-JSON lines are prefixed with `[worker N]`.

## Metrics

The `/metrics` endpoint exposes Prometheus metrics including:

Process manager:

- `process_manager_running` (gauge)
- `worker_starts_total{transport=...}` (counter)
- `worker_exits_total{exit_code=...}` (counter)
- `worker_failures_total{transport=...}` (counter)
- `worker_backoffs_total{transport=...}` (counter)
- `worker_sigkills_total` (counter)
- `messages_processed_total{transport=...}` (counter)
- `worker_last_pong_timestamp{worker=...}` (gauge) — cleared when worker enters drain
- `worker_busy{worker=...,transport=...}` (gauge, 0/1)

Autoscaler:

- `autoscaler_target_workers{transport=...}` (gauge) — last decision after the stability layer
- `autoscaler_current_workers{transport=...}` (gauge) — active worker count, excluding draining
- `autoscaler_unmet_demand{transport=...}` (gauge) — `desired - allocated` after arbitration
- `autoscaler_scale_up_total{transport=...}` (counter)
- `autoscaler_scale_down_total{transport=...}` (counter)
- `autoscaler_decisions_skipped_total{transport=...,reason=...}` (counter) — reasons: `cooldown_up`, `cooldown_down`, `step_cap`, `at_min`, `at_max`
- `worker_busy_workers{transport=...}` (gauge)

## Development

```bash
composer cs
composer analyse
composer test
composer check
```

### Docker Development

The repository ships a PHP 8.5 image and a `Makefile` that wraps all common tasks. `vendor/` is kept in a named Docker volume — no host writes, no macOS bind-mount slowness.

```bash
make help        # list all available targets
make build       # build the Docker image
make up          # start app only (detached)
make monitoring  # start app + prometheus + grafana (detached)
make down        # stop all containers
make shell       # open an interactive shell in the app container
make install     # run composer install inside the container
```

#### Services

| Service    | Profile      | Host Port (default) | Description                                   |
|------------|--------------|---------------------|-----------------------------------------------|
| app        | _(default)_  | ephemeral (0)       | Process Manager — health (`/`) + metrics (`/metrics`) |
| prometheus | `monitoring` | ephemeral (0)       | Prometheus — scrapes `app:9100/metrics`       |
| grafana    | `monitoring` | ephemeral (0)       | Grafana — pre-configured Prometheus datasource |

`prometheus` and `grafana` only start when the `monitoring` profile is active (via `make monitoring`). Ports default to `0` (OS-assigned ephemeral). Fix them when you need stable URLs:

```bash
PM_HOST_PORT=9100 PROMETHEUS_HOST_PORT=9090 GRAFANA_HOST_PORT=3000 make monitoring
curl http://localhost:9100/metrics
# Default Grafana credentials: admin / admin
open http://localhost:3000
```

#### Running Quality Gates

```bash
make test       # PHPUnit (E2E tests bind HTTP to 127.0.0.1:0 inside the container)
make cs         # php-cs-fixer check
make cs-fix     # php-cs-fixer fix
make analyse    # PHPStan
make check      # analyse + test
```

See `CONTRIBUTING.md` for code and testing rules.
