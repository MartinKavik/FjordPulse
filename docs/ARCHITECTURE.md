# FjordPulse Architecture

## Runtime topology

### Web service

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

## Domain/data model

```text
station
  imported station/infrastructure record

station_snapshot
  current departure board + nearby vehicle summary for a station

current_vehicle
  current known vehicle location/status/version + compact journey progress

journey_snapshot
  cached complete service-journey route geometry and ordered stop calls

vehicle_observation
  bounded recent trail records

watch
  durable TTL representation of active station/vehicle/focus demand

realtime_event
  compact append-only notification record created by database events

entur_request_log
  upstream request outcome, timing, cache, backoff

system_status
  observable service/source state
```

## Database-driven realtime publication

### Why

The project should demonstrate SurrealDB's live-query capability without making the browser a database client or introducing Redis.

### Flow

1. Collector receives fake/real Entur data.
2. Adapter maps raw data to typed PHP DTOs.
3. Repository computes a semantic content hash/version and writes only meaningful canonical changes.
4. A SurrealDB `DEFINE EVENT` on the canonical table creates a compact `realtime_event` in the same transaction.
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

Focused vehicle scopes refresh every three seconds, leaving headroom beneath
the 30-request-per-minute Vehicle Positions budget while remaining faster than
ordinary selected-vehicle watches. All vehicle scopes share a nationwide
Vehicle Positions response cached for two seconds inside the single realtime process. When Vehicle
Positions supplies a service-journey id and operating date, the collector
refreshes its Journey Planner geometry/calls at most every 30 seconds. The
`journey_snapshot` table has no database event: a changed journey version is
written into `current_vehicle`, whose existing database event remains the one
notification path. Authoritative HTTP/WebSocket snapshots include the complete
journey; movement events carry only its reference/version and progress.

## Entur sources

```text
Stop Place Register  station import
Geocoder v3         search/place lookup
Journey Planner v3  departure boards + service-journey geometry/calls
Vehicle Positions   live vehicle positions
```

All calls are backend-only and identified with `ET-Client-Name`.

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
  stale, then lost

SurrealDB unavailable:
  readiness fails; app shows contained/degraded state where possible

MapTiler missing/unavailable:
  map reports a visible service error with Retry; no substitute map is selected
```

## Scaling boundary

V1 realtime is one replica because rooms and socket memberships are in memory. A future multi-replica design requires a new shared fan-out ADR.
