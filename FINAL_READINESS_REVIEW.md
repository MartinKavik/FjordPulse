# FjordPulse final readiness review

## Verdict

FjordPulse is implemented and signed off as a complete local application across the frontend, CakePHP HTTP/control plane, AMPHP realtime service, SurrealDB persistence/live-query path, fake and real Entur adapters, vehicle journeys, admin diagnostics, local orchestration, contracts, and tests. The complete gate sequence passed on 2026-07-10 and the latest incremental browser/build gates passed on 2026-07-11.

Production deployment remains a separate, explicitly excluded phase.

## Delivered architecture

```text
Browser
  SolidJS + TypeScript + MapLibre
  same-origin application HTTP and signed WebSocket
  approved MapTiler style/tile requests only
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

The source adapters are selected by an explicit profile/configuration boundary:

```text
make dev:      typed real Entur adapters + persistent real catalog
make dev-demo: deterministic fake adapters + disposable demo catalog
production:    typed real Entur adapters only
```

Production configuration rejects fake data mode. Browser code never connects directly to Entur or SurrealDB; its only approved third-party network surface is the operator-configured MapTiler map provider.

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
| Vehicle Positions strategy | ADR 0013 selects demand-driven HTTP GraphQL for v1. A two-second process cache coalesces selected/focused lookups into one nationwide request; the upstream subscription remains a proven future option. |
| Vehicle journeys | Live vehicle identity resolves into Journey Planner geometry and ordered calls; validated polylines, progress, upcoming stops, degraded cache state, persistence, HTTP snapshots, and compact realtime references are tested end to end. |
| MapLibre source | MapTiler Hybrid v4 is the default satellite basemap and Streets v4 is the persistent alternative. Guarded cartography strengthens road hierarchy and place labels, is reapplied after switching, and keeps count-scaled ordinary clusters/stations below provider symbols while selected transport remains prominent. Named `#map=zoom/latitude/longitude` camera state is shareable and reload-safe. |

## Contract and product coverage

- Canonical OpenAPI 3.1: 22 operations.
- Realtime protocol: 9 client commands and 23 server message types.
- Traceability: all 108 stories accounted for, including 22 non-wire stories.
- Black-box inventory: 325 scenarios.
- Deterministic UI inventory: all 23 approved desktop, mobile, admin, and design-system routes implemented.
- Fake source states: normal, empty, stale, error, live, lost, backoff, fallback, and reconnect modes behind final adapter interfaces.
- Search: station/vehicle normalized indexes, Norwegian `ø/æ/å` folding, prefixes, and bounded typo tolerance across local and Geocoder-backed results.
- Truth boundary: fixture modules load only behind development/test scenario routing; the production build audit rejects fixture imports, known fixture sentinels, and literal relative ages.
- Admin: authenticated status, watches, Entur log, realtime rooms/bridge, persisted events, and migration ledger.

## Current verification record

Verified through 2026-07-11:

- TypeScript typecheck passed.
- PHPStan maximum level passed with no errors.
- Contract lint/fixtures passed: 32 valid realtime, 9 rejected invalid realtime, and 9 valid HTTP fixtures.
- PHPUnit passed 84 tests and 707 assertions with one intentionally skipped external smoke in the ordinary offline suite.
- Vitest passed 71 tests across 8 files.
- HTTP black-box/OpenAPI validation includes station-to-vehicle-to-journey route/calls/upcoming stops, tolerant search, provider configuration/failure behavior, and complete bounded projection of a synthetic 58,500-station catalog.
- Production frontend build, fixture/truth audit, Composer validation, Caddy adaptation, and built index check passed.
- Clean-stack Playwright passed all 11 tests using real SurrealDB migrations, CakePHP HTTP, `bin/cake realtime start`, API-mode Vite, deterministic interception of the approved MapTiler provider boundary, share/reload/malformed camera URLs, an actual realtime stop/restart lifecycle, and a complete HTTP + realtime outage/recovery on the same browser page.
- Fixture Playwright passed 7 tests, including primary public/mobile/admin accessibility and focus lifecycle checks.
- Visual Playwright matched all 23 desktop/mobile/admin/design-system baselines.
- Infrastructure validation confirmed ordered complete-catalog bootstrap, private database networking, non-published Entur egress for importer/realtime workers, and exactly one realtime replica.
- Live `make smoke-entur` passed 1 test with 12 assertions, including a current vehicle resolved to non-empty route geometry and calls.
- Long-running command authentication recovery passed exact unit regressions plus the real SurrealDB live-query and realtime WebSocket integrations: an expired app-user token creates a fresh authenticated HTTP connection and retries once without hiding unrelated authorization failures.
- Entur outage recovery passed a controlled process-boundary test: the same Amp client/scheduler first succeeds, observes the upstream process stop and unbind, retains the last authoritative data during explicit 15-second backoff, then succeeds after that process restarts on the same port. A companion budget test prevents retry hammering.

The clean-stack browser proof verifies visible station data and station/vehicle/focus updates through the final database-driven path, backend empty/stale/error/lost/fallback/reconnect scenarios, protected watch/realtime/event diagnostics, satellite default and Streets switching, real pan/zoom tile-coordinate changes, shareable camera restoration before the first viewport request, malformed-camera fallback, planned-route overview and upcoming stops, truthful startup/last-update telemetry, lazy WebSocket creation, persistent selection through actual realtime-only and complete backend outages, same-document HTTP/WebSocket recovery with watch resubscription, rendered-overlay survival, explicit provider retry/error behavior, network boundaries, and process cleanup.

The readiness review was deliberately reopened after a truthfulness audit found that the earlier suites proved mechanics but not complete production semantics. The corrected implementation separates source/fetch timestamps, removes normal-route fixture substitution, labels demo provenance, makes relative ages reactive, joins vehicles to full journeys, loads the complete provenance-checked station catalog, and distinguishes loading/error/empty UI states. The defect record and enforcement live in `docs/audits/production-truthfulness.md`.

The 57,964-row persistent catalog exposed a second readiness gap: the Norway map endpoint hydrated every station into a 128 MB PHP process and could return a fatal HTML page with HTTP 200. Bounds-aware SurrealDB projection/adaptive aggregation now keeps responses complete and below 2,000 items; `make dev` rejects non-JSON health/map responses and does not print ready until the realtime bridge, aggregate health, and station map all satisfy their schemas.

The real map review exposed a third usability gap: opaque cluster bubbles were appended above MapTiler symbols, hiding the town names needed to interpret them, and zoom 8 could jump to hundreds of overlapping stations. Ordinary context now sits below provider labels, compact translucent rings retain 36 px click targets, and individual markers require zoom 9+ with no more than 300 stations in view.

An overnight local run exposed a fourth operational gap: the SurrealDB SDK's expired app-user token caused realtime status and watch writes to return HTTP 401 even though the dedicated live-query bridge remained connected. Long-running command queries now replace the stale authenticated connection and retry once; the live-query connection continues to use its separate supervised reconnect path.

Explicit outage testing exposed a fifth resilience gap in the evidence: realtime-only fallback had been tested, but a complete FjordPulse backend outage and a physically stopped Entur upstream had not. The clean-stack browser test now stops and restores CakePHP HTTP plus realtime while preserving the same page, map, selection, and overlays; the backend boundary test stops and restarts Entur on the same port and proves cached-data preservation, bounded retry, and automatic recovery without restarting PHP.

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
