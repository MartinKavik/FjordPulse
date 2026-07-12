# 11_desktop_vehicle_lost: Desktop vehicle lost

**Image:** `11_desktop_vehicle_lost.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** No sufficiently recent position is available after a prolonged upstream reporting gap.

## Why this screen matters

Position unavailable follows stale, but the watch remains active and can recover automatically when authoritative reporting resumes.

## Key visual elements

- Red/grey Position unavailable badge.
- Clear explanatory message that distinguishes a transport-feed gap from an application connection failure.
- Buttons: Stop following and Try again.
- Last known location is dimmed on the map.

## Implementation notes

- Do not instantly remove a focused vehicle when one refresh omits it.
- Keep the position stale until the last authoritative observation is more than five minutes old; only then enter position unavailable.
- The source lookup is nationwide, so never claim that the vehicle left a watched area.
- Keep checking automatically and return the same open focus watch to live when Entur supplies a new observation; Try again requests an additional bounded refresh.
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
