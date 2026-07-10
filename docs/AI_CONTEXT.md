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

The complete product behavior is described by `docs/user-stories/`, and the visual inventory is in `docs/design/`.
