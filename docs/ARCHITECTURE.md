# FjordPulse Architecture

## Runtime topology

### Web service

In production, Coolify-managed Traefik terminates public TLS and routes the
single `fjordpulse.kavik.cz` host to app container port 8080. Caddy is embedded
in that container as FrankenPHP's application server; it is not another public
host proxy.

FrankenPHP in normal/as-is mode serves:

```text
/                 compiled SolidJS application
/api/*            CakePHP public API
/admin/*          CakePHP admin/control UI
/health/*         health/readiness endpoints
/live             reverse proxy to realtime service
```

`GET /api/map/config` exposes the operator-selected, allowlisted browser
basemaps. V1 uses MapTiler Hybrid v4 satellite imagery by default and Streets
v4 as the alternate layer. The read-only browser key is runtime configuration,
never repository content or end-user input. Normal routes show an explicit
loading/error state and never fall back to deterministic fixture geography.
Exact guarded Hybrid-v4 and Streets-v4 profiles apply the same collision-safe
settlement hierarchy: towns from zoom 6, villages from zoom 8, and denser local
places from zoom 10. Provider signature drift skips these mutations non-fatally
instead of guessing against a changed style. Selected stations are injected as
authoritative overlay features independently of viewport aggregation, so a
projected pin and name remain above clusters and provider labels at every zoom.
A newly selected station at Norway/Europe overview scale centres immediately at
local zoom 11, before its detail request completes. Once the camera is already
local, visible selections preserve it; off-screen station and vehicle selections
pan into view without decreasing the current zoom, and same-identity realtime
refreshes never move the map.
Backend health reports this dependency as `configured`, not `healthy`, because
an origin-restricted browser key cannot be safely live-probed from a synchronous
server health request. MapLibre load failures remain explicit in the browser.

The public shell treats the introduction as optional map guidance. Desktop
opens it on a first visit and expands the map across its column when collapsed;
mobile defaults collapsed and opens a compact bottom overlay. Only explicit
choices are persisted, and transport detail panels always take precedence.

The frontend localization boundary is typed and browser-local. Norwegian
Bokmål (`nb`) is the default regardless of browser preference; the shared
`NO`/`EN` control changes reactive public/admin copy and `<html lang>` in the
current document. A valid explicit selection is stored under
`fjordpulse.locale.v1`; unavailable or invalid storage is non-fatal and falls
back to Norwegian. Locale state never crosses the Entur, SurrealDB, HTTP, or
realtime contracts, and authoritative proper names, IDs, URLs, scopes, and raw
diagnostic payloads are not translated.

### Realtime service

One private v1 process:

```bash
bin/cake realtime start
```

It hosts:

- AMPHP/Revolt WebSocket server,
- client/message lifecycle,
- room registry,
- active watch registry,
- timer scheduler,
- fake/real Entur adapters,
- SurrealDB async command connection,
- SurrealDB dedicated live-query connection,
- event bridge and health.

### SurrealDB

Private service with persistent storage and migrations. The browser cannot connect to it.

The protected Database admin surface is also mediated entirely by CakePHP. Its
schema endpoint executes only one fixed, backend-owned, allowlisted
`INFO ... STRUCTURE` query and immediately maps its result into typed DTOs. Raw
database INFO output is never serialized, because database-level metadata may
contain users and password hashes. The browser cannot provide a SurrealQL
query, table name, migration path, or mutation command.

Migration diagnostics compare bundled release files with typed ledger/audit
repositories. They are read-only in Admin: there is no query editor, schema
editor, Apply, Retry, or Rollback control. Only the deployment CLI migration
runner writes the migration ledger and its attempt audit. That audit write is
performed outside the schema-change transaction so a failed attempt remains
diagnosable after the transaction rolls back.

