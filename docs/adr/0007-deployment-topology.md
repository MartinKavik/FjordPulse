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

The web service is the only application service reachable through the public
edge. In the Coolify production profile, Coolify-managed Traefik owns ports
80/443 and routes to the web container's private port 8080.

Embedded FrankenPHP/Caddy serves the SolidJS build and CakePHP and
reverse-proxies `/live` to realtime. It is an application-container server,
not a second host-level proxy beside Traefik.

Realtime runs exactly one replica in v1.

SurrealDB is private and persistent.

## Rationale

This is simpler than separate public frontend/API/WebSocket domains, avoids CORS, and preserves a clean future scaling boundary.
