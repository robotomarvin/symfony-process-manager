# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Symfony Bundle that manages Messenger worker processes. The `pm:serve` command spawns and monitors multiple `messenger:consume` subprocesses with automatic restart, exponential backoff on failure, and graceful SIGTERM shutdown.

- PHP ^8.5, Symfony ^7.4
- Requires: symfony/console, symfony/process, symfony/messenger

## Commands

```bash
# Run all tests
./vendor/bin/phpunit

# Run a single test file
./vendor/bin/phpunit tests/E2E/ProcessCommandTest.php

# Run a single test method (use --filter)
./vendor/bin/phpunit --filter testProcessCommandStartsAndStopsOnSigterm

# Static analysis
./vendor/bin/phpstan analyse
```

## Architecture

**ServeCommand** (`src/Command/ServeCommand.php`) is the main entry point — a polling event loop (200ms interval) that manages worker lifecycle. It delegates to four collaborators in `src/Command/Serve/`:

- **WorkerProcessFactory** — builds `messenger:consume` subprocesses with configurable limits (time, message count, memory)
- **WorkerState** — data class tracking per-worker state: process handle, failure timestamps, backoff timing, stop status
- **WorkerOutputHandler** — buffers stdout/stderr from workers, processes line-by-line, flushes on exit
- **WorkerOutputFormatter** — decorates output with worker ID: injects `extra.worker_id` into JSON log lines, prefixes `[worker N]` for plain text

**Key behaviors:**
- Exponential backoff: 1s → 2s → 4s → ... → 30s max on worker failure
- Failure limit: 3 failures within 60 seconds triggers full shutdown
- Clean exit (code 0) restarts immediately; non-zero exit triggers backoff

## Testing

Tests are E2E only (`tests/E2E/ProcessCommandTest.php`), running against a fixture Symfony app in `tests/Fixtures/app/`. The fixture app uses SQLite-backed Doctrine messenger transport and a custom `StdoutJsonLogger` that writes structured JSON to stdout.

Test support classes in `tests/Support/`:
- **ConsoleProcessRunner** — launches commands as subprocesses, parses JSON log output
- **ConsoleProcessSession** — manages long-running process interactions with `waitForRecord()` and `signal()` helpers
- **ConsoleProcessResult** — holds exit code, stdout, stderr, and parsed log records

The fixture app has a `FixtureMessageHandler` that responds to special payloads (`exit:0`, `exit:1`, `stdout:plain`) to simulate various worker behaviors in tests.

## Issue Tracking

This project uses **bd** (beads) for issue tracking. See `AGENTS.md` for workflow details.
