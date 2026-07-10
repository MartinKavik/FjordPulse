# ADR 0013 — Bounded demand-driven Vehicle Positions queries

## Status

Accepted for v1.

## Context

The Entur Vehicle Positions v2 service exposes both bounded HTTP GraphQL
queries and a `graphql-transport-ws` subscription endpoint. The dependency
spike proved that the subscription endpoint accepts an identified backend
connection and delivers live events. A single successful subscription does not
by itself prove reliable dynamic multiplexing, scope removal, or recovery over
the lifetime of an alpha PHP client stack.

## Decision

Use bounded, demand-driven HTTP GraphQL queries behind
`VehiclePositionsInterface` for v1:

- station watches request a bounded geographic box,
- focused vehicles request an exact vehicle id,
- the in-memory watch registry deduplicates scopes across browser clients,
- focus watches receive higher scheduler priority,
- global and per-service budgets bound all upstream traffic,
- 429 and transport failures enter explicit backoff/error states.

The browser never connects to Entur. Canonical writes still flow through
SurrealDB database events and the one global live-query bridge; changing the
upstream acquisition strategy does not add a second publication path.

## Consequences

This is simpler to supervise and test than a second long-lived WebSocket while
meeting v1 demand-driven freshness requirements. A future multiplexed Entur
subscription adapter may replace it without changing repositories, realtime
rooms, or browser contracts, after reconnect and dynamic-scope soak tests pass.
