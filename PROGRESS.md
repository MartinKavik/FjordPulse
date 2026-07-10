# FjordPulse implementation progress

Last updated: 2026-07-10

FjordPulse is now a feature-complete, locally verified application, not an implementation skeleton. This file separates completed local scope from the intentionally unperformed production deployment phase.

## Phase status

| Phase | Status | Evidence summary |
|---|---|---|
| 0 — consolidated inputs | Complete | 23 design PNGs, 23 design notes, 108 stories, and 324 black-box scenarios are present. |
| 1 — dependency spikes and runnable skeleton | Complete | Exact tool/dependency pins, CakePHP routes, FrankenPHP, AMPHP WebSockets, SurrealDB sync/async/live-query tests, and Entur probes exist and have run. |
| 2 — SolidJS visual prototype | Complete | All 23 approved desktop/mobile/admin/design-system scenario routes are implemented and pass visual comparison. |
| 3 — contract-complete fake mode | Complete | The fake adapters use the production interfaces, repositories, SurrealDB events, live-query bridge, WebSocket protocol, and API-mode frontend. |
| 4 — CakePHP HTTP/control plane | Complete | Public, health/readiness, admin, development-scenario, validation, security, logging, and fallback endpoints are implemented and contract-tested. |
| 5 — AMPHP/Revolt realtime service | Complete | `bin/cake realtime start`, signed handshakes, rooms, watch/focus lifecycle, scheduler, health, isolation, and graceful shutdown are covered by tests. |
| 6 — SurrealDB canonical event path | Complete | Real integration tests prove commit -> `DEFINE EVENT` -> `realtime_event` -> one global `LIVE SELECT` -> room/WebSocket, including database restart recovery. |
| 7 — real stack with fake third parties | Complete | The clean-stack Playwright proof uses real SurrealDB, migrations, CakePHP HTTP, the realtime command, and Vite in `VITE_DATA_MODE=api`. |
| 8 — real Entur integration | Complete for local v1 | Backend-only typed adapters and live smoke probes cover Stop Place Register, Geocoder, Journey Planner, and bounded Vehicle Positions queries. |
| 9 — full local quality/configuration | Complete | Fresh installation, static checks, contracts, PHP/Vitest suites, fixture and clean-stack E2E, 23 visual comparisons, build, and infrastructure validation are green. |
| 10 — deployment | Deliberately excluded | Hetzner, Coolify, DNS, production secrets, backups, and production rollout remain deployment work. |

## Implemented local stack

- SolidJS, TypeScript, Vite, MapLibre GL JS, responsive public surfaces, protected admin surfaces, and deterministic development/visual scenarios.
- CakePHP 6 HTTP/control endpoints running on embedded PHP 8.5 under FrankenPHP normal mode.
- `bin/cake realtime start` using AMPHP/Revolt for signed browser WebSockets, rooms, watches, focus, timers, health, and graceful shutdown.
- Typed fake and real Entur adapters; raw third-party arrays are confined to adapter/mapping boundaries.
- SurrealDB migrations, database-scoped app user, typed repositories, canonical current state, durable diagnostics, semantic database events, and a supervised dedicated live-query connection.
- HTTP polling fallback and degraded health when the live-query/realtime path is unhealthy.
- Root install/dev/stop/typecheck/phpstan/test/e2e/visual/build commands, exact lockfiles, local process orchestration, Caddy/FrankenPHP configuration, and deployment-oriented Docker/Compose artifacts.

## Verified evidence

### Exact dependency surface

- FrankenPHP `1.12.4` with embedded PHP `8.5.8` is checksum-pinned by the project wrapper.
- CakePHP reports `6.0.0-dev` and is pinned to official `6.x` commit `39f5594eb9c79e3ec46aa786b617af0a622b72d3` because no CakePHP 6 tag existed for the spike.
- Composer `2.10.2`, SurrealDB server `3.2.0`, SurrealDB PHP SDK `2.0.0-alpha.1`, AMPHP/Revolt packages, Node `22.22.0`, frontend packages, PHPUnit `13.2.4`, and PHPStan `2.2.5` are exact-pinned with lockfiles.
- Installed SDK symbols were checked rather than inferred: `Surreal`, `Runtime::sync()`, `Runtime::amp()`, `ConnectOptions`, `DatabaseAuth`, `ExponentialBackoffReconnect`, and live-query feature support.
- ADR 0012 records the experimental dependency policy; ADR 0013 records bounded demand-driven Vehicle Positions HTTP queries for v1.