Admin authentication has two distinct identities. The operator credential is
never returned to the browser. The local dev scripts may expose a separate,
deliberately public demo identity through a typed discovery endpoint;
configuration itself defaults off in every environment, and production enables
it only explicitly. Its signed session is marked `demo`, the UI keeps that
read-only role visible, and middleware permits only an explicit allowlist of
Admin diagnostic `GET` routes plus logout. This keeps the current
diagnostics-only console useful in a public product demo while failing closed if
a future Admin read or mutation endpoint appears.

## Domain/data model

```text
station
  imported station/infrastructure record

station_snapshot
  current departure board + station-serving matches/coverage + nearby vehicle summary
  typed station -> station link with cascade deletion

current_vehicle
  current known vehicle location/status/version + passenger-service classification + compact journey progress
  optional typed journey -> journey_snapshot link while that cache exists; deletion unsets only the link

journey_snapshot
  cached complete service-journey route geometry and ordered stop calls

vehicle_observation
  bounded recent trail records
  typed vehicle -> current_vehicle link with cascade deletion

station_timetable
  versioned whole-day board cache + typed station -> station link with cascade deletion

watch
  durable TTL representation of active station/vehicle/focus demand

realtime_event
  compact append-only notification record created by database events

entur_request_log
  upstream request outcome, timing, cache, backoff

entur_budget_state
  singleton rolling reservation ledger for the shared and per-source Entur request allowances

system_status
  observable service/source state

schema_migration
  successfully applied release migration name/checksum/time

schema_migration_attempt
  deployment-CLI attempt state/time and bounded failure evidence
```

Canonical Entur identifiers remain string fields at every public boundary and
inside routing/audit records. Migration 013 additionally derives typed SurrealDB
record links from the same deterministic ids. The bounded snapshot objects stay
denormalized for atomic resync and event payloads; the native links add schema
visibility, traversal, incoming-reference tracking, and deletion policy. There
is deliberately no duplicate relation table for these metadata-free ownership
links. A `TYPE RELATION` table will be introduced only for a relationship that
owns data or is actually traversed as a graph.

### SurrealDB multi-model fit

FjordPulse uses each database model only where it answers a real product or
operator query:

- **Document:** `station_snapshot`, `journey_snapshot`, and
  `station_timetable` keep bounded nested departure, call, geometry, and vehicle
  summaries. They are atomic HTTP/reconnect shapes rather than joins the browser
  must reconstruct.
- **Graph:** typed record links connect snapshots, timetables, observations,
  current vehicles, stations, and journeys. A `TYPE RELATION` table remains
  reserved for relationship-owned metadata such as a dated ordered call that
  the application actually traverses. Surrealist Designer renders these typed
  fields as connected table boxes, and Explorer exposes outgoing links plus
  incoming References. Query-result Graph mode is intentionally different: it
  draws `RELATE` edge records, so the project does not duplicate ordinary
  references as decorative relation tables merely to populate that view.
- **Indexed search:** migration 014 adds compact scalar indexes for normalized
  station name, locality, municipality, and station count. Exact and whole-field
  prefix discovery (`Førde`, `Forde`, `Fo`) uses lexicographic index ranges; a
  SurrealDB-`VALUE`-derived token-prefix index covers later words such as
  `National` in `Oslo Nationaltheatret`. A separate selective array index stores
  two derived length/first/last-character keys per token and supplies candidates for an exact residual one-edit
  Damerau check without a catalog scan. SurrealDB performs both indexed
  candidate selection and the exact distance check. PHP only performs the final
  bounded cross-entity merge and reserves a correction slot when appropriate.
  The same migration derives indexed token prefixes from each current vehicle's
  normalized search document, so direct line, route, and destination lookup is
  also an index query; vehicle typo recovery is deliberately not duplicated.
  The deliberately narrow one-edit contract avoids another search subsystem and
  keeps national-catalog imports compact on the single demo host.
- **Geospatial:** migration 015 derives station, current-vehicle, and observation
  positions as native WGS84 computed points (longitude first). Exact
  nearest-station ordering uses `geo::distance`; scalar coordinates remain the
  single stored source, indexed map-box prefilter, and public DTO form.
