# Autoscaler

The autoscaler dynamically adjusts the number of `messenger:consume` worker processes per consumer pool. It is opt-in: consumers without an `autoscaler` block keep their static `processes: N` behavior.

## Architecture

```
AutoscalerLoop  (slow tick — autoscaler_interval_sec, default 10s)
  │
  ▼
For each pool:
  1. WorkerPool::sample(now)               # update EWMA inputs
  2. Build PoolSnapshot (smoothed values)
  3. ScalingStrategy::decide(snapshot) → desired count
  ▼
PriorityArbiter::allocate(desired, pools)  # only when total_cap is set
  ▼
WorkerPool::setTarget(allocated, now)
  └── clamp [min,max] → step caps → cooldowns → reconcile
```

`ProcessManagerLoop` (fast tick) is unchanged in spirit: it spawns/restarts/exits workers. The autoscaler only adjusts each pool's *target* count; the fast tick converges toward that target by spawning new workers and moving excess workers into a `draining` list (SIGTERM, then SIGKILL after `shutdown_timeout`).

`AutoscalerLoop::start()` emits a one-shot snapshot of pool-level gauges (`autoscaler_target_workers`, `autoscaler_current_workers`, `autoscaler_unmet_demand`, `worker_busy_workers`) for every registered pool before scheduling the periodic timer. This bootstrap emission carries the pool's initial state (`target = min`, no scaling decision applied) and exists so that `/metrics` is consistent from t=0 — without it, gauges would be absent for up to one `autoscaler_interval_sec` after boot, breaking dashboards that derive the consumer template variable from `autoscaler_current_workers` and starving Fixed-strategy pools of visibility until they happen to tick.

A pool is keyed by **consumer label** (the YAML key under `consumers:`), not by transport. A multi-transport consumer is a single pool whose worker processes consume every transport in the consumer's `transports` list — the strategy decides one process count for the whole pool.

## Strategy contract

Strategies are pure functions: `PoolSnapshot → int`.

```php
interface ScalingStrategyInterface {
    public function decide(PoolSnapshot $snapshot): int;
}
```

`PoolSnapshot` carries:

- `consumer` (string): pool identity.
- `transports` (list<string>): the transports the pool reads from. Useful for strategies that want per-transport visibility (e.g. queue-depth lookups).
- Smoothed values: `busyWorkers`, `idleWorkers`, `throughputPerSecond` (aggregate sum across transports), `throughputByTransport` (per-transport breakdown).
- `currentWorkers`, `min`, `max`, `secondsSinceLastScaleUp`/`Down`, `recentFailureCount`.

`busyWorkers` and `idleWorkers` are floats (raw EWMA output), so a worker that is busy half the time appears as `0.5` rather than rounding to `0` or `1` — this is what keeps `utilization` strategies stable under bursty traffic. Strategies do **not** know about cooldowns, step caps, or history — those concerns live in the pool's stability layer.

A strategy may return any non-negative integer; the pool is responsible for clamping into `[min, max]`.

### Built-in strategies

- **`fixed`** — returns the configured count always. Used as the implicit fallback when a consumer has no `autoscaler` block (count = `processes`).
- **`utilization`** — applies hysteresis around target utilization. Computes `util = busy / max(1, current)` and:
  - if `util > scale_up_threshold`, returns `ceil(busy / target)` (under-provisioned, ask for more);
  - if `util < scale_down_threshold`, returns `ceil(busy / target)` (over-provisioned, ask for less);
  - otherwise returns `current` (within deadband — hold).

  Defaults: `scale_up_threshold = target`, `scale_down_threshold = target × 0.5`. Without a deadband, EWMA noise around the target causes flapping; the deadband + smoothing + cooldowns are layered defences.
- **`service`** — references a custom strategy service via `strategy.id`. The service must implement `ScalingStrategyInterface`.

## Stability layer (WorkerPool)

`WorkerPool::setTarget(rawDesired, now)` applies, in order:

1. **Smoothing** of inputs (already done at sample-time, before the strategy runs).
2. **Clamp** to `[min, max]`.
3. **Step caps**: `next = clamp(target, current - scale_down_step, current + scale_up_step)`.
4. **Cooldowns** (asymmetric): if scaling up, ensure `now - last_scaled_up_at >= scale_up_cooldown_sec`; symmetric check for scale-down. Short up cooldowns (~30s) and long down cooldowns (~300s) are critical: brief load spikes should add capacity quickly, but capacity should leave slowly to absorb traffic re-bursts.
5. **Reconcile workers**:
   - Scale up: append new `WorkerState` instances; the fast loop will spawn their processes on the next tick.
   - Scale down: pick idle-first, then highest-id, mark as `draining`. The fast loop sends SIGTERM and waits for graceful exit; `shutdown_timeout` escalates to SIGKILL if needed.

Smoothing + cooldowns + step caps replace traditional hysteresis.

### EWMA formula (time-weighted)

```
α = 1 - exp(-Δt / τ)
ewma = α · x_new + (1 - α) · ewma_prev
```

where `τ = smoothing_window_sec` and `Δt` is real elapsed seconds since the previous update. The naive `α = 1 / (window + 1)` form is **not** used: tick spacing is irregular, and a uniform-spacing assumption produces wrong values when the loop is busy.

### Per-transport throughput

For multi-transport consumers, the pool maintains an EWMA per transport (driven by the IPC `transport` field on `MessengerEventMessage` for `event=handled` and `event=failed`) plus a rolled-up aggregate sum used by strategies. The aggregate keeps the strategy interface stable; the per-transport map is exposed via `PoolSnapshot::throughputByTransport` for dashboards and custom strategies that want finer-grained signal.

