# 16_mobile_station_sheet: Mobile station half sheet

**Image:** `16_mobile_station_sheet.png`  
**Category:** Mobile app  
**Packaged dimensions:** 1024 × 1792 px  
**State represented:** Station selected on mobile with a half-height bottom sheet showing the count-badged departure board without duplicated vehicle lists.

## Why this screen matters

This is the primary mobile station interaction and should remain readable while preserving map context.

## Key visual elements

- Map remains visible behind bottom sheet.
- Selected station marker is visible above sheet.
- Bottom sheet has header, updated time, Departures and Vehicles count badges, Details tab, departure rows, and no redundant healthy `Live` badge.
- Departures owns only time, line, destination, platform when reported, and status rows. Vehicles owns station-serving and other-nearby groups; Details owns stable facts and collapsed technical identifiers.

## Implementation notes

- Bottom sheet should support collapsed/half/full states.
- Use large row tap targets.
- Keep selected station marker visible when possible.
- The sheet should drag/expand without losing state.
- Switching tabs must not refetch or duplicate already loaded resources. Loading/error copy belongs to the affected transport tab, while known Details facts remain usable.
- A station-serving row may represent a far-away vehicle; tapping it uses the same selected-vehicle marker/pan behavior as desktop without reducing the current zoom.

## Suggested visual/regression scenarios

- `mobile_station_sheet`
- `station name visible`
- `count-badged departure rows with platform visible`
- `no station-serving or nearby lists below departures`
- `large tab targets without localized-label overflow`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
