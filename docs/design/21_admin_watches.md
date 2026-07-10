# 21_admin_watches: Admin active watches

**Image:** `21_admin_watches.png`  
**Category:** Admin/dev  
**Packaged dimensions:** 1920 × 1080 px  
**State represented:** Admin table of active station/vehicle/focus watches with priority and refresh scheduling.

## Why this screen matters

This screen makes the demand-driven architecture visible and debuggable.

## Key visual elements

- Cards for total watches, focus watches, expiring soon, failed watches.
- Table columns: Type, Scope, Clients, Priority, Last refresh, Next refresh, State.
- Rows include active and stale watches.

## Implementation notes

- Watches should exist as first-class backend records or in-memory state reflected in admin output.
- Use this page to verify cleanup of expired watches.
- Priority and next-refresh columns should match scheduler logic.
- This page should expose why Entur requests are being made.

## Suggested visual/regression scenarios

- `admin_watches`
- `active watches table`
- `priority column`
- `stale watch row`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
