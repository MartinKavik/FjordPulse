# FjordPulse implementation progress

Last updated: 2026-07-13

FjordPulse is a feature-complete, locally verified application, not an implementation skeleton. The Norwegian/English localization baseline passed on 2026-07-12, and the complete affected verification sequence passed again after the admin-observability work on 2026-07-13. This file separates completed local scope from the intentionally unperformed production deployment phase.

## 2026-07-13 admin navigation cleanup

- Removed the duplicate `Overview` / `Oversikt` sidebar link that pointed to the same `/admin/status` route as `System status` / `Systemstatus`. The documented operator dashboard now has one canonical, active navigation destination instead of two labels for one page.
- Added component, routing, and browser regressions for a single `/admin/status` link, its localized accessible name, `aria-current="page"`, and compatible hidden `/admin` / `/admin/overview` resolution. The focused Chromium admin flow passed 1/1 and the refreshed Norwegian/English admin-status baselines passed 2/2; the final integrated 2026-07-13 gate record is listed below.

## 2026-07-13 admin observability truthfulness

- Active watch metrics now require a connected persisted client, a future lease, and a non-expired state. Disconnect-grace rows become expiring immediately, cannot be reactivated by an in-flight refresh, and restore correctly if the browser reconnects before TTL. Realtime startup prunes only past-expiry rows, so it does not erase another process's still-valid lease.
- Replaced the unexplained generic rate-budget value with a bilingual `FjordPulse → Entur` allowance that identifies the affected backend APIs, exact configuration settings, rolling 60-second semantics, request-log evidence, and provider documentation. HTTP and realtime callers reserve against one atomic SurrealDB ledger before transport, making the global and per-service limits shared across processes and including in-flight requests without pretending the values are an Entur-reported account quota.
- System status now presents only five compact database-notification summaries and links to the complete Persisted events evidence page. Realtime-server and live-query-bridge signals remain separate in the contract and detailed diagnostics but are grouped into one `Realtime delivery` overview card with independent Server and Database events state/latency checks, avoiding repeated Live Query labels without hiding a degraded subcheck.
- Added real-stack browser assertions for closed-tab cleanup, concise event preview/full-history separation, complete real dependency coverage, and bilingual desktop/mobile overflow safety.
- The completed 2026-07-13 affected gates passed planning, contracts, strict TypeScript, maximum-level PHPStan, 159 backend tests with 1207 assertions and one intentional external-Entur skip, all 10 frontend test files with 131 tests, all 15 fixture Playwright tests, and all 14 clean-stack Playwright tests.
- Headless Playwright commands now remove a stale desktop `DISPLAY` value before launching Chromium. This keeps MapLibre tests on Chromium's surfaceless SwiftShader/WebGL path when the host's Xwayland display is unavailable; it does not change the manually opened browser or `make dev`.

## Phase status

| Phase | Status | Evidence summary |
|---|---|---|
| 0 — consolidated inputs | Complete | The planning inventory now defines 25 design pairs, including two coded non-passenger vehicle states, plus 108 stories and 339 black-box scenarios. |
| 1 — dependency spikes and runnable skeleton | Complete | Exact tool/dependency pins, CakePHP routes, FrankenPHP, AMPHP WebSockets, SurrealDB sync/async/live-query tests, and Entur probes exist and have run. |
| 2 — SolidJS visual prototype | Complete | The bilingual matrix covers 25 deterministic routes in both locales, plus dedicated desktop/mobile Vehicles and Details tab captures, for 58 reviewed comparisons. |
| 3 — contract-complete fake mode | Complete | The fake adapters use the production interfaces, repositories, SurrealDB events, live-query bridge, WebSocket protocol, and API-mode frontend. |
| 4 — CakePHP HTTP/control plane | Complete | Public, health/readiness, admin, development-scenario, validation, security, logging, and fallback endpoints are implemented and contract-tested. |
| 5 — AMPHP/Revolt realtime service | Complete | `bin/cake realtime start`, signed handshakes, rooms, watch/focus lifecycle, scheduler, health, isolation, and graceful shutdown are covered by tests. |
| 6 — SurrealDB canonical event path | Complete | Real integration tests prove commit -> `DEFINE EVENT` -> `realtime_event` -> one global `LIVE SELECT` -> room/WebSocket, including database restart recovery. |
| 7 — real stack with fake third parties | Complete | The clean-stack Playwright proof uses real SurrealDB, migrations, CakePHP HTTP, the realtime command, and Vite in `VITE_DATA_MODE=api`. |
| 8 — real Entur integration | Complete for local v1 | Backend-only typed adapters cover Stop Place Register, Geocoder, Journey Planner, and coalesced nationwide Vehicle Positions queries; a live smoke resolves a current vehicle into route geometry and ordered calls. |
| 9 — full local quality/configuration | Complete | Planning, static checks, contracts, PHP/Vitest, fixture and clean-stack E2E, all 58 locale-aware visual comparisons, production build/truth audit, infrastructure validation, and diff hygiene are green. |
| 10 — deployment | Deliberately excluded | Hetzner, Coolify, DNS, production secrets, backups, and production rollout remain deployment work. |