### Contracts and traceability

- OpenAPI 3.1 defines 21 HTTP operations.
- Realtime schemas define 9 client commands and 23 server message types.
- `contracts/traceability.json` accounts for all 108 stories, including 22 explicitly non-wire stories.
- `docs/user-stories/00_manifest.json` records 324 black-box scenarios.
- Fresh `make test` contract evidence on 2026-07-10: OpenAPI lint passed; 32 valid realtime fixtures were accepted, 9 invalid fixtures were rejected, and 8 HTTP fixtures were accepted.

### PHP, persistence, HTTP, and realtime

- Fresh `make phpstan` on 2026-07-10: PHPStan maximum level completed with no errors across application and test code.
- Fresh `make test` on 2026-07-10: PHPUnit passed 50 tests with 390 assertions; one explicit external Entur test was skipped by the ordinary offline suite.
- A focused HTTP black-box run passed 6 tests with 135 assertions and validates responses against OpenAPI.
- The PHPUnit suite includes real SurrealDB migration/idempotency/checksum tests, typed repository tests, semantic `DEFINE EVENT` tests, non-blocking `Runtime::amp()` live delivery, a real database restart/re-subscription test, WebSocket authorization/isolation/shutdown tests, and a canonical-write-to-WebSocket test.

### Frontend and build

- Fresh `make typecheck` on 2026-07-10: strict TypeScript completed successfully.
- Fresh `make test` on 2026-07-10: Vitest passed 48 tests in 5 files.
- Fresh `make build` on 2026-07-10: TypeScript, contracts, the Vite production build, Composer validation, infrastructure topology validation, Caddy adaptation, and generated `frontend/dist/index.html` all passed. Composer emitted expected warnings about the intentional exact/commit pins; it did not fail validation.

### Clean-stack Playwright proof

Command:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  npx playwright test --config=playwright.live.config.ts
```

Result on 2026-07-10: 1 test passed in 24.9 seconds.

The test creates a clean SurrealDB data directory, applies all three migrations, imports deterministic stations, and starts the actual realtime command, FrankenPHP/CakePHP HTTP service, and Vite with `VITE_DATA_MODE=api` and frontend fixtures disabled. It then proves:

- visible station map/search/departure data comes from CakePHP and authoritative SurrealDB state;
- the browser obtains a signed realtime token and opens `/live`;
- station watch and vehicle watch/focus acknowledgements arrive over WebSocket;
- HTTP-triggered canonical writes become database-originated `station_snapshot_changed`, `vehicle_moved`, and `vehicle_lost` messages before updating the visible SolidJS UI;
- backend scenarios visibly exercise station empty/stale/error, vehicle lost, polling fallback, and reconnecting bridge state;
- protected admin watch, realtime, and persisted-event diagnostics reflect the live session;
- browser traffic never calls Entur or SurrealDB directly;
- all isolated test services and ports are stopped afterward.

### Real Entur probes

Backend-only requests with `ET-Client-Name: martinkavik-fjordpulse` passed against:

- Geocoder v3 autocomplete;
- Journey Planner v3 departure data;
- Stop Place Register v1 read data;
- Vehicle Positions v2 bounded HTTP GraphQL queries;
- the Vehicle Positions subscription endpoint as a capability spike.

The typed external PHPUnit smoke passed all four production adapter surfaces. Production browser code has no Entur or SurrealDB access path.

## Final completion gates

The complete required sequence passed on 2026-07-10.

| Gate | Current evidence |
|---|---|
| `make verify-planning` | Passed: 23 design PNGs, 23 design notes, 108 stories, zero source-corpus ZIPs. |
| `make install` | Passed from exact Composer/npm lockfiles and installed the project-managed Chromium. |
| `make typecheck` | Passed fresh on 2026-07-10. |
| `make phpstan` | Passed fresh at maximum level on 2026-07-10. |
| `make test` | Passed fresh: contracts, PHPUnit 50/390 with one external skip, Vitest 48/48. |
| `make e2e` | Passed: 7 deterministic fixture/accessibility tests plus 1 clean-stack SurrealDB/CakePHP/AMPHP/Vite test. |
| `make visual` | Passed: all 23 reviewed coded baselines matched. |
| `make build` | Passed fresh on 2026-07-10. |

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
