# 16_mobile_station_sheet: Mobile station half sheet

**Image:** `16_mobile_station_sheet.png`  
**Category:** Mobile app  
**Packaged dimensions:** 1024 × 1792 px  
**State represented:** Station selected on mobile with half-height bottom sheet showing key departures.

## Why this screen matters

This is the primary mobile station interaction and should remain readable while preserving map context.

## Key visual elements

- Map remains visible behind bottom sheet.
- Selected station marker is visible above sheet.
- Bottom sheet has header, live badge, updated time, departure rows.
- Nearby vehicles section is secondary/collapsed.

## Implementation notes

- Bottom sheet should support collapsed/half/full states.
- Use large row tap targets.
- Keep selected station marker visible when possible.
- The sheet should drag/expand without losing state.

## Suggested visual/regression scenarios

- `mobile_station_sheet`
- `station name visible`
- `departure rows visible`
- `nearby vehicles collapsed`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
