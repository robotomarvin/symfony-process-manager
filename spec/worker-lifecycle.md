# Worker Lifecycle

## Overview

Each configured transport with `processes: N` results in N independent `WorkerState` instances. They are managed by `ProcessManagerLoop` through a tick-based state machine.

---

## State Machine

```
  ┌──────────┐
  │ PENDING  │◄─────────────────────────────────────────────────┐
  └────┬─────┘  (nextStartAt reached, or immediate)             │
       │ shouldStart(now) == true                               │
       ▼                                                        │
  ┌──────────┐                                                  │
  │ RUNNING  │  (process active, IPC in flight)                 │
  └────┬─────┘                                                  │
       │ process exits                                          │
       ▼                                                        │
  ┌──────────┐                                                  │
  │  EXITED  │                                                  │
  └────┬─────┘                                                  │
       │                                                        │
       ├── exit code 0 ──► clearFailures()                      │
       │                   scheduleImmediateRestart() ──────────┘
       │
       └── exit code ≠ 0 ──► recordFailure()
                              pruneOldFailures()
                                    │
                       ┌────────────┴────────────┐
                  count > limit           count ≤ limit
                       │                         │
                 ShutdownState            scheduleRestart(now + backoff)
                 FAILURE_LIMIT                   └────────────────────► PENDING
```

---

## WorkerState Fields

| Field | Type | Description |
|---|---|---|
| `id` | `int` | Immutable worker identifier (sequential per transport, 0-based) |
| `process` | `?Process` | symfony/process handle while running; null otherwise |
| `inputStream` | `?InputStream` | ReactPHP InputStream for IPC downlink (stdin of worker) |
| `failureTimestamps` | `float[]` | Unix timestamps of recent non-zero exits |
| `nextStartAt` | `float` | Unix timestamp when this worker may next be started; 0 = immediate |
| `stopped` | `bool` | true when the worker has been permanently stopped (shutdown in progress) |
| `stopSignalSent` | `bool` | true when SIGTERM has been sent; prevents double-signal |
| `lastPongAt` | `?float` | Unix timestamp of last received PongMessage |

---

## Lifecycle Events

### Worker Start (`startWorker`)

1. Create a `symfony/process` instance via `WorkerProcessFactoryInterface::create(transport, consumeArgs)`.
2. The command constructed: `[PHP_BINARY, bin/console, messenger:consume, transport, ...consumeArgs->toCliArguments()]`
3. No timeout, no idle timeout; output enabled.
4. Register stdout/stderr callbacks with `WorkerOutputHandler`.
5. Create a `React\Stream\InputStream` and pass it as process stdin.
6. Register the stream in `IpcFanout` for this worker ID.
7. Call `process->start()`.
8. Call `WorkerState::markStarted()` (sets `stopped=false`).
9. Increment `worker_starts_total{transport=...}` counter.

### Worker Running

On each tick for a running worker:

1. Call `process->checkTimeout()` (no-op as timeout is disabled, but required by symfony/process).
2. Check output callbacks (already invoked asynchronously by ReactPHP).
3. Call `dispatchIpcMessages()` — drain the IPC queue for this worker and process each message.
4. If shutdown is requested and stop signal not yet sent: send SIGTERM to process, mark stop signal sent.

### Worker Exit (`handleWorkerExit`)

Triggered when `isRunning()` returns false on a previously running worker:

1. Flush remaining buffered output via `WorkerOutputHandler::flush(workerId)`.
2. Unregister from `IpcFanout`.
3. Clear the InputStream from WorkerState.
4. Clear the Process from WorkerState.
5. Increment `worker_exits_total{exit_code=N}` counter.
6. Branch on exit code:

**Exit code 0:**
- `WorkerState::clearFailures()` — reset failure timestamps
- `WorkerState::scheduleImmediateRestart()` — set `nextStartAt = 0`
- Log info: worker exited cleanly

**Exit code != 0:**
- `WorkerState::recordFailure()` — append current timestamp to `failureTimestamps`
- Prune timestamps older than `failureWindowSeconds`
- Increment `worker_failures_total{transport=...}` counter
- Count remaining timestamps
- If `count > failureLimit`:
  - Log error: failure limit exceeded, shutting down
  - `ShutdownState::request(ShutdownReason::FAILURE_LIMIT)`
  - Mark worker as stopped (no restart)
- Else:
  - Calculate backoff delay: `min(backoff_base × 2^(count - 1), backoff_max)`
  - Increment `worker_backoffs_total{transport=...}` counter
  - `WorkerState::scheduleRestart(now, delay)`
  - Log warning: worker failed, will retry in Xs

---

## Failure Tracking

### Sliding Window

Failures are tracked as a list of Unix timestamps. On each failure:

1. Append `clock->now()->getTimestamp()` (as float).
2. Remove all entries older than `now - failureWindowSeconds`.
3. Count remaining entries — this is the current failure count.

This means failures "expire" as time passes. A burst of failures all within the window counts fully; if the process then stays healthy for `failureWindowSeconds`, the count resets to 0 even without an explicit clean exit.

### Backoff Calculation

```
attempt = count of failures still in window
delay   = min(backoff_base × 2^(attempt - 1), backoff_max)
```

The `attempt` value used is the count **after** recording the failure and pruning the window. If `attempt=1`, delay = `backoff_base × 1 = backoff_base`.

### Failure Limit

The failure limit check is: `count > failureLimit` (strict greater-than). With `failure_limit: 3`, shutdown triggers on the **4th** failure within the window.

---

## Restart Scheduling

`WorkerState::nextStartAt` controls when the worker is eligible to start:

- **Immediate restart:** `nextStartAt = 0` — the worker starts on the next tick.
- **Scheduled restart:** `nextStartAt = now + delaySeconds` — the worker starts once `clock->now() >= nextStartAt`.

`WorkerState::shouldStart(float $now): bool` returns true when:
- `stopped == false`
- `process == null` (not already running)
- `now >= nextStartAt`

---

## Shutdown Interaction

When `ShutdownState::isRequested()` becomes true:

- No new workers are started (`shouldStart` is bypassed by checking shutdown state before calling it).
- For each running worker: send SIGTERM exactly once (guard via `stopSignalSent`).
- The loop continues ticking until `allWorkersStopped()` — i.e., all WorkerState instances have `isRunning() == false`.
- The tick returns a non-null exit code (0) only when all workers have stopped.

Workers are expected to handle SIGTERM gracefully and exit within a reasonable time; there is no hard kill timeout in the current implementation.

---

## Worker Process Command

The subprocess command built by `WorkerProcessFactory`:

```
<PHP_BINARY> <project_dir>/bin/console messenger:consume <transport> [queues...] \
  [--memory-limit=<N>] [--time-limit=<N>] [--limit=<N>] [--sleep=<N>] \
  [extra_flags...]
```

- Queues are passed as positional arguments (before flags).
- `extra` entries are appended verbatim after all other flags.
- `null` values for optional args are omitted entirely.
- Working directory: project root (from `KernelInterface::getProjectDir()`).
- No timeout, no idle timeout.
- Environment: inherited from the parent process.
