# FjordPulse Final Readiness Review

## Verdict

The repository skeleton is ready for a coding agent to implement everything locally except actual production deployment.

Included and verified:

```text
23 design PNGs + 23 corresponding design notes
108 production user stories with black-box scenarios
phase gates and bootstrap instructions
agent rules and ADRs
HTTP and realtime contract drafts
SurrealDB live-query architecture
one comprehensive GOAL.md
empty frontend/backend/infra/test skeleton directories
```

## Final local architecture

```text
Browser
  SolidJS + MapLibre
  | HTTPS / WebSocket
  v
FrankenPHP/CakePHP web service
  - SPA/static assets
  - /api
  - /admin
  - reverse proxy /live
  |
  +--> AMPHP/Revolt realtime command
  |     - browser WebSocket server
  |     - rooms and watches
  |     - timers
  |     - Entur adapters
  |     - SurrealDB Runtime::amp() connections
  |
  +--> SurrealDB
        - canonical current state
        - database events
        - realtime_event table
        - LIVE SELECT stream
```

## Canonical realtime path

The live-query path is intentional and primary:

```text
Entur
  -> typed PHP adapter
  -> canonical state write
  -> SurrealDB DEFINE EVENT
  -> realtime_event CREATE
  -> LIVE SELECT notification
  -> PHP event bridge
  -> browser room broadcast
```

This demonstrates SurrealDB's specialty while preserving correctness:

- current tables are authoritative,
- event records are durable diagnostics/notifications,
- browser reconnects receive current snapshots,
- versions make duplicate/out-of-order notifications harmless,
- live-query failure degrades to snapshot/poll behavior instead of corrupting state.

## Remaining deliberate implementation spikes

The coding agent must execute these before relying on the stack:

1. Resolve and pin an exact CakePHP 6 installable version.
2. Verify CakePHP 6 + PHP 8.5 under FrankenPHP normal mode.
3. Verify `bin/cake realtime start` can host AMPHP WebSockets.
4. Verify the exact SurrealDB PHP alpha supports `Runtime::amp()` with non-blocking live queries.
5. Verify live-query recreation after SurrealDB reconnect.
6. Verify SurrealDB `DEFINE EVENT` plus `LIVE SELECT` behavior transactionally.
7. Probe Entur Stop Place, Geocoder, Journey Planner, and Vehicle Positions.
8. Compare Entur Vehicle Positions upstream subscription vs bounded query strategy.
9. Validate MapLibre map source strategy for local/testing usage.

## Excluded from the Codex goal

Actual deployment is intentionally excluded:

```text
no Hetzner provisioning
no Coolify installation
no DNS changes
no production secrets
no production rollout
```

The agent should still create deployment-ready documentation and local Compose configuration.