## Implemented local stack

- SolidJS, TypeScript, Vite, MapLibre GL JS, Norwegian Bokmål as the deterministic default locale, an accessible persistent `NO`/`EN` switcher shared by public/admin/scenario surfaces, responsive localized copy, a labelled MapTiler Hybrid satellite default with a persistent Streets alternative, shareable reload-safe camera URLs, context-preserving selection, persistent selected station/vehicle pins, a bottom-tip-anchored selected-vehicle marker with one non-overlapping responsive mode/line label, class-aware roads and collision-managed town/village/local-place labels from zoom 6/8/10, label-safe count-scaled station clusters, complete journey overlays, a persistent collapsible desktop/mobile introduction, station-serving vehicle groups with bounded coverage plus separate completed nearby-vehicle states using the server-reported 5 km radius in both station views, responsive public surfaces, protected admin surfaces, and isolated deterministic scenarios.
- CakePHP 6 HTTP/control endpoints running on embedded PHP 8.5 under FrankenPHP normal mode.
- `bin/cake realtime start` using AMPHP/Revolt for signed browser WebSockets, rooms, watches, focus, timers, health, and graceful shutdown.
- Typed fake and real Entur adapters; raw third-party arrays are confined to adapter/mapping boundaries. Vehicle Positions service-journey identities resolve through Journey Planner into validated route geometry, calls, progress, upcoming stops, authoritative vehicle modes, and bounded station-service matches.
- SurrealDB migrations, database-scoped app user, typed repositories, source-provenance-safe station catalogs, canonical current state, journey snapshots, durable diagnostics, semantic database events, and a supervised dedicated live-query connection.
- Bounds-aware station-map aggregation runs in SurrealDB and adaptively clusters every matched station into at most 2,000 response items, so the 57,964-row real catalog is never hydrated into one PHP request.
- HTTP polling fallback and degraded health when the live-query/realtime path is unhealthy.
- Automatic same-page frontend recovery after realtime-only or complete CakePHP HTTP + realtime outages, including watch resubscription; transient Entur transport failures retain authoritative cached data and retry from the backend after bounded backoff.
- Public update health derives from validated backend, realtime, Entur, refresh-mode, and resource timestamps without exposing a permanent service matrix. Healthy lazy realtime is silent; selected resources own age and exceptional warnings, and one contextual desktop/mobile notice explains reconnecting, periodic refresh, or unavailable saved-data fallback. Component diagnostics remain in Admin, so a source error/rate limit cannot be hidden behind a healthy global badge.
- Admin status exposes deployment environment, data mode, build identity, sampled CPU usage/load, scoped free/used memory, application-filesystem free/used space, SurrealDB/catalog/import state, canonical data counts, an explained internal Entur allowance, truthful connected-client/watch demand, and a latest-five persisted-event preview linked to the full evidence page. Lost/stale vehicle evidence retains source, version, timestamps, explanation, and raw payload on that dedicated page. Metrics without a real data source are omitted, demand-driven Entur inactivity is neutral `IDLE` rather than a false degradation, anonymous visitor/session analytics are not fabricated from connection or watch counts, and the signed-in identity is visually separate from the explicit exit-icon `Log out` action.
- Root install/dev/dev-demo/stop/typecheck/phpstan/test/e2e/visual/build commands, exact lockfiles, real/demo-isolated local orchestration, JSON-shape startup readiness checks, Caddy/FrankenPHP configuration, and deployment-oriented Docker/Compose artifacts.

