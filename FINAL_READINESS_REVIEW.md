# FjordPulse final readiness review

## Verdict

FjordPulse is implemented and signed off as a complete local application across the frontend, CakePHP HTTP/control plane, AMPHP realtime service, SurrealDB persistence/live-query path, fake and real Entur adapters, vehicle journeys, admin diagnostics, local orchestration, contracts, and tests. The complete Admin-metric, mobile-navigation, workflow-runtime, documentation, deterministic-clock, and Norwegian/English verification sequence passed on 2026-07-15.

The production demo is live at `https://fjordpulse.kavik.cz`. Host, DNS,
Coolify/Traefik, exact-SHA CI, real-data application rollout, encrypted
same-host backup/isolated restore and live browser/API/WSS/Admin acceptance all
passed on 2026-07-15. The accepted backup cannot survive loss or compromise of
the entire VPS or disk.

## Delivered architecture

```text
Browser
  SolidJS + TypeScript + MapLibre
  same-origin application HTTP and signed WebSocket
  approved MapTiler style/tile requests only
  |
  v
Coolify-managed Traefik
  public TLS and host routing only
  |
  v
embedded Caddy / FrankenPHP normal mode
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
        schema migrations, attempt audit, and app user
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

## Read-only Database experiment

The application includes one protected Database destination with
URL-backed Current schema and Migrations tabs. CakePHP maps one fixed,
backend-owned, allowlisted SurrealDB structure query into typed table, field,
index, event, and permission DTOs; raw database INFO, users,
password hashes, authentication definitions, and credentials are discarded.
The migration report compares bundled release files with the complete ledger
and attempt audit, distinguishing applied, pending, checksum-mismatch,
orphaned, and failed rows and exposing bounded read-only source/metadata.

This does not embed a database console. The browser has no SurrealDB connection,
query/path input, schema editor, Apply, Retry, or Rollback control. Only the
deployment CLI migration runner writes ledger and attempt-audit records;
Admin reads them. Free-form record/query work remains a standalone Surrealist
operator task through a private connection.

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
| MapLibre source | MapTiler Hybrid v4 is the default satellite basemap and Streets v4 is the persistent alternative. Guarded cartography phases towns from zoom 6, villages from zoom 8, and dense local places from zoom 10 on both styles; it is reapplied after switching and keeps count-scaled ordinary clusters/stations below provider symbols while selected transport remains prominent. Named `#map=zoom/latitude/longitude` camera state is shareable and reload-safe. The introductory panel is desktop-open/mobile-map-first by default, collapses to an `About` control, and preserves an explicit choice. |

## Contract and product coverage

- Canonical OpenAPI 3.1: 25 operations, including public read-only-demo credential discovery, two canonical read-only Database diagnostics, and the deprecated migrations alias.
- Realtime protocol: 9 client commands and 23 server message types.
- Traceability: all 108 stories accounted for, including 22 non-wire stories.
- Black-box inventory: 340 scenarios.
- Deterministic UI inventory: 27 approved desktop, mobile, admin, and design-system routes, including the coded Database scenario.
- Localization: Norwegian Bokmål (`nb`) is the deterministic default; an accessible `NO`/`EN` switcher updates public/admin/scenario UI and `<html lang>` immediately, stores only the explicit locale preference, and falls back safely when storage is missing, invalid, or blocked. The current visual inventory covers all 27 routes in both locales, eight secondary station-tab states, six mobile-admin hierarchy/resource/drawer states, and six expanded Database states (74 comparisons), with responsive localized-label overflow checks.
- Fake source states: normal, empty, stale, error, live, lost, backoff, fallback, and reconnect modes behind final adapter interfaces.
- Search: station/vehicle normalized indexes, Norwegian `ø/æ/å` folding, prefixes, and bounded typo tolerance across local and Geocoder-backed results.
- Truth boundary: fixture modules load only behind development/test scenario routing; the production build audit rejects fixture imports, known fixture sentinels, and literal relative ages.
- Admin: authenticated focused System status, distinct Infrastructure, watches, Entur log, realtime rooms/bridge, persisted events, and a read-only Database inspector. The login keeps public-map navigation visible and can fill a separate public demo identity when explicitly enabled; private deployments leave it off, while this production demo deliberately enables the separate public identity. It never exposes the operator password, and middleware limits demo sessions to an explicit diagnostic-GET allowlist plus logout. The sidebar keeps the public-demo/read-only role visible. System status owns overall/service health and live demand; Infrastructure owns deployment/data-mode/build identity, timestamped CPU/load, scoped memory and application-filesystem status, sanitized SurrealDB target, map configuration, catalog/import, and canonical counts; Entur log owns the internal rolling allowance; Persisted events owns raw evidence; Database owns allowlisted effective schema and release/migration compatibility. Metrics without a real data source are omitted; connection/watch counts are not misrepresented as unique visitors; database credentials/raw INFO and mutation controls are absent; desktop and mobile navigation keep the signed-in identity and explicit exit-icon `Log out` action reachable and visually distinct.