- **Time series:** `vehicle_observation` is the bounded observation series: it
  has a vehicle tag/link, authoritative observation timestamp, ordered
  vehicle/time index, and explicit expiry/retention cleanup. Migration 016 also
  indexes `observed_at` independently, allowing the global expiry-or-observation
  cutoff to use a two-branch index union. SurrealDB has no separate table kind
  that FjordPulse needs to enable for this model.
- **Vector:** deliberately unused in v1. FjordPulse has no authoritative
  embedding source or semantic-nearest-neighbour user story. Adding a model and
  embedding refresh pipeline merely to populate HNSW/DiskANN would make search
  less explainable and the single-host demo less reliable.

This keeps the database genuinely multi-model without duplicating every fact
into every available representation.

## Database-driven realtime publication

### Why

The project should demonstrate SurrealDB's live-query capability without making the browser a database client or introducing Redis.

### Flow

1. Collector receives fake/real Entur data.
2. Adapter maps raw data to typed PHP DTOs.
3. Repository computes a semantic content hash/version, writes meaningful canonical changes, and may advance refresh-only metadata without changing that hash.
4. A SurrealDB `DEFINE EVENT` on the canonical table creates a compact `realtime_event` in the same transaction only when semantic content changed.
5. A dedicated PHP live-query bridge receives `CREATE` notifications from `LIVE SELECT * FROM realtime_event`.
6. The bridge validates the event, looks up its scope, and broadcasts to matching browser room(s).
7. SolidJS applies the event only if its version is newer.

There is no second direct-broadcast path after writes.

## Snapshot + notification correctness

Live events are not authoritative state. Current tables are authoritative.

When a client watches/resubscribes:

1. join the room,
2. read and send current snapshot,
3. process newer live events,
4. ignore duplicate/older event versions.

After a browser reconnect or live-query bridge recovery, send fresh snapshots. This avoids needing exact event replay while preserving correct UI state.

## SurrealDB runtime split

```text
CakePHP request/response:
  SurrealDB Runtime::sync()

Realtime service:
  SurrealDB Runtime::amp()
```

Use separate async connections for command/query work and live-query streaming. The live connection uses WebSocket transport, automatic connection backoff, feature checks, and an application-level supervisor that recreates the unmanaged query when needed.

## Demand-driven collection

The initial Norway map shows locally imported stations/clusters only. Upstream vehicle/departure work starts only for active watches.

Priority:

```text
focus vehicle
selected vehicle
selected station
pinned/operator scopes
background maintenance
```

Multiple clients share one refresh scope.

A selected-station refresh asks Journey Planner for the normal departure board
and for station calls in a bounded six-hours-before/six-hours-after window. It
de-duplicates exact service-journey/date pairs, prioritizes upcoming departure
journeys, and caps the Vehicle Positions match at 200 pairs. Vehicle Positions
then answers one backend request with both those potentially far-away
station-serving matches and the station bounding-box candidates used for the
exact 5 km radial list. Typed matching separates schedule role
(`starts_here`/`calls_here`) from observed progress
(`at_station`/`before_station`/`after_station`/`unknown`); it never synthesizes
a vehicle for a scheduled call. Candidate/queried counts and a
truncation flag cross HTTP/realtime boundaries so the UI does not describe this
bounded window as exhaustive national coverage.

The normal departure board is a compact preview, not a two-hour statement. It
selects at most the next 20 calls between refresh time and Oslo midnight and
records its explicit window plus whether later rows exist. This keeps ordinary
station snapshots bounded while ensuring a quiet station still shows a service
that is several hours away. The UI time-buckets station-linked positions, so a
service originating at the station hours later remains discoverable without
being promoted to `starting now`.

