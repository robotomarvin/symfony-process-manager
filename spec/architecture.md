# Architecture

## Overview

`robotomarvin/symfony-process-manager` is a Symfony bundle that runs and supervises Symfony Messenger worker processes. It is designed to replace running multiple `messenger:consume` commands manually or via a process supervisor like Supervisor.

The bundle exposes a single console command, `pm:serve`, which:

1. Starts a ReactPHP HTTP server for health checks and Prometheus metrics.
2. Spawns N worker subprocesses per configured **consumer**. Each worker runs `messenger:consume <transports...>`, where `<transports...>` is the consumer's transport list (one or more). Messenger polls listed transports in order and takes the first available message, so list order is priority order — `t1` is fully drained before `t2` gets a turn (no round-robin, no fair sharing).
3. Monitors workers in a tick-based event loop, restarting them according to a configurable restart policy.
4. Handles graceful shutdown on SIGTERM, draining all workers before exiting.

---

## Component Map

```
                    ┌──────────────────────────────────┐
                    │       pm:serve (ServeCommand)     │
                    └──────────────┬───────────────────┘
                                   │
                   ┌───────────────┴────────────────┐
                   │                                │
        ┌──────────▼──────────┐         ┌──────────▼──────────────┐
        │      HttpServer      │         │   ProcessManagerLoop     │
        │                     │         │                         │
        │  GET /  → health    │         │  tick every 200ms       │
        │  GET /metrics       │         │  spawn / restart workers │
        └──────────┬──────────┘         │  SIGTERM drain          │
                   │                   └──────────┬──────────────┘
        ┌──────────▼──────────┐                   │ spawns ×N per consumer
        │   MetricsRegistry   │         ┌──────────▼─────────────────────┐
        │                     │         │    Worker (symfony/process)     │
        │   Counter[]         │◄────────┤    messenger:consume <t1> <t2>  │
        │   Gauge[]           │ metrics │                                │
        └─────────────────────┘ update  │  stdout/stderr → OutputHandler │
                                        │  stdin         ← IpcFanout     │
                                        └────────────────────────────────┘
```

### Key Namespaces and Responsibilities

| Namespace | Responsibility |
|---|---|
| `Command\` | CLI entrypoint only; wires DI dependencies and starts the loop |
| `ProcessManager\` | Core tick-based state machine; `ProcessManagerLoop`, `WorkerState`, `ShutdownState` |
| `Worker\` | Process creation (`WorkerProcessFactory`) and in-worker IPC event subscriber (`WorkerIpcSubscriber`) |
| `Consumer\` | Consumer-pool config value object (`ConsumerConfig` — label + transport list + lifecycle/backoff/autoscaler config) |
| `Transport\` | `ConsumeArgs` value object (CLI flag mapping for `messenger:consume`) |
| `Ipc\` | IPC codec, messages, fanout, and worker context |
| `Output\` | Worker stdout/stderr capture, formatting, and IPC line extraction |
| `Http\` | ReactPHP HTTP server for health and metrics |
| `Metrics\` | Counter/Gauge registry and Prometheus text renderer |
| `DependencyInjection\` | Bundle configuration schema and service wiring |

---

## Runtime Data Flow

### Startup Sequence

```
pm:serve
  │
  ├─► HttpServer::start()        — bind ReactPHP HTTP server on host:port
  │
  └─► ProcessManagerLoop::run()
        │
        ├─► installSignalHandler()   — SIGTERM -> ShutdownState::request(SIGNAL)
        ├─► initializeWorkers()      — create one WorkerPool per consumer; each pool seeds N WorkerState (one process per worker, multi-transport workers consume the full transport list)
        └─► loop->addPeriodicTimer() — tick every min(poll_interval_ms) across consumers