## Priority arbitration

`total_cap` is optional. When unset, the autoscaler skips arbiter construction and pools receive their raw target directly.

When `total_cap` is set, every consumer is uniformly wrapped in an `AutoscalerConfig`:

- Consumer with `autoscaler` block → that config.
- Consumer without `autoscaler` block → implicit `AutoscalerConfig{ min: processes, max: processes, priority: PHP_INT_MAX, strategy: FixedStrategy(processes) }`. Fixed pools at `PHP_INT_MAX` are reserved first; their demand-above-min is always 0.

### Algorithm

1. **Validate at config load**: `sum(pool.min for all pools) <= total_cap`.
2. Each pool starts allocated `min`. `remaining = total_cap - sum(mins)`.
3. **Process priority groups descending**.
4. Within a group, `demand_above_min = target - min` per pool. If `sum(demand_above_min) == 0`, skip the group.
5. If group demand fits in `remaining`, satisfy fully. Else split proportionally to demand-above-min within the group.
6. **Tie-break for rounding remainder**: highest fractional remainder first; then highest current worker count; then alphabetical by consumer label.
7. Lower-priority groups receive what's left — possibly forced down to `min` during high-priority spikes.

`autoscaler_unmet_demand{consumer}` records `desired - allocated` per evaluation.

## Busy/idle IPC

Pong-only state reporting is not viable: `WorkerIpcSubscriber` only reads stdin during `WorkerRunningEvent`, which fires *between* message handling cycles. A long-running handler would block ping/pong, leaving the manager with stale state at the worst possible moment.

The bundle uses proactive one-way state-change events instead. All four are carried in a single `MessengerEventMessage` envelope distinguished by the `event` field; each carries the receiver name (`getReceiverName()`) so the manager records busy state with the real transport that delivered the work. See `spec/ipc-protocol.md` for the wire schema.

- `event=received` is emitted on `WorkerMessageReceivedEvent` (just before handler dispatch).
- `event=handled` / `event=failed` are emitted on the matching terminating events, also carrying `duration_seconds` (and `error_class` on failed).
- `event=retried` is emitted on `WorkerMessageRetriedEvent`.

Manager state machine:

- Receive `event=received` → mark worker `Busy`, set `worker_busy{worker, consumer, transport}=1`.
- Receive `event=handled` or `event=failed` → mark worker `Idle`, set `worker_busy{...}=0`, increment per-transport throughput EWMA. On `handled`, also increment `messages_processed_total{consumer, transport}`.
- Receive `event=retried` → no busy/idle change (the original `received` slot is reused; in-flight gauge unchanged).
- New worker → `Idle` until first `event=received`.

`PongMessage` is unchanged. Pongs continue as a liveness signal; busy/idle is fully decoupled.

## Scale-down mechanics

- **How**: SIGTERM only. Reuses `shutdown_timeout` and `worker_sigkills_total` from the manager-shutdown path. Same SIGKILL escalation applies if a draining worker won't exit.
- **Which worker**: idle first (using busy/idle state from reactive IPC), then highest worker id as a fallback.
- **Counting (strategy snapshot)**: draining workers are excluded from `currentWorkers`, `busyWorkers`, and `idleWorkers` in the snapshot fed to scaling strategies. They are not future capacity — including them would let a hung drain block scale-up under load.
- **Counting (observability metrics)**: by contrast, the `worker_busy_workers{consumer}` gauge counts busy workers across the entire pool, including any draining worker still finishing its last message. A worker handling a message is busy regardless of whether it is being torn down. The split keeps two coherent views:
  - `autoscaler_*` gauges = strategy view (excludes draining)
  - `worker_*` gauges = ground-truth view (includes draining until exit)
- **Busy/idle transitions during drain**: a draining worker may emit further `MessengerEventMessage` envelopes (`event=received` followed by `handled` / `failed`) between drain entry and SIGTERM delivery (Symfony Messenger's consume loop checks `shouldStop` only between messages, so it can pick up one more message in that window). These transitions are honoured: `WorkerState->runState`, `worker_busy_workers`, and the per-worker `worker_busy` gauge all move with the worker's actual activity until process exit. This is the same rationale as the per-worker liveness gauges below — drain-progress visibility is more useful than freezing at drain entry, and the autoscaler/arbiter view is unaffected because draining workers are excluded from `activeBusyWorkerCount()` and the strategy snapshot.
- **Worker liveness gauges**: `worker_last_pong_timestamp` and `worker_busy` entries are cleared from the registry when the worker process exits (not on entry to draining). Keeping them set during the drain window preserves per-worker visibility into drain progress — operators can see which workers are still busy with a final message and which have already finished.

## Validation

Configuration validation enforces:

- `min >= 1`, `max >= min`.
- `autoscaler` block and top-level `processes` are mutually exclusive per consumer.
- `transports` is required and non-empty per consumer.
- When `total_cap` is set: `sum(autoscaler.min) + sum(processes for static pools) <= total_cap`.
- `strategy.type=service` requires `id`, and the referenced service must implement `ScalingStrategyInterface`.

## Out of scope (filed as follow-ups)

- `queue_depth` strategy (`MessageCountAwareInterface` + multi-container coordination)
- `scheduled` strategy
- `composite` strategy

The `ScalingStrategyInterface` is small and stable enough that adding these strategies is purely additive.
