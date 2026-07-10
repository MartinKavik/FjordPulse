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
  "id": "msg_001",
  "type": "watch_station",
  "payload": {}
}
```

## Server success envelope

```json
{
  "id": "msg_001",
  "type": "watch_station_ack",
  "payload": {}
}
```

## Server event envelope

```json
{
  "type": "vehicle_moved",
  "scope": "vehicle:SKY:Vehicle:12345",
  "entityId": "SKY:Vehicle:12345",
  "version": "2026-07-09T12:00:00.000Z",
  "payload": {}
}
```

## Server error envelope

```json
{
  "id": "msg_001",
  "type": "error",
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
watch_vehicle_ack
focus_started
focus_stopped
station_departures_changed
nearby_vehicles_changed
vehicle_moved
vehicle_stale
vehicle_lost
source_backoff
rate_limited
telemetry_tick
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
contracts/realtime/messages/*.schema.json
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

V1 realtime service has one replica; in-memory room membership is therefore authoritative for active connections.
