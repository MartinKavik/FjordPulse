# FjordPulse final readiness review

## Verdict

FjordPulse is implemented and signed off as a complete local application across the frontend, CakePHP HTTP/control plane, AMPHP realtime service, SurrealDB persistence/live-query path, fake and real Entur adapters, admin diagnostics, local orchestration, contracts, and tests. Every required local completion command passed on 2026-07-10.

Production deployment remains a separate, explicitly excluded phase.

## Delivered architecture

```text
Browser
  SolidJS + TypeScript + MapLibre
  same-origin HTTP and signed WebSocket only
  |
  v
FrankenPHP normal mode
  CakePHP 6 / PHP 8.5 HTTP and control plane
  static SPA + /api + protected /api/admin + /live reverse proxy
  |
  +--> bin/cake realtime start
  |     AMPHP/Revolt WebSocket server
  |     room + watch/focus registries
  |     demand-driven scheduler
  |     dedicated async command/query connection
  |     dedicated supervised live-query connection
  |
  +--> SurrealDB 3.2
        authoritative current state
        typed repositories
        schema migrations and app user
        semantic DEFINE EVENT records
        durable realtime_event diagnostics
```

The source adapters are selected by configuration:

```text
development/test: deterministic fake Entur adapters
production:        typed real Entur adapters only
```

Production configuration rejects fake data mode. Browser code never connects directly to Entur or SurrealDB.

## Canonical realtime path

The implemented primary path is:

```text
Entur/fake adapter
  -> typed DTO
  -> canonical station_snapshot/current_vehicle repository write
  -> SurrealDB DEFINE EVENT creates realtime_event atomically
  -> one global LIVE SELECT on a dedicated WebSocket connection
  -> validated PHP RealtimeEvent DTO
  -> scoped room broadcast
  -> SolidJS applies only newer versions
```

There is no direct broadcast after a canonical database write. Current tables remain authoritative; realtime events are durable notifications and diagnostics. New or reconnected browser subscriptions receive authoritative snapshots. A degraded live-query bridge is exposed through health/admin state and causes HTTP snapshot polling rather than a second event bus.

Browser commands travel in the opposite direction through the realtime service:

```text
watch_station / watch_vehicle / focus_vehicle
  -> signed same-origin WebSocket
  -> strict protocol decoder/router
  -> in-memory room and watch registry
  -> durable SurrealDB watch record
  -> AMPHP demand-driven refresh scheduler
```

## Resolved dependency spikes

| Spike | Result |
|---|---|
| CakePHP 6 + PHP 8.5 | Official CakePHP `6.x` commit pinned; routes, HTTP, PHPUnit, and PHPStan run on PHP 8.5.8. |
| FrankenPHP normal mode | Health/API serving, SPA/static configuration, Caddy adaptation, and graceful shutdown were exercised. |
| AMPHP WebSocket command | Multiple clients, authorization, validation, ping/pong, room isolation, focus lifecycle, timers, and shutdown are tested. |
| SurrealDB SDK v2 alpha | Installed `2.0.0-alpha.1` symbols were inspected; short HTTP paths use `Runtime::sync()` and realtime uses `Runtime::amp()`. |
| Non-blocking live query | A real integration test receives committed database events while Revolt timers continue running. |
| Database event path | Repository writes create semantic `realtime_event` records and reach both an AMPHP consumer and a real WebSocket client. |
| Reconnect supervision | A test stops and restarts the real SurrealDB process, observes degraded state, recreates the global subscription, and receives later events. |
| Entur services | Backend-only typed smoke probes passed for Stop Place Register, Geocoder, Journey Planner, and Vehicle Positions. |
| Vehicle Positions strategy | ADR 0013 selects bounded demand-driven HTTP GraphQL queries for v1; the upstream subscription remains a proven future option. |
| MapLibre source | Local deterministic style/data avoids public tile dependency in automated tests; deployment may configure an approved same-origin style. |

## Contract and product coverage

- Canonical OpenAPI 3.1: 21 operations.
- Realtime protocol: 9 client commands and 23 server message types.
- Traceability: all 108 stories accounted for, including 22 non-wire stories.
- Black-box inventory: 324 scenarios.
- Deterministic UI inventory: all 23 approved desktop, mobile, admin, and design-system routes implemented.
- Fake source states: normal, empty, stale, error, live, lost, backoff, fallback, and reconnect modes behind final adapter interfaces.
- Admin: authenticated status, watches, Entur log, realtime rooms/bridge, persisted events, and migration ledger.

## Current verification record

Verified on 2026-07-10:

- TypeScript typecheck passed.
- PHPStan maximum level passed with no errors.
- Contract lint/fixtures passed: 32 valid realtime, 9 rejected invalid realtime, and 8 valid HTTP fixtures.
- PHPUnit passed 50 tests and 390 assertions with one intentionally skipped external smoke in the ordinary offline suite.
- Vitest passed 48 tests across 5 files.
- HTTP black-box/OpenAPI validation passed 6 tests and 135 assertions.
- Production frontend build, Composer validation, Caddy adaptation, and built index check passed.
- Clean-stack Playwright passed in 24.9 seconds using real SurrealDB migrations, CakePHP HTTP, `bin/cake realtime start`, and API-mode Vite.
- Fixture Playwright passed 7 tests, including primary public/mobile/admin accessibility and focus lifecycle checks.
- Visual Playwright matched all 23 desktop/mobile/admin/design-system baselines.
- Infrastructure validation confirmed ordered migration/station bootstrap, internal database/realtime networking, and exactly one realtime replica.

The clean-stack browser proof verifies visible station data and station/vehicle/focus updates through the final database-driven path, backend empty/stale/error/lost/fallback/reconnect scenarios, protected watch/realtime/event diagnostics, same-origin boundaries, and process cleanup.

The final ordered gate sequence—planning verification, install, typecheck, PHPStan, tests, fixture/live E2E, visual comparison, build, and diff hygiene—passed. `PROGRESS.md` contains the exact counts and deployment boundary.

## Deployment boundary

The repository contains local/deployment-oriented Caddy, Dockerfile, Compose, environment examples, health endpoints, migrations, maintenance commands, and operational diagnostics. These are implementation artifacts, not proof of a deployed service.

The following are deliberately not done here:

```text
Hetzner CX33 provisioning
Coolify installation or project configuration
fjordpulse.kavik.cz DNS changes
production secret creation or loading
production TLS/backup/monitoring/rollback setup
production smoke tests and rollout
```

No checked-in development credential should be reused in production. Actual deployment must provide strong secrets, force real data mode, validate one realtime replica, apply migrations, verify backups/restores, and rerun black-box smoke tests against the deployed origin.
