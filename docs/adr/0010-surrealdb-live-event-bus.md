# ADR 0010 — SurrealDB live queries are the primary realtime publication path

## Status

Accepted for the v1 experiment.

## Decision

Canonical state updates create compact `realtime_event` records through SurrealDB `DEFINE EVENT`. A dedicated PHP `Runtime::amp()` connection consumes one global `LIVE SELECT` and broadcasts validated events to browser rooms.

Do not directly broadcast immediately after writes. Avoid two competing publication paths.

## Rationale

- Demonstrates SurrealDB's realtime specialty.
- Keeps the browser behind PHP security/contracts.
- Avoids Redis or another broker.
- Makes canonical state and event creation transactional.
- Gives operator-visible durable event diagnostics.

## Reliability model

- Current state tables are authoritative.
- Clients receive snapshots on join/reconnect.
- Events are versioned, idempotent notifications.
- Live bridge failure degrades to HTTP polling.
- Application supervises and recreates unmanaged live queries after reconnect.

## Consequences

- One realtime replica in v1.
- SurrealDB availability affects realtime publication.
- Event table requires retention cleanup.
- SDK alpha behavior must be integration-tested and isolated behind adapters.
