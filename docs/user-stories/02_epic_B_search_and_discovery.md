# Epic B — Search and discovery

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-009 — Open search

**User story:** As a public user, I want to open search from the top bar or nav, so that I can quickly find a station, place, or line.

### Acceptance criteria

- On phone widths, a normally sized search input is visibly present in the top bar before interaction; it is never replaced by an invisible field that only opens the software keyboard.
- The top-bar search action/input and the bottom-navigation Search action open search and focus that same visible input.
- Map remains visible but de-emphasized.
- Keyboard focus is placed in the input, and the entered query remains visible while the software keyboard is open.

### Black-box test scenarios

1. Open at 390x844 or on a real phone. Before tapping anything, verify a full visible search input is present in the top bar.
2. Tap the header search action and then the bottom-navigation Search action. Verify each opens search, focuses the visible input, and leaves the text caret and typed query visible above the software keyboard.
3. On desktop, click the top search box, then press `/` or the configured keyboard shortcut if present. Verify search opens and focus enters the input.
4. Click outside or press Escape. Verify search closes, the input loses focus, and the map selection does not change.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-010 — Search for Førde

**User story:** As a public user, I want to search for “førde”, so that I can quickly open relevant stations or routes.

### Acceptance criteria

- Results include Førde rutebilstasjon, Førde ferjekai, Førde sentrum, Line 100.
- Search is tolerant of Norwegian characters: unaccented `Forde` and the prefix `Fo` can return correctly labelled `Førde` results.
- Single-word queries allow one typo from four characters; candidate selection and exact validation remain SurrealDB-backed against the national station catalog.
- A valid query uses a 300 ms trailing quiet-period debounce and sends one request after the user pauses rather than one request per letter. Pressing Enter submits immediately.
- Typing and the first 250 ms of a request stay visually quiet. A localized, query-specific progress message appears only for a slower response, avoiding spinner flashes.
- The complete query stays visible while searching and showing results; a result list longer than the available phone height scrolls inside the overlay.
- First result is keyboard-highlighted.
- Enter selects highlighted result.

### Black-box test scenarios

1. On a phone, type `Forde` continuously without pausing longer than 300 ms. Verify every character remains visible, no progress message flashes, and no same-origin search request starts for each letter.
2. Stop typing. Verify exactly one `/api/search` request starts for the settled `Forde` query. A response completed within 250 ms must show results directly; a deliberately slower response must first show localized `Searching for “Forde”…` / `Søker etter «Forde» …` progress. Repeat with `Fo` and `førde` to verify tolerant prefix and native-character input.
3. Against the complete station catalog, search for a long station name with two character errors, including errors in the first and last character. Verify the intended station remains present without a national table scan.
4. Return enough results to exceed the available phone height. Verify the result list scrolls within the overlay while the input and current query remain visible.
5. In the deterministic `førde` scenario, verify the four expected result types, press ArrowDown and ArrowUp, then highlight `Førde rutebilstasjon` and press Enter. Verify the station panel opens and the map moves to Førde.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-011 — Search no results

**User story:** As a public user, I want a clear no-results state, so that I understand that my query returned nothing.

### Acceptance criteria

- Search keeps debounce and fast-request intervals visually quiet, then exposes distinct localized slow-progress and completed-empty states.
- The completed no-results message names the settled query and appears only after the request finishes.
- Message is calm and not error-styled.

### Black-box test scenarios

1. Open search and type `xyzabc` without a long pause. Verify the visible query remains quiet during debounce and a fast request; delay the response and verify query-specific progress appears only after the grace period.
2. Complete the request with zero results. Verify a calm localized empty state names `xyzabc` and suggests trying a station, place, or line name.
3. Clear the query. Verify progress and empty copy disappear and ordinary search behavior returns normally.

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

- A station selected from overview centres immediately at a useful local zoom (at least 11), before its detail request finishes. At an already useful local zoom, visible results preserve the settled camera and off-screen results pan without zooming out.
- Panel opens.
- Station watch is registered.
- Departures load.

### Black-box test scenarios

1. From the Norway overview, search for `Reed` or `førde` and select a station. Verify it centres immediately at zoom 11 or closer, even if details fail; at an already useful local zoom, verify visible results preserve the camera and off-screen results never zoom out.
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
