# 17_mobile_station_full_sheet: Mobile station full sheet

**Image:** `17_mobile_station_full_sheet.png`  
**Category:** Mobile app  
**Packaged dimensions:** 1024 × 1792 px  
**State represented:** Expanded mobile station sheet with departures tab and nearby vehicles preview.

## Why this screen matters

Defines the information-dense mobile station details view.

## Key visual elements

- Only a map strip remains at top.
- Station title, live metadata, close/back control.
- Tabs: Departures, Vehicles, Info.
- Large departure rows and nearby vehicle preview.

## Implementation notes

- Use the same data/resources as desktop station panel.
- Full sheet should be accessible and scrollable.
- Tabs should preserve loaded data and avoid unnecessary refetches.
- Close returns to map with station selected or clears selection depending on UX decision.

## Suggested visual/regression scenarios

- `mobile_station_full_sheet`
- `tabs visible`
- `departure list visible`
- `close control visible`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
