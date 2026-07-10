# ADR 0004 — SurrealDB strategy

## Status

Accepted as experiment.

## Decision

Use SurrealDB as the primary v1 database, but isolate it behind repositories/adapters.

## Rationale

SurrealDB is aligned with the experimental goal and may support live-query/event-oriented workflows. However, the PHP SDK v2 alpha may be unstable or blocking, so the realtime hot path must be tested carefully.

## Rules

- Do not let raw SurrealDB response shapes leak deep into domain/UI boundaries.
- Use typed DTOs for app-facing data.
- Use migrations.
- Keep a future path to PostgreSQL possible through repository interfaces.

## Risk

If SurrealDB SDK v2 alpha blocks the AMPHP loop, use a direct AMPHP HTTP/WebSocket adapter in the realtime process and keep the SDK for migrations/admin/testing only.
