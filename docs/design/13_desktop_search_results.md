# 13_desktop_search_results: Desktop search results

**Image:** `13_desktop_search_results.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Search overlay open for query “førde” with station/place/route results.

## Why this screen matters

Search is the fastest way to jump to stations and routes, especially from country-level map.

## Key visual elements

- Expanded search input with query “førde”.
- Dropdown with Førde rutebilstasjon, Førde ferjekai, Førde sentrum, Line 100.
- First row has keyboard focus highlight.
- Map behind is dimmed with Førde/Nordfjord area highlighted.

## Implementation notes

- Search overlay should support keyboard navigation and Enter selection.
- Result types need icons and metadata.
- Selecting a station should pan/zoom and open station panel.
- Selecting a route can show route-related stations/vehicles later.

## Suggested visual/regression scenarios

- `desktop_search_results`
- `query førde visible`
- `four result rows`
- `first result highlighted`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
