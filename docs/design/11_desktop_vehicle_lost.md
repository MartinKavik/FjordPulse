# 11_desktop_vehicle_lost: Desktop vehicle lost

**Image:** `11_desktop_vehicle_lost.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** The watched vehicle is no longer reported in the watched area.

## Why this screen matters

Lost is the terminal state after stale, and it must be honest without looking catastrophic.

## Key visual elements

- Red/grey Lost badge.
- Clear explanatory message.
- Buttons: Stop following and Try again.
- Last known location is dimmed on the map.

## Implementation notes

- Do not instantly remove a focused vehicle when one refresh fails.
- Only enter lost after stale timeout or repeated misses.
- Try again should reacquire using last known area.
- Stop following should expire the watch and close focus state.

## Suggested visual/regression scenarios

- `desktop_vehicle_lost`
- `lost badge visible`
- `try again button`
- `stop following button`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
