# FjordPulse implementation progress

Last updated: 2026-07-11

FjordPulse is now a feature-complete, locally verified application, not an implementation skeleton. This file separates completed local scope from the intentionally unperformed production deployment phase.

## Phase status

| Phase | Status | Evidence summary |
|---|---|---|
| 0 — consolidated inputs | Complete | 23 design PNGs, 23 design notes, 108 stories, and 325 black-box scenarios are present. |
| 1 — dependency spikes and runnable skeleton | Complete | Exact tool/dependency pins, CakePHP routes, FrankenPHP, AMPHP WebSockets, SurrealDB sync/async/live-query tests, and Entur probes exist and have run. |
| 2 — SolidJS visual prototype | Complete | All 23 approved desktop/mobile/admin/design-system scenario routes are implemented and pass visual comparison. |
| 3 — contract-complete fake mode | Complete | The fake adapters use the production interfaces, repositories, SurrealDB events, live-query bridge, WebSocket protocol, and API-mode frontend. |
| 4 — CakePHP HTTP/control plane | Complete | Public, health/readiness, admin, development-scenario, validation, security, logging, and fallback endpoints are implemented and contract-tested. |
| 5 — AMPHP/Revolt realtime service | Complete | `bin/cake realtime start`, signed handshakes, rooms, watch/focus lifecycle, scheduler, health, isolation, and graceful shutdown are covered by tests. |
| 6 — SurrealDB canonical event path | Complete | Real integration tests prove commit -> `DEFINE EVENT` -> `realtime_event` -> one global `LIVE SELECT` -> room/WebSocket, including database restart recovery. |
| 7 — real stack with fake third parties | Complete | The clean-stack Playwright proof uses real SurrealDB, migrations, CakePHP HTTP, the realtime command, and Vite in `VITE_DATA_MODE=api`. |
| 8 — real Entur integration | Complete for local v1 | Backend-only typed adapters cover Stop Place Register, Geocoder, Journey Planner, and coalesced nationwide Vehicle Positions queries; a live smoke resolves a current vehicle into route geometry and ordered calls. |
| 9 — full local quality/configuration | Complete | Real/demo profile isolation, truthfulness enforcement, static checks, contracts, PHP/Vitest suites, fixture and clean-stack E2E, 23 visual comparisons, build, and infrastructure validation are green. |
| 10 — deployment | Deliberately excluded | Hetzner, Coolify, DNS, production secrets, backups, and production rollout remain deployment work. |

## Implemented local stack

- SolidJS, TypeScript, Vite, MapLibre GL JS, a labelled MapTiler Hybrid satellite default with a persistent Streets alternative, shareable reload-safe camera URLs, class-aware roads/place labels, label-safe count-scaled station clusters, complete journey overlays, responsive public surfaces, protected admin surfaces, and isolated deterministic scenarios.
- CakePHP 6 HTTP/control endpoints running on embedded PHP 8.5 under FrankenPHP normal mode.
- `bin/cake realtime start` using AMPHP/Revolt for signed browser WebSockets, rooms, watches, focus, timers, health, and graceful shutdown.
- Typed fake and real Entur adapters; raw third-party arrays are confined to adapter/mapping boundaries. Vehicle Positions service-journey identities resolve through Journey Planner into validated route geometry, calls, progress, and upcoming stops.
- SurrealDB migrations, database-scoped app user, typed repositories, source-provenance-safe station catalogs, canonical current state, journey snapshots, durable diagnostics, semantic database events, and a supervised dedicated live-query connection.
- Bounds-aware station-map aggregation runs in SurrealDB and adaptively clusters every matched station into at most 2,000 response items, so the 57,964-row real catalog is never hydrated into one PHP request.
- HTTP polling fallback and degraded health when the live-query/realtime path is unhealthy.
- Automatic same-page frontend recovery after realtime-only or complete CakePHP HTTP + realtime outages, including watch resubscription; transient Entur transport failures retain authoritative cached data and retry from the backend after bounded backoff.
- Public telemetry derives backend, realtime, Entur, refresh-mode, and last-update labels from validated health/resource timestamps. Idle demand-driven realtime is presented as ready/on-demand, not as a failure.
- Root install/dev/dev-demo/stop/typecheck/phpstan/test/e2e/visual/build commands, exact lockfiles, real/demo-isolated local orchestration, JSON-shape startup readiness checks, Caddy/FrankenPHP configuration, and deployment-oriented Docker/Compose artifacts.

## Verified evidence

### Exact dependency surface

- FrankenPHP `1.12.4` with embedded PHP `8.5.8` is checksum-pinned by the project wrapper.
- CakePHP reports `6.0.0-dev` and is pinned to official `6.x` commit `39f5594eb9c79e3ec46aa786b617af0a622b72d3` because no CakePHP 6 tag existed for the spike.
- Composer `2.10.2`, SurrealDB server `3.2.0`, SurrealDB PHP SDK `2.0.0-alpha.1`, AMPHP/Revolt packages, Node `22.22.0`, frontend packages, PHPUnit `13.2.4`, and PHPStan `2.2.5` are exact-pinned with lockfiles.
- Installed SDK symbols were checked rather than inferred: `Surreal`, `Runtime::sync()`, `Runtime::amp()`, `ConnectOptions`, `DatabaseAuth`, `ExponentialBackoffReconnect`, and live-query feature support.
- ADR 0012 records the experimental dependency policy; ADR 0013 records bounded demand-driven Vehicle Positions HTTP queries for v1.

