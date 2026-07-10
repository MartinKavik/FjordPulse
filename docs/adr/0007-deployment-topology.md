# ADR 0007 — Deployment topology

## Status

Accepted.

## Decision

Use three runtime services:

```text
web
realtime
surrealdb
```

The web service is the only public service.

FrankenPHP/Caddy serves the SolidJS build and CakePHP and reverse-proxies `/live` to realtime.

Realtime runs exactly one replica in v1.

SurrealDB is private and persistent.

## Rationale

This is simpler than separate public frontend/API/WebSocket domains, avoids CORS, and preserves a clean future scaling boundary.
