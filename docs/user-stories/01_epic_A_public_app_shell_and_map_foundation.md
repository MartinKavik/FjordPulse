# Epic A — Public app shell and map foundation

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-001 — Load the public app

**User story:** As a public user, I want to open `fjordpulse.kavik.cz` and see the FjordPulse app quickly, so that I can start browsing transport data.

### Acceptance criteria

- Public URL loads without login.
- Top bar, map, navigation, and status area are visible.
- Optional realtime failure does not prevent the shell from rendering.

### Black-box test scenarios

1. Open `https://fjordpulse.kavik.cz` in a fresh browser profile. Verify the page shows the FjordPulse brand, map area, navigation, and status/telemetry area.
2. Throttle the network to Slow 3G or reload while backend realtime is restarting. Verify a usable shell appears before live data finishes loading.
3. Disable cookies/local storage and reload. Verify public browsing still loads, with no forced login.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-002 — Show default Norway station map

**User story:** As a public user, I want to see a map of Norway with station clusters, so that I can understand where public transport stations are available.

### Acceptance criteria

- Default map shows Norway-level station clusters.
- No all-Norway live vehicle load is triggered by the initial view.
- Førde/Nordfjord is visible or easily discoverable.

### Black-box test scenarios

1. Load the app and wait until the map is ready. Confirm station clusters appear across Norway, including western Norway.
2. Confirm no individual moving vehicle markers are shown immediately on initial load.
3. Use map zoom/pan only. Confirm the map remains responsive while clusters update.

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

### Black-box test scenarios

1. Drag the map repeatedly in different directions. Verify the map follows the cursor smoothly.
2. Zoom quickly in and out several times. Verify the UI does not lock, crash, or show duplicated panels.
3. While clusters are refreshing, interact with the search input. Verify typing still works.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-006 — Display selected station marker

**User story:** As a public user, I want the selected station to be visually highlighted, so that I always know what the side panel refers to.

### Acceptance criteria

- Selected station marker is visually distinct.
- Nearby stations remain visible.
- Highlight persists while the station panel is open.

### Black-box test scenarios

1. Click `Førde rutebilstasjon` or another visible station. Verify its marker becomes larger/brighter/selected.
2. Click a different station. Verify the previous marker returns to normal and the new one is selected.
3. Close and reopen the station panel. Verify selected-state behavior remains consistent.

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
