# ADR 0008 — Map tiles and attribution

## Status

Accepted for local/test and deployment-ready production configuration.

## Production decision

Use a configured MapLibre style backed by a self-hosted Norway-focused
PMTiles/vector basemap. The style URL is deployment configuration and must be
same-origin through the public web service; attribution remains visible.

If a self-hosted dataset is not loaded at first deployment, a managed
MapLibre-compatible provider may be configured only with explicit attribution,
origin-restricted credentials, and a monitored quota.

## Local and test decision

Use the checked-in deterministic GeoJSON MapLibre style. It performs no tile
network requests, renders reliably in Playwright, and is also the safe fallback
when no production style URL is configured.

## Rejected options

- Do not use the public OpenStreetMap tile server as the production backend.
- Do not put provider credentials in browser source or the repository.
- Do not make visual tests depend on external map availability.

## Spike result

MapLibre 5.24.0 initializes against the deterministic style without an external
source and the frontend build/type tests pass. Browser visual tests use the
same path, so their pixels do not depend on a third-party tile service.
