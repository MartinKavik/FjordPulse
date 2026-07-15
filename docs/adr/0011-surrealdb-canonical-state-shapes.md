# ADR 0011 — Snapshot-oriented canonical state

## Status

Accepted.

## Decision

Use `station_snapshot` and `current_vehicle` as the primary current-state records consumed by UI snapshots and database events.

## Rationale

This reduces realtime event complexity:

- station room receives one versioned station snapshot event,
- vehicle room receives versioned vehicle state events,
- detailed historical observations remain separate,
- frontend can resync without replaying every event.

## Consequence

Fine-grained departure-row events may be added later, but v1 favors robust snapshots and semantic hashes over many small event types.

Snapshot denormalization does not make cross-table identity application-only.
The database also stores typed record links while retaining the stable Entur
string fields used by HTTP, WebSocket, logging, and routing contracts:

- `station_snapshot.station -> station` and
  `station_timetable.station -> station` cascade when the station is deleted;
- `vehicle_observation.vehicle -> current_vehicle` cascades when its current
  vehicle is deleted;
- `current_vehicle.journey -> journey_snapshot` is optional and is unset when
  an expiring/rebuilt journey snapshot is deleted. The compact
  `journey_reference` remains available for a later refresh. A schema-level
  existence guard converts a missing target to `NONE` during backfill and normal
  writes, preventing a later vehicle refresh from resurrecting a deleted cache link.

Migration 013 backfills these links from the existing deterministic record ids.
SurrealDB field `VALUE` clauses derive every native link from its public identity
field on write, so repositories do not maintain a duplicate graph path. This
gives SurrealDB and Surrealist an inspectable schema, forward traversal,
incoming-reference tracking, and explicit deletion behavior without changing
the canonical realtime publication path.

No `TYPE RELATION` edge table is added. These links express ownership/reference
and carry no independent metadata; duplicating them as graph edges would create
a second representation that could diverge. A relation table is appropriate
only when FjordPulse needs relationship-owned data or multi-hop traversal, for
example ordered dated journey calls. `watch.scope`, `watch.entity_id`,
`realtime_event.scope`, and `realtime_event.entity_id` intentionally remain
strings because they are routing/audit identities that may precede or outlive a
target record.
