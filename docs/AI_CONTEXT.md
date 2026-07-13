# FjordPulse AI Context

FjordPulse is a map-first realtime Norwegian public transport explorer. Users browse stations, open departures, distinguish reporting vehicles matched to station services from other nearby positions, inspect a typed vehicle, and use Focus mode to follow it. The project deliberately tests modern PHP: CakePHP 6 for HTTP/control, AMPHP/Revolt for long-running realtime, and SurrealDB live queries for database-driven event propagation.

## Canonical loop

```text
Frontend watch/focus command
  -> PHP WebSocket handler
  -> durable watch + in-memory room membership
  -> AMPHP scheduler calls fake/real Entur adapter
  -> typed state is written to SurrealDB
  -> SurrealDB DEFINE EVENT creates realtime_event
  -> PHP Runtime::amp() LIVE SELECT bridge receives event
  -> PHP broadcasts to room
  -> SolidJS updates visible state
```

The browser never talks directly to Entur or SurrealDB.

Station collection isolates Journey Planner from Vehicle Positions. One source
may refresh while the other's cached data remains visible as stale/rate-limited.
Realtime persists that snapshot before putting the watch into bounded retry;
transport failure replaces the failed Amp connection pool on the next scheduled
attempt rather than retrying immediately.

Each station refresh matches exact dated service-journey calls from a bounded
six-hours-before/six-hours-after window to currently reporting Vehicle Positions
records. At most 200 unique journey/date pairs are queried, upcoming departures
are prioritized, and the snapshot exposes candidate/queried/truncated coverage.
These potentially far-away station-serving matches remain separate from other
vehicles inside the exact 5 km radius. The UI never calls the result exhaustive
national coverage and never synthesizes a vehicle from a scheduled call.

Vehicle mode comes only from Vehicle Positions and remains `unknown` when Entur
does not report a recognised mode. The panel derives Previous stop from ordered
journey calls plus monitored/next-call progress, falling back to `Not available`;
bearing remains map data rather than the primary rider summary. A stale vehicle
offers `Refresh position`, which performs the existing bounded retry while the
watch remains active. Vehicle observations remain live through 30 seconds,
stale through five minutes, and become position-unavailable only after that
grace; a temporarily omitted nationwide-feed row follows the same policy rather
than becoming lost immediately. The watch keeps checking and automatically
returns to live when Entur reporting resumes.

Vehicle passenger-service state is a separate backend-authored enum:
`passenger`, `non_passenger`, or `unknown`. Exact public service journeys remain
passenger movements even when Journey Planner is temporarily unavailable;
explicit dead runs and narrowly recognised provider garage/internal movements
are non-passenger. A non-passenger vehicle keeps its live marker, trail, and
Focus watch, but the public UI hides operational line/delay/stop metadata and
does not request a public journey schedule. The browser never derives this
classification from warning text or a null journey.

Normal map routes obtain an allowlisted MapTiler configuration from
`GET /api/map/config`. Satellite-with-labels is the first-visit default, users
can switch to the ordinary street map, and only successful choices are stored
locally. Missing credentials or provider failure is a visible service error;
the deterministic inline map exists only on fixture/test routes.
Public camera state uses MapLibre's named `#map=zoom/latitude/longitude`
fragment. It is applied before the default camera, replaced after settled map
movement, preserved across reload/share, and disabled on deterministic routes.
Guarded MapTiler cartography brings collision-managed towns in at zoom 6,
villages at zoom 8, and dense local places at zoom 10 for both basemaps.
Selected stations and vehicles use dedicated projected pins above clusters and
provider labels. Overview selections centre immediately at local zoom 11 before
details finish loading. At an already useful local zoom, a visible selection
keeps the camera; an off-screen selection pans without zooming out, and realtime
refreshes of the same selection do not recenter the map.

The desktop introduction is expanded on a first visit but can release its map
column into a labelled `About` edge control. Mobile is map-first and defaults
to the collapsed control; explicit choices persist safely when storage exists.

Norwegian Bokmål (`nb`) is the deterministic default interface language. A
shared, accessible `NO`/`EN` switcher is reachable on public and admin surfaces,
updates reactive copy plus `<html lang>` without navigation, and remembers only
an explicit locale choice in local storage. Missing, invalid, or inaccessible
storage falls back to Norwegian. Provider/place names, transport identifiers,
URLs, scopes, and raw diagnostic payloads remain authoritative data rather than
translated UI copy. Every deterministic scenario has Norwegian and English
visual coverage, including localized-label overflow checks at supported widths.

Public update health is contextual rather than a permanent component matrix.
Healthy lazy realtime has no ready badge; selected station/vehicle panels own
resource age and exceptional warnings. One desktop/mobile notice explains
reconnecting, periodic updates, or saved-data fallback and stays available with
a detail panel open. Backend, realtime, SurrealDB, Entur, bridge, budget, and
latency diagnostics remain in Admin. Real mode uses neutral `Transport data:
Entur` attribution; fake mode keeps a prominent `Demo data` badge.

Admin's Database destination is deliberately narrower than Surrealist. Current
schema maps one fixed, backend-owned, allowlisted SurrealDB structure query into
typed tables/fields/indexes/events/permissions; raw INFO users,
password hashes, credentials, and authentication definitions never cross PHP.
Migrations compares bundled source with ledger/attempt records and is also
GET-only. The browser cannot submit SurrealQL, edit schema, choose a file, or
apply/retry/roll back a migration. Only the deployment CLI writes
migration-attempt evidence; standalone Surrealist uses a private operator
connection for record/query work.

The complete product behavior is described by `docs/user-stories/`, and the visual inventory is in `docs/design/`.
