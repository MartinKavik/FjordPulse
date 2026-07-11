# FjordPulse AI Context

FjordPulse is a map-first realtime Norwegian public transport explorer. Users browse stations, open departures and nearby vehicles, inspect a vehicle, and use Focus mode to follow it. The project deliberately tests modern PHP: CakePHP 6 for HTTP/control, AMPHP/Revolt for long-running realtime, and SurrealDB live queries for database-driven event propagation.

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

The desktop introduction is expanded on a first visit but can release its map
column into a labelled `About` edge control. Mobile is map-first and defaults
to the collapsed control; explicit choices persist safely when storage exists.

The complete product behavior is described by `docs/user-stories/`, and the visual inventory is in `docs/design/`.
