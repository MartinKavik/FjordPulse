# 14_desktop_search_empty: Desktop search empty

**Image:** `14_desktop_search_empty.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Search overlay open for query “xyzabc” with no results.

## Why this screen matters

No-results search should feel calm and recoverable, not like a data failure.

## Key visual elements

- Search input active with query.
- Dropdown panel shows “No stations found.”
- Search tips are shown.
- Map remains visible/dimmed behind the overlay.

## Implementation notes

- No results is not an error.
- Keep previous map/panel state while search is open.
- Search should debounce backend requests.
- Search should eventually support station, place, line, and known vehicle results.

## Suggested visual/regression scenarios

- `desktop_search_empty`
- `no stations found text`
- `search tips visible`
- `no red error state`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