### Contracts and traceability

- OpenAPI 3.1 defines 22 HTTP operations, including the typed same-origin map-provider configuration endpoint.
- Realtime schemas define 9 client commands and 23 server message types.
- `contracts/traceability.json` accounts for all 108 stories, including 22 explicitly non-wire stories.
- `docs/user-stories/00_manifest.json` records 325 black-box scenarios.
- Fresh `make test` contract evidence on 2026-07-11: OpenAPI lint passed; 32 valid realtime fixtures were accepted, 9 invalid fixtures were rejected, and 9 HTTP fixtures were accepted.

### PHP, persistence, HTTP, and realtime

- Fresh `make phpstan` on 2026-07-11: PHPStan maximum level completed with no errors across application and test code.
- Fresh `make test` on 2026-07-11: PHPUnit passed 84 tests with 707 assertions; one explicit external Entur test was skipped by the ordinary offline suite.
- HTTP black-box coverage validates responses against OpenAPI, including map-provider configuration, tolerant search, station-to-vehicle-to-journey resolution, non-empty route/calls/upcoming stops, explicit failure states, and a synthetic 58,500-station map whose complete totals remain bounded and stable without a PHP memory spike.
- The PHPUnit suite includes real SurrealDB migration/idempotency/checksum tests, typed repository and catalog-provenance tests, journey persistence and no-dual-event tests, semantic `DEFINE EVENT` tests, non-blocking `Runtime::amp()` live delivery, a real database restart/re-subscription test, WebSocket authorization/isolation/shutdown tests, and a canonical-write-to-WebSocket test. Exact expired-token regressions prove that a long-running command replaces its authenticated HTTP connection and retries the interrupted operation once, while unrelated 401 responses and replacement failures remain visible. A controlled Entur process-boundary gate establishes a real Amp HTTP connection, stops and unbinds the upstream, preserves the last successful snapshot through failure/backoff, restarts the same endpoint, and proves the unchanged backend client/scheduler recovers; companion assertions enforce the shared request budget.

### Frontend and build

- Fresh `make typecheck` on 2026-07-11: strict TypeScript completed successfully.
- Fresh `make test` on 2026-07-11: Vitest passed 72 tests in 8 files, including shared-clock advancement, Norwegian character folding/typo tolerance, compact-event journey advancement, strict journey contracts, label-safe transport overlay ordering, cartography policy, validated dependency telemetry, rider-centred welcome copy, and failure-state truthfulness.
- Fresh `make build` on 2026-07-11: TypeScript, contracts, the Vite production build, production-fixture/truth audit, Composer validation, infrastructure topology validation, Caddy adaptation, and generated `frontend/dist/index.html` all passed. Composer emitted expected warnings about the intentional exact/commit pins; it did not fail validation.

### Clean-stack Playwright proof

Command:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  npx playwright test --config=playwright.live.config.ts
```

Result on 2026-07-11: all 11 clean-stack tests passed.

The test creates a clean SurrealDB data directory, applies all five migrations, imports deterministic stations, and starts the actual realtime command, FrankenPHP/CakePHP HTTP service, and Vite with `VITE_DATA_MODE=api` and frontend fixtures disabled. It then proves:

- visible station map/search/departure data comes from CakePHP and authoritative SurrealDB state;
- the browser obtains a signed realtime token and opens `/live`;
- station watch and vehicle watch/focus acknowledgements arrive over WebSocket;
- HTTP-triggered canonical writes become database-originated `station_snapshot_changed`, `vehicle_moved`, and `vehicle_lost` messages before updating the visible SolidJS UI;
- backend scenarios visibly exercise station empty/stale/error, vehicle lost, polling fallback, and reconnecting bridge state;
- protected admin watch, realtime, and persisted-event diagnostics reflect the live session;
- browser traffic never calls Entur or SurrealDB directly;
- first visits load the Hybrid satellite basemap, pan/zoom requests new tile coordinates, and switching to Streets preserves the camera and rendered transport overlays;
- settled camera state is canonicalized as `#map=zoom/latitude/longitude`; copied links restore before the first viewport request, survive reload and a second tab, and malformed state falls back without losing query parameters;
- the last successfully loaded layer survives reload, while provider failure exposes Retry and never substitutes deterministic fixture geography;
- selecting and focusing a vehicle exposes its complete planned route, passed/remaining split, breadcrumb trail, route overview, and upcoming calls without replacing the route with observations;
- a fresh reload returns valid JSON for health and the complete fake catalog, renders transport overlays, shows truthful ready/standby/on-demand telemetry with a concrete last-update age, and opens no application WebSocket before selection;
- an actual realtime child-process stop changes the selected station to HTTP polling fallback; restarting the child reconnects, resubscribes, and preserves the station;
- stopping both CakePHP HTTP and realtime leaves the selected station, usable map, rendered overlays, and page document intact; restarting both automatically restores backend health, creates a new WebSocket, resubscribes the watch, and returns to realtime without Reload or manual Retry;
- all isolated test services and ports are stopped afterward.

