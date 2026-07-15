# FjordPulse implementation progress

Last updated: 2026-07-15

FjordPulse is a feature-complete application with a live production demo, not an implementation skeleton. The Norwegian/English localization baseline passed on 2026-07-12, the admin-observability and read-only Database sequence passed on 2026-07-13, the complete production-preparation sequence passed locally on 2026-07-14, and live production acceptance passed on 2026-07-15. The accepted same-host demo-backup limitation remains explicit below.

## 2026-07-15 truthful metrics, mobile Admin, and release presentation

- Replaced the misleading process-lifetime `Messages / min` average with exact
  rolling-60-second counters for WebSocket messages received from browsers and
  messages successfully delivered to browsers. Zero-recipient broadcasts no
  longer advance the last-delivered timestamp.
- Audited the remaining Admin figures and labels against their actual sources.
  Browser sockets are explicitly not people, active totals exclude disconnect
  grace, Watch records retain and label that grace, Entur outbound calls exclude
  cache/budget/backoff skips, p95 uses actual outbound attempts, and stale retry
  deadlines no longer leave Backoff stuck active. `docs/ADMIN_MEASUREMENTS.md`
  records every source, window, reset boundary, and intentionally absent metric.
- Database-side diagnostic sampling now prevents high cache volume from hiding
  actual outbound Entur calls. A stale realtime heartbeat clears process-local
  clients, rooms, counters, and delivery time instead of presenting the stopped
  process's final values as live measurements.
- Added a labelled Admin destination to the public bottom navigation at mobile
  widths while retaining the existing desktop header control. Norwegian and
  English controls fit at 320 px and 390 px without horizontal overflow.
- Moved all GitHub-owned workflow actions to reviewed Node 24 runtime majors:
  `actions/checkout@v6`, `actions/setup-node@v6`, and
  `actions/upload-artifact@v7`. Repository validation now rejects an unreviewed
  action or an older major in either quality or deployment workflow.
- Reworked the README around real production imagery and diagrams. Its hero is
  a point-in-time production capture of a reporting Line 1 bus in Ålesund on
  satellite imagery with its planned path and journey progress; the gallery
  also includes Førde and read-only Admin/resource views. Added an explicit
  browser, Linux host, pinned-library, external-service, and production-capacity
  compatibility reference.
- A calendar-date test failure exposed wall-clock-dependent departure fixtures.
  HTTP and realtime test profiles now share a strict test-only injected clock.
  A second clean-stack failure exposed equal/sub-millisecond station versions;
  both refresh paths now use a database-side base-version cohort: concurrent
  same-base semantic writers receive strictly increasing millisecond versions,
  while a delayed older cohort cannot overwrite newer state. This preserves
  SurrealDB's canonical live-query ordering without weakening stale-write guards.
- The exact final local sequence passed: planning 25/27/108/340; typecheck;
  maximum-level PHPStan; 32 valid/16 invalid realtime and 12 valid/12 invalid
  HTTP fixtures; 354 PHPUnit tests with 2,218 assertions and one intentional
  external-Entur skip; 172 Vitest tests; 20 fixture and 17 clean-stack browser
  tests; 74 bilingual visual comparisons; encrypted backup/restore; production
  build/truth audit; infrastructure/workflow and production-screenshot evidence
  validation; and diff hygiene.

## 2026-07-15 accepted implementation rollout

