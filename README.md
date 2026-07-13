# FjordPulse

FjordPulse is a realtime Norwegian public transport explorer built to demonstrate modern typed, asynchronous PHP with a SolidJS map interface.

The local application is implemented end to end:

```text
SolidJS + TypeScript + Vite + MapLibre GL JS
CakePHP 6 + PHP 8.5 + FrankenPHP normal mode
AMPHP/Revolt browser WebSocket service
SurrealDB canonical state + DEFINE EVENT + LIVE SELECT
typed fake and real Entur adapters
PHPUnit + PHPStan + Vitest + Playwright
```

Actual Hetzner/Coolify provisioning, DNS changes, production secrets, and rollout are intentionally deferred.

## Quick start

Install the exact lockfile-backed dependencies and project-managed tools:

```bash
make install
```

Set the one service key needed by the browser map. `make install` creates the
ignored `.env` from `.env.example` when necessary:

```bash
${EDITOR:-vi} .env
# Set MAPTILER_API_KEY, then start the normal real-data profile.
make dev
```

`make dev` is the normal application, backed by real Entur services. It forces
`DATA_MODE=real`, uses the persistent `.data/surreal-real` store and
`fjordpulse_real` database, applies migrations, imports the complete Entur Stop
Place catalog, and then starts SurrealDB, CakePHP/FrankenPHP,
`bin/cake realtime start`, and Vite. The catalog is currently about 58,000
source records; the first import therefore takes time. The terminal prints one
`station_import_progress` JSON event per persisted 1,000-record source page and
a final `station_import_complete` event. Interrupted imports retain their
offset and resume on the next `make dev`; a healthy completed catalog is reused.

Entur's APIs used here are open: there is no signup, API key, OAuth client, or
local-development token to obtain. `ENTUR_CLIENT_NAME` becomes the required
`ET-Client-Name` request header. It is a stable, non-secret operator/application
identifier, not a credential. Browser traffic never goes directly to Entur.
The `ENTUR_*_REQUESTS_PER_MINUTE` values in `.env` are FjordPulse's own rolling
backend safeguards, not Entur account quotas; the Entur request log identifies
the affected APIs beside request evidence and provider documentation, while
System status links to that dedicated page.

For a fast, deterministic demonstration instead, run:

```bash
make dev-demo
```