## Verified evidence

The 2026-07-12 verification established the full reactive Norwegian/English public, map, search, station, vehicle, admin, scenario, formatting, accessibility, and 58-comparison visual baseline. The complete 58-comparison matrix and production build passed again on 2026-07-13 after the admin-observability delta, alongside fresh planning, strict TypeScript, PHPStan, contracts, PHPUnit, Vitest, fixture Playwright, and clean-stack Playwright verification.

### Exact dependency surface

- FrankenPHP `1.12.4` with embedded PHP `8.5.8` is checksum-pinned by the project wrapper.
- GitHub replaced the official FrankenPHP `v1.12.4` Linux asset on 2026-07-11 and again on 2026-07-12. CI correctly rejected each stale digest; both runtime wrappers pin the current asset's GitHub-published SHA-256, and the wrapper was verified from an empty tool cache. Fresh-download checksum failures print the failed file instead of ending with an opaque install error.
- CakePHP reports `6.0.0-dev` and is pinned to official `6.x` commit `39f5594eb9c79e3ec46aa786b617af0a622b72d3` because no CakePHP 6 tag existed for the spike.
- Composer `2.10.2`, SurrealDB server `3.2.0`, SurrealDB PHP SDK `2.0.0-alpha.1`, AMPHP/Revolt packages, Node `22.22.0`, frontend packages, PHPUnit `13.2.4`, and PHPStan `2.2.5` are exact-pinned with lockfiles.
- Installed SDK symbols were checked rather than inferred: `Surreal`, `Runtime::sync()`, `Runtime::amp()`, `ConnectOptions`, `DatabaseAuth`, `ExponentialBackoffReconnect`, and live-query feature support.
- ADR 0012 records the experimental dependency policy; ADR 0013 records bounded demand-driven Vehicle Positions HTTP queries for v1.

### Contracts and traceability

- OpenAPI 3.1 defines 22 HTTP operations, including the typed same-origin map-provider configuration endpoint.
- Realtime schemas define 9 client commands and 23 server message types.
- `contracts/traceability.json` accounts for all 108 stories, including 22 explicitly non-wire stories.
- `docs/user-stories/00_manifest.json` records 339 black-box scenarios.
- Fresh contract evidence on 2026-07-13: OpenAPI lint and the complete realtime/HTTP schema-and-fixture validation passed.

### PHP, persistence, HTTP, and realtime

- Fresh `make phpstan` on 2026-07-13: PHPStan maximum level completed with no errors across application and test code.
- Fresh backend PHPUnit on 2026-07-13 passed 159 tests with 1207 assertions; one explicit external Entur test was intentionally skipped by the ordinary offline suite.
- HTTP black-box coverage validates responses against OpenAPI, including map-provider configuration, tolerant search, station-to-vehicle-to-journey resolution, non-empty route/calls/upcoming stops, explicit failure states, and a synthetic 58,500-station map whose complete totals remain bounded and stable without a PHP memory spike.
- The PHPUnit suite includes real SurrealDB migration/idempotency/checksum tests, typed repository and catalog-provenance tests, journey persistence and no-dual-event tests, semantic `DEFINE EVENT` tests, non-blocking `Runtime::amp()` live delivery, a real database restart/re-subscription test, WebSocket authorization/isolation/shutdown tests, and a canonical-write-to-WebSocket test. Exact expired-token regressions prove that a long-running command replaces its authenticated HTTP connection and retries the interrupted operation once, while unrelated 401 responses and replacement failures remain visible. Controlled Entur gates prove independent Journey Planner/Vehicle Positions results, cached snapshot preservation, watch backoff, and recovery after an upstream restart. Amp transport failures discard the failed process-lifetime connection pool without an immediate duplicate request; the next scheduler attempt creates a fresh pool, and the retry delay starts only after a slow failed attempt completes while shared budgets remain authoritative.

### Frontend and build

