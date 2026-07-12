# 04_desktop_station_empty: Desktop station selected — no departures

**Image:** `04_desktop_station_empty.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Station selected successfully, with independently empty Departures and Vehicles tabs and stable station facts still available under Details.

## Why this screen matters

An empty transport result is not an error. This screen prevents ambiguous “nothing happened” UX.

## Key visual elements

- Station panel shows a current updated age without a redundant `Live` badge.
- Departures shows a zero badge and one calm no-upcoming-departures state, without vehicle empty cards below it.
- Vehicles shows a zero unique-vehicle badge and separate completed station-serving and other-nearby empty states.
- Details still shows station type, place, modes, and plain-language data scope; technical ID, coordinates, and timezone remain collapsed.
- Map remains normal with selected station and nearby stations.

## Implementation notes

- Model empty state separately from error state.
- Use “No station-serving vehicle reported now” and “No nearby vehicles reported” as scoped headings, never “No vehicles exist.”
- Explain that the first result means no currently reporting position matched dated services in the reported ±6-hour window. It does not mean the station has no scheduled service, and it is not proof that every vehicle in Norway was searched.
- State that the second result found no live vehicle position within the 5 km station search radius. Read the radius from the nearby-vehicles response so the copy remains truthful if configuration changes.
- Render both vehicle empty-state components only in Vehicles. Departures owns only its departure-board empty state, so the three results are explicit without being duplicated.
- Keep the loading indicator visible only while a request is actually in progress. Refreshing, stale, backoff, rate-limited, and unavailable zero-result states must use their own truthful copy and never say the search is complete.
- Scope loading/error presentation to Departures or Vehicles and keep known Details facts usable.
- Keep the completed empty state calm and current; reserve status colour for exceptional freshness or delivery warnings.
- This state is likely at night or for quiet rural stops.

## Suggested visual/regression scenarios

- `desktop_station_no_departures`
- `empty departure message`
- `Vehicles zero badge and empty station-serving message`
- `Vehicles empty nearby message with reported radius`
- `Details facts survive transport empty/error states`
- `no error banner`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
