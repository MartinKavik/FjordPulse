# ADR 0008 — Map tiles and attribution

## Status

Accepted for the managed v1 basemap and deterministic test rendering.

## Production decision

Use MapTiler through MapLibre GL JS with two fixed versioned styles:

- `hybrid-v4` satellite imagery with labels is the first-visit default;
- `streets-v4` is the user-selectable ordinary map.

The operator supplies a dedicated read-only browser key through
`MAPTILER_API_KEY`. CakePHP publishes fixed provider/style URLs through
`/api/map/config`; visitors never enter credentials. Production keys must be
restricted to the FjordPulse HTTPS origin, attribution remains visible, and
provider quota is monitored by the operator.

A self-hosted Norway-focused PMTiles/vector basemap remains a possible future
provider migration, not a runtime fallback.

## Local and test decision

Fixture and visual-test routes use the checked-in deterministic GeoJSON style.
It performs no tile network requests and renders reliably in Playwright. Normal
local and production routes never use it as a fallback: missing configuration,
provider errors, or loading timeouts produce an explicit user-visible map
service state with Retry.

## Rejected options

- Do not use the public OpenStreetMap tile server as the production backend.
- Do not put provider credentials in browser source or the repository.
- Do not make visual tests depend on external map availability.
- Do not silently replace failed imagery with a different provider or the
  deterministic fixture map.

## Spike result

MapLibre 5.24.0 supports runtime style replacement. FjordPulse owns its
transport source/layers separately and reinstalls them after every provider
`style.load`, preserving camera and selected transport state. Browser tests
intercept deterministic provider styles and tiles; reviewed visual pixels do
not depend on MapTiler availability.
