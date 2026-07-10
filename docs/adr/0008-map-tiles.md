# ADR 0008 — Map tiles and attribution

## Status

Required spike before production; implementation choice pending.

## Preferred option

Self-host a Norway-focused PMTiles/vector basemap compatible with MapLibre.

## Fallback option

Use a managed MapLibre-compatible tile provider with explicit attribution, key restrictions, and a monitored quota.

## Rejected option

Do not use the public OpenStreetMap tile server as FjordPulse's production tile backend.

## Phase behavior

The visual prototype may use a deterministic simplified basemap. The production tile choice must pass `DEPENDENCY_SPIKES.md`.
