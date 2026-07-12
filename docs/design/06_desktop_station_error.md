# 06_desktop_station_error: Desktop station selected — error

**Image:** `06_desktop_station_error.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** A departure or vehicle resource for the selected station failed while stable station Details remain available.

## Why this screen matters

Errors should be contained inside the relevant panel while leaving the map and rest of app usable.

## Key visual elements

- Selected station marker remains visible.
- The affected Departures or Vehicles tab includes one concise error card.
- Retry and Close panel actions are prominent.
- The panel does not repeat disabled departure and vehicle sections together, and Details remains usable for known station facts.

## Implementation notes

- Implement retry as a scoped station-resource retry, not a full app reload.
- Keep station selection until user closes it.
- Show technical reason in admin/logs, but user-facing text should be concise.
- Error format should be shared between HTTP and WebSocket messages.
- Keep technical Stop ID, coordinates, and timezone inside the ordinary collapsed Details disclosure, not inside the error card.

## Suggested visual/regression scenarios

- `desktop_station_error`
- `retry button visible`
- `close panel button visible`
- `Details facts remain usable`
- `map still usable`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
