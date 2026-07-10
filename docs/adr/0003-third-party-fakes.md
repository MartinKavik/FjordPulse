# ADR 0003 — Fake services before real Entur

## Status

Accepted.

## Decision

Before real Entur integration, create fake services that implement the same interfaces as real Entur clients.

## Rationale

This allows UI, realtime, backend state, admin pages, visual tests, and black-box stories to be validated deterministically.

## Rules

- Fake services are allowed only in local/dev/test.
- Fake service interfaces must match real service interfaces.
- Production must never use fake transport data.
- Frontend must not know whether data came from fake or real service.

## Fake scenarios

```text
normal
station_empty
station_stale
station_error
vehicle_live
vehicle_stale
vehicle_lost
fallback
entur_backoff
```
