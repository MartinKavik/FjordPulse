# 04_desktop_station_empty: Desktop station selected — no departures

**Image:** `04_desktop_station_empty.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Station selected successfully, but there are no upcoming departures and no nearby live vehicles.

## Why this screen matters

An empty transport result is not an error. This screen prevents ambiguous “nothing happened” UX.

## Key visual elements

- Station panel is live and updated.
- Departures section has a calm empty state.
- Nearby vehicles section has its own empty state.
- Map remains normal with selected station and nearby stations.

## Implementation notes

- Model empty state separately from error state.
- Use wording like “No live vehicles currently reported nearby,” not “No vehicles exist.”
- Keep live status green if the request succeeded.
- This state is likely at night or for quiet rural stops.

## Suggested visual/regression scenarios

- `desktop_station_no_departures`
- `empty departure message`
- `empty nearby vehicles message`
- `no error banner`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
