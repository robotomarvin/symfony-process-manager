# Symfony Process Manager — Specifications

This directory contains the technical specification for the `robotomarvin/symfony-process-manager` library — a Symfony 7.4 bundle that supervises `messenger:consume` worker processes using a ReactPHP event loop.

## Specification Index

| File | Contents |
|---|---|
| [architecture.md](architecture.md) | Overall architecture, component map, and runtime data flow |
| [configuration.md](configuration.md) | Bundle configuration schema and all options |
| [autoscaler.md](autoscaler.md) | Dynamic worker scaling: strategies, EWMA, arbitration, busy/idle IPC |
| [worker-lifecycle.md](worker-lifecycle.md) | Worker state machine, restart logic, and failure handling |
| [ipc-protocol.md](ipc-protocol.md) | Internal IPC protocol between the manager and workers |
| [http-api.md](http-api.md) | HTTP server endpoints (health and metrics) |
| [metrics.md](metrics.md) | All exposed Prometheus metrics with labels and semantics |
| [shutdown.md](shutdown.md) | Shutdown triggers, signal handling, and graceful drain |
| [testing.md](testing.md) | Test architecture, conventions, and test utilities |

## Quick Summary

- **Command:** `bin/console pm:serve`
- **Language/Runtime:** PHP 8.5+, Symfony 7.4, ReactPHP event loop
- **Worker model:** N workers per **consumer** (a logical pool), each running `messenger:consume <transports...>`. A consumer may read one transport or several. With multiple transports, Messenger polls them in list order and processes the first available message, so list order = priority order (earlier transports drain before later ones get a turn — not fair sharing).
- **Restart policy:** Immediate on exit code 0; exponential backoff on non-zero; shutdown after failure limit
- **IPC:** JSON messages on stdin/stdout with `@spm:` prefix; `MessengerEventMessage` carries `event` (received/handled/failed/retried) plus the receiver `transport` so multi-transport pools attribute work correctly
- **Observability:** HTTP `/metrics` (Prometheus text), structured JSON logs enriched with `worker_id` and `consumer`
- **Shutdown:** SIGTERM propagated to all workers; loop exits when all workers stop
