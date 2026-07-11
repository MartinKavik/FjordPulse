# ADR 0013 — Demand-driven Vehicle Positions polling with coalesced focus lookups

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

Use demand-driven HTTP GraphQL queries behind
`VehiclePositionsInterface` for v1:

- station watches request a bounded geographic box,
- selected/focused vehicle lookups share one nationwide response cached for two
  seconds inside the single realtime process,
- the in-memory watch registry deduplicates scopes across browser clients,
- focus watches receive higher scheduler priority,
- global and per-service budgets bound all upstream traffic,
- 429 and transport failures enter explicit backoff/error states.

The browser never connects to Entur. Canonical writes still flow through
SurrealDB database events and the one global live-query bridge; changing the
upstream acquisition strategy does not add a second publication path.

## Consequences

This is simpler to supervise and test than a second long-lived WebSocket while
meeting v1 demand-driven freshness requirements. The short nationwide cache
prevents several simultaneously due vehicle scopes from spending one upstream
request each, while station boxes remain bounded. A future multiplexed Entur
subscription adapter may replace it without changing repositories, realtime
rooms, or browser contracts, after reconnect and dynamic-scope soak tests pass.
