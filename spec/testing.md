# Testing

## Overview

The test suite is divided into two levels:

- **Unit tests** (`tests/Unit/`) — test individual classes in isolation using fakes and mocks; no filesystem, no subprocesses, no wall-clock time.
- **E2E tests** (`tests/E2E/`) — spawn a real `pm:serve` process, exercise it through its external interfaces, and validate observable behavior.

---

## Running Tests

```bash
# All tests
composer test

# Single file
./vendor/bin/phpunit tests/Unit/Command/Serve/ProcessManagerLoopTest.php

# Single method
./vendor/bin/phpunit --filter testSingleWorkerFailureLimitTriggersShutdown

# Static analysis (PHPStan level max)
composer analyse

# Coding standards check
composer cs

# Fix coding standards
composer cs-fix

# All checks (required before PR)
composer check
```

---

## Unit Tests

### Structure

Unit tests mirror the `src/` structure exactly. Every concrete class with logic has a corresponding test file.

```
tests/Unit/
├── Command/
│   └── Serve/ProcessManagerLoopTest.php
├── DependencyInjection/
│   └── ConfigurationTest.php
├── Http/
│   └── HttpServerTest.php
├── Ipc/
│   ├── IpcCodecTest.php
│   ├── IpcEndpointTest.php
│   ├── IpcFanoutTest.php
│   └── Filter/WorkerIdFilterTest.php
├── Metrics/
│   ├── CounterTest.php
│   ├── GaugeTest.php
│   ├── MetricsRegistryTest.php
│   └── PrometheusTextRendererTest.php
├── Output/
│   ├── WorkerOutputFormatterTest.php
│   └── WorkerOutputHandlerTest.php
├── ProcessManager/
│   ├── ShutdownStateTest.php
│   └── WorkerStateTest.php
├── Transport/
│   ├── ConsumeArgsTest.php
│   └── TransportConfigTest.php
└── Worker/
    └── WorkerIpcSubscriberTest.php
```

### Time Control

All time-dependent logic uses `Symfony\Component\Clock\ClockInterface`. Unit tests inject `AutoAdvancingClock` — a test fake that advances the clock by a fixed amount on each call to `now()`, allowing deterministic time-based assertions without `sleep()`.

```php
// Example: clock advances 0.1s on each now() call
$clock = new AutoAdvancingClock(new DateTimeImmutable('2024-01-01'), 0.1);
```

### Process Faking

`WorkerProcessFactoryInterface` is faked via `FakeProcessFactory`. The fake returns configurable `Process` instances that can be pre-set to exit with a given code at a given tick, simulating worker lifecycles without spawning real subprocesses.

### Event Loop Faking

`FakeLoop` (implements `React\EventLoop\LoopInterface`) captures timer registrations and signal handlers without actually running an event loop. Tests drive the loop manually by calling `tick()` directly on `ProcessManagerLoop`.

### Conventions

- `declare(strict_types=1)` in every test file.
- `final` test classes.
- Arrange-Act-Assert pattern; no branching within a test method.
- No `sleep()` or wall-clock dependencies.
- Each test exercises exactly one observable outcome.

---

## E2E Tests

### Location

`tests/E2E/ProcessCommandTest.php`

### How They Work

E2E tests use `ConsoleProcessSession` to start a real `pm:serve` process and communicate with it:

```
ConsoleProcessRunner::run('pm:serve')
  → spawns real php bin/console pm:serve
  → waits for startup log line
  → returns ConsoleProcessSession

session->sendSignal(SIGTERM)
  → sends SIGTERM to the pm:serve process

session->waitForExit()
  → waits for the process to finish and returns exit code
```

### Fixture App

E2E tests run against a minimal Symfony app at `tests/Fixtures/app/`:

```
tests/Fixtures/app/
├── bin/console
├── config/
│   ├── bundles.php
│   └── packages/
│       ├── doctrine.yaml           # SQLite Doctrine DBAL transport
│       └── symfony_process_manager.yaml
├── src/
│   ├── Command/
│   │   └── DispatchFixtureMessageCommand.php
│   ├── Message/
│   │   └── FixtureMessage.php
│   ├── MessageHandler/
│   │   └── FixtureMessageHandler.php
│   └── StdoutJsonLogger.php        # Writes JSON to stdout for log assertions
└── var/
    ├── test.db                     # SQLite messenger transport
    └── cache/
```

The fixture app uses a Doctrine DBAL transport (SQLite) as the messenger backend.

### Fixture Message Handler

`FixtureMessageHandler` interprets the message payload to trigger specific behaviors:

| Payload | Behavior |
|---|---|
| `exit:N` | Exit with code N (tests failure/restart) |
| `stdout:plain` | Write a plain-text line to stdout (tests non-JSON output handling) |
| `worker-id-check` | Log a JSON line; test asserts `worker_id` is present in the output |
| *(any other)* | Log a standard handler success message |

### JsonLogParser

`JsonLogParser` parses structured log output from worker processes:

```php
$log = JsonLogParser::parse($output);
$log->findByMessage('was handled successfully');
$log->all();
```

### E2E Test Coverage

| Scenario | What is verified |
|---|---|
| Startup and shutdown on SIGTERM | Process exits 0, log line present |
| HTTP `/` health check | Returns `{"status":"ok"}` while running |
| HTTP `/metrics` endpoint | Returns Prometheus text, `worker_starts_total` > 0 |
| Worker clean exit (code 0) | Worker restarted immediately |
| Worker failure (code != 0) | Worker restarted with backoff delay |
| Failure limit exceeded | All workers stopped, process exits 0 |
| JSON log enrichment | `worker_id` field present in enriched log lines |
| Plain text output | Output prefixed with `[worker N]` |

---

## Test Support Utilities

### `ConsoleProcessRunner`

Runs a one-shot console command and returns the result.

```php
$result = ConsoleProcessRunner::run('bin/console some:command');
$result->getExitCode();
$result->getOutput();
```

### `ConsoleProcessSession`

Manages a long-running process for interactive testing.

```php
$session = ConsoleProcessSession::start('pm:serve');
$session->waitForLog('HTTP server listening');
$session->sendSignal(SIGTERM);
$exitCode = $session->waitForExit(timeout: 10);
```

### `ConsoleProcessResult`

Wraps the result of a completed process:

```php
$result->getExitCode(): int
$result->getOutput(): string
$result->getErrorOutput(): string
```

### `JsonLogParser`

Parses JSON log lines from worker output:

```php
$parser = new JsonLogParser($rawOutput);
$lines = $parser->all();                        // all parsed lines
$line  = $parser->findByMessage('...');         // first matching line
$line['extra']['worker_id'];                    // access fields
```

---

## PHPUnit Configuration

`phpunit.xml` key settings:

| Setting | Value |
|---|---|
| Bootstrap | `vendor/autoload.php` |
| Test directory | `tests/` |
| Coverage source | `src/` |
| Cache | `.phpunit.cache/` |
| Strict coverage metadata | true |

---

## Static Analysis

PHPStan is configured at **level max** (`phpstan.neon`):

```neon
parameters:
    level: max
    paths:
        - src
        - tests
    includes:
        - vendor/phpstan/phpstan-symfony/extension.neon
```

All source and test code must pass PHPStan at level max. This means:
- No implicit mixed types.
- Full generic type annotations where applicable.
- No dead code (unreachable branches, unused variables).

---

## Coding Standards

PHP-CS-Fixer is configured in `.php-cs-fixer.dist.php`. Rules enforce:
- `declare(strict_types=1)` in every file.
- PSR-12 style.
- Import ordering.
- No unused imports.

Run `composer cs-fix` to auto-fix violations before committing.
