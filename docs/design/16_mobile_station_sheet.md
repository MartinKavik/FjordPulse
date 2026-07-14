# 16_mobile_station_sheet: Mobile station half sheet

**Image:** `16_mobile_station_sheet.png`  
**Category:** Mobile app  
**Packaged dimensions:** 1024 × 1792 px  
**State represented:** Station selected on mobile with a half-height bottom sheet showing a compact departure preview without duplicated vehicle lists or incomparable tab totals.

## Why this screen matters

This is the primary mobile station interaction and should remain readable while preserving map context.

## Key visual elements

- Map remains visible behind bottom sheet.
- Selected station marker is visible above sheet.
- The grabber remains visible immediately below the top bar in every sheet state; its visual bar may be small, but its touch target is at least 44 × 44 px.
- Bottom sheet has header, updated time, Departures, Vehicles, and Details tabs without aggregate count badges, departure rows, and no redundant healthy `Live` badge.
- Departures owns only time, line, destination, platform when reported, and status rows. Vehicles owns station-serving and other-nearby groups; Details owns stable facts and collapsed technical identifiers.

## Implementation notes

- Bottom sheet supports three explicit mobile snap states: peek, half, and full. Peek leaves a compact station identity and the grabber available while returning most of the viewport to the map; it is not a closed or deselected state.
- Use large row tap targets.
- Keep selected station marker visible when possible.
- Dragging the grabber upward advances toward half/full and dragging it downward retreats toward half/peek. A tap, Enter, or Space expands peek to half and toggles half/full; the grabber never closes the sheet.
- Only the explicit X control closes the sheet and clears the selection/watch. Moving among peek, half, and full preserves the selected station, its watch, the active tab, loaded data, and relevant scroll state.
- Switching tabs must not refetch or duplicate already loaded resources. Loading/error copy belongs to the affected transport tab, while known Details facts remain usable.
- A station-serving row may represent a far-away vehicle; tapping it uses the same selected-vehicle marker/pan behavior as desktop without reducing the current zoom.

## Suggested visual/regression scenarios

- `mobile_station_sheet`
- `station name visible`
- `compact departure rows with platform visible`
- `no station-serving or nearby lists below departures`
- `large tab targets without localized-label overflow`
- `grabber visible with map context preserved`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
