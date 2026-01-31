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
  http_server:
    host: 127.0.0.1
    port: 9100

  transports:
    async:
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
```

### Transport Options

Each entry under `transports` configures one `messenger:consume <transport>` pool.

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

- `process_manager_running` (gauge)
- `worker_starts_total{transport=...}` (counter)
- `worker_exits_total{exit_code=...}` (counter)
- `worker_failures_total{transport=...}` (counter)
- `worker_backoffs_total{transport=...}` (counter)
- `messages_processed_total{transport=...}` (counter)

## Development

```bash
composer cs
composer analyse
composer test
composer check
```

### Docker Development

The repository includes a dev-focused PHP 8.5 image and a compose file.

Build the image (recommended: match the container user to your host UID/GID):

```bash
APP_UID="$(id -u)" APP_GID="$(id -g)" docker compose -f docker-compose.dev.yml build
```

Install dependencies (writes `./vendor` on your host via the bind-mounted project directory):

```bash
APP_UID="$(id -u)" APP_GID="$(id -g)" docker compose -f docker-compose.dev.yml run --rm app composer install
```

Start an interactive shell:

```bash
APP_UID="$(id -u)" APP_GID="$(id -g)" docker compose -f docker-compose.dev.yml run --rm app
```

Optional: keep `vendor/` in a named Docker volume (useful if you do not want `./vendor` on your host, or to avoid slow bind-mount performance on macOS). The image entrypoint ensures the volume is writable by the non-root `app` user.

To enable it, uncomment the `vendor:/app/vendor` line in `docker-compose.dev.yml`.

Run quality gates:

```bash
composer check
```

Run the supervisor (ensure the configured HTTP host is reachable from outside the container, e.g. `0.0.0.0`):

```bash
php tests/Fixtures/app/bin/console pm:serve
```

See `CONTRIBUTING.md` for code and testing rules.
