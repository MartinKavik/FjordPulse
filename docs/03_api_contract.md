# FjordPulse API Contract Draft

This is the initial contract that both the fake backend and real backend should implement.

The contract is intentionally simple and should be expanded only when implementation requires it.

## HTTP endpoints

```text
GET /api/health
GET /api/readiness
GET /api/map/config
GET /api/stations?bbox=minLon,minLat,maxLon,maxLat&zoom=number
GET /api/search?q=query
GET /api/stations/{stationId}
GET /api/stations/{stationId}/departures
GET /api/stations/{stationId}/nearby-vehicles
GET /api/vehicles/{vehicleId}
POST /api/realtime-token
GET /api/admin/demo-credentials
GET /api/admin/session
POST /api/admin/session
DELETE /api/admin/session
GET /api/admin/status
GET /api/admin/watches
GET /api/admin/entur-log
GET /api/admin/realtime
GET /api/admin/events
GET /api/admin/database/schema
GET /api/admin/database/migrations
GET /api/admin/migrations                 compatibility alias
```

## Admin authentication and public demo boundary

`GET /api/admin/demo-credentials` is the only unauthenticated Admin read. It
returns `{ "enabled": false }` unless public Admin demo access is enabled. When
enabled, it returns a deliberately public demo username/password that is
separate from the operator account; the login panel can fill those values
without embedding the real operator secret in frontend code.

Sessions created by that demo identity can use only the explicitly allowlisted
Admin diagnostic `GET` routes and log out. Middleware rejects every other Admin
route with `admin_read_only`, so neither a future mutation nor a future
sensitive read silently becomes available to the public demo account. The
session response identifies the role as `demo`, allowing the UI to keep its
read-only label visible while server middleware remains authoritative. Turning
the flag off revokes existing demo sessions after the service reloads its
configuration. Production defaults this feature off and requires an explicit
`ADMIN_DEMO_ACCESS=true`. This is independent of `DATA_MODE`:
production transport data remains real and fake production mode remains
forbidden.

## Development-only endpoints

Only enabled in local/dev/test:

```text
GET  /api/dev/scenario
POST /api/dev/scenario
GET  /api/dev/scenarios
```

## Common response envelope

```json
{
  "ok": true,
  "data": {},
  "meta": {
    "requestId": "req_123",
    "updatedAt": "2026-07-09T12:00:00Z"
  }
}
```

## Error envelope

```json
{
  "ok": false,
  "error": {
    "code": "invalid_station",
    "message": "Station id is invalid.",
    "details": {}
  },
  "meta": {
    "requestId": "req_123"
  }
}
```

## Core DTO names

```text
Station
StationCluster
Departure
NearbyVehicle
StationVehicle
ServingVehicleCoverage
VehicleState
VehicleTransportMode
VehicleObservation
RealtimeTelemetry
MapConfig
BasemapConfig
AdminStatus
WatchRow
EnturLogRow
AdminDatabaseSchema
AdminDatabaseMigrations
```

The protected `AdminStatus` database target identifies SurrealDB by a sanitized
WebSocket origin, namespace, and database name. Credentials, the RPC path,
query, and fragment never cross the HTTP boundary; staging/production loopback
targets carry an explicit configuration warning.

The same protected response includes a timestamped `resources` snapshot. It
contains a bounded CPU utilisation sample plus load averages, memory totals and
availability with host/cgroup scope, and application-filesystem totals/free
space with the inspected path. Unsupported measurements are nullable and are
omitted by the UI rather than displayed as invented or permanently empty data.

## Read-only database diagnostics

The protected Database admin page uses two canonical same-origin GET endpoints:

```text
GET /api/admin/database/schema
GET /api/admin/database/migrations
```

The former `/api/admin/migrations` path is a deprecated compatibility alias for
the second endpoint. None of these routes accepts a query, table name, file
path, or mutation body. They cannot execute SurrealQL, apply or retry a
migration, edit schema, or change database data.

`AdminDatabaseSchema` is an immediate typed mapping of one fixed,
backend-owned, allowlisted SurrealDB `INFO ... STRUCTURE` query. It
contains `readOnly`, `checkedAt`, and allowlisted table structure only: table
name/kind/schema mode, normalized `full` / `none` / `conditional` permissions,
fields, indexes, and events. The raw INFO
object never crosses the PHP boundary. Database users, credentials, password
hashes, authentication definitions, and other non-allowlisted metadata are
discarded before serialization.

