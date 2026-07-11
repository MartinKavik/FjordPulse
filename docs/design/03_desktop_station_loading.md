# 03_desktop_station_loading: Desktop station selected — loading

**Image:** `03_desktop_station_loading.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** A station was clicked and the app is registering a live watch while fetching details.

## Why this screen matters

Loading must feel intentional, not broken. This screen defines skeleton usage and transient watch-registration state.

## Key visual elements

- Selected station marker is already visible.
- Right panel header shows the station name.
- Skeleton blocks replace details, departures, and nearby vehicles.
- The station panel owns ordinary loading and skeletons; global chrome appears only for delivery disruption, using the canonical contextual notice copy.

## Implementation notes

- Keep the map interactive while the side panel loads.
- Do not blank the map or replace the whole page with a spinner.
- Use skeleton components instead of random loading spinners in list areas.
- If station metadata is cached, show name immediately and skeleton only missing sections.

## Suggested visual/regression scenarios

- `desktop_station_loading`
- `skeleton rows visible`
- `telemetry pending state`
- `selected station marker visible`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
