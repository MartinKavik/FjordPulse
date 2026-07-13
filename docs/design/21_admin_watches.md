# 21_admin_watches: Admin active watches

**Image:** `21_admin_watches.png`  
**Category:** Admin/dev  
**Packaged dimensions:** 1920 × 1080 px  
**State represented:** Admin table of active station/vehicle/focus watches with priority and refresh scheduling.

## Why this screen matters

This screen makes the demand-driven architecture visible and debuggable.

## Key visual elements

- Cards for current lifecycle rows, focus watches, expiring soon, and failed watches. Zero-client grace rows are diagnostics, not active demand.
- Table columns: Type, Scope, Clients, Priority, Last refresh, Next refresh, State.
- Rows include active and stale watches.

## Implementation notes

- Watches should exist as first-class backend records or in-memory state reflected in admin output.
- Use this page to verify cleanup of expired watches. Past-expiry rows are omitted, and a zero-client grace row is labelled expiring rather than active.
- Priority and next-refresh columns should match scheduler logic.
- This page should expose why Entur requests are being made.
- The System status active-watch totals count only rows with at least one persisted client, a future expiry, and a non-expired state. On startup, the v1 realtime replica prunes past-expiry previous-process rows but retains still-valid leases so an overlapping process cannot have its demand erased; a crash-era lease ages out within the configured TTL, and reconnecting browsers register their scopes again.

## Suggested visual/regression scenarios

- `admin_watches`
- `active watches table`
- `priority column`
- `stale watch row`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