An explicit `View today's timetable` request uses the same backend-only Journey
Planner adapter to build a versioned `station_timetable` cache for one
`Europe/Oslo` calendar day. If an upstream result ceiling is reached, the
adapter subdivides the time window and de-duplicates boundary rows before it
can claim the cache is complete. HTTP pages use opaque cache-version/offset
cursors. Pages use the persisted fetch time as a stable relevance anchor,
delivering upcoming calls before earlier history while keeping every row
reachable. `station_timetable` intentionally defines no database event: the full
day can contain hundreds or thousands of calls and is never copied into the
canonical station snapshot or WebSocket event path.

Focused vehicle scopes refresh every three seconds, leaving headroom beneath
the 30-request-per-minute Vehicle Positions budget while remaining faster than
ordinary selected-vehicle watches. All vehicle scopes share a nationwide
Vehicle Positions response cached for two seconds inside the single realtime process. When Vehicle
Positions supplies a service-journey id and operating date, the collector
first classifies that movement independently from live/stale/lost position
freshness. Canonical service journeys refresh Journey Planner geometry/calls at
most every 30 seconds; explicit dead runs and bounded provider-specific
garage/internal movements remain position-visible but skip public-journey
enrichment. Unknown noncanonical references remain unknown rather than being
guessed from a failed lookup. The
`journey_snapshot` table has no database event: a changed journey version is
written into `current_vehicle`, whose existing database event remains the one
notification path. Authoritative HTTP/WebSocket snapshots include the complete
journey; movement events carry only its reference/version and progress.

## Entur sources

```text
Stop Place Register  station import
Geocoder v3         search/place lookup
Journey Planner v3  departure boards + bounded station calls + service-journey geometry/calls
Vehicle Positions   live vehicle positions
```

All calls are backend-only and identified with `ET-Client-Name`.

Before transport starts, both CakePHP request paths and the realtime worker
reserve one slot in the same `entur_budget_state:shared` record. The record is a
bounded rolling 60-second ledger, not a provider-reported account quota. Its
single-record conditional update is the conflict boundary that prevents two
independent PHP/AMPHP connections from oversubscribing either the global limit
or a source-specific limit. A stable request id makes a retried reservation
idempotent, and the admin allowance reads the reservation ledger so in-flight
requests are included even before their outcome is written to
`entur_request_log`. This operational table does not create `realtime_event`
records and is never exposed directly to the browser.

Station refresh attempts Journey Planner and Vehicle Positions independently.
A failed adapter retains its authoritative cached values while a successful
adapter still advances; the combined snapshot becomes stale/rate-limited and
the watch enters bounded retry. A process-lifetime Amp transport drops a failed
HTTP connection pool, performs no immediate duplicate request, and creates a
fresh pool only when the scheduler's next allowed attempt begins.

## Public update-health presentation

The browser retains typed backend, realtime, live-query, source, refresh-mode,
and timestamp telemetry for recovery decisions, but the public app does not
render that operator data as a permanent service matrix. Healthy lazy realtime
is silent. Selected station and vehicle panels own resource age and exceptional
warnings; one contextual notice appears only while delivery is reconnecting,
periodically updating, or unavailable and explains the rider effect. The
protected Admin status pages remain the component-level diagnostic surface.

Source provenance is independent of health. Real mode shows neutral
`Transport data: Entur` attribution, while fake mode shows a prominent `Demo
data` badge. Neither is used to claim that an individual request succeeded.

## Failure behavior

```text
live-query bridge down:
  realtime degraded, snapshots/polling continue

Entur rate limited:
  cached/stale data + visible backoff

one station Entur adapter down:
  retain that adapter's cached values, refresh the independent adapter,
  publish stale/rate-limited station state, and retry the failed watch

vehicle not updating:
  live through 30 seconds, stale through five minutes, then position unavailable;
  keep the watch active and recover automatically on the next observation

SurrealDB unavailable:
  readiness fails; app shows contained/degraded state where possible

MapTiler missing/unavailable:
  map reports a visible service error with Retry; no substitute map is selected
```

## Scaling boundary

V1 realtime is one replica because rooms and socket memberships are in memory. A future multi-replica design requires a new shared fan-out ADR.
