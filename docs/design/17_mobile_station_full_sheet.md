# 17_mobile_station_full_sheet: Mobile station full sheet

**Image:** `17_mobile_station_full_sheet.png`  
**Category:** Mobile app  
**Packaged dimensions:** 1024 × 1792 px  
**State represented:** Expanded mobile station sheet with the same non-overlapping Departures, Vehicles, and Details allocation as desktop.

## Why this screen matters

Defines the information-dense mobile station details view.

## Key visual elements

- Only a map strip remains at top.
- Station title, live metadata, close/back control.
- Tabs: Departures, Vehicles, Details. Departures and Vehicles carry compact authoritative counts.
- Departures shows only the board, including platform when reported. Vehicles distinguishes dated-service matches from other positions within the reported nearby radius and keeps exact coverage diagnostics collapsed. Details prioritizes place/type/modes and plain-language scope, with Stop ID, coordinates, and timezone collapsed.

## Implementation notes

- Use the same data/resources as desktop station panel.
- Full sheet should be accessible and scrollable.
- Tabs should preserve loaded data and avoid unnecessary refetches.
- Loading and error content is scoped to the active transport tab; stable Details content remains available with a compact live-content Retry notice on failure.
- Close returns to map with station selected or clears selection depending on UX decision.
- Vehicle rows retain mode/relation labels and call time on up to two lines at mobile widths, with last-seen age as secondary metadata; a tap opens the ordinary vehicle sheet and selected marker.

## Suggested visual/regression scenarios

- `mobile_station_full_sheet`
- `tabs visible`
- `Departures list and platforms visible without vehicle duplication`
- `Vehicles groups with collapsed coverage disclosure`
- `Details facts with collapsed technical disclosure`
- `close control visible`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
