# 08_desktop_vehicle_focus_following: Desktop vehicle Focus — following

**Image:** `08_desktop_vehicle_focus_following.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Focus mode is active and the map follows Line 100.

## Why this screen matters

This is the main realtime demo state and should feel impressive and operational.

## Key visual elements

- Floating green focus pill.
- Selected vehicle marker is the brightest map object.
- Trail dots fade with time.
- Right panel lists delay, next stop, and upcoming stops.
- Bottom telemetry shows realtime connected and vehicle watch active.

## Implementation notes

- Map should pan smoothly to new positions while following.
- Do not constantly reset user zoom after initial focus.
- Focus mode should stop when the user clicks Unfocus or when vehicle is lost.
- Use throttled map animation to avoid jitter.

## Suggested visual/regression scenarios

- `desktop_vehicle_focus_following`
- `focus pill visible`
- `vehicle watch active`
- `upcoming stops visible`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