`AdminDatabaseMigrations` compares only bundled release migration files with
the database ledger and attempt audit. Its compatibility state is `in_sync`,
`pending`, `drift`, or `failed`; each row is `applied`, `pending`,
`checksum_mismatch`, `orphaned`, or `failed`. Rows include release and database
checksums, applied/last-attempted times, a bounded failure message, a human
description, read-only bundled source when present, and structured affected
tables/fields/indexes/events. Source discovery is server-owned and allowlisted;
the client cannot choose an arbitrary path.

The deployment-only CLI migration runner remains a writer by design: it writes
the migration ledger and attempt-audit records before/after a migration so a
failed transaction can be diagnosed later. Admin only reads those records and
never invokes the runner.

---

# API Contract — Final Additions

The canonical contract should be moved into:

```text
contracts/http/openapi.yaml
```

Add/import responsibilities for:

```text
GET /api/stations
GET /api/search
GET /api/stations/{id}
GET /api/stations/{id}/departures
GET /api/stations/{id}/nearby-vehicles
GET /api/vehicles/{id}
POST /api/realtime-token
```

Development scenario endpoints must be disabled in production.

All timestamps are RFC3339. Transport times display in Europe/Oslo.

`GET /api/stations/{id}/nearby-vehicles` performs an exact radial search around
the selected station rather than treating its bounding box as the final result.
Its response includes the positive integer `searchRadiusMeters` used for that
request (5,000 metres in the v1 station-watch profile), including when
`vehicles` is empty, so clients can explain the completed search without
hard-coding its reach.

`GET /api/search` excludes lost vehicles from ordinary line, route, destination,
and fuzzy vehicle discovery. An exact vehicle identifier (optionally prefixed by
`vehicle` or `kjøretøy`) remains discoverable from authoritative persisted state,
even after its position becomes lost, so a restored Focus link can truthfully
open the last-known vehicle instead of silently becoming a no-results query.

## Station-serving vehicles

The composite station snapshot returned by `GET /api/stations/{id}` contains
two deliberately different vehicle lists:

- `servingVehicles` contains currently reporting Vehicle Positions records
  whose exact dated service-journey identity matches a non-cancelled call at the
  selected station. Candidate calls cover six hours before through six hours
  after the snapshot refresh; matches may be anywhere on the map and are not
  restricted to the 5 km radius. A `non_passenger` movement is never a serving
  match. During Journey Planner degradation, a saved station match survives
  only while a fresh same-ID position remains passenger-classified and carries
  the same non-null dated journey identity; otherwise the vehicle may remain in
  the nearby list but the old station match is removed.
- `nearbyVehicles` contains the exact radial result described above. The client
  presents only entries not already present in `servingVehicles` as `Other
  nearby vehicles`.

Each `StationVehicle` carries the normal vehicle summary plus two independent
facts and nullable `stationCallAt`. `callRole` is `starts_here` when the matched
call is the service origin and `calls_here` otherwise. `progress` is
`at_station`, `before_station`, `after_station`, or `unknown`, derived only from
current monitored-call/actual-departure evidence. A service origin several
hours away is therefore shown as `Starts here at …` in a later group rather
than as a vehicle starting now. An `after_station` match remains useful only
while Vehicle Positions still reports that vehicle; it is not journey history
synthesized by FjordPulse.

`servingVehicleCoverage` makes the bounded match explicit with `windowStart`,
`windowEnd`, `candidateJourneyCount`, `queriedJourneyCount`, and `truncated`.
At most 200 unique dated service journeys are sent to Vehicle Positions;
upcoming departure journeys are prioritized. Either Entur call list reaching
its own 200-result ceiling also marks coverage as truncated. The candidate
count is the number of distinct journey identities observed in the returned
calls; when `truncated` is true because an Entur list reached its ceiling, that
count is a lower bound rather than the unknown total. Consequently the list
describes only the reported window and returned candidates, not an exhaustive
search of every vehicle or service in Norway.

## Compact departures and daily timetable

The departures embedded in `StationSnapshot` remain a compact realtime
preview. They contain at most the next 20 calls between refresh time and the
end of the current `Europe/Oslo` calendar day. `departureBoard` reports
`windowStart`, `windowEnd`, `limit`, and `hasMore`; therefore an empty preview
means no more known calls today, not merely no call in an undisclosed two-hour
window.

