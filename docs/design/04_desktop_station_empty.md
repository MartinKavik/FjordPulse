# 04_desktop_station_empty: Desktop station selected — no departures

**Image:** `04_desktop_station_empty.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Station selected successfully, but there are no upcoming departures and no nearby live vehicles.

## Why this screen matters

An empty transport result is not an error. This screen prevents ambiguous “nothing happened” UX.

## Key visual elements

- Station panel shows a current updated age without a redundant `Live` badge.
- Departures section has a calm empty state.
- Nearby vehicles section has its own completed empty state in both the Departures and Vehicles views.
- Map remains normal with selected station and nearby stations.

## Implementation notes

- Model empty state separately from error state.
- Use “No nearby vehicles reported” as the heading, not “No vehicles exist.”
- State that no live vehicle positions were found within the 5 km station search radius. Read the radius from the nearby-vehicles response so the copy remains truthful if configuration changes.
- Reuse the same empty-state component in the Departures view's nearby section and the dedicated Vehicles view; neither may render a blank completed list.
- Keep the loading indicator visible only while a request is actually in progress. Refreshing, stale, backoff, rate-limited, and unavailable zero-result states must use their own truthful copy and never say the search is complete.
- Keep the completed empty state calm and current; reserve status colour for exceptional freshness or delivery warnings.
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