- Fresh `make typecheck` on 2026-07-13: strict TypeScript completed successfully.
- Fresh frontend Vitest on 2026-07-13 passed all 10 test files and all 131 tests, including Norwegian-default locale selection, reactive switching, valid/invalid/blocked local-storage behavior, document-language synchronization, shared-clock advancement, Norwegian character folding/typo tolerance, compact-event journey advancement, strict cross-field journey contracts, backend-authored passenger-service classification, non-passenger panel/map/Focus presentation, passenger-to-operational-to-passenger Focus recovery without reselection, destination-neutral accessibility copy, cached-versus-unavailable journey wording, context-preserving selection, selected-station survival outside a clustered viewport catalog, label-safe transport overlay ordering, selected-vehicle label-side placement, guarded town/village/place label phasing, persisted responsive welcome-panel state, validated dependency-state reduction, contextual public update health, stale notice-value crash protection, truthful station-to-Entur state combination, credential-free admin database-target diagnostics, bounded host-resource parsing and unavailable-measurement omission, compact System-status event summaries with dedicated evidence history, grouped-but-independent realtime delivery diagnostics, deterministic fallback-to-live recovery, rider-centred welcome copy, failure-state truthfulness, exclusive station-tab resource allocation, accessible resource counts, missing station-metadata handling, completed-versus-loading/paused nearby-vehicle states, and protection against mislabelling unrelated vehicle metrics as station distance.
- Fresh `make build` on 2026-07-13: the Vite production build, production-fixture/truth audit, and infrastructure topology validation passed.
- The UI now self-hosts exact-pinned Inter Variable normal and italic web fonts. Visual scenarios require the bundled face to be loaded before capture, eliminating the host-font fallback that made local screenshots use Noto Sans while GitHub's Ubuntu runner used a different fallback.

### Clean-stack Playwright proof

Command:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  npm run e2e:live
```

Result on 2026-07-13: all 14 clean-stack tests passed. The repository scripts unset `DISPLAY` for headless Chromium, avoiding an unusable inherited Xwayland display while retaining surfaceless SwiftShader WebGL for the map assertions.

The test creates a clean SurrealDB data directory, applies all nine migrations, imports deterministic stations, and starts the actual realtime command, FrankenPHP/CakePHP HTTP service, and Vite with `VITE_DATA_MODE=api` and frontend fixtures disabled. It then proves:

- visible station map/search/departure data comes from CakePHP and authoritative SurrealDB state;
- the browser obtains a signed realtime token and opens `/live`;
- station watch and vehicle watch/focus acknowledgements arrive over WebSocket;
- HTTP-triggered canonical writes become database-originated `station_snapshot_changed`, `vehicle_moved`, and `vehicle_lost` messages before updating the visible SolidJS UI;
- backend scenarios visibly exercise a completed 5 km station/vehicle empty result, rate-limited zero-result refresh without a false completion claim, station stale/error, vehicle lost, polling fallback, and reconnecting bridge state;
- protected admin watch, realtime, and persisted-event diagnostics reflect the live session;
- browser traffic never calls Entur or SurrealDB directly;
- first visits load the Hybrid satellite basemap, pan/zoom requests new tile coordinates, and switching to Streets preserves the camera and rendered transport overlays;
- settled camera state is canonicalized as `#map=zoom/latitude/longitude`; copied links restore before the first viewport request, survive reload and a second tab, and malformed state falls back without losing query parameters;
- the last successfully loaded layer survives reload, while provider failure exposes Retry and never substitutes deterministic fixture geography;
- selecting and focusing a vehicle exposes its complete planned route, passed/remaining split, breadcrumb trail, route overview, and upcoming calls without replacing the route with observations;
- a fresh reload returns valid JSON for health and the complete fake catalog, renders transport overlays, shows no redundant healthy-ready indicator, keeps `Demo data` provenance visible, and opens no application WebSocket before selection;
- an actual realtime child-process stop changes the selected station to HTTP polling fallback; restarting the child reconnects, resubscribes, and preserves the station;
- stopping both CakePHP HTTP and realtime leaves the selected station, usable map, rendered overlays, and page document intact; restarting both automatically restores backend health, creates a new WebSocket, resubscribes the watch, and returns to realtime without Reload or manual Retry;
- all isolated test services and ports are stopped afterward.

### Corrected product truthfulness

