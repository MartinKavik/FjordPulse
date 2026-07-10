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
