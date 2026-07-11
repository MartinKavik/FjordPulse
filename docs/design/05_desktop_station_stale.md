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
- The station panel explains that last-known data remains visible. If delivery is disrupted, one contextual notice uses `Reconnecting to live updates…`, `Live connection interrupted · Updating periodically`, or `Updates temporarily unavailable · Showing saved information` as applicable.

## Implementation notes

- Use stale state with preserved previous data, not an empty/error panel.
- Public copy should explain the rider effect; component-level Backend, WebSocket, and Entur diagnostics remain on Admin status.
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
