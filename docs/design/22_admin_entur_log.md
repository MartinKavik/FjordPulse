# 22_admin_entur_log: Admin Entur request log

**Image:** `22_admin_entur_log.png`  
**Category:** Admin/dev  
**Packaged dimensions:** 1920 × 1080 px  
**State represented:** Admin request log for Entur API usage, cache behavior, latency, and backoff.

## Why this screen matters

This is important for protecting the public API and debugging slow/stale transport data.

## Key visual elements

- The complete `Internal Entur request limit` / `Intern grense for Entur-kall`
  card, including global headroom, rolling-window semantics, optional backoff,
  every per-service cap, exact `ENTUR_*_REQUESTS_PER_MINUTE` configuration name,
  and official provider documentation.
- Top cards for requests/min, cache hit rate, p95 latency, backoff state.
- Filters for API type, status, scope, time range.
- Table includes Journey Planner and Vehicle Positions rows.
- Backoff row shows retry timing.

## Implementation notes

- Every Entur request should produce a structured log entry.
- Rate limiting and backoff should be visible here.
- Cache hit/miss matters for validating that the app is not overfetching.
- Use this page to tune request budgets.
- System status delegates allowance detail here instead of duplicating a large
  configuration table. FjordPulse's configured shared/per-service rolling
  limits are an internal safeguard and are distinct from provider-side Entur
  quotas; never present the internal value as an Entur account balance.
- The page loads request history and the protected status allowance in parallel
  and combines them only in the frontend. Both remain typed same-origin
  FjordPulse APIs; the browser never calls Entur.

## Suggested visual/regression scenarios

- `admin_entur_log`
- `request log table`
- `backoff row`
- `cache hit rate card`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
