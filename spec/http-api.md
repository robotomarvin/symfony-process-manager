# HTTP API

## Overview

`HttpServer` starts a ReactPHP HTTP server when `pm:serve` runs. The server is always started on the configured host and port. It exposes two endpoints: a health check and a Prometheus metrics endpoint.

The HTTP server shares the same ReactPHP event loop as the process manager, so it is non-blocking and does not interfere with worker management.

---

## Configuration

```yaml
symfony_process_manager:
  http_server:
    host: '127.0.0.1'   # default
    port: 9100           # default
```

To bind to all interfaces (e.g., in a container):

```yaml
symfony_process_manager:
  http_server:
    host: '0.0.0.0'
    port: 9100
```

---

## Endpoints

### `GET /`

Health check endpoint. Always returns 200 as long as the `pm:serve` process is running.

**Response:**

```
HTTP/1.1 200 OK
Content-Type: application/json

{"status":"ok"}
```

**Use cases:**
- Docker `HEALTHCHECK`
- Load balancer health probes
- Liveness probe in Kubernetes

---

### `GET /metrics`

Prometheus metrics in [Prometheus text format](https://prometheus.io/docs/instrumenting/exposition_formats/#text-based-format).

**Response:**

```
HTTP/1.1 200 OK
Content-Type: text/plain; version=0.0.4

# HELP worker_starts_total Total number of worker starts
# TYPE worker_starts_total counter
worker_starts_total{transport="async"} 3

# HELP messages_processed_total Total messages processed
# TYPE messages_processed_total counter
messages_processed_total{transport="async"} 142

...
```

**Use cases:**
- Prometheus scrape target
- Grafana dashboards
- Alerting on failure rates or stalled workers

See [metrics.md](metrics.md) for the full list of exposed metrics.

---

## Routing

All unrecognized paths (anything other than `/metrics`) return the health check response:

```
HTTP/1.1 200 OK
Content-Type: application/json

{"status":"ok"}
```

This means `GET /health`, `GET /healthz`, and any other path all return the same health response.

---

## Request Handling

The server is implemented as a single `handleRequest(ServerRequestInterface $request): Response` method. It is synchronous within the ReactPHP event loop — each request is handled in a single tick with no async I/O.

The method inspects `$request->getUri()->getPath()` and returns the appropriate response.

---

## Startup Log

When the server starts, it logs at the `notice` level:

```
HTTP server listening on http://<host>:<port>
```

This log entry is written to the PSR-3 logger (typically stdout with the JSON formatter, so it appears in the container log).

---

## Limitations

- No authentication or authorization.
- No TLS/HTTPS support (terminate TLS at a reverse proxy or load balancer).
- No rate limiting.
- The server cannot be disabled; it always starts. If the port is in use, the process will fail to start.
- Only `GET` method is supported. Other HTTP methods will receive the health check response (no method check is performed).
