# Metrics

## Overview

`MetricsRegistry` collects counters, gauges, and histograms in-process. `PrometheusTextRenderer` formats them in the Prometheus text exposition format, served via `GET /metrics`.

Metrics are incremented/set by the process manager loop and by the worker output formatter.

---

## Metric Types

### Counter

A monotonically increasing value. Never decreases. Counter names get a `_total` suffix in the Prometheus output.

### Gauge

A value that can increase or decrease. Used for current state or timestamps.

### Histogram

Cumulative bucket counts plus `_sum` and `_count`. Buckets are sorted and deduped on construction; `+Inf` is appended automatically by the renderer. `observe(value, labels)` increments every bucket whose upper bound is `>= value`, accumulates the sum, and increments the count.

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

Updated in `handleIpcMessage()` when a `PongMessage` is received. Cleared when the worker process exits.

---

### `worker_busy`

**Type:** Gauge
**Labels:** `worker`, `transport`
**Description:** Per-worker busy state (1.0 while a message is being handled, 0.0 otherwise). Cleared when the worker process exits — including draining workers, which keep emitting busy/idle transitions through the drain window so operators can observe drain progress per worker.

Set on `MessengerEventMessage` with `event=received` (busy); cleared on `event=handled` or `event=failed` (idle).

---

## Messenger Message Metrics

All five metrics below are gated by `metrics.messages.enabled` (default `true`). When disabled, the in-worker `WorkerIpcSubscriber` is removed at compile time and the loop short-circuits any `MessengerEventMessage` it might still see — zero runtime cost.

The `message_class` label cardinality is controlled by `metrics.messages.whitelist`:

- Empty whitelist: every FQCN is its own label value.
- Whitelist set: each entry is either an exact FQCN or a glob (`*` / `?` resolved with `fnmatch(..., FNM_NOESCAPE)`). Misses are bucketed under `message_class="other"`.

### `messenger_messages_processed_total`

**Type:** Counter
**Labels:** `transport`, `message_class`
**Description:** Total Messenger messages handled successfully by workers of the given transport.

```
# HELP messenger_messages_processed_total Total messenger messages handled successfully
# TYPE messenger_messages_processed_total counter
messenger_messages_processed_total{message_class="App\\Message\\Foo",transport="async"} 1427
```

Incremented on `MessengerEventMessage` with `event=handled`.

---

### `messenger_messages_failed_total`

**Type:** Counter
**Labels:** `transport`, `message_class`
**Description:** Total Messenger messages that failed handling.

Incremented on `MessengerEventMessage` with `event=failed`. The throwing exception's class is carried in the IPC payload (`errorClass`) but is not currently exposed as a label — keep `message_class` cardinality predictable.

---

### `messenger_messages_retried_total`

**Type:** Counter
**Labels:** `transport`, `message_class`
**Description:** Total Messenger messages scheduled for retry.

Incremented on `MessengerEventMessage` with `event=retried`. Retries do not affect the in-flight gauge: the receive→retried transition reuses the original received slot.

---

### `messenger_message_duration_seconds`

**Type:** Histogram
**Labels:** `transport`, `message_class`
**Description:** End-to-end handling duration in seconds, observed on both `handled` and `failed` events.

Default buckets: `[0.01, 0.05, 0.1, 0.5, 1, 5, 10, 30, 60]`. Override via `metrics.messages.duration_buckets`. Buckets are sorted and deduped on load; `+Inf` is appended automatically by the renderer.

```
# HELP messenger_message_duration_seconds Messenger message handling duration in seconds
# TYPE messenger_message_duration_seconds histogram
messenger_message_duration_seconds_bucket{message_class="App\\Message\\Foo",transport="async",le="0.01"} 0
messenger_message_duration_seconds_bucket{message_class="App\\Message\\Foo",transport="async",le="0.05"} 1
messenger_message_duration_seconds_bucket{message_class="App\\Message\\Foo",transport="async",le="+Inf"} 1
messenger_message_duration_seconds_sum{message_class="App\\Message\\Foo",transport="async"} 0.0250
messenger_message_duration_seconds_count{message_class="App\\Message\\Foo",transport="async"} 1
```

Timing is captured in `WorkerIpcSubscriber`: a clock reading on `WorkerMessageReceivedEvent` is keyed by `spl_object_id($envelope->getMessage())` and popped on `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent`. The map has a 1024-entry FIFO eviction cap to bound growth if a `received` is never paired (e.g. crash before handle/fail). Duration is `null` in that case and the histogram is not observed.

---

### `messenger_messages_in_flight`

**Type:** Gauge
**Labels:** `transport`
**Description:** Messages currently in flight per transport. Incremented on `received`, decremented on `handled` / `failed`. Floored at 0 to tolerate decrement-without-prior-increment.

```
# HELP messenger_messages_in_flight Messenger messages currently in flight per transport
# TYPE messenger_messages_in_flight gauge
messenger_messages_in_flight{transport="async"} 3
```

---

### `worker_busy_workers`