```

### Per-Tick Execution (`tick()`)

```
tick()
  │
  ├─ shutdown requested?
  │    └─ all workers stopped? → return exit code
  │
  ├─ for each WorkerState:
  │    ├─ not started / restart due → startWorker()
  │    │    ├─ create Process via WorkerProcessFactory
  │    │    ├─ attach stdout/stderr callbacks → WorkerOutputHandler
  │    │    ├─ create InputStream, register in IpcFanout
  │    │    └─ start Process
  │    │
  │    ├─ running:
  │    │    ├─ dispatchIpcMessages() — getAndClearIpcMessages() → handleIpcMessage()
  │    │    └─ shutdown? → send SIGTERM (once)
  │    │
  │    └─ exited (process finished):
  │         ├─ flush remaining output
  │         ├─ unregister from IpcFanout
  │         └─ handleWorkerExit()
  │               ├─ exit code 0: clearFailures(), scheduleImmediateRestart()
  │               └─ exit code ≠ 0:
  │                    ├─ recordFailure()
  │                    ├─ prune failures outside window
  │                    ├─ failureCount > failureLimit? → ShutdownState::request(FAILURE_LIMIT)
  │                    └─ otherwise: scheduleRestart(now + backoff)
  │
  └─ maybeSendPing() — IpcFanout::send(PingMessage) every N ticks
```

### Worker Output Flow

```
Worker stdout/stderr
  │
  └─► WorkerOutputHandler::handleOutput()
        │
        ├─ buffer partial lines
        └─ on complete line:
              ├─ IpcCodec::isIpcLine()? → decode → queue as IpcMessage
              └─ non-IPC → WorkerOutputFormatter::format()
                    ├─ valid JSON object → inject worker_id and consumer into extra{}
                    └─ plain text → prepend "[worker N consumer-label] "
                    → write to STDOUT/STDERR
```

### IPC Message Flow (Manager → Worker)

```
ProcessManagerLoop::maybeSendPing()
  └─► IpcFanout::send(PingMessage)
        └─► InputStream (worker stdin) for each registered worker
              └─► WorkerIpcSubscriber::onWorkerRunning() reads from STDIN
                    └─► decode PingMessage → send PongMessage to stdout
```

### IPC Message Flow (Worker → Manager)

```
Worker emits MessengerEventMessage / PongMessage to stdout
  └─► WorkerOutputHandler detects @spm: prefix → queues IpcMessage
        └─► ProcessManagerLoop::dispatchIpcMessages()
              └─► handleIpcMessage()
                    ├─ PongMessage → WorkerState::setLastPongAt()
                    └─ MessengerEventMessage → switch(event):
                          ├─ received → mark Busy; worker_busy{worker, consumer, transport}=1
                          ├─ handled  → mark Idle; pool->recordMessageProcessed(message.transport);
                          │             messages_processed_total{consumer, transport}++
                          ├─ failed   → mark Idle; pool->recordMessageProcessed(message.transport)
                          └─ retried  → (no busy/idle change)
```

---

## Dependency Graph (simplified)

```
ServeCommand
  ├── HttpServer
  │     └── MetricsRegistry
  │           ├── Counter[]
  │           ├── Gauge[]
  │           └── PrometheusTextRenderer
  └── ProcessManagerLoop
        ├── ClockInterface (symfony/clock)
        ├── LoggerInterface (PSR-3)
        ├── WorkerProcessFactoryInterface
        │     └── KernelInterface (for project dir)
        ├── WorkerOutputHandler
        │     └── WorkerOutputFormatter
        │           └── MetricsRegistry
        ├── MetricsRegistry
        ├── IpcFanout
        │     └── IpcCodec
        └── WorkerContextInterface
```

---

## Design Constraints

- **No wall-clock sleeps** — time is controlled via `ClockInterface`; tests inject `AutoAdvancingClock`.
- **No `new` inside logic classes** — all object creation goes through DI or factory interfaces.
- **Final classes** — all concrete classes are `final`; extension points are interfaces.
- **Single event loop** — the ReactPHP `LoopInterface` instance is shared between HTTP server and process manager.
- **Stdout as the log sink** — workers write JSON logs to stdout; the manager captures and enriches them.
- **Stdin as the IPC downlink** — the manager writes IPC messages to worker stdin via `InputStream`.
