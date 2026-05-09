# IPC Protocol

## Overview

The process manager communicates with its worker subprocesses via a lightweight JSON-based protocol carried over the worker's stdin (manager → worker) and stdout (worker → manager).

The protocol uses a dedicated line prefix `@spm:` to distinguish IPC frames from regular application output. A worker can continue writing normal log output to stdout; lines not starting with `@spm:` are treated as user output and forwarded to the host terminal.

---

## Wire Format

Each IPC message is a single line of text:

```
@spm:<JSON>
```

Where `<JSON>` is a JSON object with two fields:

```json
{
  "type": "<message class name>",
  "payload": { ... }
}
```

### Encoding

- The IPC codec (`IpcCodec`) handles all encoding/decoding.
- The prefix `@spm:` is constant and must appear at the very start of the line.
- The remainder of the line is a JSON-encoded object with `type` and `payload` keys.
- Messages are terminated with a newline (`\n`).

### Decoding

The codec decodes a line by:

1. Checking that the line starts with `@spm:` (via `IpcCodec::isIpcLine()`).
2. Stripping the prefix and JSON-decoding the remainder.
3. Validating that `type` is a string and `payload` is an array.
4. Checking that `type` is in the allowedTypes list (if configured).
5. Verifying that `type` names a class implementing `IpcMessage`.
6. Calling `$type::fromArray($payload)` to deserialize.
7. Returning `null` on any validation failure (silent drop).

---

## Message Types

### PingMessage

**Direction:** Manager → Worker  
**Purpose:** Liveliness check; confirms the worker's event loop is processing stdin.  
**Payload:** empty (`{}`)

```json
@spm:{"type":"RobotoMarvin\\SymfonyProcessManager\\Ipc\\Message\\PingMessage","payload":{}}
```

**Worker response:** Worker must reply with a `PongMessage` immediately upon receiving a Ping.

---

### PongMessage

**Direction:** Worker → Manager  
**Purpose:** Acknowledgement of a PingMessage.  
**Payload:** empty (`{}`)

```json
@spm:{"type":"RobotoMarvin\\SymfonyProcessManager\\Ipc\\Message\\PongMessage","payload":{}}
```

**Manager handling:** Updates `WorkerState::lastPongAt` to the current timestamp. This can be used to detect unresponsive workers (last pong too old). The `worker_last_pong_timestamp` gauge tracks this value per worker.

---

### MessengerEventMessage

**Direction:** Worker → Manager
**Purpose:** Single envelope reporting every Messenger lifecycle event the worker observes (received / handled / failed / retried). Replaces the earlier split between `WorkerStartedHandlingMessage` and `ProcessedCommandMessage`.
**Payload:**

| Field | Type | Description |
|---|---|---|
| `event` | `string` | One of `"received"`, `"handled"`, `"failed"`, `"retried"` |
| `command` | `string` | Fully-qualified class name of the message being handled |
| `transport` | `string` | Receiver name reported by the worker — `WorkerMessage(Received\|Handled\|Failed\|Retried)Event::getReceiverName()`. Identifies which transport delivered the message in a multi-transport consumer. **Required and non-empty** — payloads with a missing or empty `transport` are dropped at decode time. |
| `duration_seconds` | `float\|null` | Elapsed wall-clock seconds between `received` and the terminating event. Set on `handled` and `failed`; absent on `received` and `retried`. |
| `error_class` | `string\|null` | FQCN of the throwable that caused the failure. Set on `failed`; absent otherwise. Carried in the IPC payload but not currently exposed as a metric label (see `spec/metrics.md`). |

```json
@spm:{"type":"RobotoMarvin\\SymfonyProcessManager\\Ipc\\Message\\MessengerEventMessage","payload":{"event":"handled","command":"App\\Message\\MyMessage","transport":"orders","duration_seconds":0.042}}
```

**Manager handling per `event`:**

| Event | State change | Metrics |
|---|---|---|
| `received` | mark worker `Busy` | set `worker_busy{worker, consumer, transport}=1`; `messenger_messages_in_flight{transport}` += 1 |
| `handled` | mark worker `Idle` | set `worker_busy{...}=0`; `messages_processed_total{consumer, transport}` += 1; `messenger_messages_processed_total{transport, message_class}` += 1; observe `messenger_message_duration_seconds`; `messenger_messages_in_flight{transport}` -= 1; record per-transport throughput on the pool's EWMA |
| `failed` | mark worker `Idle` | set `worker_busy{...}=0`; `messenger_messages_failed_total{transport, message_class}` += 1; observe `messenger_message_duration_seconds`; `messenger_messages_in_flight{transport}` -= 1; record per-transport throughput on the pool's EWMA |
| `retried` | (no change) | `messenger_messages_retried_total{transport, message_class}` += 1 |

