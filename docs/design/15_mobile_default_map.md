# 15_mobile_default_map: Mobile default map

**Image:** `15_mobile_default_map.png`  
**Category:** Mobile app  
**Packaged dimensions:** 1024 × 1792 px  
**State represented:** Mobile map with station clusters loaded and no station selected.

## Why this screen matters

Defines mobile-first browsing behavior and bottom navigation baseline.

## Key visual elements

- Full-screen map.
- Compact top bar with logo/search/live dot.
- Station clusters shown.
- Bottom nav with Map, Search, Saved, Alerts, Menu.
- Collapsed bottom sheet handle.

## Implementation notes

- Mobile should use bottom sheets instead of desktop side panels.
- The app should be usable with one thumb and large touch targets.
- Initial mobile state should not overwhelm with panels.
- Clusters are local/backend data, not per-user Entur vehicle fetches.

## Suggested visual/regression scenarios

- `mobile_default_map`
- `bottom nav visible`
- `clusters visible`
- `no station sheet open`

## Notes and caveats

- This image is a deterministic/static mockup reference and is suitable for guiding the first SolidJS prototype.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
