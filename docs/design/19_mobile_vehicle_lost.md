# 19_mobile_vehicle_lost: Mobile vehicle lost

**Image:** `19_mobile_vehicle_lost.png`  
**Category:** Mobile app  
**Packaged dimensions:** 1024 × 1792 px  
**State represented:** Mobile lost-vehicle state with warning card and recovery actions.

## Why this screen matters

Defines mobile handling of the most important failure state in Focus mode.

## Key visual elements

- Faded last known vehicle marker.
- Bottom sheet shows Lost badge and warning.
- Buttons: Stop following and Try again.
- Last known location and heading are visible.

## Implementation notes

- Lost state should not auto-close the sheet.
- Try again should attempt reacquisition around last known location.
- Stop following should expire the focus watch.
- Keep wording clear and non-alarming.

## Suggested visual/regression scenarios

- `mobile_vehicle_lost`
- `lost badge`
- `try again button`
- `last known location`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
