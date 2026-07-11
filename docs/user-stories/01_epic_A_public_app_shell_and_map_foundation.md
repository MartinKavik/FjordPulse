# Epic A — Public app shell and map foundation

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-001 — Load the public app

**User story:** As a public user, I want to open `fjordpulse.kavik.cz` and see the FjordPulse app quickly, so that I can start browsing transport data.

### Acceptance criteria

- Public URL loads without login.
- Top bar, map, navigation, and status area are visible.
- Optional realtime failure does not prevent the shell from rendering.
- The first-visit desktop introduction can be collapsed to reclaim the map, restored from a small labelled control, and remembers only an explicit user choice.

### Black-box test scenarios

1. Open `https://fjordpulse.kavik.cz` in a fresh browser profile. Verify the page shows the FjordPulse brand, map area, navigation, and status/telemetry area.
2. Throttle the network to Slow 3G or reload while backend realtime is restarting. Verify a usable shell appears before live data finishes loading.
3. Disable cookies/local storage and reload. Verify public browsing still loads, with no forced login.
4. Collapse the desktop introduction, reload, and restore it. Verify the map gains the released width, the explicit choice survives reload, keyboard focus follows the control, and station/vehicle detail panels still take priority.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-002 — Show default Norway station map

**User story:** As a public user, I want to see a map of Norway with station clusters, so that I can understand where public transport stations are available.

### Acceptance criteria

- Default map shows Norway-level station clusters.
- No all-Norway live vehicle load is triggered by the initial view.
- Førde/Nordfjord is visible or easily discoverable.
- Country, city, town, and road labels remain legible where clusters or ordinary station markers overlap their geographic positions.
- Collision-managed town labels phase in at regional zoom 6, villages at zoom 8, and denser local places at zoom 10 on both Satellite and Map.

### Black-box test scenarios

1. Load the app and wait until the map is ready. Confirm station clusters appear across Norway, including western Norway.
2. Confirm no individual moving vehicle markers are shown immediately on initial load.
3. Use map zoom/pan only. Confirm the map remains responsive while clusters update.
4. At country, region, and town zoom levels, switch between Satellite and Map. Confirm place/road labels remain readable above ordinary cluster and station context while cluster counts stay visible and clickable.
5. Zoom from the Norway view to regional zoom 6. Confirm non-capital towns such as Førde, Ålesund, or another town in view appear without waiting for street-level zoom; continue to zoom 8 and confirm smaller settlements phase in without an overlap flood.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-003 — Use station-first map behavior

**User story:** As a public user, I want the app to show stations first and live vehicles only when relevant, so that the interface stays fast and understandable.

### Acceptance criteria

- Initial view is station/cluster-first.
- Live vehicles appear only after station/vehicle intent.
- The app does not fetch or display every vehicle in Norway on startup.

### Black-box test scenarios

1. Open the app and do not click anything. Verify only station clusters/markers appear, not a dense vehicle layer.
2. Click a station. Verify nearby vehicles appear only in that station context.
3. Close the station panel. Verify vehicle markers eventually disappear or stop being emphasized.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-004 — Zoom from clusters to stations

**User story:** As a public user, I want station clusters to become individual stations as I zoom in, so that I can select the correct station.

### Acceptance criteria

- Low zoom shows clusters.
- Medium zoom splits clusters.
- High zoom shows individual station markers.
- Marker count remains bounded.

### Black-box test scenarios

1. Start from the default Norway view. Zoom in toward Førde/Nordfjord using mouse wheel or `+` control. Verify large clusters split into smaller clusters.
2. Continue zooming until individual station markers are visible. Verify they can be clicked.
3. Zoom back out. Verify individual stations merge back into clusters.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-005 — Pan and zoom smoothly

**User story:** As a public user, I want the map to pan and zoom smoothly, so that browsing feels like a real map application.

### Acceptance criteria

- Pan/zoom works smoothly.
- Visible viewport requests update cluster data.
- UI does not freeze during updates.
- The settled public-map center and zoom are visible in a canonical `#map=zoom/latitude/longitude` URL.
- Reloading or opening a copied map URL restores that camera before the first viewport request; invalid camera state falls back safely to the Norway default.
- Camera movement replaces the current URL instead of adding one browser-history entry per pan or zoom.

### Black-box test scenarios

1. Drag the map repeatedly in different directions. Verify the map follows the cursor smoothly.
2. Zoom quickly in and out several times. Verify the UI does not lock, crash, or show duplicated panels.
3. While clusters are refreshing, interact with the search input. Verify typing still works.
4. Pan and zoom once, copy the resulting URL, reload it, and open it in a second tab. Verify both maps start with the same center and zoom and issue matching viewport requests.
5. Open a malformed or out-of-range `#map` value. Verify the map loads the Norway default, rewrites a canonical camera URL, and preserves unrelated query parameters.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-006 — Display selected station marker

**User story:** As a public user, I want the selected station to be visually highlighted, so that I always know what the side panel refers to.

### Acceptance criteria

- Selected station marker is visually distinct.
- Nearby stations remain visible.
- Highlight persists while the station panel is open.
- A dedicated selected-station pin and name remain above clusters and provider labels at every zoom, including when the viewport response aggregates ordinary stations into a cluster.
- Selecting a station from a Norway/Europe overview immediately centres it at a useful local zoom (at least 11), even while details are loading or fail. Once the camera is already local, a visible selection preserves it; an off-screen selection pans without reducing the current zoom, and same-station realtime refreshes never recenter it.

### Black-box test scenarios

1. At street-level zoom, click `Reed` (`NSR:StopPlace:34503`), `Førde rutebilstasjon`, or another visible station. Verify the panel opens without changing the settled zoom or unnecessarily recentering the map.
2. With the station panel open, zoom out until ordinary stations aggregate into clusters. Verify a named selected-station pin remains visible above the cluster and map labels.
3. From the Norway overview, select `Reed` from search and verify the map immediately settles near `#map=11/61.7376/6.4097`, including when its detail request fails. Repeat from a closer off-screen camera and verify the pan never decreases that zoom.
4. Click a different station. Verify the previous marker returns to normal and the new one is selected.
5. Close and reopen the station panel. Verify selected-state behavior remains consistent.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-007 — Display bottom telemetry strip

**User story:** As a public user, I want to see compact live/system status, so that I can tell whether data is live, stale, or degraded.

### Acceptance criteria

- Desktop telemetry strip shows backend, realtime, Entur, and last update.
- Strip changes for connected/reconnecting/delayed/fallback/offline states.

### Black-box test scenarios

1. Open desktop viewport. Verify bottom telemetry strip exists and shows Backend, Realtime, Entur, and Last update.
2. Temporarily stop or simulate realtime failure from the admin/operator controls if available. Verify the strip changes to reconnecting/offline/fallback.
3. When data refreshes, verify the Last update value changes visibly.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-008 — Preserve map usability during errors

**User story:** As a public user, I want the map to remain usable even when station or realtime data fails, so that one failure does not break the whole app.

### Acceptance criteria

- Station failure does not disable map.
- Realtime failure does not disable map.
- Entur delay/rate limit shows cached/empty/stale states instead of crash.

### Black-box test scenarios

1. Trigger a station error using a test station/error fixture or admin toggle. Verify the side panel shows an error while the map can still pan/zoom.
2. Force realtime offline. Verify the map and search still work.
3. Force Entur delayed/backoff state. Verify cached/stale messaging appears and no blank white screen occurs.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