- [Quality run
  `29428606472`](https://github.com/MartinKavik/FjordPulse/actions/runs/29428606472)
  passed exact commit `bf23cc80895da35df1fb9ff0aeee862efc29c8fe`:
  both the complete quality job and the production application/backup image job
  are green. The reviewed Node 24 action majors produced zero annotations and
  no Node 20 deprecation warning.
- [Deployment run
  `29429291299`](https://github.com/MartinKavik/FjordPulse/actions/runs/29429291299)
  passed the blocking backup, immutable Coolify release, and public exact-SHA
  readiness check. During acceptance, production reported healthy normal/real
  mode at that exact commit; realtime, SurrealDB, the live-query bridge, and
  the most recent Entur request evidence were healthy. Entur is demand-driven
  and truthfully returns `unknown` after five idle minutes without making the
  aggregate service unhealthy.
- The read-only production migration diagnostic reports 12 applied migrations,
  zero pending, checksum-mismatched, orphaned, or failed migrations, and an
  `in_sync` release state. Migration 012 is therefore deployed, not merely
  present in the repository.
- A repeatable Playwright production capture followed a reporting Ålesund Line
  1 bus while separately confirming its browser socket, Focus room, and rolling
  WebSocket count through Admin. The resulting satellite, Førde, status,
  realtime, infrastructure, and default-Norwegian mobile images are stored in
  `docs/screenshots/`; every image records its exact build and viewport.

## 2026-07-15 live production-host deployment

- The first accepted live application release is exact commit
  `31a4ec2036a1af897b57e668b3c9406e601a49d9`. GitHub Actions run
  `29383862850` passed both production images and their offline runtime/tool
  smokes plus planning, typecheck, maximum-level PHPStan, 337 PHP tests with
  2,133 assertions and one intentional external-Entur skip, 168 frontend
  tests, encrypted backup/restore smoke, production build, all browser
  black-box scenarios and all 74 visual states.
- The Sharptech host now runs the official pinned Docker packages: Engine/CLI
  29.6.1, containerd 2.2.6, Buildx 0.35.0 and Compose 5.3.1. A persistent IPv4
  and IPv6 `DOCKER-USER` policy survived UFW and Docker restarts. Independent
  probes proved public 80/443 and blocked 8000/6001/6002/8080/18080 after the
  owner claim; key-only SSH remained available.
- Coolify 4.1.2 was installed from release commit `e7dff30` after verifying the
  installer SHA-256. Its services and Traefik edge proxy are healthy, automatic
  Coolify updates are disabled, and
  `https://coolify.fjordpulse.kavik.cz` has a valid Let's Encrypt certificate.
  The first owner is claimed, public registration is disabled, and the direct
  bootstrap ports are closed. Optional external notification channels remain
  deliberately disabled for this low-value demo rather than creating another
  account; application/Admin health checks remain rollout gates.
- Netlify DNS now resolves both IPv4 and IPv6 for `fjordpulse.kavik.cz` and the
  dedicated `coolify.fjordpulse.kavik.cz` control plane. The unrelated legacy
  `coolify.kavik.cz` record was intentionally left unchanged.
- The first no-container deployment attempt correctly selected the green SHA
  and read-only repository key, then failed before build because Coolify 4.1.2
  resolves Compose contexts from its explicit repository-root project
  directory. The production profile now accepts
  `FJORDPULSE_BUILD_CONTEXT=.` while preserving `..` for direct local Compose;
  static validation covers all six build services. The exposed read-only key
  was revoked and rotated before retrying.
- Coolify completed a clean exact-`31a4ec2` deployment: the non-root SurrealDB
  volume initializer, all eleven migrations, the complete 57,963-record real
  Entur import, exactly one realtime worker and the application startup all
  passed. Coolify's supported service-domain port selector routes public HTTPS
  through Traefik to app container port 8080 without publishing a host port.
  Embedded Caddy belongs to FrankenPHP inside that app container; it is not a
  second public host proxy.
- Public acceptance then found that the embedded FrankenPHP/Caddy site address
  treated production's `0.0.0.0` bind address as a Host matcher. That allowed
  an empty HTTP 200 to satisfy the former reachability-only healthcheck. The
  release fix separates the listener bind from virtual-host matching, requires
  a typed JSON readiness envelope with status and version, and adds an actual
  arbitrary/proxied-Host black-box regression. Coolify deployment
  `i6d80moggwlkpfd0q1auahjy` finished at that exact green commit, and a later
  Coolify-owned configuration redeploy preserved the same version and data.
- Public IPv4 and IPv6 readiness return non-empty healthy production/real JSON
  with the exact build. HTTP redirects to HTTPS, the Let's Encrypt certificate
  covers `fjordpulse.kavik.cz`, fixture routes return 404, and host/public-port
  probes expose only the intended web edge plus restricted SSH.
- Production browser acceptance passed with a real WebGL MapLibre canvas,
  labelled Hybrid satellite first, Streets switching, persistent selected
  station context, tolerant `Forde` -> `Førde rutebilstasjon` search and signed
  WSS token/watch/ping/snapshot/unwatch traffic. Browser destinations were
  limited to FjordPulse and MapTiler; there was no direct browser Entur or
  SurrealDB request.
- The public demo Admin flow passed with a Secure, HttpOnly, SameSite=Strict
  session. Status, realtime, events, schema and eleven-migration compatibility
  diagnostics were readable; three mutation probes were rejected; logout
  invalidated the session. The database report contained 13 allowlisted tables
  with no pending, mismatched, orphaned or failed migration.
- The separate database operator is defined with SurrealDB's built-in `VIEWER`
  role. A production proof selected an existing station, attempted an identity
  update inside an always-aborted transaction, required zero mutation rows and
  byte-identical before/after state, then independently selected the row again.
  The sanitized result confirmed `unchanged: true` and `rolledBack: true`.
- The encrypted same-host Restic repository was initialized only once. A
  checksummed logical backup and `restic check --read-data` passed; restoring
  its snapshot into a fresh, non-public SurrealDB container reproduced critical
  table counts, the migration ledger and a deterministic 32-station sample
  byte-for-byte. The isolated container and volume were removed afterward.
- `RESTIC_INITIALIZE_REPOSITORY=false` is now authoritative in Coolify. The
  Coolify-owned daily 03:15 UTC task is enabled. Both its initial operator-run
  and its first automatic 03:15 execution recorded `success`; the latter added
  a verified `scheduled` snapshot. An exact-SHA pre-deployment backup hook
  blocks subsequent releases on backup failure.
- Realtime, the HTTP app and SurrealDB were restarted independently. Container
  health, public readiness, the 57,963-record catalog, Entur demand and the
  live-query bridge recovered without repair. A separate open-browser drill
  kept the selected station and map, opened a replacement signed WSS connection,
  resent its station watch, received a new acknowledgement and returned to
  healthy without reload.
- The final host-capacity check found 4 CPU cores at 0.35 one-minute load,
  1,514 MiB memory used with 6,426 MiB available, zero swap use, and 83,856 MiB
  disk available (15% used). The provider's nearly 8 GiB figure is installed
  capacity, not current consumption.
- GitHub now holds only the Coolify URL, application UUID and a dedicated
  non-expiring team token scoped to `read`, `write` and `deploy`. Coolify's own
  automatic Git deployment remains off; the serialized `workflow_run` path
  accepts only a still-current green `main` SHA, creates an immutable release
  branch, runs the blocking pre-release backup and verifies exact public
  readiness.
- Production-only secrets and the earlier exposed read-only repository key were
  rotated before final acceptance, followed by a clean-volume redeploy where
  required. Image/public-response audits found no private secret material.
- At the user's direction, production uses no S3 account. The encrypted logical
  backup repository is a separate named volume on the same VPS with short
  retention. This accepted demo-only choice protects against
  application/database mistakes but not total host, disk, provider or
  host-compromise loss.

## 2026-07-14 production deployment Gate 0 preparation (historical checkpoint)

- The actual production candidate is the provisioned Sharptech Medium VPS
  `fjordpulse-01` at `185.248.146.194`: Ubuntu 24.04.4 LTS, x86_64, 4 vCPU,
  8 GiB marketed RAM and 100 GB NVMe. SSH with the 1Password-managed key works.
  Root SSH is now public-key-only with password login proven rejected, local
  forwarding retained for the future database tunnel, Fail2ban and UFW active,
  and a persistent 2 GiB swap file configured with conservative swappiness.
  At this checkpoint Docker/Coolify and the Docker-aware forwarding boundary
  were not installed and no DNS change had run. The live 2026-07-15 section
  above supersedes that state.
- Added a Coolify-specific Compose candidate with no custom network or public
  app/database host port, exactly one realtime replica, a stable RocksDB volume,
  loopback-only SurrealDB `127.0.0.1:18000`, one-shot migration/import ordering,
  and separate readiness/health boundaries. Focused topology validation and an
  isolated pinned SurrealDB 3.2.0 RocksDB persistence smoke passed.
- Added typed bootstrap for a distinct database-scoped `VIEWER`, trusted-proxy
  client-address resolution that rejects untrusted forwarded headers, and
  focused unit/integration coverage. Production still needs the actual Coolify
  proxy CIDR plus a live Surrealist read/write-denial proof through SSH.
- Production configuration now rejects non-canonical environment names,
  debug/HTTP origins, URL credentials or suffixes, universal proxy trust, and
  weak application/Admin secrets. HTTP abuse budgets use a lock-protected
  single-host store that survives separate FrankenPHP classic requests; a real
  61-request black-box sequence proves the next login is rejected. The v1
  boundary remains one HTTP app replica, with only the current one-minute
  window reset by a container replacement.
- Added pinned SurrealDB/Restic backup tooling for checksummed logical exports,
  non-overlapping retention, SHA-tagged pre-release snapshots and isolated
  restore verification. For this non-valuable demo, the Coolify profile now
  defaults to an encrypted named Restic volume on the same VPS and retains
  three daily, one weekly and three pre-release snapshots; no external storage
  account is required. This deliberately does not cover total VPS/disk loss.
  The local smoke proves short pre-release retention plus isolated restore
  against SurrealDB 3.2.0 and Restic 0.19.1; production initialization and a
  live drill remain open.
- Added a serialized GitHub `workflow_run` deployment workflow that accepts only
  a successful `quality` run for the still-current `main` SHA, creates an
  immutable per-SHA release branch, patches Coolify to it, and verifies the
  terminal reported commit plus public readiness version. The workflow also
  updates Coolify's build/runtime `APP_VERSION` row to that exact SHA before
  deployment so later releases cannot inherit a stale readiness identity. It
  remains inert without three Coolify secrets and has not executed against a
  live resource.
- ADR 0014 records the Sharptech/manual-Coolify, RocksDB, private operator,
  accepted same-host demo-backup limitation and CI gate decision. Gate 0.7
  disposable-host proof, the GitHub Actions run, Docker-aware host boundary,
  Coolify, production secrets,
  DNS, staging, live isolated restore and production smoke remain open; code
  presence is not deployment acceptance.
- The integrated local production-preparation gate passed on 2026-07-14:
  planning inventory 25 PNGs / 27 notes / 108 stories / 340 scenarios;
  maximum-level PHPStan; 337 PHPUnit tests with 2,128 assertions and the one
  intentional external Entur skip; 168 Vitest tests; 19 fixture plus 17
  clean-stack browser tests; 74 Norwegian/English visual baselines; production
  build/truth audit; infrastructure/workflow validation; encrypted backup and
  independent-endpoint restore; and diff hygiene. Live Entur, container image,
  external firewall, TLS/WSS, app-level restore and exact-SHA CI/deployment
  evidence are still required.
- The first GitHub run for deployment commit `4d66938` stopped during tool
  installation because upstream replaced the FrankenPHP 1.12.4 GNU release
  asset on 2026-07-14. The wrapper correctly rejected the changed bytes. Its
  pinned SHA-256 was refreshed from GitHub's published asset digest before the
  run was retried; this is a supply-chain fail-closed event, not an application
  test failure. The retry passed dependency installation, planning, typecheck,
  PHPStan, all 337 PHP tests and all 168 frontend tests, then exposed an
  IPv6-sensitive backup-smoke query: the servers bind `127.0.0.1`, while the
  runner can resolve `localhost` to `::1`. Guard setup still tests the alias,
  but the subsequent no-mutation inspection now uses the canonical IPv4
  endpoint and prints stage-specific diagnostics on failure. Those diagnostics
  then exposed a clean-cache Restic wrapper defect where the compressed archive
  was copied onto its own path; installation now decompresses to an atomic
  temporary binary, and `make install` verifies Restic before the test phase.

## 2026-07-14 station departures and vehicle scope redesign

- Station details now lead with a compact, de-duplicated departure preview from the current instant through the next `Europe/Oslo` midnight, capped at 20 rows with an explicit indication when more departures exist. An on-demand calendar-day view pages up to 50 rows at a time and groups earlier, next, and later departures without making a short preview look like the whole day.
- Calendar-day responses distinguish complete from incomplete source coverage. A retry can explicitly bypass the timetable cache, while an expired pagination cursor keeps already loaded rows visible and restarts safely from the first current page instead of retrying an unusable cursor.
- Station vehicle results use canonical `starts_here` / `calls_here` roles and `at_station` / `before_station` / `after_station` / `unknown` progress. The UI groups vehicles as at or due, later, already passed, or other nearby vehicles, avoiding a single misleading vehicle count beside the departure preview.
- Strict PHP and TypeScript validation now covers station departure boards and station-serving realtime payloads before version ledgers advance. OpenAPI, protocol fixtures, fake adapters, black-box/DST/cache/reconnect tests, deterministic browser coverage, testing guidance, and station user stories were updated with the behavior.

## 2026-07-14 mobile detail-sheet interaction

- Defined one consistent peek/half/full interaction for station and vehicle sheets. The grabber remains reachable below the top bar with a full touch target, supports drag, tap, Enter, and Space, and reveals more map without being mistaken for a close control.
- Snap-state changes preserve selection, watch/Focus state, station tab, and loaded content. Only the explicit X action closes the sheet and clears its selection/watch; the existing deterministic station and vehicle scenarios cover the behavior without increasing story or scenario counts.

## 2026-07-14 mobile-first search interaction

- Kept a full-size search input visible by default on phones and made both the header and bottom-navigation Search actions focus it, so the current query stays readable when the software keyboard opens. Long result lists now scroll inside the overlay without displacing the input.
- Split search into explicit waiting, loading, populated, and completed-empty phases. A two-character minimum and 700 ms trailing quiet-period debounce prevent per-letter loading/requests, while the existing Norwegian-character normalization keeps `Forde`, `Førde`, and `Fo` useful for finding correctly labelled `Førde` results.

## 2026-07-14 trusted-LAN mobile development mode

- Added explicit `make dev-mobile` startup for real-device testing without changing the safer loopback-only `make dev` default. It detects or accepts the PC LAN IPv4 address, exposes only Vite on TCP 5173, and prints the exact phone URL.
- The detected LAN origin is added at runtime to both CakePHP CORS and realtime WebSocket origin allowlists. An explicit Caddy bind keeps CakePHP, AMPHP realtime, and SurrealDB loopback-only behind Vite's `/api` and `/live` proxies; startup uses a strict frontend port so the printed URL cannot silently drift.
- Documented the trusted-network boundary, IP override, firewall/client-isolation checks, MapTiler origin restriction, stop command, and deterministic mobile scenario gallery.

## 2026-07-14 smooth Admin navigation and state layout

- Replaced full-document Admin link reloads with a small same-origin History API router. Direct links, copied URLs, hard refresh, browser Back/Forward, and ordinary modified/new-tab link behavior remain intact while Admin-to-Admin links keep one running SolidJS application and one authenticated shell.
- Split the one-time session check from page-data loading. The sidebar, connection state, identity, and Log out remain mounted while route data refreshes; the previous or cached page stays visible under an indeterminate top progress bar, and only the resolved content receives the restrained 150 ms entrance treatment.
- Page responses are request-generation guarded and aborted where possible, so a late response from an older route cannot repaint a newer destination. A small route cache makes revisited pages immediate while still revalidating them.
- Initial session loading/errors now use a centred dark state card with safe-area padding. Authenticated page failures stay inside the normal Admin content area with Retry and restored heading focus; retained stale content is inert until its replacement resolves. The document declares its dark canvas before JavaScript/CSS startup to prevent a white first-paint flash, and motion collapses to effectively zero under `prefers-reduced-motion`.
- Added focused router and Admin component coverage plus clean-stack browser proofs for zero extra document requests, persistent DOM/window sentinels across Database links and history, 320 px loading/error geometry, short-landscape clearance, desktop progress alignment, no horizontal overflow, reduced-motion timing, Retry recovery, and latest-route-wins behavior.

## 2026-07-13 read-only Database inspector experiment

- Added protected canonical `GET /api/admin/database/schema` and
  `GET /api/admin/database/migrations` contracts, with the former
  `/api/admin/migrations` path retained as a deprecated compatibility alias.
  Schema inspection maps one fixed, backend-owned, allowlisted SurrealDB
  structure query; raw INFO users, password hashes, authentication definitions,
  credentials, arbitrary queries, and arbitrary migration paths never reach the
  browser.
- Added URL-backed Current schema and Migrations tabs. The schema view filters
  and expands tables/fields/indexes/events/permissions. Migration
  compatibility distinguishes applied, pending, checksum mismatch, orphaned,
  and failed rows with both checksums, attempt/application times, descriptions,
  affected objects, bounded failure evidence, and bundled read-only source.
- The Database diagnostics remain GET-only. They have no query/schema editor
  or Apply, Retry, Edit, or Rollback control. Only the deployment CLI runner writes migration ledger
  and attempt-audit records; its outside-transaction attempt record preserves
  evidence of a rolled-back failure. Surrealist remains a separate operator
  tool reached through a private connection for record/query exploration.
- Authentication now follows CakePHP's matched canonical route instead of raw
  URL text, closing percent-encoded `/api/admin` bypasses across all protected
  diagnostics. Canonical and encoded routes are black-box tested as 401 without
  a session and 200 with a valid session.
- The planning inventory contains 25 paired design references plus two
  coded-only specifications and 27 deterministic routes. The complete current
  gate passed: 201 PHPUnit tests/1,543 assertions with one external skip, 139
  Vitest tests, 17 fixture and 15 clean-stack browser tests, 74 reviewed
  bilingual visuals, maximum-level PHPStan, production build/truth audit,
  infrastructure validation, and diff hygiene.

## 2026-07-13 admin status information architecture

- Rebuilt System status as a one-screen health-triage surface with an explicit overall state, four user-facing service paths, a single grouped Realtime delivery card, compact runtime context, and one neutral live-demand panel. Healthy prose no longer repeats across cards; demand-driven Entur inactivity says `IKKE BRUKT NYLIG` / `NOT RECENTLY USED` instead of looking like unfinished loading.
- Added a genuinely distinct `/admin/infrastructure` page for environment/build/data mode, sanitized SurrealDB target, MapTiler configuration boundary, CPU/RAM/disk, station-import provenance, and stored-data inventory. The internal Entur allowance moved to Entur request log, and routine event rows remain exclusively on Persisted events.
- Replaced the disappearing mobile admin sidebar with a labelled modal drawer containing all destinations, connection state, operator identity, and Log out. The background becomes inert, keyboard focus cannot escape, and the scrim or Escape closes it and restores focus to Menu.
- Added the bilingual `admin_infrastructure` fixture/visual route plus mobile Status, Infrastructure-resource, and drawer captures, expanding the reviewed visual inventory to 26 routes / 66 comparisons. Planning, contracts, strict checks, 159 backend tests/1207 assertions, 134 frontend tests, all 16 fixture and 14 clean-stack browser tests, all visuals, production build/truth audit, infrastructure validation, and diff hygiene passed on 2026-07-13.

## 2026-07-13 shared brand mark and favicon

- Extracted the existing cyan mountain paths from the inline header logo into one public `fjordpulse-mark.svg` asset. The public and admin header logo and the browser favicon now reference that same file, preventing brand-shape drift while preserving the existing visible mark.
- Focused verification passed 38 component tests, the Norwegian-default browser scenario with asset/content-type assertions, both Norwegian and English desktop-default visual baselines, strict TypeScript, the production build/truth audit, infrastructure validation, and diff hygiene.

## 2026-07-13 admin navigation cleanup

- Removed the duplicate `Overview` / `Oversikt` sidebar link that pointed to the same `/admin/status` route as `System status` / `Systemstatus`. The documented operator dashboard now has one canonical, active navigation destination instead of two labels for one page.
- Added component, routing, and browser regressions for a single `/admin/status` link, its localized accessible name, `aria-current="page"`, and compatible hidden `/admin` / `/admin/overview` resolution. The focused Chromium admin flow passed 1/1 and the refreshed Norwegian/English admin-status baselines passed 2/2; the final integrated 2026-07-13 gate record is listed below.

## 2026-07-13 admin observability truthfulness

- Active watch metrics now require a connected persisted client, a future lease, and a non-expired state. Disconnect-grace rows become expiring immediately, cannot be reactivated by an in-flight refresh, and restore correctly if the browser reconnects before TTL. Realtime startup prunes only past-expiry rows, so it does not erase another process's still-valid lease.
- Replaced the unexplained generic rate-budget value with a bilingual `FjordPulse → Entur` allowance that identifies the affected backend APIs, exact configuration settings, rolling 60-second semantics, request-log evidence, and provider documentation. HTTP and realtime callers reserve against one atomic SurrealDB ledger before transport, making the global and per-service limits shared across processes and including in-flight requests without pretending the values are an Entur-reported account quota.
- Realtime-server and live-query-bridge signals remain separate in the contract and detailed diagnostics but are grouped into one `Realtime delivery` overview card with independent Server and Database events state/latency checks. Persisted events owns all database-notification rows and raw evidence rather than duplicating routine pipeline traffic on System status.
- Added real-stack browser assertions for closed-tab cleanup, dedicated Persisted-events ownership, complete real dependency coverage, and bilingual desktop/mobile overflow safety.
- The completed 2026-07-13 affected gates passed planning, contracts, strict TypeScript, maximum-level PHPStan, 159 backend tests with 1207 assertions and one intentional external-Entur skip, all 10 frontend test files with 134 tests, all 16 fixture Playwright tests, and all 14 clean-stack Playwright tests.
- Headless Playwright commands now remove a stale desktop `DISPLAY` value before launching Chromium. This keeps MapLibre tests on Chromium's surfaceless SwiftShader/WebGL path when the host's Xwayland display is unavailable; it does not change the manually opened browser or `make dev`.

## Phase status

| Phase | Status | Evidence summary |
|---|---|---|
| 0 — consolidated inputs | Complete | The planning inventory defines 25 paired design references plus coded-only Infrastructure and Database specifications, 108 stories, and 340 black-box scenarios. |
| 1 — dependency spikes and runnable skeleton | Complete | Exact tool/dependency pins, CakePHP routes, FrankenPHP, AMPHP WebSockets, SurrealDB sync/async/live-query tests, and Entur probes exist and have run. |
| 2 — SolidJS visual prototype | Complete | The reviewed bilingual inventory covers 27 deterministic routes in both locales, plus dedicated desktop/mobile Vehicles/Details, mobile-admin, and expanded Database captures, for 74 comparisons. |
| 3 — contract-complete fake mode | Complete | The fake adapters use the production interfaces, repositories, SurrealDB events, live-query bridge, WebSocket protocol, and API-mode frontend. |
| 4 — CakePHP HTTP/control plane | Complete | Public, health/readiness, admin, development-scenario, validation, security, logging, and fallback endpoints are implemented and contract-tested. |
| 5 — AMPHP/Revolt realtime service | Complete | `bin/cake realtime start`, signed handshakes, rooms, watch/focus lifecycle, scheduler, health, isolation, and graceful shutdown are covered by tests. |
| 6 — SurrealDB canonical event path | Complete | Real integration tests prove commit -> `DEFINE EVENT` -> `realtime_event` -> one global `LIVE SELECT` -> room/WebSocket, including database restart recovery. |
| 7 — real stack with fake third parties | Complete | The clean-stack Playwright proof uses real SurrealDB, migrations, CakePHP HTTP, the realtime command, and Vite in `VITE_DATA_MODE=api`. |
| 8 — real Entur integration | Complete for local v1 | Backend-only typed adapters cover Stop Place Register, Geocoder, Journey Planner, and coalesced nationwide Vehicle Positions queries; a live smoke resolves a current vehicle into route geometry and ordered calls. |
| 9 — full local quality/configuration | Complete | Planning, static, contract, PHP/Vitest, fixture/clean-stack E2E, all 74 visuals, production image/runtime, build, infrastructure, workflow and diff gates are green for the accepted live release. |
| 10 — deployment | Complete for the accepted demo boundary | Coolify/Traefik serves the real-data app over IPv4/IPv6 HTTPS/WSS; exact-SHA CI, public/Admin/browser acceptance, private viewer denial, encrypted same-host backup and isolated restore, scheduled backup, service recovery and browser resubscription passed. Same-host backups deliberately do not cover loss of the VPS or disk. |

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
- Admin System status exposes overall/service health and truthful connected-client/watch demand; Infrastructure owns deployment identity, resource capacity, sanitized database/map configuration, catalog provenance, and canonical counts; Entur request log owns the internal allowance beside request evidence; Persisted events owns event rows and raw payloads; Database owns read-only allowlisted effective schema and release/migration compatibility. Metrics without a real data source are omitted, demand-driven Entur inactivity is neutral rather than a false degradation, anonymous visitor/session analytics are not fabricated from connection or watch counts, raw database credentials/INFO and mutation controls are absent, and desktop/mobile navigation keeps the signed-in identity and explicit exit-icon `Log out` action reachable.
- Root install/dev/dev-demo/stop/typecheck/phpstan/test/e2e/visual/build commands, exact lockfiles, real/demo-isolated local orchestration, JSON-shape startup readiness checks, Caddy/FrankenPHP configuration, and deployment-oriented Docker/Compose artifacts.

## Verified evidence

The 2026-07-12 verification established the full reactive Norwegian/English public, map, search, station, vehicle, admin, scenario, formatting, accessibility, and then-current 58-comparison visual baseline. The 2026-07-13 admin information-architecture and hierarchy work expanded that evidence to 66 comparisons and passed fresh planning, strict TypeScript, PHPStan, contracts, PHPUnit, Vitest, fixture Playwright, clean-stack Playwright, production build/truth audit, infrastructure validation, and diff hygiene.

### Exact dependency surface

- FrankenPHP `1.12.4` with embedded PHP `8.5.8` is checksum-pinned by the project wrapper.
- GitHub replaced the official FrankenPHP `v1.12.4` Linux asset on 2026-07-11 and again on 2026-07-12. CI correctly rejected each stale digest; both runtime wrappers pin the current asset's GitHub-published SHA-256, and the wrapper was verified from an empty tool cache. Fresh-download checksum failures print the failed file instead of ending with an opaque install error.
- CakePHP reports `6.0.0-dev` and is pinned to official `6.x` commit `39f5594eb9c79e3ec46aa786b617af0a622b72d3` because no CakePHP 6 tag existed for the spike.
- Composer `2.10.2`, SurrealDB server `3.2.0`, SurrealDB PHP SDK `2.0.0-alpha.1`, AMPHP/Revolt packages, Node `22.22.0`, frontend packages, PHPUnit `13.2.4`, and PHPStan `2.2.5` are exact-pinned with lockfiles.
- Installed SDK symbols were checked rather than inferred: `Surreal`, `Runtime::sync()`, `Runtime::amp()`, `ConnectOptions`, `DatabaseAuth`, `ExponentialBackoffReconnect`, and live-query feature support.
- ADR 0012 records the experimental dependency policy; ADR 0013 records bounded demand-driven Vehicle Positions HTTP queries for v1.

### Contracts and traceability

- OpenAPI 3.1 defines 25 HTTP operations, including typed same-origin map-provider configuration, public read-only-demo credential discovery, two canonical read-only Database diagnostics, and the deprecated migrations alias.
- Realtime schemas define 9 client commands and 23 server message types.
- `contracts/traceability.json` accounts for all 108 stories, including 22 explicitly non-wire stories.
- `docs/user-stories/00_manifest.json` records 340 black-box scenarios, and planning verification enforces that aggregate.
- Fresh contract evidence on 2026-07-14: OpenAPI lint plus 32 valid and 16 intentionally invalid realtime fixtures, and 12 valid and 12 intentionally invalid HTTP fixtures, all passed schema-and-fixture validation.

### PHP, persistence, HTTP, and realtime

- Fresh `make phpstan` on 2026-07-14: PHPStan maximum level completed with no errors across application and test code.
- Fresh backend PHPUnit on 2026-07-14 passed 248 tests with 1832 assertions; one explicit external Entur test was intentionally skipped by the ordinary offline suite. The repository test wrapper now allows up to ten minutes for this database-heavy process instead of Composer terminating a still-progressing suite at its five-minute default. The added Admin coverage proves production demo access is opt-in, public credentials cannot reuse operator/session/database secrets, encoded discovery/login routing is exact and rate-limited, existing demo cookies are revoked when access is disabled, only explicitly allowlisted diagnostics work, and future Admin reads or mutations are denied before their handlers run.
- HTTP black-box coverage validates responses against OpenAPI, including map-provider configuration, tolerant search, station-to-vehicle-to-journey resolution, non-empty route/calls/upcoming stops, explicit failure states, and a synthetic 58,500-station map whose complete totals remain bounded and stable without a PHP memory spike.
- The PHPUnit suite includes real SurrealDB migration/idempotency/checksum tests, typed repository and catalog-provenance tests, journey persistence and no-dual-event tests, semantic `DEFINE EVENT` tests, non-blocking `Runtime::amp()` live delivery, a real database restart/re-subscription test, WebSocket authorization/isolation/shutdown tests, and a canonical-write-to-WebSocket test. Exact expired-token regressions prove that a long-running command replaces its authenticated HTTP connection and retries the interrupted operation once, while unrelated 401 responses and replacement failures remain visible. Controlled Entur gates prove independent Journey Planner/Vehicle Positions results, cached snapshot preservation, watch backoff, and recovery after an upstream restart. Amp transport failures discard the failed process-lifetime connection pool without an immediate duplicate request; the next scheduler attempt creates a fresh pool, and the retry delay starts only after a slow failed attempt completes while shared budgets remain authoritative.

### Frontend and build

- Fresh `make typecheck` on 2026-07-14: strict TypeScript completed successfully.
- Fresh frontend Vitest on 2026-07-14 passed all 168 tests, including Norwegian-default locale selection, reactive switching, valid/invalid/blocked local-storage behavior, document-language synchronization, shared-clock advancement, Norwegian character folding/typo tolerance, compact-event journey advancement, strict cross-field journey and station-departure contracts, malformed-station-event ledger protection, backend-authored passenger-service classification, non-passenger panel/map/Focus presentation, passenger-to-operational-to-passenger Focus recovery without reselection, destination-neutral accessibility copy, cached-versus-unavailable journey wording, context-preserving selection, selected-station survival outside a clustered viewport catalog, label-safe transport overlay ordering, selected-vehicle label-side placement, guarded town/village/place label phasing, persisted responsive welcome-panel state, validated dependency-state reduction, contextual public update health, stale notice-value crash protection, truthful station-to-Entur state combination, credential-free admin database-target diagnostics, bounded host-resource parsing and unavailable-measurement omission, focused System-status/Infrastructure/Entur ownership, named hidden-dependency summaries, partial Entur-diagnostics recovery, grouped-but-independent realtime delivery diagnostics, deterministic fallback-to-live recovery, rider-centred welcome copy, failure-state truthfulness, exclusive station-tab resource allocation, accessible resource counts, missing station-metadata handling, complete-versus-incomplete daily timetable states, expired-cursor recovery, overdue station-call grouping, completed-versus-loading/paused nearby-vehicle states, read-only public demo credential discovery/filling/role labelling and disabled-demo behavior, bilingual Database schema/migration behavior, and protection against mislabelling unrelated vehicle metrics as station distance.
- Fresh `make build` on 2026-07-14: the Vite production build, production-fixture/truth audit, and infrastructure topology validation passed.
- The UI now self-hosts exact-pinned Inter Variable normal and italic web fonts. Visual scenarios require the bundled face to be loaded before capture, eliminating the host-font fallback that made local screenshots use Noto Sans while GitHub's Ubuntu runner used a different fallback.

### Clean-stack Playwright proof

Command:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  npm run e2e:live
```

Result on 2026-07-14: all 17 clean-stack tests passed. The repository scripts use the project-managed Chromium under Xvfb, retaining reliable SwiftShader WebGL for the map assertions.

The test creates a clean SurrealDB data directory, applies the complete release migration set, imports deterministic stations, and starts the actual realtime command, FrankenPHP/CakePHP HTTP service, and Vite with `VITE_DATA_MODE=api` and frontend fixtures disabled. It then proves:

- visible station map/search/departure data comes from CakePHP and authoritative SurrealDB state;
- the browser obtains a signed realtime token and opens `/live`;
- station watch and vehicle watch/focus acknowledgements arrive over WebSocket;
- HTTP-triggered canonical writes become database-originated `station_snapshot_changed`, `vehicle_moved`, and `vehicle_lost` messages before updating the visible SolidJS UI;
- backend scenarios visibly exercise a completed 5 km station/vehicle empty result, rate-limited zero-result refresh without a false completion claim, station stale/error, vehicle lost, polling fallback, and reconnecting bridge state;
- protected admin watch, realtime, and persisted-event diagnostics reflect the live session;
- the explicitly enabled public demo fills its separate credentials, remains
  visibly labelled read-only after login, and keeps both login links at least
  32 px tall without 320 px horizontal overflow;
- authenticated Database schema/migration routes render real typed data, stay
  GET-only, survive reload/back/forward/copied URLs, and keep the legacy UI
  alias pointed at canonical tab links;
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

The complete affected verification sequence passed again on 2026-07-15 after
the Admin-metric, mobile-navigation, workflow-runtime, documentation, and
deterministic-clock work. Exact lockfile installation evidence remains valid
from 2026-07-11 because this delta changed no dependencies.

| Gate | Current evidence |
|---|---|
| `make verify-planning` | Passed fresh on 2026-07-15: 25 design PNGs, 27 design notes, 108 stories, 340 black-box scenarios, zero source-corpus ZIPs. |
| `make install` | Passed from exact Composer/npm lockfiles and installed the project-managed Chromium. |
| `make typecheck` | Passed fresh on 2026-07-15. |
| `make phpstan` | Passed fresh at maximum level on 2026-07-15. |
| `make test` | Passed fresh on 2026-07-15: contracts (32 valid/16 invalid realtime and 12 valid/12 invalid HTTP fixtures), PHPUnit 354 tests/2,218 assertions with one intentional external-Entur skip, all 172 Vitest tests, and encrypted backup/restore smoke. |
| `make e2e` | Passed fresh on 2026-07-15: all 20 deterministic fixture tests and all 17 clean-stack SurrealDB/CakePHP/AMPHP/Vite/provider/selection/lifecycle/camera-URL/resilience/Admin-navigation tests. |
| `make visual` | Passed fresh on 2026-07-15: the complete 74-baseline Norwegian/English matrix, including truthful Watch/Entur metrics and the mobile public Admin destination. |
| `make build` | Passed fresh on 2026-07-15, including the production truth audit, Node 24 action-major enforcement, backup tooling, infrastructure validation, and hash/dimension/provenance checks for all six production screenshots. |

## Final aggregate gate record

The 2026-07-15 affected release handoff ran:

```bash
make verify-planning
make typecheck
make phpstan
make test
npm run e2e:fixture
npm run e2e:live
make visual
make build
git diff --check
```

All commands above passed on 2026-07-15. The two explicit browser commands are
exactly the sequential constituents of `make e2e`. The unchanged lockfiles
retain the previously verified `make install` evidence. `git diff --check`
also passed after the complete typecheck, unit, fixture-browser, live-browser,
visual, and production-build sequence.

## Deployment boundary

Production deployment, TLS, private SurrealDB networking, exact-SHA CI/Coolify
rollout, scheduled and pre-release same-host backups, isolated restore, and
browser/API/WSS acceptance are complete. Each later `main` release still has to
pass the same quality workflow, blocking backup, exact public version check,
and post-deploy smoke; this is an operational gate, not unfinished topology.

The remaining optional operator convenience is opening standalone Surrealist
through the proven SSH-tunnelled database `VIEWER`. Repository `.env.example`
files and Compose/Caddy artifacts contain development placeholders only and are
not production secret material.