- `make dev` now forces real Entur adapters and a persistent `.data/surreal-real` / `fjordpulse_real` catalog; `make dev-demo` forces fake adapters in an ephemeral `.run/surreal-demo` / `fjordpulse_demo` store. Source modes cannot silently share authoritative state.
- The complete Stop Place catalog is staged with source identity and resumable progress. The source contained 57,964 rows during the 2026-07-10 live import. Entur accepted 5,000-row probes, but complete local bootstrap exposed the PHP 128 MB ceiling; the proven default is therefore 1,000-row source/write chunks, with 5,000 retained only as an operator-configurable maximum.
- The public station map performs server-side projection/aggregation and probes one item past its 2,000-item budget. Live Norway zoom 4 returned 31 clusters representing all 57,822 in-bounds stations; a synthetic 58,500-row regression is part of the ordinary suite.
- Ordinary clusters/stations render below provider symbols, selected transport remains above, cluster counts are compact, and transparent 36 px hit targets preserve clickability. Dense viewports stay aggregated through zoom 8; zoom 9+ exposes individual markers only when at most 300 stations are present.
- A selected station is carried as its own authoritative overlay feature and projected pin even when the viewport response contains only clusters. A Norway/Europe overview selection centres immediately at local zoom 11 before details finish loading; once already local, visible selections preserve the exact camera, off-screen station and vehicle selections never zoom out, and same-resource realtime refreshes do not recenter the map.
- Search normalizes Norwegian characters and diacritics, supports prefix matches such as `Fo`, and permits one bounded adjacent transposition/edit such as `Frode` for `Førde` without turning unrelated text into results.
- A shared reactive clock owns all relative ages. Vehicle mode, previous stop, delay, source state, locality, admin identity, clocks, and nullable measurements are derived from authoritative values rather than display literals; raw bearing is no longer presented as primary rider context.
- Public welcome, loading, empty-state, and vehicle-follow copy describes rider outcomes—finding stations, seeing departures, and following routes—rather than presenting clustering, request scope, cache strategy, or scheduler priority as product benefits.
- Station detail now distinguishes currently reporting vehicles matched by dated service journey to calls within six hours before/after refresh from unrelated vehicles inside the exact 5 km radius. Starting/approaching/at, unknown-progress, and passed relations are grouped separately; authoritative mode, call time, ±6-hour coverage, at-most-200 queried journeys, and provider-neutral truncation are explicit. Far-away matches remain selectable, duplicates are suppressed, and no result claims exhaustive national coverage.
- Station snapshot semantic hashes exclude refresh-only vehicle versions, so unchanged Entur observations no longer manufacture database events. Identical-content saves still advance canonical refresh/success/coverage metadata through a no-event repository update, and capped Entur candidate counts are documented as observed lower bounds rather than exact totals.
- During a Journey Planner outage, fresh nearby Vehicle Positions observations now refresh overlapping saved station-serving rows without changing their cached relation/call metadata; lost observations remove those rows, fresh nearby records win persistence deduplication, and the warning explicitly distinguishes refreshed positions from saved matches.
- Vehicle detail identifies the upstream-reported bus/ferry/train/etc. mode, replaces compass Direction with the previous authoritative journey call when available, labels the stale retry action `Refresh position` while preserving the existing bounded watch refresh, and centres the Journey progress rail through both ordinary and enlarged current-stop circles.
- Vehicle reporting gaps stay live through 30 seconds and stale through five minutes before becoming position unavailable. Successful nationwide responses that temporarily omit the selected vehicle use the same age policy instead of declaring immediate loss; focus remains active and recovers automatically when Entur publishes a newer observation. The public copy no longer makes the false `left the watched area` claim, and stable repeated degraded-journey refreshes no longer manufacture repeated lost events.
- Rider-facing previous, next, and upcoming stop output skips cancelled calls while the complete ordered journey retains them for authoritative Entur order and route-progress indices.
- Backend-authored passenger-service state is independent from position freshness. A non-passenger movement keeps its live marker, trail, selection, Last seen, and Focus watch while operational line, route/destination, delay, stops, stale-schedule wording, and raw Entur diagnostics are suppressed. Dedicated desktop/mobile scenarios cover this behavior in Norwegian and English without horizontal overflow.
- Long-running realtime database commands recover from an expired SurrealDB app-user token by creating a fresh authenticated connection, swapping it atomically, and retrying the interrupted query exactly once. The dedicated live-query connection retains its independent reconnect supervisor.
- Entur station refreshes isolate Journey Planner and Vehicle Positions failures. A failed source retains its cached values while the independently successful source still updates; the station snapshot remains visible as stale/rate-limited, the watch enters at least 15 seconds of `source_unavailable` backoff after the failed attempt completes, and `lastSuccessfulAt` remains authoritative. Active watches retry automatically, obey shared budgets, and clear the error after the upstream returns; 429 responses continue to honor `Retry-After`.
- Focus refreshes every three seconds rather than saturating the 30/minute Vehicle Positions ceiling, preserving normal operating headroom while remaining faster than selected-vehicle watches.
- Normal frontend routes no longer import or substitute transport fixtures. `scripts/audit-production-truth.mjs` scans production-reachable source and the built bundle; demo mode has a prominent `Demo data` badge and real mode carries neutral `Transport data: Entur` attribution separate from health.
- `docs/audits/production-truthfulness.md` records why earlier mechanical readiness gates were not sufficient and lists every corrected production-reachable defect.

