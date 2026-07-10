# 05_desktop_station_stale: Desktop station selected — stale data

**Image:** `05_desktop_station_stale.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Station panel shows last known data while realtime or Entur refresh is delayed.

## Why this screen matters

Stale data is a normal operational state in realtime systems. It should be clear, recoverable, and non-fatal.

## Key visual elements

- Amber “Live delayed”/“Stale” status.
- Warning banner says last known data is being shown.
- Rows remain visible but muted.
- Bottom telemetry explains backend OK, realtime reconnecting, Entur delayed.

## Implementation notes

- Use stale state with preserved previous data, not an empty/error panel.
- Telemetry should explain whether the source is Entur, WebSocket, or backend.
- Stale vehicle markers should fade but remain visible.
- This is a key screenshot for visual regression testing.

## Suggested visual/regression scenarios

- `desktop_station_stale`
- `stale warning banner`
- `muted previous data`
- `amber telemetry`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