## Current verification record

The complete local verification record passed on 2026-07-15:

- Planning verification passed with 25 design PNGs, 27 design notes, 108 stories, and 340 black-box scenarios.
- TypeScript typecheck passed.
- PHPStan maximum level passed with no errors.
- Contract lint/fixtures passed: 32 valid/16 rejected invalid realtime and 12 valid/12 rejected invalid HTTP fixtures.
- PHPUnit passed 354 tests and 2,218 assertions with one intentionally skipped external smoke in the ordinary offline suite.
- Vitest passed 172/172 tests across 13 files, including Norwegian-default locale selection, reactive switching, persistence/fallback, document-language behavior, truthful rolling-window Admin metrics, distinct Entur rate-limit filtering, mobile public Admin navigation, localized label containment, public read-only demo access, and bilingual read-only Database behavior.
- HTTP black-box/OpenAPI validation includes station-to-vehicle-to-journey route/calls/upcoming stops, the exact 5 km radial nearby-vehicle search and its response metadata, tolerant search, provider configuration/failure behavior, and complete bounded projection of a synthetic 58,500-station catalog.
- Production frontend build and production-fixture/truth audit passed.
- Clean-stack Playwright passed all 17 tests using real SurrealDB migrations, CakePHP HTTP, `bin/cake realtime start`, API-mode Vite, canonical database-to-WebSocket events, atomically allocated monotonic station versions, non-zero persisted Admin message activity during a watched vehicle, map/provider boundaries, protected Admin/Database routes, and actual realtime plus complete-backend outage/recovery lifecycles.
- Fixture Playwright passed 20/20 tests, including locale switching and reload persistence, localized public/mobile/Admin accessibility, mobile Admin reachability at 320 px and 390 px, detail-sheet interaction, focus lifecycle checks, truthful empty/error/update states, and label containment.
- Visual Playwright matched all 74 Norwegian/English desktop/mobile/admin/design-system, secondary station-tab, mobile-admin, and expanded Database baselines against the canonical fixture clock after each public map reached its ready state.
- Infrastructure validation confirmed ordered complete-catalog bootstrap, private database networking, non-published Entur egress for importer/realtime workers, and exactly one realtime replica.
- Live `make smoke-entur` passed 1 test with 23 assertions, including a passenger-service current vehicle resolved to non-empty route geometry and calls without selecting an operational/dead-run record.
- Long-running command authentication recovery passed exact unit regressions plus the real SurrealDB live-query and realtime WebSocket integrations: an expired app-user token creates a fresh authenticated HTTP connection and retries once without hiding unrelated authorization failures.
- Entur outage recovery passed controlled process-boundary and partial-source tests: the scheduler retains authoritative data during at least 15 seconds of backoff after a failed attempt completes, independently accepts the healthy station source, discards a failed Amp connection pool without an immediate duplicate request, and creates a fresh pool on the next scheduled attempt. Companion timing and budget tests prevent retry hammering, including after two sequential upstream timeouts.

The clean-stack browser proof verifies visible station data and station/vehicle/focus updates through the final database-driven path, completed and paused nearby-vehicle zero-result states, backend empty/stale/error/lost/fallback/reconnect scenarios, protected watch/realtime/event diagnostics, satellite default and Streets switching, real pan/zoom tile-coordinate changes, overview-to-local station selection before detail completion, shareable camera restoration before the first viewport request, malformed-camera fallback, exact high-zoom preservation on visible station selection, a named selection pin that survives clustered projections and layer changes, planned-route overview and upcoming stops, truthful resource freshness plus contextual update health, lazy WebSocket creation, persistent selection through actual realtime-only and complete backend outages, same-document HTTP/WebSocket recovery with watch resubscription, rendered-overlay survival, explicit provider retry/error behavior, network boundaries, and process cleanup.

The readiness review was deliberately reopened after a truthfulness audit found that the earlier suites proved mechanics but not complete production semantics. The corrected implementation separates source/fetch timestamps, removes normal-route fixture substitution, labels demo provenance, makes relative ages reactive, joins vehicles to full journeys, loads the complete provenance-checked station catalog, and distinguishes loading/error/empty UI states. The defect record and enforcement live in `docs/audits/production-truthfulness.md`.

The 57,964-row persistent catalog exposed a second readiness gap: the Norway map endpoint hydrated every station into a 128 MB PHP process and could return a fatal HTML page with HTTP 200. Bounds-aware SurrealDB projection/adaptive aggregation now keeps responses complete and below 2,000 items; `make dev` rejects non-JSON health/map responses and does not print ready until the realtime bridge, aggregate health, and station map all satisfy their schemas.