The demo profile uses the same HTTP, SurrealDB, live-query, realtime, and
frontend paths with fake source adapters. It is isolated in the ephemeral
`.run/surreal-demo` store and `fjordpulse_demo` database, which are recreated
for each run and removed on stop. The UI shows a persistent **Demo data** badge;
the real profile instead shows neutral **Transport data: Entur** attribution.
That source credit is retained because [Entur's open-data licence guidance](https://developer.entur.org/pages-intro-setup-and-access/)
asks applications using its API/data to credit Entur; it is not an application
health indicator.

Default local URLs for either profile are:

```text
Public app:       http://127.0.0.1:5173
CakePHP/built UI: http://127.0.0.1:8080
Realtime health: http://127.0.0.1:8081/health/realtime
Admin:            http://127.0.0.1:5173/admin/status
Infrastructure:   http://127.0.0.1:5173/admin/infrastructure
Database schema:  http://127.0.0.1:5173/admin/database/schema
Migrations:       http://127.0.0.1:5173/admin/database/migrations
```

The Admin sign-in panel offers **Fill demo credentials** beside **Return to
public map** when started with `make dev` or `make dev-demo`. This uses a
separate read-only demo identity, not the operator credential. A deployed
public demo can opt in with `ADMIN_DEMO_ACCESS=true`; the configuration default
is off in every environment. The server permits demo sessions to read only
explicitly allowlisted diagnostics and log out, and the sidebar keeps the
public-demo/read-only role visible after sign-in.

Database is a protected, read-only release-diagnostics surface, not an embedded
database console. Current schema shows the effective allowlisted table, field,
index, event, and permission structure returned by CakePHP.
Migrations compares the bundled release files with the applied ledger and
attempt audit, including drift/failure states, both checksums, timestamps,
affected objects, and read-only bundled source. The browser cannot run a query,
edit schema, choose a migration file, or apply/retry/roll back anything; it
never connects directly to SurrealDB. Use Surrealist separately through the
private operator connection for record exploration or operator-run queries.
Only the deployment CLI migration command writes ledger/attempt records.

The interface starts in Norwegian Bokmål, even when the browser prefers
English. Use the visible `NO`/`EN` switcher on public or admin screens to change
the current page; an explicit choice is remembered locally and the document
language changes with it. If browser storage is unavailable or contains an
invalid value, FjordPulse still loads safely in Norwegian.

Public map movement is reflected in a shareable fragment such as
`#map=9.25/61.452/5.857` (`zoom/latitude/longitude`). The camera fragment is
restored before the first viewport request, survives reload, can be copied to
another browser, preserves query parameters, and is not sent to the backend.

Both development commands stay attached so service failure is visible. Press
Ctrl-C, or run `make stop` from another terminal. Real catalog data is
preserved; demo data is discarded.

Development defaults come from `.env.example`. The operator-managed
`MAPTILER_API_KEY` is the only browser map key: it enables the default labelled
satellite basemap and ordinary street-map layer, while end users never enter
credentials. If it is absent or invalid, the application reports a map-service
problem instead of silently rendering fake geography. Protect deployed browser
keys with allowed HTTP origins in MapTiler Cloud.

Fake adapters and development scenarios are allowed only in development/test;
production configuration requires `DATA_MODE=real`. See
[`docs/LOCAL_DEVELOPMENT.md`](docs/LOCAL_DEVELOPMENT.md) for profile behavior,
station-import details, and troubleshooting.

## Quality gates

```bash
make verify-planning
make typecheck
make phpstan
make test
make e2e
make visual
make build
```

`make e2e` runs both browser layers:

- deterministic fixture UI behavior/accessibility; and
- a clean-stack proof that boots real SurrealDB, applies migrations, starts CakePHP HTTP and the AMPHP realtime command, runs Vite with `VITE_DATA_MODE=api`, and verifies database-originated visible updates.

`make visual` compares all 27 deterministic scenario routes in Norwegian and
English, plus responsive Vehicles/Details station-tab, mobile-admin, and
expanded Database captures (74 reviewed comparisons), including compact mobile
Infrastructure metrics, the open navigation drawer, read-only schema/migration
details, and localized layout wrapping.

Run only the final-path clean-stack proof with:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" npm run e2e:live
```

The ordinary test suite does not require live Entur. To explicitly probe the real backend-only Entur adapters:

```bash
make smoke-entur
```

See `tests/README.md` for the test-layer matrix and `PROGRESS.md` for the latest verified gate state.

## Architecture invariant

The sole realtime publication path is database-driven:

```text
Entur/fake source
  -> typed PHP DTO
  -> canonical SurrealDB state write
  -> DEFINE EVENT creates realtime_event
  -> one global LIVE SELECT reaches the PHP bridge
  -> scoped WebSocket room broadcast
  -> SolidJS applies newer versions
```

Browser commands flow back through PHP and create durable demand:

```text
watch/focus command
  -> signed PHP WebSocket handler
  -> in-memory room/watch registry + SurrealDB watch record
  -> AMPHP scheduler refreshes the requested scope
```

The browser never calls Entur or SurrealDB directly. HTTP snapshots remain authoritative, and degraded realtime falls back to polling without introducing a second event bus.

## Repository map

- `frontend/` — SolidJS application, API/realtime clients, deterministic visual states, and Vitest tests.
- `backend/` — CakePHP HTTP/control plane, AMPHP realtime service, typed adapters/repositories, migrations, PHPUnit, and PHPStan.
- `contracts/` — canonical OpenAPI and realtime JSON Schemas plus fixtures and traceability.
- `infra/` — Caddy/FrankenPHP, Dockerfile, and Compose artifacts for later deployment work.
- `tests/` — cross-service fixture E2E, clean-stack E2E, and visual browser tests.
- `docs/` — architecture, protocol, dependency, ADR, design, and user-story documentation.

Start with `AGENTS.md`, `GOAL.md`, `docs/ARCHITECTURE.md`, and `FINAL_READINESS_REVIEW.md` when changing the system.

## Deployment boundary

The checked-in environment examples contain local placeholders, not production credentials. A later deployment phase must provision Hetzner/Coolify, configure `fjordpulse.kavik.cz`, supply strong secrets, enforce one realtime replica, configure backups/TLS/monitoring, and run deployed smoke tests.