### Real Entur probes

Backend-only requests with `ET-Client-Name: martinkavik-fjordpulse` passed against:

- Geocoder v3 autocomplete;
- Journey Planner v3 departure data;
- Stop Place Register v1 read data;
- Vehicle Positions v2 bounded HTTP GraphQL queries;
- a current Vehicle Positions record joined through its service-journey identity to non-empty Journey Planner geometry and ordered calls;
- the Vehicle Positions subscription endpoint as a capability spike.

Fresh `make smoke-entur` passed 1 external integration test with 23 assertions across all four production adapter surfaces, including a passenger-only live vehicle-to-journey join that excludes operational/dead-run records. Production browser code has no Entur or SurrealDB access path.

## Final completion gates

The complete affected admin-observability verification sequence passed on 2026-07-13. Exact lockfile installation evidence remains valid from 2026-07-11 because this delta changed no dependencies.

| Gate | Current evidence |
|---|---|
| `make verify-planning` | Passed fresh on 2026-07-13: 25 design PNGs, 25 design notes, 108 stories, zero source-corpus ZIPs. |
| `make install` | Passed from exact Composer/npm lockfiles and installed the project-managed Chromium. |
| `make typecheck` | Passed fresh on 2026-07-13. |
| `make phpstan` | Passed fresh at maximum level on 2026-07-13. |
| `make test` | Passed fresh on 2026-07-13: contracts, PHPUnit 159 tests/1207 assertions with one intentional external-Entur skip, and all 10 Vitest files/131 tests. |
| `make e2e` | Passed fresh on 2026-07-13: all 15 deterministic fixture tests and all 14 clean-stack SurrealDB/CakePHP/AMPHP/Vite/provider/selection/lifecycle/camera-URL/resilience tests. Headless commands unset `DISPLAY` to retain reliable surfaceless SwiftShader WebGL. |
| `make visual` | Passed fresh on 2026-07-13: the complete 58-baseline Norwegian/English matrix, including both changed admin-status baselines. |
| `make build` | Passed fresh on 2026-07-13, including the production truth audit and infrastructure validation. |

## Final aggregate gate record

The 2026-07-13 affected release handoff ran:

```bash
make verify-planning
make typecheck
make phpstan
make test
make e2e
git diff --check
```

All commands above passed on 2026-07-13. The unchanged lockfiles retain the previously verified `make install` evidence. `git diff --check` also passed after the complete typecheck, unit, fixture-browser, live-browser, visual, and production-build sequence.

## Deployment-only work

Local readiness does not mean FjordPulse has been deployed. The following remain intentionally outside this implementation run:

- provisioning a Hetzner CX33;
- installing or configuring Coolify;
- changing `fjordpulse.kavik.cz` DNS;
- creating or loading production credentials/secrets;
- configuring production backup/restore, TLS, monitoring, or rollback policy;
- running production smoke tests or rollout.

Repository `.env.example` files and Compose/Caddy artifacts contain development placeholders only and are not production secret material.
