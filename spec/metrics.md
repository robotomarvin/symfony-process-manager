# Metrics

## Overview

`MetricsRegistry` collects counters and gauges in-process. `PrometheusTextRenderer` formats them in the Prometheus text exposition format, served via `GET /metrics`.

Metrics are incremented/set by the process manager loop and by the worker output formatter.

---

## Metric Types

### Counter

A monotonically increasing value. Never decreases. Counter names get a `_total` suffix in the Prometheus output.

### Gauge

A value that can increase or decrease. Used for current state or timestamps.

---

## Exposed Metrics

### `process_manager_running`

**Type:** Gauge  
**Labels:** none  
**Description:** 1.0 while the process manager is running normally; 0.0 when shutdown has been requested.

```
# HELP process_manager_running 1 if the process manager is running, 0 if shutting down
# TYPE process_manager_running gauge
process_manager_running 1
```

Set to `1.0` at startup and `0.0` when `ShutdownState::isRequested()` becomes true.

---

### `worker_starts_total`

**Type:** Counter  
**Labels:** `transport`  
**Description:** Total number of times a worker process was (re)started for the given transport.

```
# HELP worker_starts_total Total number of worker process starts
# TYPE worker_starts_total counter
worker_starts_total{transport="async"} 5
worker_starts_total{transport="priority"} 2
```

Incremented in `startWorker()` each time a worker subprocess is launched.

---

### `worker_exits_total`

**Type:** Counter  
**Labels:** `exit_code`  
**Description:** Total number of worker process exits, broken down by exit code.

```
# HELP worker_exits_total Total number of worker process exits
# TYPE worker_exits_total counter
worker_exits_total{exit_code="0"} 3
worker_exits_total{exit_code="1"} 2
```

Incremented in `handleWorkerExit()` with the actual exit code of the subprocess as a string label.

---

### `worker_failures_total`

**Type:** Counter  
**Labels:** `transport`  
**Description:** Total number of worker failures (non-zero exits) for the given transport.

```
# HELP worker_failures_total Total number of worker failures (non-zero exits)
# TYPE worker_failures_total counter
worker_failures_total{transport="async"} 2
```

Incremented alongside `worker_exits_total` when exit code != 0.

---

### `worker_backoffs_total`

**Type:** Counter  
**Labels:** `transport`  
**Description:** Total number of times a worker restart was delayed due to backoff.

```
# HELP worker_backoffs_total Total number of times a worker restart was backed off
# TYPE worker_backoffs_total counter
worker_backoffs_total{transport="async"} 2
```

Incremented when a failure is recorded but the failure limit is not yet reached and a backoff delay is scheduled.

---

### `worker_sigkills_total`

**Type:** Counter  
**Labels:** none  
**Description:** Total number of SIGKILLs sent to workers after `shutdown_timeout` elapsed during graceful shutdown.

```
# HELP worker_sigkills_total Total number of SIGKILLs sent to workers
# TYPE worker_sigkills_total counter
worker_sigkills_total 1
```

Only emitted when at least one worker fails to exit within `shutdown_timeout` seconds after SIGTERM. Use this as a signal that workers are not draining cleanly.

---

### `worker_last_pong_timestamp`

**Type:** Gauge  
**Labels:** `worker`  
**Description:** Unix timestamp (float seconds) of the last `PongMessage` received from the given worker. 0 if no pong has been received yet.

```
# HELP worker_last_pong_timestamp Unix timestamp of the last pong received from each worker
# TYPE worker_last_pong_timestamp gauge
worker_last_pong_timestamp{worker="0"} 1744459200.123
worker_last_pong_timestamp{worker="1"} 1744459200.456
```

The label value is the string representation of the worker's integer ID.

Updated in `handleIpcMessage()` when a `PongMessage` is received.

---

### `messages_processed_total`

**Type:** Counter  
**Labels:** `transport`  
**Description:** Total number of Messenger messages successfully processed by workers of the given transport.

```
# HELP messages_processed_total Total messages processed by workers
# TYPE messages_processed_total counter
messages_processed_total{transport="async"} 1427
```

Incremented by `ProcessManagerLoop::handleIpcMessage()` when it receives a `ProcessedCommandMessage` with `status=handled`.

---

### `worker_busy`

**Type:** Gauge
**Labels:** `worker`, `transport`
**Description:** Per-worker busy state (1.0 while a message is being handled, 0.0 otherwise). Cleared when the worker enters draining or exits.

Set on `WorkerStartedHandlingMessage` (busy) and `ProcessedCommandMessage` (idle).

---

### `worker_busy_workers`

**Type:** Gauge
**Labels:** `transport`
**Description:** Count of currently-busy workers per pool, set by the autoscaler on each evaluation.

---

### `autoscaler_target_workers`

**Type:** Gauge
**Labels:** `transport`
**Description:** Last autoscaler decision after the stability layer (clamp/step/cooldowns).

---

### `autoscaler_current_workers`

**Type:** Gauge
**Labels:** `transport`
**Description:** Active worker count per pool, excluding draining workers.

---

### `autoscaler_unmet_demand`

**Type:** Gauge
**Labels:** `transport`
**Description:** `desired - allocated` per autoscaler evaluation, after `PriorityArbiter`. Always 0 when `total_cap` is unset.

---

### `autoscaler_scale_up_total` / `autoscaler_scale_down_total`

**Type:** Counter
**Labels:** `transport`
**Description:** Number of scale-up / scale-down events applied (after stability layer). Skipped decisions (cooldown, step cap, at min/max) are not counted here.

---

### `autoscaler_decisions_skipped_total`

**Type:** Counter
**Labels:** `transport`, `reason`
**Description:** Number of autoscaler decisions skipped by the stability layer.

`reason` values:

- `cooldown_up` — scale-up cooldown not elapsed
- `cooldown_down` — scale-down cooldown not elapsed
- `step_cap` — direction was capped to zero by the step cap
- `at_min` — already at `min`
- `at_max` — already at `max`

---

## Prometheus Text Format

The renderer (`PrometheusTextRenderer`) generates output per the [Prometheus text format 0.0.4](https://prometheus.io/docs/instrumenting/exposition_formats/) specification:

```
# HELP <name> <help text>
# TYPE <name> <counter|gauge>
<name>[{<label_name>="<label_value>"[,...]}] <value>
```

Rules:
- Counter metric names get `_total` appended.
- Label values are escaped: backslashes become `\\`, double-quotes become `\"`, newlines become `\n`.
- Float values with no decimal point get `.0` appended (e.g., `1` → `1.0`).
- Metrics with no recorded values still emit HELP and TYPE lines with no data lines.

---

## Label Semantics

| Label | Values | Notes |
|---|---|---|
| `transport` | Transport name as configured (e.g., `async`) | Set at worker start; persists for the process lifetime |
| `exit_code` | String integer (e.g., `"0"`, `"1"`, `"143"`) | SIGTERM exit is typically 143 (128+15) |
| `worker` | String integer worker ID (e.g., `"0"`, `"1"`) | IDs are 0-based, scoped per transport |

---

## Adding New Metrics

Metrics are registered lazily via `MetricsRegistry`:

```php
// Increment a counter (creates it on first use)
$this->metrics->incrementCounter(
    'my_metric_name',
    'Human readable help text',
    ['label_key' => 'label_value'],
);

// Set a gauge
$this->metrics->setGauge(
    'my_gauge_name',
    $value,
    'Help text',
    ['label_key' => 'label_value'],
);
```

Metric names must be unique across counters and gauges. There is no collision check at the registry level; duplicate names of the same type will share the same metric object.
