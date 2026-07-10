# 06_desktop_station_error: Desktop station selected — error

**Image:** `06_desktop_station_error.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** The selected station failed to load details due to an Entur/backend error.

## Why this screen matters

Errors should be contained inside the relevant panel while leaving the map and rest of app usable.

## Key visual elements

- Selected station marker remains visible.
- Right panel includes red error card.
- Retry and Close panel actions are prominent.
- Departures and nearby vehicles sections are collapsed/disabled.

## Implementation notes

- Implement retry as a scoped station retry, not a full app reload.
- Keep station selection until user closes it.
- Show technical reason in admin/logs, but user-facing text should be concise.
- Error format should be shared between HTTP and WebSocket messages.

## Suggested visual/regression scenarios

- `desktop_station_error`
- `retry button visible`
- `close panel button visible`
- `map still usable`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