The real map review exposed a third usability gap: opaque cluster bubbles were appended above MapTiler symbols, hiding the town names needed to interpret them, and zoom 8 could jump to hundreds of overlapping stations. Ordinary context now sits below provider labels, compact translucent rings retain 36 px click targets, and individual markers require zoom 9+ with no more than 300 stations in view.

An overnight local run exposed a fourth operational gap: the SurrealDB SDK's expired app-user token caused realtime status and watch writes to return HTTP 401 even though the dedicated live-query bridge remained connected. Long-running command queries now replace the stale authenticated connection and retry once; the live-query connection continues to use its separate supervised reconnect path.

Explicit outage testing exposed a fifth resilience gap in the evidence: realtime-only fallback had been tested, but a complete FjordPulse backend outage and a physically stopped Entur upstream had not. The clean-stack browser test now stops and restores CakePHP HTTP plus realtime while preserving the same page, map, selection, and overlays; the backend boundary test stops and restarts Entur on the same port and proves cached-data preservation, bounded retry, and automatic recovery without restarting PHP.

Station collection also isolates Journey Planner from Vehicle Positions. A failure in either adapter retains that source's authoritative cached values, still accepts a successful result from the other adapter, publishes an explicit stale/rate-limited snapshot instead of replacing the station panel with a global error, and marks the active watch for bounded automatic retry measured from failure completion. A full failure with no previously successful snapshot remains an honest error. Frontend health, polling, and realtime paths combine the same station source state with the server health baseline, so neither a periodic healthy tick nor cached data can mislabel a visible Entur error as healthy.

The release gate sequence—planning verification, typecheck, maximum-level
PHPStan, contracts/PHPUnit/Vitest, encrypted backup/restore, fixture and
clean-stack E2E, all 74 visual comparisons, production build/truth audit,
Node 24 workflow validation, infrastructure validation, and diff hygiene—passed
on 2026-07-15. `PROGRESS.md` contains the exact counts and deployment boundary.

## Production deployment status

The accepted implementation and screenshot release is
`bf23cc80895da35df1fb9ff0aeee862efc29c8fe`. GitHub Actions [quality run
`29428606472`](https://github.com/MartinKavik/FjordPulse/actions/runs/29428606472)
passed the complete quality and production-image jobs; [deployment run
`29429291299`](https://github.com/MartinKavik/FjordPulse/actions/runs/29429291299)
then passed the blocking backup, immutable Coolify rollout, and public
exact-version check. Both runs have zero annotations, and the executed
GitHub-owned actions produced no Node 20 deprecation warning; all workflow
action majors, including the failure-only artifact uploader, are pinned and
validated for the Node 24 runner transition.

During acceptance, production reported healthy normal/real mode at that exact
version. The realtime server, SurrealDB, live-query bridge, and the most recent
Entur request evidence were healthy. Entur is demand-driven and truthfully
returns `unknown` after five minutes without a request; that idle state does
not make the aggregate service unhealthy. The migration diagnostic was
`in_sync` with all 12 release migrations applied and no pending, mismatched,
orphaned, or failed row. Fresh production captures also verify the Ålesund
Line 1 Focus view, rolling WebSocket activity, active room/client diagnostics,
host resources, Førde departures, and the default Norwegian mobile Admin
destination.

For historical context, the first accepted live application release was
`31a4ec2036a1af897b57e668b3c9406e601a49d9`; GitHub Actions run
`29383862850` passed the full quality and production-image jobs before Coolify
reported that exact commit finished. That initial release ran with 57,963
catalog records, all eleven migrations then present, exactly one realtime
replica, no fixture route, and no public SurrealDB listener.

Public IPv4/IPv6 readiness, HTTP-to-HTTPS redirect, TLS, satellite/Streets
MapTiler rendering, tolerant search, station reads, signed WSS commands and the
read-only demo Admin flow passed. Browser traffic was limited to FjordPulse and
MapTiler. Realtime, HTTP and SurrealDB restart drills recovered the live-query
bridge and catalog; an already-open selected-station page replaced its socket,
resubscribed and received a new watch acknowledgement without reload.

The database-scoped viewer passed a fail-closed transactional proof: it could
read a station, its identity update returned zero rows, before/after state was
identical and the transaction was always aborted. The first encrypted logical
backup passed a full Restic data check and restored into a separate non-public
SurrealDB with identical critical counts, migration checksums and deterministic
station sample. Coolify now owns both the 03:15 UTC daily task and the blocking
exact-SHA pre-deployment backup hook; the first automatic scheduled execution
recorded success, and repository initialization is permanently off.

The remaining explicit operator convenience is opening standalone Surrealist
through the already-proven SSH/viewer profile. This is not a public app or data
durability blocker. No checked-in development credential was reused, and all
production credentials affected during provisioning were rotated before final
acceptance.
