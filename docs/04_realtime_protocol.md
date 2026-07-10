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

V1 realtime service has one replica; in-memory room membership is therefore authoritative for active connections.
