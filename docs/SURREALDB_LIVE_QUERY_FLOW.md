# SurrealDB Live-Query Flow

## Objective

Use SurrealDB as both the canonical state store and the durable trigger point for browser realtime, while keeping PHP responsible for security, rooms, contracts, and browser connections.

## Official PHP SDK runtime

Current v2 alpha documentation uses:

```php
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Runtime\Runtime;

$sync = new Surreal(Runtime::sync());
$amp = new Surreal(Runtime::amp());
```

The live-query connection must use WebSocket transport.

Recommended imports to verify against the pinned alpha:

```php
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Auth\DatabaseAuth;
use SurrealDB\SDK\Reconnect\ExponentialBackoffReconnect;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Live\LiveAction;
```

## Why two async database connections

```text
commandDb:
  reads/writes canonical state, watches, logs

liveDb:
  dedicated LIVE SELECT subscription
```

This isolates a long-lived stream from normal RPC work and makes health/reconnect behavior easier to reason about.

## Canonical tables and event scopes

### station_snapshot

One record per station with:

```text
station_id
version
content_hash
updated_at
source_state
 departures[]
nearby_vehicles[]
```

Database event creates:

```text
type: station_snapshot_changed
scope: station:<station_id>
```

### current_vehicle

One record per known watched vehicle with:

```text
vehicle_id
version
content_hash
state: live|stale|lost
position
bearing
delay
last_seen_at
updated_at
```

Database event creates one of:

```text
vehicle_moved
vehicle_stale
vehicle_lost
```

with scope:

```text
vehicle:<vehicle_id>
```

### realtime_event

Compact append-only record:

```text
event_id
scope
type
entity_id
version
payload
created_at
```

No event is defined on this table.

### Operational tables outside the publication path

`watch`, `entur_request_log`, `entur_budget_state`, `system_status`,
`schema_migration`, and `schema_migration_attempt` support scheduling,
deployment compatibility, and operator diagnostics but do not
publish browser realtime events. In particular, `entur_budget_state:shared`
stores short-lived, idempotent outbound-request reservations in one record so
the HTTP and realtime processes enforce the same rolling Entur allowance. It
is a database concurrency boundary, not another event stream.

The CLI migration runner alone writes `schema_migration` and
`schema_migration_attempt`; Admin has protected GET access only. Its schema
inspector uses one fixed backend-owned, allowlisted `INFO ... STRUCTURE` query, maps only
tables/fields/indexes/events/permissions, and discards
the raw database INFO object. Database users, password hashes, credentials, and
arbitrary SurrealQL never cross the HTTP boundary.

## Suggested migration behavior

Use `DEFINE EVENT OVERWRITE` so migrations can update event definitions deterministically.

Conceptual example—not a guaranteed final SurrealQL syntax for the pinned server version:

```surql
DEFINE EVENT OVERWRITE publish_station_snapshot
ON TABLE station_snapshot
WHEN $event = 'CREATE' OR $before.content_hash != $after.content_hash
THEN (
  CREATE realtime_event CONTENT {
    event_id: rand::uuid::v7(),
    scope: 'station:' + <string>$after.station_id,
    type: 'station_snapshot_changed',
    entity_id: <string>$after.station_id,
    version: $after.version,
    payload: $after,
    created_at: time::now()
  }
);
```

The coding agent must adapt this to the exact pinned SurrealDB version and test it transactionally.

## Live bridge lifecycle

1. Create `Surreal(Runtime::amp())`.
2. Connect to `ws://surrealdb:8000/rpc` using database-scoped credentials.
3. Configure exponential reconnect.
4. Subscribe to lifecycle events for health.
5. Verify `Features::liveQueries()`.
6. Start:

```surql
LIVE SELECT * FROM realtime_event;
```

7. Consume messages inside an AMPHP fiber/task.
8. Handle only `LiveAction::Create` for the append-only table.
9. Validate `RealtimeEvent` DTO.
10. Broadcast to room matching `scope`.
11. On loop termination/reconnect, supervise and register a new query.
12. On graceful shutdown, `KILL` the query when possible and close the connection.

Do not assume an unmanaged live query automatically restarts after reconnect unless the exact pinned SDK explicitly guarantees it and tests prove it.

## Browser consistency

On `watch_station` or `watch_vehicle`:

```text
join room
read current snapshot
send snapshot with version
then apply live events with newer versions
```

On browser reconnect:

```text
re-open WebSocket
resend watch/focus commands
receive fresh snapshots
continue live events
```

On bridge recovery:

```text
mark bridge connected
notify clients that resync is occurring
send/ask for fresh snapshots for active rooms
```

## Degraded fallback

If liveDb is disconnected:

- canonical writes may continue through commandDb,
- health reports realtime degraded,
- frontend uses periodic HTTP snapshot refresh,
- no direct post-write broadcast bypass is added,
- when live bridge recovers, active rooms resync snapshots.

## End-to-end integration test

The test must:

1. connect a browser/test WebSocket client to a station room,
2. write/update `station_snapshot`,
3. confirm the SurrealDB table event creates `realtime_event`,
4. confirm the PHP live bridge receives it,
5. confirm the browser receives `station_snapshot_changed`,
6. confirm UI/store applies the new version.

A second test must repeat this for `current_vehicle` and `vehicle_moved`.
