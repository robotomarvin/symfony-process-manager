# Configuration

## Bundle Registration

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    RobotoMarvin\SymfonyProcessManager\SymfonyProcessManagerBundle::class => ['all' => true],
];
```

---

## Full Configuration Schema

```yaml
# config/packages/symfony_process_manager.yaml
symfony_process_manager:

  # Seconds to wait after SIGTERM before escalating to SIGKILL.
  # 0 = wait indefinitely (no SIGKILL escalation).
  # Min: 0. Default: 30.
  shutdown_timeout: 30

  # Optional global ceiling on the sum of workers across all pools.
  # When set, a PriorityArbiter shares the cap by priority groups.
  # Min: 1. Default: null (no cap).
  total_cap: ~

  # How often the autoscaler evaluates strategies (seconds).
  # Min: 1. Default: 10.
  autoscaler_interval_sec: 10

  # HTTP server for health checks and Prometheus metrics.
  # The server is always started; disable by not scraping it.
  http_server:
    host: '127.0.0.1'   # string, default: 127.0.0.1
    port: 9100           # int,    default: 9100

  # One entry per Symfony Messenger transport name.
  # At least one transport is required.
  # A transport must use either `processes` (static) OR `autoscaler` (dynamic),
  # never both — setting both is a configuration error.
  transports:
    <transport_name>:

      # Number of concurrent worker processes (static pool).
      # Min: 1. Default: 1 (when autoscaler is not configured).
      processes: 1

      # Dynamic worker scaling. Mutually exclusive with `processes`.
      # When omitted, the pool is static (count = `processes`).
      autoscaler:
        min: 1                          # required, min worker count
        max: 10                         # required, max worker count
        priority: 0                     # default 0; higher = preferred under total_cap
        smoothing_window_sec: 30        # default 30; EWMA time constant for busy/idle/throughput
        scale_up_cooldown_sec: 30       # default 30
        scale_down_cooldown_sec: 300    # default 300; intentionally longer than up cooldown
        scale_up_step: 2                # default 2; max workers added per evaluation
        scale_down_step: 1              # default 1; max workers removed per evaluation
        strategy:
          type: utilization             # 'fixed' | 'utilization' | 'service'
          target: 0.7                   # for utilization: ceil(busy / target). Default 0.7.
          # id: app.my_strategy         # for type: service. The service must implement
          #                             # ScalingStrategyInterface.

      # How many non-zero exit events within failure_window seconds
      # trigger a full shutdown. Min: 1. Default: 3.
      failure_limit: 3

      # Sliding window in seconds for counting failures.
      # Min: 1. Default: 60.
      failure_window: 60

      # Base delay in seconds for exponential backoff after a failure.
      # Backoff formula: min(backoff_base * 2^(attempt-1), backoff_max)
      # Min: 1. Default: 1.
      backoff_base: 1

      # Maximum backoff delay in seconds (caps the exponential growth).
      # Min: 1. Default: 30.
      backoff_max: 30

      # How often (in milliseconds) the tick loop polls this transport's workers.
      # The actual timer uses the minimum value across all transports.
      # Min: 1. Default: 200.
      poll_interval_ms: 200

      # Arguments forwarded to messenger:consume for this transport.
      consume_args:

        # --memory-limit <MB>  Worker exits after consuming this much memory.
        # int or null. Default: null (no limit).
        memory_limit: ~

        # --time-limit <seconds>  Worker exits after running this many seconds.
        # int or null. Default: null (no limit).
        time_limit: ~

        # --limit <count>  Worker exits after handling this many messages.
        # int or null. Default: null (no limit).
        limit: ~

        # --sleep <seconds>  Sleep duration when no messages are available.
        # int or null. Default: null (use messenger default).
        sleep: ~

        # Queue names to consume from (appended as positional arguments).
        # list<string>. Default: [].
        queues: []

        # Extra CLI flags passed verbatim to messenger:consume.
        # list<string>. Default: [].
        extra: []
```

---

## Minimal Working Configuration

```yaml
symfony_process_manager:
  transports:
    async: ~
```

This spawns one worker for the `async` transport with all defaults.

---

## Multi-Transport Example

```yaml
symfony_process_manager:
  http_server:
    host: '0.0.0.0'
    port: 9100

  transports:
    high_priority:
      processes: 3
      failure_limit: 5
      failure_window: 120
      backoff_base: 2
      backoff_max: 60
      consume_args:
        time_limit: 3600
        memory_limit: 128

    low_priority:
      processes: 1
      poll_interval_ms: 500
      consume_args:
        time_limit: 3600
        queues:
          - notifications
          - reports
        extra:
          - '--no-reset'
```

---

## Option Details

### `processes`

Controls how many `messenger:consume` subprocesses run concurrently for the transport. Each process has an independent failure counter and restart schedule. Setting this to N does **not** mean N messages are processed in parallel — each worker is single-threaded; parallelism comes from multiple OS processes.

### `failure_limit` and `failure_window`

A "failure" is any worker exit with a non-zero exit code. Failures are counted within a sliding window of `failure_window` seconds. If the number of failures exceeds `failure_limit` within the window, the process manager initiates a full shutdown (`ShutdownReason::FAILURE_LIMIT`).

A clean exit (code 0) resets the failure counter for that worker and triggers an immediate restart.

### `backoff_base` and `backoff_max`

Exponential backoff is applied to restart delays after non-zero exits (that do not yet hit the failure limit). The delay formula is:

```
delay = min(backoff_base × 2^(attempt - 1), backoff_max)
```

Where `attempt` is the number of failures recorded in the current window. Examples with `backoff_base=1, backoff_max=30`:

| attempt | delay |
|---------|-------|
| 1       | 1s    |
| 2       | 2s    |
| 3       | 4s    |
| 4       | 8s    |
| 5       | 16s   |
| 6       | 30s   |
| 7+      | 30s   |

### `poll_interval_ms`

The period at which the event loop checks worker state. Lower values mean faster detection of worker exits and faster restarts, but higher CPU overhead. The loop timer uses the **minimum** value across all configured transports, so one transport cannot starve others.

### `consume_args`

These map directly to `messenger:consume` CLI options. The generated command looks like:

```
php bin/console messenger:consume <transport> [queues...] [--memory-limit=N] [--time-limit=N] [--limit=N] [--sleep=N] [extra...]
```

Options with `null` values are omitted from the command line entirely.

---

## DI Wiring

The `SymfonyProcessManagerExtension` loads `Resources/config/services.yaml` and then injects the processed configuration into services:

- A `WorkerPool` service is registered per transport (id: `symfony_process_manager.worker_pool.<name>`).
- `ProcessManagerLoop` receives the list of pools and `shutdownTimeoutSeconds` (`null` when configured value is `0`, the integer otherwise).
- `AutoscalerLoop` receives the same pools, the `StrategyRegistry`, and the optional `PriorityArbiter` (only constructed when `total_cap` is set).
- `Orchestrator` owns the run lifecycle: installs the SIGTERM handler, starts each loop participant, runs the React loop, and returns the exit code from `ShutdownState`.
- `ServeCommand` is a one-line entrypoint that delegates to `Orchestrator::run()`.

Transport, autoscaler, and strategy config objects (`TransportConfig`, `ConsumeArgs`, `AutoscalerConfig`, `StrategyConfig`) are value objects instantiated by the extension at container compile time. Transports without an `autoscaler` block synthesize an implicit `AutoscalerConfig::legacyFixed($processes)` so the arbiter sees a homogeneous list.
