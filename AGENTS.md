# Agent Instructions

This file provides guidance to AI coding agents working in this repository.

Always read `CONTRIBUTING.md` before making code changes.

## What This Is

A Symfony 7.4 bundle (PHP 8.5+) that supervises Symfony Messenger `messenger:consume` worker processes using a ReactPHP event-loop. It spawns configurable worker counts per transport, handles graceful shutdown on SIGTERM, implements exponential backoff on failures, and exposes HTTP health/metrics endpoints.

## Commands

See `CONTRIBUTING.md` for the full list of composer scripts. Quick reference for running individual tests:

```bash
# Single test file
./vendor/bin/phpunit tests/Unit/Command/Serve/ProcessManagerLoopTest.php

# Single test method
./vendor/bin/phpunit --filter testSingleWorkerFailureLimitTriggersShutdown
```

## Architecture

### Entry Point
`ServeCommand` (`pm:serve`) wires dependencies from DI and starts `ProcessManagerLoop` inside a ReactPHP event loop.

### Core Loop
`ProcessManagerLoop` is the heart — a tick-based state machine running on a configurable timer (default 200ms). Each tick checks all workers, handles exits, schedules restarts with exponential backoff, and manages graceful shutdown. The `tick()` method receives `ShutdownState` and workers by reference, returns `?int` (exit code or null to continue).

### Worker Lifecycle
- `WorkerState` — mutable state per worker (process handle, failure timestamps, scheduled restart time, stop signals)
- `WorkerProcessFactory` (behind `WorkerProcessFactoryInterface`) — creates `symfony/process` instances for `messenger:consume`
- `WorkerOutputHandler` — buffers stdout/stderr per worker, flushes on exit
- `WorkerOutputFormatter` — enriches JSON log lines with `worker_id`, prefixes non-JSON lines with `[worker N]`

### Shutdown
`ShutdownState` tracks whether shutdown was requested and why (`ShutdownReason::SIGNAL` or `FAILURE_LIMIT`). The loop sends SIGTERM to all workers and waits for them to exit.

### HTTP & Metrics
`HttpServer` (ReactPHP) serves `GET /` (health) and `GET /metrics` (Prometheus text format). `MetricsRegistry` holds `Counter` and `Gauge` instances; `PrometheusTextRenderer` formats output.

### Configuration & DI
`Configuration` defines the bundle config schema. `SymfonyProcessManagerExtension` loads `services.yaml` and wires transport configs + HTTP settings into `ServeCommand`. Value objects `TransportConfig` and `ConsumeArgs` are immutable (`readonly`) with factory methods.

## Testing Notes

Code conventions and testing rules are in `CONTRIBUTING.md`. Additional context for agents:

- Time is controlled via injected `ClockInterface` and test fakes (`AutoAdvancingClock`, `FakeProcessFactory`, `FakeLoop`)
- E2E tests use `ConsoleProcessRunner`/`ConsoleProcessSession` to spawn real `pm:serve` processes and `JsonLogParser` to validate output
- Test fixtures app lives in `tests/Fixtures/app/` (minimal Symfony app with Doctrine messenger transport)

## Issue Tracking (Beads)

This project uses **bd** (beads) for issue tracking. Run `bd onboard` to get started.

### Quick Reference

```bash
bd ready              # Find available work
bd create "Title" -p 0 # Create a new issue (P0 example)
bd show <id>          # View issue details
bd update <id> --status in_progress  # Claim work
bd close <id>         # Complete work
bd dep add <child> <parent>  # Mark parent blocked by child
bd sync               # Sync with git
```

### Epics, Tasks, Sub-tasks (Hierarchy)

Beads supports hierarchical IDs for epics and sub-issues:

- `bd-a3f8` (Epic)
- `bd-a3f8.1` (Task)
- `bd-a3f8.1.1` (Sub-task)

**Rules:**

- Prefer creating sub-issues under the current epic instead of creating unrelated top-level issues.
- Sub-issues should use hierarchical IDs (`bd-<epic>.1`, `bd-<epic>.2`, ...), not standalone top-level IDs.
- Sub-issue titles are scoped to the epic: do not repeat the epic title or prefix the parent ID in the title.
- Use short, action-oriented titles for tasks/sub-tasks (verb phrase + object).
- When you split work, the parent issue must be blocked by its children (use `bd dep add <child> <parent>`).
- A parent issue is only closed after all sub-issues are closed.
- Keep sub-issues small and independently completable (one primary outcome per issue).

## Landing the Plane (Session Completion)

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work**
   - Create issues for anything that needs follow-up
   - If follow-up work is part of the current epic, create it as a sub-issue (hierarchical ID) and link it so it blocks the parent (`bd dep add <child> <parent>`)
2. **Run quality gates** (if code changed) - `composer check` must pass
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd sync
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds

<!-- BEGIN BEADS INTEGRATION v:1 profile:minimal hash:ca08a54f -->
## Beads Issue Tracker

This project uses **bd (beads)** for issue tracking. Run `bd prime` to see full workflow context and commands.

### Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --claim  # Claim work
bd close <id>         # Complete work
```

### Rules

- Use `bd` for ALL task tracking — do NOT use TodoWrite, TaskCreate, or markdown TODO lists
- Run `bd prime` for detailed command reference and session close protocol
- Use `bd remember` for persistent knowledge — do NOT use MEMORY.md files

## Session Completion

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd dolt push
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
<!-- END BEADS INTEGRATION -->
