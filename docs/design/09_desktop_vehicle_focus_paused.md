# 09_desktop_vehicle_focus_paused: Desktop vehicle Focus — paused by user

**Image:** `09_desktop_vehicle_focus_paused.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Follow mode is paused because the user manually moved the map.

## Why this screen matters

This prevents the app from fighting the user. It is an important UX refinement for map-follow behavior.

## Key visual elements

- Amber “Follow paused” pill.
- Map still shows selected vehicle and trail, but vehicle is not forcibly centered.
- Resume and Unfocus actions are available.
- Right panel uses Resume follow as primary action.

## Implementation notes

- Detect manual pan/zoom while following and transition to paused state.
- Continue receiving vehicle updates while paused.
- Resume returns the map to following behavior.
- This state should not be treated as an error.

## Suggested visual/regression scenarios

- `desktop_vehicle_focus_paused`
- `resume button visible`
- `unfocus button visible`
- `amber paused status`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