**Type:** Gauge
**Labels:** `transport`
**Description:** Count of currently-busy workers per pool, set by the autoscaler on each evaluation. Includes draining workers still finishing their last message — a worker handling a message is busy regardless of whether it is being torn down. Distinct from the strategy-snapshot view (`autoscaler_current_workers`), which excludes draining workers because they are not future capacity.

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
# TYPE <name> <counter|gauge|histogram>
<name>[{<label_name>="<label_value>"[,...]}] <value>
```

Rules:
- Counter metric names get `_total` appended.
- Histograms emit `<name>_bucket{le="..."}` (one per bound, plus `le="+Inf"`), `<name>_sum`, and `<name>_count` lines. Bucket counts are cumulative.
- Label values are escaped: backslashes become `\\`, double-quotes become `\"`, newlines become `\n`.
- Float values with no decimal point get `.0` appended (e.g., `1` → `1.0`). Histogram bucket bounds are rendered as plain decimals (no scientific notation, no trailing zeros), and integer-valued bounds are rendered without a decimal point (e.g. `1`, `60`) for round-trip compatibility with Prometheus tooling.
- Metrics with no recorded values still emit HELP and TYPE lines with no data lines.

---

## Label Semantics

| Label | Values | Notes |
|---|---|---|
| `transport` | Transport name as configured (e.g., `async`) | Set at worker start; persists for the process lifetime |
| `exit_code` | String integer (e.g., `"0"`, `"1"`, `"143"`) | SIGTERM exit is typically 143 (128+15) |
| `worker` | String integer worker ID (e.g., `"0"`, `"1"`) | IDs are 0-based, scoped per transport |
| `message_class` | Message FQCN, or `"other"` when whitelist is set and FQCN matches no entry | Cardinality controlled by `metrics.messages.whitelist` |
| `le` | Histogram bucket upper bound, or `+Inf` | Internal label emitted only on `<name>_bucket` lines |
| `reason` | Autoscaler skip reason | See `autoscaler_decisions_skipped_total` |

---

## Grafana Dashboard

`docker/grafana/provisioning/dashboards/process-manager.json` exposes these metrics in three rows:

- **Stat header** — `process_manager_running`, `messenger_messages_processed_total`, `worker_last_pong_timestamp` (active worker count), `worker_failures_total`, `worker_backoffs_total`.
- **Messages** — `messenger_messages_processed_total`, `messenger_messages_failed_total`, `messenger_messages_retried_total`, `messenger_messages_in_flight`, and `messenger_message_duration_seconds` quantiles per transport.
- **Worker Lifecycle** — `worker_starts_total`, `worker_exits_total` by `exit_code`, `worker_failures_total` + `worker_backoffs_total`.
- **Autoscaler** —
  - Stat row: `autoscaler_target_workers`, `autoscaler_current_workers`, `autoscaler_unmet_demand` (summed across selected transports).
  - *Workers per Transport* — stacked `autoscaler_current_workers` with dashed `autoscaler_target_workers` overlay (stepAfter).
  - *Pool Utilization* — `worker_busy_workers / autoscaler_current_workers`, percentunit, thresholds 0.7 / 0.85 / 0.95.
  - *Busy vs Idle Workers* — stacked `worker_busy_workers` and `current − busy`.
  - *Scale Events / min* — rate of `autoscaler_scale_up_total` and `autoscaler_scale_down_total`.
  - *Skipped Decisions / min by Reason* — stacked rate of `autoscaler_decisions_skipped_total` by `reason`.
- **Worker Liveness** — `time() − worker_last_pong_timestamp` per worker.

The dashboard relies on the autoscaler emitting `autoscaler_current_workers` for **every** configured transport, including pools using the Fixed strategy — `AutoscalerLoop` evaluates all pools registered through `services.yaml`, so this holds without special-casing.

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

// Observe a histogram (buckets are used only on first observation)
$this->metrics->observeHistogram(
    'my_histogram_name',
    $valueSeconds,
    'Help text',
    [0.01, 0.05, 0.1, 0.5, 1.0],
    ['label_key' => 'label_value'],
);
```

Metric names must be unique across counters, gauges, and histograms. There is no collision check at the registry level; duplicate names of the same type will share the same metric object.

### IPC events from workers

Worker → manager events flow through `MessengerEventMessage` (one IPC envelope, four event types):

- `received` — `WorkerMessageReceivedEvent`; `durationSeconds=null`, `errorClass=null`. Carries the message FQCN and `getReceiverName()` as `transport`.
- `handled` — `WorkerMessageHandledEvent`; `durationSeconds` filled when paired with a prior `received`.
- `failed` — `WorkerMessageFailedEvent`; `durationSeconds` filled when paired, `errorClass` is `$throwable::class`.
- `retried` — `WorkerMessageRetriedEvent`; no duration, no error class.

To add a new in-worker signal, prefer extending `MessengerEventMessage` with optional fields over introducing a new IPC envelope, so the existing IPC pipeline (codec allow-list, output handler, fanout) carries it for free.