The full Messenger metrics suite (`messenger_*`) is gated by `metrics.messages.enabled`; the per-pool signals (`worker_busy`, `messages_processed_total`, throughput EWMA) are unconditional.

---

## IpcMessage Interface

All messages implement `IpcMessage`:

```php
interface IpcMessage
{
    public function toArray(): array;
    public static function fromArray(array $data): static;
}
```

---

## Transport Channels

### Downlink: Manager → Worker (stdin)

The manager writes to a `React\Stream\InputStream` that is passed as the subprocess stdin. The stream is created at worker start and registered in `IpcFanout` under the worker's ID.

**IpcFanout** manages one `InputStream` per worker and provides a broadcast send:

```php
$fanout->send(new PingMessage());          // send to all workers
$fanout->send(new PingMessage(), [         // send to filtered subset
    new WorkerIdFilter(workerId: 3),
]);
```

If a worker's stream is closed (e.g., the process exited), the fanout skips it and logs a warning.

### Uplink: Worker → Manager (stdout)

Worker IPC messages are written to stdout (same stream as regular log output). The manager captures stdout line-by-line via `WorkerOutputHandler`. Each line is inspected:

- If `IpcCodec::isIpcLine($line)` → decode and queue in an in-memory IPC queue per worker.
- Otherwise → format and forward to host stdout.

The manager drains the IPC queue each tick via `WorkerOutputHandler::getAndClearIpcMessages(workerId)`.

---

## Worker-Side Implementation

`WorkerIpcSubscriber` (implements `EventSubscriberInterface`) is the in-worker component. It subscribes to Messenger events and reads from stdin.

### Subscribed Events

| Event | Handler |
|---|---|
| `WorkerRunningEvent` | `onWorkerRunning()` — reads pending IPC messages from stdin |
| `WorkerMessageReceivedEvent` | `onMessageReceived()` — sends `MessengerEventMessage(event=received)`, records start time keyed by message identity |
| `WorkerMessageHandledEvent` | `onMessageHandled()` — sends `MessengerEventMessage(event=handled, duration_seconds)` |
| `WorkerMessageFailedEvent` | `onMessageFailed()` — sends `MessengerEventMessage(event=failed, duration_seconds, error_class)` |
| `WorkerMessageRetriedEvent` | `onMessageRetried()` — sends `MessengerEventMessage(event=retried)`, drops the recorded start time |

### Stdin Reading

`onWorkerRunning()` is called on every Messenger tick (when the worker is idle between messages). It:

1. Reads from stdin in non-blocking mode.
2. Buffers partial lines.
3. Decodes complete lines using `IpcCodec`.
4. Dispatches each decoded message:
   - `PingMessage` → immediately write `PongMessage` to stdout via `IpcEndpoint`.

### IpcEndpoint

Used by the worker to send messages to the manager:

```php
$endpoint->send(new PongMessage());
```

Encodes the message and writes it to stdout (or a configured stream), followed by a newline, and flushes.

---

## Ping Interval

The manager sends a ping every `pingIntervalTicks` ticks (default: 50). With `poll_interval_ms: 200` and `pingIntervalTicks: 50`, a ping is sent every ~10 seconds.

The ping interval is intentionally much longer than the tick interval so pings do not dominate I/O. The `worker_last_pong_timestamp` gauge allows external monitoring tools to detect workers that have stopped responding.

---

## IpcCodec `allowedTypes`

The codec can be constructed with an `allowedTypes` list (fully-qualified class names). If the list is non-empty, messages whose `type` is not in the list are silently dropped. This allows the manager-side and worker-side codecs to be separately configured to accept only the types they care about:

- Manager codec: accepts `PongMessage`, `MessengerEventMessage`.
- Worker codec: accepts `PingMessage`.

In the current service wiring, both sides use the same codec with all types allowed.

---

## Error Handling

All decoding errors result in a silent `null` return (message is dropped). The codec does not throw or log errors, to avoid feedback loops where a logging error triggers another log line.

Fanout write errors are logged as warnings but do not affect other workers.
