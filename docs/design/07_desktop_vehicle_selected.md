# 07_desktop_vehicle_selected: Desktop vehicle selected

**Image:** `07_desktop_vehicle_selected.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** A live vehicle is selected but Focus/follow mode is not active yet.

## Why this screen matters

This is the transition from station exploration to vehicle-focused realtime tracking.

## Key visual elements

- Selected vehicle marker and short trail are visible on the map.
- Right panel identifies the authoritative vehicle mode (for example Bus, Ferry, or Train) and shows Line 100 details.
- Primary action is Focus.
- Previous stop, next stop, and recent trail preview are visible when journey progress is available.

## Implementation notes

- Vehicle selection and Focus mode should be separate states.
- Clicking Focus should create/upgrade a focused vehicle watch.
- Trail should be based on observed positions, not fabricated route history.
- Show last seen and delay prominently.
- Bottom-anchor the selected pin so its tip is the reported map coordinate. Keep one unrotated mode/line label outside the pin footprint; do not stack a second MapLibre label underneath the DOM marker.
- Use one shared horizontal axis for the Journey progress rail and every ordinary/current stop circle, so marker-size changes cannot move their centres off the rail.
- Use Entur Vehicle Positions mode as the type source; label an unrecognised/missing mode as generic Vehicle rather than inferring it from a line or station.
- Derive Previous stop from the ordered authoritative journey calls and matched monitored/next call. Show `Not available` when progress is insufficient; do not substitute the raw compass bearing as rider-facing context.
- Treat backend-authored passenger-service state separately from position freshness. For `non_passenger`, keep the selected pin, trail, Last seen, and Focus action, but replace line/route/delay/stop content with `Not in passenger service` and a concise operational-movement explanation.

## Suggested visual/regression scenarios

- `desktop_vehicle_selected`
- `focus button visible`
- `recent trail preview`
- `authoritative vehicle mode and previous stop`
- `vehicle marker highlighted`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