### Corrected product truthfulness

- `make dev` now forces real Entur adapters and a persistent `.data/surreal-real` / `fjordpulse_real` catalog; `make dev-demo` forces fake adapters in an ephemeral `.run/surreal-demo` / `fjordpulse_demo` store. Source modes cannot silently share authoritative state.
- The complete Stop Place catalog is staged with source identity and resumable progress. The source contained 57,964 rows during the 2026-07-10 live import. Entur accepted 5,000-row probes, but complete local bootstrap exposed the PHP 128 MB ceiling; the proven default is therefore 1,000-row source/write chunks, with 5,000 retained only as an operator-configurable maximum.
- The public station map performs server-side projection/aggregation and probes one item past its 2,000-item budget. Live Norway zoom 4 returned 31 clusters representing all 57,822 in-bounds stations; a synthetic 58,500-row regression is part of the ordinary suite.
- Ordinary clusters/stations render below provider symbols, selected transport remains above, cluster counts are compact, and transparent 36 px hit targets preserve clickability. Dense viewports stay aggregated through zoom 8; zoom 9+ exposes individual markers only when at most 300 stations are present.
- Search normalizes Norwegian characters and diacritics, supports prefix matches such as `Fo`, and permits one bounded adjacent transposition/edit such as `Frode` for `Førde` without turning unrelated text into results.
- A shared reactive clock owns all relative ages. Direction, delay, source state, locality, admin identity, clocks, and nullable measurements are derived from authoritative values rather than display literals.
- Public welcome, loading, empty-state, and vehicle-follow copy describes rider outcomes—finding stations, seeing departures, and following routes—rather than presenting clustering, request scope, cache strategy, or scheduler priority as product benefits.
- Long-running realtime database commands recover from an expired SurrealDB app-user token by creating a fresh authenticated connection, swapping it atomically, and retrying the interrupted query exactly once. The dedicated live-query connection retains its independent reconnect supervisor.
- Entur `SourceUnavailable` refreshes enter an explicit 15-second `source_unavailable` backoff. Active watches retry automatically, retain cached values and `lastSuccessfulAt`, obey shared budgets, and clear the error after the upstream returns; 429 responses continue to honor `Retry-After`.
- Normal frontend routes no longer import or substitute transport fixtures. `scripts/audit-production-truth.mjs` scans production-reachable source and the built bundle; demo mode is visibly labelled and real mode carries Entur attribution.
- `docs/audits/production-truthfulness.md` records why earlier mechanical readiness gates were not sufficient and lists every corrected production-reachable defect.

### Real Entur probes

Backend-only requests with `ET-Client-Name: martinkavik-fjordpulse` passed against:

- Geocoder v3 autocomplete;
- Journey Planner v3 departure data;
- Stop Place Register v1 read data;
- Vehicle Positions v2 bounded HTTP GraphQL queries;
- a current Vehicle Positions record joined through its service-journey identity to non-empty Journey Planner geometry and ordered calls;
- the Vehicle Positions subscription endpoint as a capability spike.

Fresh `make smoke-entur` passed 1 external integration test with 12 assertions across all four production adapter surfaces, including the live vehicle-to-journey join. Production browser code has no Entur or SurrealDB access path.

## Final completion gates

The complete required sequence passed on 2026-07-10.

| Gate | Current evidence |
|---|---|
| `make verify-planning` | Passed: 23 design PNGs, 23 design notes, 108 stories, zero source-corpus ZIPs. |
| `make install` | Passed from exact Composer/npm lockfiles and installed the project-managed Chromium. |
| `make typecheck` | Passed fresh on 2026-07-11. |
| `make phpstan` | Passed fresh at maximum level on 2026-07-11. |
| `make test` | Passed fresh: contracts, PHPUnit 84/707 with one external skip, Vitest 72/72. |
| `make e2e` | Passed: 7 deterministic fixture/accessibility tests plus 11 clean-stack SurrealDB/CakePHP/AMPHP/Vite/provider/lifecycle/camera-URL/resilience tests. |
| `make visual` | Passed: all 23 reviewed coded baselines matched. |
| `make build` | Passed fresh on 2026-07-11. |

## Final aggregate gate record

The release handoff ran:

```bash
make verify-planning
make install
make typecheck
make phpstan
make test
make e2e
make visual
make build
git diff --check
```

All commands above passed. `git diff --check` also passed before staging.

## Deployment-only work

Local readiness does not mean FjordPulse has been deployed. The following remain intentionally outside this implementation run:

- provisioning a Hetzner CX33;
- installing or configuring Coolify;
- changing `fjordpulse.kavik.cz` DNS;
- creating or loading production credentials/secrets;
- configuring production backup/restore, TLS, monitoring, or rollback policy;
- running production smoke tests or rollout.

Repository `.env.example` files and Compose/Caddy artifacts contain development placeholders only and are not production secret material.
