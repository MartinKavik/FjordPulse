# 22_admin_entur_log: Admin Entur request log

**Image:** `22_admin_entur_log.png`  
**Category:** Admin/dev  
**Packaged dimensions:** 1920 × 1080 px  
**State represented:** Admin request log for Entur API usage, cache behavior, latency, and backoff.

## Why this screen matters

This is important for protecting the public API and debugging slow/stale transport data.

## Key visual elements

- Top cards for requests/min, cache hit rate, p95 latency, backoff state.
- Filters for API type, status, scope, time range.
- Table includes Journey Planner and Vehicle Positions rows.
- Backoff row shows retry timing.

## Implementation notes

- Every Entur request should produce a structured log entry.
- Rate limiting and backoff should be visible here.
- Cache hit/miss matters for validating that the app is not overfetching.
- Use this page to tune request budgets.

## Suggested visual/regression scenarios

- `admin_entur_log`
- `request log table`
- `backoff row`
- `cache hit rate card`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
