# 07_desktop_vehicle_selected: Desktop vehicle selected

**Image:** `07_desktop_vehicle_selected.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** A live vehicle is selected but Focus/follow mode is not active yet.

## Why this screen matters

This is the transition from station exploration to vehicle-focused realtime tracking.

## Key visual elements

- Selected vehicle marker and short trail are visible on the map.
- Right panel shows Line 100 details.
- Primary action is Focus.
- Next stop and recent trail preview are visible.

## Implementation notes

- Vehicle selection and Focus mode should be separate states.
- Clicking Focus should create/upgrade a focused vehicle watch.
- Trail should be based on observed positions, not fabricated route history.
- Show last seen and delay prominently.

## Suggested visual/regression scenarios

- `desktop_vehicle_selected`
- `focus button visible`
- `recent trail preview`
- `vehicle marker highlighted`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
