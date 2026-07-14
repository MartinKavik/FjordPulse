# Codex Goal — Build FjordPulse End to End

Work from the repository root. Read `AGENTS.md` and all files listed under its **Read first** section before changing code.

## Goal

Implement the complete FjordPulse application locally, including the responsive SolidJS frontend, CakePHP HTTP/control app, AMPHP/Revolt realtime server, SurrealDB persistence and live-query event bridge, deterministic fake third-party services, real Entur adapters, tests, visual states, admin diagnostics, local development orchestration, and documentation.

The original local-only phase is complete. Production work is now explicitly
authorized only through `docs/PRODUCTION_DEPLOYMENT_PLAN.md`: use the
provisioned Sharptech host, finish every safety gate, and do not change DNS or
load production secrets before its prerequisites are proven.

Do not stop after creating a skeleton. Continue through the phases below until the local application is complete, tests pass, and only deployment/production-secret tasks remain.

## Operating mode

- Make reasonable decisions without waiting for confirmation.
- If an experimental dependency fails a spike, use the documented adapter-compatible fallback, record the decision in a new ADR, and continue.
- Never silently drop a required capability.
- Keep `PROGRESS.md` current with completed phases, commands run, test results, deviations, and remaining deployment-only work.
- Pin exact dependency versions and commit lockfiles.
- Do not claim a phase is complete unless its automated and black-box-relevant checks pass.

## Required phases

### 1. Verify inputs and run dependency spikes

Run:

```bash
make verify-planning
```

Then prove and document:

1. CakePHP 6 installs on PHP 8.5 and is pinned exactly.
2. CakePHP runs under FrankenPHP normal/as-is mode.
3. A CakePHP CLI command can run an AMPHP/Revolt WebSocket server.
4. SurrealDB PHP SDK v2 alpha works with `Runtime::sync()` in short request paths.
5. SurrealDB PHP SDK v2 alpha works with `Runtime::amp()` in an AMPHP process.
6. A `LIVE SELECT` can run in an Amp fiber without blocking WebSocket ping/pong.
7. A SurrealDB `DEFINE EVENT` can create a `realtime_event` record after canonical state changes.
8. The live-query supervisor recreates the subscription after connection loss/recovery.
9. Real Entur API probes work backend-only with `ET-Client-Name`.

Use the current official SDK symbols, but verify them against the exact package rather than assuming docs and alpha source are identical.

### 2. Build project/tooling skeleton

Create a coherent monorepo implementation under:

```text
frontend/
backend/
contracts/
infra/
tests/
```

Provide root commands:

```bash
make install
make dev
make stop
make typecheck
make phpstan
make test
make e2e
make visual
make build
```

Create `.env.example` files and local development configuration. Do not include secrets.

### 3. Implement the SolidJS visual prototype

Implement every state described in `docs/design/` and required by `docs/user-stories/`:

- 15 desktop public states,
- 6 mobile responsive states,
- 5 admin states,
- reusable design-system components,
- Norwegian Bokmål as the deterministic default language and an accessible `NO`/`EN` switcher whose explicit choice persists locally.

Provide deterministic fixture routes/scenario controls so Playwright can render every state reliably in both Norwegian and English. Localized labels must reflow without clipped controls or unintended viewport overflow across supported desktop/mobile widths. Use SolidJS, strict TypeScript, Vite, and MapLibre. The coded UI, not the AI-generated image pixels, becomes the exact visual-test baseline.

### 4. Implement and lock the contracts

Complete and validate:

```text
contracts/http/openapi.yaml
contracts/realtime/envelope.schema.json
contracts/realtime/client-message.schema.json
contracts/realtime/server-message.schema.json
```

Generate or maintain matching PHP DTOs/validators and TypeScript types/validators. Add contract tests that ensure fake and real adapters expose identical shapes.

### 5. Implement fake mode using final interfaces

Implement dev/test fake adapters for:

```text
Stop Place/Register import
Geocoder/search
Journey Planner/departures
Vehicle Positions
```

Expose deterministic scenarios:

```text
normal
station_empty
station_stale
station_error
vehicle_live
vehicle_stale
vehicle_lost
fallback
entur_backoff
realtime_reconnect
```

The fake adapters must drive the same repositories, database events, live queries, WebSocket messages, and frontend services as real adapters whenever SurrealDB mode is enabled. Do not build a throwaway incompatible fake server.

### 6. Implement CakePHP HTTP/control plane

CakePHP owns:

- public HTTP API,
- search/station/vehicle snapshots,
- health/readiness,
- admin authentication,
- focused admin status, infrastructure, watches, Entur log, realtime, events, and a read-only Database inspector with Current schema and Migrations tabs,
- validation,
- configuration,
- structured logging,
- commands,
- shared services.

Use FrankenPHP normal mode, not worker mode, for the base implementation.

### 7. Implement AMPHP/Revolt realtime service

Create:

```bash
bin/cake realtime start
```

It must provide:

- browser WebSocket endpoint,
- typed message decoder/validator/router,
- client lifecycle,
- room registry,
- in-memory active watch registry,
- durable watch records in SurrealDB,
- timers and demand-driven collectors,
- connection and source telemetry,
- graceful shutdown,
- reconnect/resubscribe behavior.

Use one realtime process/replica for v1.

### 8. Implement the SurrealDB live-query event pipeline

Use the official v2 SDK alpha behind an adapter/factory.

Short CakePHP request paths:

```php
new Surreal(Runtime::sync())
```

Realtime process:

```php
new Surreal(Runtime::amp())
```

Use two async SurrealDB connections in the realtime process:

1. a command/query connection for reads and writes,
2. a dedicated live-query connection for event streaming.

Create schemafull or intentionally flexible tables for at least:

