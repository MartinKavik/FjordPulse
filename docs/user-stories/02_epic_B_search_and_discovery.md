# Epic B — Search and discovery

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-009 — Open search

**User story:** As a public user, I want to open search from the top bar or nav, so that I can quickly find a station, place, or line.

### Acceptance criteria

- Search opens from top input or nav.
- Map remains visible but de-emphasized.
- Keyboard focus is placed in input.

### Black-box test scenarios

1. Click the top search box. Verify the search overlay/dropdown opens and the text caret is active.
2. Press `/` or the configured keyboard shortcut if present. Verify search opens.
3. Click outside or press Escape. Verify search closes without changing map selection.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-010 — Search for Førde

**User story:** As a public user, I want to search for “førde”, so that I can quickly open relevant stations or routes.

### Acceptance criteria

- Results include Førde rutebilstasjon, Førde ferjekai, Førde sentrum, Line 100.
- First result is keyboard-highlighted.
- Enter selects highlighted result.

### Black-box test scenarios

1. Open search and type `førde`. Verify the four expected results appear with correct types.
2. Press ArrowDown and ArrowUp. Verify the highlighted result changes.
3. Highlight `Førde rutebilstasjon` and press Enter. Verify the station panel opens and map moves to Førde.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-011 — Search no results

**User story:** As a public user, I want a clear no-results state, so that I understand that my query returned nothing.

### Acceptance criteria

- No-results message appears.
- Message is calm and not error-styled.

### Black-box test scenarios

1. Open search and type `xyzabc`. Verify the message `No stations found.` appears.
2. Verify the message suggests trying a station, place, or line name.
3. Clear the query. Verify previous search/results behavior returns normally.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-012 — Navigate search results by keyboard

**User story:** As a keyboard user, I want to use arrow keys and Enter in search, so that I can use the app efficiently.

### Acceptance criteria

- Arrow keys change highlight.
- Enter opens highlighted result.
- Escape closes search.

### Black-box test scenarios

1. Open search, type `førde`, press ArrowDown twice. Verify the highlight moves through the list.
2. Press Enter on `Line 100`. Verify route/line context opens or an intentional limited route state is shown.
3. Open search again and press Escape. Verify focus returns to a logical UI element.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-013 — Open station from search

**User story:** As a public user, I want selecting a station result to open its station panel and move the map to it, so that search connects directly to the map experience.

### Acceptance criteria

- An off-screen station selection pans into view without reducing the user's current zoom; an already visible result keeps the settled camera.
- Panel opens.
- Station watch is registered.
- Departures load.

### Black-box test scenarios

1. Search for `førde` and click `Førde rutebilstasjon`. Verify an off-screen result pans into view without zooming out, while an already visible result keeps the settled camera.
2. Verify the right panel opens first in loading state, then fresh/empty/stale/error state.
3. Open admin watches page in another tab. Verify a station watch appears for the selected station.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-014 — Open route or line from search

**User story:** As a public user, I want selecting a line result to show useful related stations or route context, so that line search is not a dead end.

### Acceptance criteria

- Line search opens route context or relevant stations.
- Limited route support is explicit, not a crash.

### Black-box test scenarios

1. Search for `Line 100` or select it from `førde` results. Verify a useful route-related view appears.
2. If detailed route data is not available, verify the app shows an explicit limited-state message.
3. Verify Back/Escape returns to the previous map/search context.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
