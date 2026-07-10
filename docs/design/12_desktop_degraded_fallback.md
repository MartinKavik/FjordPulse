# 12_desktop_degraded_fallback: Desktop degraded fallback mode

**Image:** `12_desktop_degraded_fallback.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** WebSocket/live updates unavailable; app falls back to periodic refresh.

## Why this screen matters

This proves the app remains useful when realtime transport is unavailable.

## Key visual elements

- Amber Fallback mode chip.
- Map remains usable with station clusters.
- Right panel explains periodic refresh.
- Status cards show Backend OK, WebSocket Offline, Entur OK, Refresh mode Polling.

## Implementation notes

- Realtime transport should be abstracted so UI can switch between WebSocket and polling.
- Do not block station browsing when live mode fails.
- Use clear status labels in top and bottom telemetry.
- The backend health endpoint should distinguish API availability from realtime availability.

## Suggested visual/regression scenarios

- `desktop_degraded_fallback`
- `fallback mode chip`
- `websocket offline card`
- `refresh mode polling`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