```text
station
station_snapshot
current_vehicle
vehicle_observation
watch
realtime_event
entur_request_log
system_status
schema_migration
schema_migration_attempt
```

The protected Database inspector must stay behind typed CakePHP GET endpoints.
Its schema response maps one fixed, backend-owned, allowlisted SurrealDB
`INFO ... STRUCTURE` query and never returns raw users, password hashes,
credentials, or authentication metadata. Its migration response compares the
bundled release with ledger/attempt records and may show bundled source, but
the browser cannot submit SurrealQL, select a filesystem path, edit schema, or
apply/retry/roll back a migration. Only the deployment CLI runner writes the
migration ledger and attempt audit.

Implement database events:

- changes to `station_snapshot` create a compact `station_snapshot_changed` realtime event scoped to `station:<id>`,
- meaningful changes to `current_vehicle` create `vehicle_moved`, `vehicle_stale`, or `vehicle_lost` events scoped to `vehicle:<id>`,
- events are created only when semantic content/version changes,
- no event is defined on `realtime_event`.

Run one global live subscription in a supervised Amp fiber:

```surql
LIVE SELECT * FROM realtime_event;
```

Requirements:

- connect by WebSocket,
- use database-scoped service credentials rather than root in normal runtime,
- use reconnect/backoff support,
- verify live-query feature support,
- consume `LiveAction::Create`,
- validate/map event records to typed realtime envelopes,
- broadcast by scope to the PHP room registry,
- kill/recreate the query during graceful shutdown/reconnect as supported,
- do not assume unmanaged live queries automatically survive reconnect,
- expose bridge health and last-event timestamps.

Do not directly broadcast after writes. The database live-query path is the single realtime publication path.

Correctness model:

- SurrealDB current state is authoritative.
- A client joining a room receives an authoritative snapshot immediately.
- Events carry `eventId`, `entityId`, `scope`, `type`, `version`, `createdAt`, and payload.
- Clients ignore duplicate or older versions.
- After browser reconnect or live-bridge recovery, resubscribe and send fresh snapshots.
- If the live bridge fails, mark realtime degraded and use HTTP snapshot/poll fallback.

### 9. Implement demand-driven data collection

Frontend commands:

```text
watch_station
unwatch_station
watch_vehicle
unwatch_vehicle
focus_vehicle
unfocus_vehicle
pause_focus
resume_focus
```

Flow:

1. validate command,
2. join/leave room,
3. persist/refresh a TTL watch record,
4. scheduler prioritizes active/focus watches,
5. adapter fetches data if cache is stale and budget allows,
6. canonical state is written to SurrealDB,
7. database event/live query performs publication.

Multiple clients watching the same scope share one upstream refresh scope.

### 10. Implement real Entur adapters

Use backend-only Entur open services:

```text
Stop Place Register: station/infrastructure import
Geocoder v3: search/place lookup
Journey Planner v3: departure boards
Vehicle Positions: live vehicle data
```

Every request includes configured `ET-Client-Name`.

Implement:

- typed mappers,
- caching/freshness,
- request logs,
- global and per-service budgets,
- 429/backoff handling,
- timeout/error states,
- no fake data in production mode.

For Vehicle Positions, spike and choose the simplest reliable backend approach:

- prefer one managed/multiplexed upstream GraphQL WebSocket strategy if it is robust and supports dynamic active scopes,
- otherwise use bounded, demand-driven queries/polls behind the same interface.

Document the choice in an ADR. Do not expose Entur to the browser.

### 11. Integrate frontend end to end

The frontend must use only FjordPulse HTTP/WebSocket contracts. Verify:

- search,
- station snapshots and updates,
- station-serving vehicles with explicit bounded coverage, plus other vehicles within the reported nearby radius,
- vehicle selection/trail,
- Focus/pause/resume/unfocus,
- stale/lost states,
- reconnect,
- fallback polling,
- mobile bottom sheets,
- admin pages.

### 12. Complete tests and documentation

Implement:

```text
PHPUnit tests
PHPStan
Vitest
Playwright E2E
Playwright visual regression
contract tests
SurrealDB migration/integration tests
live-query-to-browser integration test
Entur mapper tests
```

A required integration test must prove this full path:

```text
fake or real adapter writes canonical station/vehicle state
  -> DEFINE EVENT creates realtime_event
  -> PHP LIVE SELECT bridge receives it
  -> browser WebSocket receives the corresponding message
  -> SolidJS updates visible state
```

Use `docs/user-stories/traceability_matrix.csv` to report which stories/tests are implemented. Complete all non-deployment stories and all local/staging-testable portions of deployment stories.

Update:

- README,
- architecture docs,
- ADRs,
- API/realtime contracts,
- local runbook,
- test runbook,
- deployment-ready but not executed Coolify/Compose documentation.

## Safety and scope constraints

- No unplanned infrastructure mutation outside the production runbook.
- No DNS change before the host firewall, control plane and rollback path are proven.
- No production credential may be committed, printed in evidence, or reused from development.
- No Redis or extra message broker.
- No direct browser-to-Entur or browser-to-SurrealDB connection.
- No fake vehicle movement when `APP_ENV=production` or `DATA_MODE=real`.
- Do not use public OSM tiles as a bulk/offline production tile source.

## Completion criteria

Finish only when:

```text
make verify-planning passes
make typecheck passes
make phpstan passes
make test passes
make e2e passes
make visual passes
make build passes
```

The app must run locally with:

1. deterministic fake services, and
2. real Entur services when configured.

The final response must include:

- implemented architecture summary,
- exact run commands,
- exact test commands/results,
- dependency versions pinned,
- ADRs created or changed,
- user-story coverage summary,
- only deployment/production-secret tasks remaining.
