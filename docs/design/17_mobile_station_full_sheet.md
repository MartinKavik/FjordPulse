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
- The grabber stays visible directly below the top bar and remains a 44 × 44 px or larger touch target even when the sheet is full.
- Tabs: Departures, Vehicles, Details, without aggregate badges that compare timetable rows with live positions.
- Departures shows the bounded preview plus an explicit full-day timetable action, including platform when reported. Vehicles separates due within 60 minutes, later calls, already-passed calls, and non-overlapping positions within the reported nearby radius; exact coverage diagnostics stay collapsed. Details prioritizes place/type/modes and plain-language scope, with Stop ID, coordinates, and timezone collapsed.

## Implementation notes

- Use the same data/resources as desktop station panel.
- Full sheet should be accessible and scrollable.
- The full state is the upper snap point of the same peek/half/full sheet. Dragging down returns to half and then peek; tapping the grabber or activating it with Enter/Space returns full to half. These actions reveal more map without dismissing the station.
- Tabs should preserve loaded data and avoid unnecessary refetches.
- Loading and error content is scoped to the active transport tab; stable Details content remains available with a compact live-content Retry notice on failure.
- Sheet-state changes preserve the selected station, active watch, active tab, loaded data, and relevant scroll state. Only the explicit X control closes the sheet and clears the station selection/watch; dragging the grabber never closes it.
- Vehicle rows retain mode, call role/progress, and station call time on up to two lines at mobile widths, with last-seen age as secondary metadata; a tap opens the ordinary vehicle sheet and selected marker.

## Suggested visual/regression scenarios

- `mobile_station_full_sheet`
- `tabs visible`
- `Departures list and platforms visible without vehicle duplication`
- `Vehicles groups with collapsed coverage disclosure`
- `Details facts with collapsed technical disclosure`
- `close control visible`
- `drag down restores half/peek map context without clearing selection`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
