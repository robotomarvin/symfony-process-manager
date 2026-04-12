# Shutdown

## Overview

The process manager supports a single shutdown path: **graceful drain**. All workers are sent SIGTERM and the loop continues until every worker has exited.

There is no hard kill (SIGKILL) or timeout — the loop waits indefinitely for workers to exit. Workers are expected to complete their current message and exit cleanly.

---

## Shutdown Triggers

### 1. SIGTERM (external signal)

The most common trigger. Sent by:
- `docker stop` (default signal)
- Kubernetes pod eviction
- A process supervisor (systemd, Supervisor)
- A human operator: `kill <pid>`

When `pm:serve` receives SIGTERM, a ReactPHP signal handler calls `ShutdownState::request(ShutdownReason::SIGNAL)`.

**Reason:** `ShutdownReason::SIGNAL`  
**Exit code:** 0

### 2. Failure Limit Exceeded

If any worker exceeds its `failure_limit` within `failure_window` seconds, the loop calls `ShutdownState::request(ShutdownReason::FAILURE_LIMIT)`.

**Reason:** `ShutdownReason::FAILURE_LIMIT`  
**Exit code:** 0 (the loop still exits cleanly; alerting should come from the `worker_failures_total` metric)

---

## ShutdownState

`ShutdownState` is a mutable object shared between the signal handler and the loop:

```php
final class ShutdownState
{
    private bool $requested = false;
    private ?ShutdownReason $reason = null;

    public function request(ShutdownReason $reason): void;  // idempotent: first caller wins
    public function isRequested(): bool;
    public function getReason(): ?ShutdownReason;
}
```

`request()` is idempotent: calling it multiple times does not change the reason already captured. This means if both a SIGTERM and a failure limit occur near-simultaneously, the first one wins.

---

## ShutdownReason Enum

```php
enum ShutdownReason
{
    case SIGNAL;          // External signal (e.g. SIGTERM)
    case FAILURE_LIMIT;   // Worker exceeded failure_limit within failure_window
}
```

---

## Shutdown Sequence

```
1. ShutdownState::request() is called
   │
   ├─ process_manager_running gauge set to 0.0
   └─ on next tick:

2. Loop detects shutdown requested
   └─ for each running worker:
        └─ SIGTERM sent (exactly once, guarded by stopSignalSent flag)

3. Workers receive SIGTERM
   └─ each worker:
        ├─ Symfony Messenger catches SIGTERM
        ├─ finishes current message (if any)
        └─ exits (code 0 if clean, non-zero if mid-message error)

4. Tick loop continues
   └─ for each exited worker:
        ├─ flush buffered output
        ├─ handleWorkerExit() called
        │   └─ exit code 0: clearFailures() (no restart scheduled — stopped=true)
        │   └─ exit code ≠ 0: record failure, but do NOT restart (stopped=true)
        └─ mark worker as stopped

5. allWorkersStopped() returns true
   └─ tick() returns exit code 0
        └─ ReactPHP loop::stop() called
             └─ pm:serve command exits with code 0
```

---

## Worker SIGTERM Handling

Each worker runs `messenger:consume`, which installs its own SIGTERM handler via Symfony:

- If the worker is idle (waiting for messages): exits almost immediately.
- If the worker is processing a message: finishes the message, then exits.
- If the worker is in a long-running handler: the operator must ensure handlers respect stop signals or use `time_limit` to bound execution.

The manager sends SIGTERM to the process group of the worker subprocess (via `Process::stop()`).

---

## Restart Suppression During Shutdown

Once `ShutdownState::isRequested()` is true:

- `shouldStart()` is never called for pending workers; the loop skips start logic entirely.
- Workers that exit during the drain phase have `stopped=true` set, preventing restart scheduling.
- `scheduleImmediateRestart()` and `scheduleRestart()` are not called while shutdown is in progress.

---

## Double SIGTERM

If a second SIGTERM is received while draining:

- `ShutdownState::request()` is called again → no-op (reason already set, flag already true).
- The SIGTERM was already forwarded to workers on the first shutdown tick.
- No second SIGTERM is sent to workers.

If the operator wants to force-kill, they should send SIGKILL directly to the workers or to the manager process (which will orphan workers).

---

## Failure Limit Shutdown

When a failure limit is hit:

1. `ShutdownState::request(ShutdownReason::FAILURE_LIMIT)` is called.
2. The triggering worker is marked `stopped=true` (it will not be restarted).
3. All other running workers receive SIGTERM on the next tick.
4. The drain proceeds as above.

The process manager exits with code 0 even in this case. Downstream monitoring should use the `worker_failures_total` counter and `process_manager_running` gauge to detect this condition and alert.

---

## Exit Codes

| Scenario | Exit Code |
|---|---|
| Clean SIGTERM drain | 0 |
| Failure limit shutdown | 0 |
| Unexpected exception in loop (uncaught) | PHP default (255) |

All normal shutdown paths exit with 0. Non-zero exits from `pm:serve` itself indicate a programming error or infrastructure failure.
