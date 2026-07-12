# FjordPulse Realtime Protocol Draft

## WebSocket URL

```text
WS /live
```

In production:

```text
wss://fjordpulse.kavik.cz/live
```

## Client message envelope

```json
{
  "protocolVersion": 1,
  "id": "msg_001",
  "type": "watch_station",
  "payload": {}
}
```

## Server success envelope

```json
{
  "protocolVersion": 1,
  "id": "msg_001",
  "type": "watch_station_ack",
  "createdAt": "2026-07-09T12:00:00Z",
  "payload": {}
}
```

## Server event envelope

```json
{
  "protocolVersion": 1,
  "type": "vehicle_moved",
  "scope": "vehicle:SKY:Vehicle:12345",
  "entityId": "SKY:Vehicle:12345",
  "eventId": "evt_001",
  "version": "2026-07-09T12:00:00.000Z",
  "createdAt": "2026-07-09T12:00:00.000Z",
  "payload": {}
}
```

## Server error envelope

```json
{
  "protocolVersion": 1,
  "id": "msg_001",
  "type": "error",
  "createdAt": "2026-07-09T12:00:00Z",
  "error": {
    "code": "invalid_message",
    "message": "Message payload is invalid.",
    "details": {}
  }
}
```

## Client messages

```text
watch_station
unwatch_station
watch_vehicle
unwatch_vehicle
focus_vehicle
unfocus_vehicle
resume_focus
pause_focus
ping
```

## Server events

```text
watch_station_ack
unwatch_station_ack
watch_vehicle_ack
unwatch_vehicle_ack
focus_started
focus_stopped
focus_paused
focus_resumed
station_snapshot
station_snapshot_changed
station_departures_changed
nearby_vehicles_changed
vehicle_snapshot
vehicle_moved
vehicle_stale
vehicle_lost
source_backoff
rate_limited
telemetry_tick
realtime_degraded
resync_required
pong
error
```

## Rooms/scopes

```text
station:{stationId}
vehicle:{vehicleId}
focus:{sessionId}:{vehicleId}
admin:status
```

## Rules

```text
All incoming messages must be validated.
Unknown message types return structured errors.
Invalid payloads do not crash or kill the process.
Each connection can join multiple rooms.
Closing a connection expires its watches after TTL unless another client shares them.
```

---

# Realtime Protocol — Final Additions

Canonical schemas:

```text
contracts/realtime/envelope.schema.json
contracts/realtime/client-message.schema.json
contracts/realtime/server-message.schema.json
```

Every message includes protocol version:

```json
{
  "protocolVersion": 1,
  "id": "msg_001",
  "type": "watch_station",
  "payload": {}
}
```

The server rejects unsupported protocol versions with a structured error.

Every server message includes `createdAt`. Notifications originating from
`realtime_event` also include `eventId`, `entityId`, `scope`, and `version`.
Authoritative `station_snapshot` and `vehicle_snapshot` messages have versions
but do not invent database event IDs. The canonical station database event is
`station_snapshot_changed`; the older `station_departures_changed` and
`nearby_vehicles_changed` names are schema-covered only as derived views of the
same event identity, never as a direct post-write publication path.

Refresh-only station metadata (`updatedAt`, `lastSuccessfulAt`, and serving
coverage bounds) may advance in canonical storage while the semantic content
hash and `version` remain unchanged. Because the database event predicate keys
on that hash, such a write intentionally emits no duplicate notification.

An authoritative `station_snapshot` or `station_snapshot_changed` payload
includes both `nearbyVehicles` and `servingVehicles` plus
`servingVehicleCoverage`. Serving rows are current positions matched by exact
dated service journey to a call in the reported six-hours-before/six-hours-after
window, while nearby rows remain the radial station result. Coverage exposes
candidate/queried counts and truncation (at most 200 selected journey
identities). A candidate count is the observed returned count and becomes a
lower bound when an Entur call list reaches its result ceiling, so reconnect
snapshots and compact database notifications never imply an exhaustive
all-Norway lookup.

V1 realtime service has one replica; in-memory room membership is therefore authoritative for active connections.

## Journey snapshots and movement events

An authoritative `vehicle_snapshot` includes sibling `journey` and
`upcomingStops` fields. `journey` may be null when Vehicle Positions supplies no
service-journey reference; degraded journey snapshots retain cached geometry
and calls with an explicit source state and warning. The full ordered journey
retains cancelled calls for route-index integrity; `upcomingStops` omits those
cancelled calls from the rider-facing sequence.

Every full vehicle state and compact vehicle event carries
`passengerServiceState` (`passenger`, `non_passenger`, or `unknown`) independently
from live/stale/lost position freshness. A non-passenger snapshot keeps its live
marker/trail and Focus identity, but its authoritative `journey` is null and
`upcomingStops` is empty. A later canonical passenger journey for the same
physical vehicle changes this field and restores normal journey enrichment
without replacing the browser watch.

Every full vehicle state, nearby-vehicle summary, and station-serving row carries
the canonical `transportMode`. Vehicle snapshots and compact
movement/stale/lost events retain that value across reconnects; `unknown` means
the upstream source did not report a recognised mode, not that FjordPulse
inferred one.

Database-originated `vehicle_moved`, `vehicle_stale`, and `vehicle_lost` events
remain compact. They carry the vehicle's journey reference/version and progress
but never repeat full geometry or the ordered call list. A newer
`journeyVersion` tells the browser to obtain a fresh authoritative snapshot.
There is no database event on `journey_snapshot`; `current_vehicle` remains the
single database-to-WebSocket notification path.

`telemetry_tick.entur` uses `idle` when real adapters are configured but no
recent request has proved an upstream outcome. It changes to `ok`, `delayed`,
`backoff`, or `rate_limited` only from an observed request result; fake mode
uses `not_used`.
