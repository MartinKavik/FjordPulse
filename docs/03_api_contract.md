# FjordPulse API Contract Draft

This is the initial contract that both the fake backend and real backend should implement.

The contract is intentionally simple and should be expanded only when implementation requires it.

## HTTP endpoints

```text
GET /api/health
GET /api/stations?bbox=minLon,minLat,maxLon,maxLat&zoom=number
GET /api/search?q=query
GET /api/stations/{stationId}
GET /api/stations/{stationId}/departures
GET /api/stations/{stationId}/nearby-vehicles
GET /api/vehicles/{vehicleId}
GET /api/admin/status
GET /api/admin/watches
GET /api/admin/entur-log
```

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
VehicleState
VehicleObservation
RealtimeTelemetry
AdminStatus
WatchRow
EnturLogRow
```

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
