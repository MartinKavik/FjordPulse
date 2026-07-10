# ADR 0005 — Entur data sources

## Status

Accepted.

## Decision

Use four separate Entur adapters:

```text
Stop Place Register:
  authoritative station/infrastructure import

Geocoder v3:
  autocomplete and place lookup

Journey Planner v3:
  departures

Vehicle Positions:
  live vehicles
```

## Rationale

The station-first map requires local station data before users select a station. Journey Planner and Vehicle Positions alone are not the correct bulk station source.

## Rules

- all calls backend-only,
- every call has `ET-Client-Name`,
- local station search is primary after import,
- Geocoder enriches autocomplete/place search,
- imported station data has a refresh/import timestamp.
