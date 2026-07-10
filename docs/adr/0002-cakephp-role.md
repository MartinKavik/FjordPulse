# ADR 0002 — CakePHP role

## Status

Accepted.

## Decision

CakePHP is the HTTP/control plane, not the realtime event loop.

CakePHP handles:

- routing,
- validation,
- admin pages,
- auth/tokens,
- logs,
- config,
- commands,
- shared services.

AMPHP/Revolt handles:

- WebSocket connections,
- timers,
- async clients,
- connection lifecycle,
- room registry.

## Rationale

CakePHP is excellent for structured HTTP applications. WebSocket servers are long-lived stateful processes and require explicit lifecycle, rooms, broadcast, backpressure, and timers. Forcing this into controllers would create the wrong architecture.

## Implementation

Realtime starts as:

```bash
bin/cake realtime start
```

This command bootstraps CakePHP services/config but runs an AMPHP/Revolt server.
