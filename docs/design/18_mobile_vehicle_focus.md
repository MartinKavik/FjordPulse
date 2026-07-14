# 18_mobile_vehicle_focus: Mobile vehicle Focus

**Image:** `18_mobile_vehicle_focus.png`  
**Category:** Mobile app  
**Packaged dimensions:** 1024 × 1792 px  
**State represented:** Mobile Focus mode follows Line 100 with a collapsed/half vehicle sheet.

## Why this screen matters

This is the strongest mobile realtime demo state.

## Key visual elements

- Green following pill at top of map.
- Vehicle marker centered with route/trail.
- Bottom sheet shows Line 100, last seen, delay, Unfocus and Details actions.
- The sheet grabber remains visible below the top bar in peek, half, and full states and provides at least a 44 × 44 px touch target.
- Bottom navigation remains accessible.

## Implementation notes

- Focus follow behavior should be shared with desktop but adapted for touch.
- Use smooth map pan and keep bottom sheet from hiding vehicle if possible.
- Dragging the grabber upward advances peek → half → full; dragging downward retreats full → half → peek. Tap, Enter, and Space expand peek to half and toggle half/full. The grabber never closes the vehicle detail.
- Peek returns most of the viewport to the map while keeping a compact vehicle identity and the grabber reachable. Every snap-state transition preserves the selected vehicle, live watch, Focus state, loaded journey, and current detail context.
- Only the explicit X control closes the vehicle sheet and clears its selection/watch. Minimizing the sheet is distinct from Unfocus, which changes camera following without discarding the selected vehicle.
- Pause/Unfocus should be easy to reach.
- Delay/last seen should update live.
- When the backend reports `non_passenger`, keep the focused marker and reachable Pause/Unfocus controls, label the state `Not in passenger service`, and suppress operational line, delay, and stop details without overflowing the narrow sheet.

## Suggested visual/regression scenarios

- `mobile_vehicle_focus`
- `following pill visible`
- `unfocus button`
- `vehicle marker centered`
- `peek/half/full without losing live focus context`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
