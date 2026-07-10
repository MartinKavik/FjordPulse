# 02_desktop_station_fresh: Desktop station selected — fresh data

**Image:** `02_desktop_station_fresh.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Selected station with live departures and nearby vehicles loaded.

## Why this screen matters

This is the core happy path: click a station, see departures and nearby vehicles. It defines the most important right-panel layout.

## Key visual elements

- Selected “Førde rutebilstasjon” marker is prominent on the map.
- Departures tab is active.
- Rows include on-time, delayed, and scheduled statuses.
- Nearby vehicles list appears under departures.

## Implementation notes

- Represent station details, departures, and nearby vehicles as separate resources so one can refresh/fail independently.
- Use stable row components for departures and nearby vehicles.
- Station watch should be registered when the panel opens.
- Vehicle rows should be clickable and should open the vehicle panel.

## Suggested visual/regression scenarios

- `desktop_station_fresh_departures`
- `selected station marker`
- `departure rows visible`
- `nearby vehicle rows visible`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