`GET /api/stations/{id}/departures` without a date returns that preview. With
`date=YYYY-MM-DD` (today through seven days ahead), optional `limit` (maximum
50), and an opaque base64url `cursor`, it returns a separately cached daily
timetable page. `refresh=true` with a date and without a cursor bypasses the
five-minute first-page cache, so an explicit incomplete-board retry really
revalidates Entur. Preview requests reject daily-only `limit`, `cursor`, and
`refresh` parameters. The response identifies
`mode`, `date`, `timeZone`, exact range bounds, paging state, `complete`, and a
nullable exact `totalCount`. `complete` means the backend verified the source
day without an irreducibly saturated Entur window; `page.hasMore` separately
states whether the browser has loaded every cached row. Exact totals require
`complete=true`, and `all shown` language additionally requires
`page.hasMore=false`. A daily page with `hasMore=true` always supplies a
base64url `nextCursor`, and an exhausted page supplies `nextCursor=null`.

Entur exposes time windows and result limits but no stable departure cursor.
The backend therefore obtains a bounded calendar day, subdivides a provider
window when it reaches its ceiling, de-duplicates calls at window boundaries,
and stores a versioned `station_timetable` record. FjordPulse cursors bind to
that immutable cached version plus an offset, so a concurrent refresh cannot
skip or duplicate rows in a user's pagination session. The timetable table has
no `DEFINE EVENT`; full-day boards never enter the station realtime payload.
For the current day, stable page ordering uses the cache's `fetchedAt` anchor:
upcoming calls are delivered first, followed by earlier calls newest-first.
The client restores chronological display order and keeps earlier rows
collapsed, so a busy midnight history cannot hide the next useful departure.
If a retained cursor version expires, the client keeps already loaded rows and
restarts at the first page instead of retrying the dead cursor indefinitely.

Station `version` changes only with semantic station content. A successful
identical-content refresh may advance `updatedAt`, `lastSuccessfulAt`, and the
coverage window without changing `version`; that metadata-only write does not
create a realtime event.

## Vehicle journey details

`GET /api/vehicles/{id}` returns authoritative current vehicle state together
with its observed breadcrumb trail and, when Entur supplies a service-journey
reference, a cached `JourneySnapshot`. The snapshot contains the complete
scheduled GeoJSON `LineString`, up to 1,000 ordered calls, realtime planned and
expected times, cancellation state, and refresh/degraded metadata.

Both full vehicle state and nearby-vehicle summaries carry the required
`transportMode` enum (`air`, `bus`, `coach`, `ferry`, `metro`, `taxi`, `tram`,
`rail`, or `unknown`). Real mode maps Entur Vehicle Positions `mode`; missing or
unrecognised upstream values become `unknown` and are never inferred from a line
number or station type. Vehicle search results may repeat the same field so the
browser can label a result before opening its detail.

They also carry the required `passengerServiceState` enum (`passenger`,
`non_passenger`, or `unknown`). This is independent of the position freshness
`state`. Exact NeTEx `*:ServiceJourney:*` references are passenger movements;
exact `*:DeadRun:*` references are non-passenger. A bounded Skyss compatibility
rule also classifies a noncanonical/missing journey identity with an internal
`GAR...` origin, destination, or monitored stop, or the provider fallback
destination `skyss.no`, as non-passenger. Other noncanonical identities remain
`unknown`; the browser never repeats these classification heuristics.

The vehicle state carries only compact progress fields (`journeyReference`,
`monitoredCall`, `progressBetweenStops`, `journeyVersion`, and
`routeProgress`). `lastSeenAt` is Entur's upstream observation timestamp;
`refreshedAt` is when FjordPulse most recently fetched the record.

The public vehicle summary derives `Previous stop` from the ordered journey
calls plus the matched monitored/next call. If those authoritative values are
missing it displays `Not available`; it does not infer a stop from coordinates.
Cancelled calls remain in the journey snapshot to preserve Entur's authoritative
orders and route-progress indices, but rider-facing previous, next, and upcoming
stop lists skip them.
`bearing` remains available to render/map vehicle orientation, but is no longer
presented as rider-facing journey context. A non-passenger vehicle retains its
live coordinate, trail, raw operational metadata, and watch identity, while the
top-level `journey` is null and `upcomingStops` is empty. Consumers must not
present raw line, route, destination, delay, or stop-progress fields as passenger
information when `passengerServiceState` is `non_passenger`.
