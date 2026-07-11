# Epic C — Station details and departure boards

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-015 — Open station panel

**User story:** As a public user, I want to click a station and see its details, so that I can inspect departures and nearby vehicles.

### Acceptance criteria

- Panel shows name, updated age or exceptional freshness warning, departures, and nearby vehicles.

### Black-box test scenarios

1. Zoom to a region with station markers and click a station. Verify the station panel opens.
2. Verify the panel contains station name, updated age, Departures section, and Nearby vehicles section without a redundant healthy `Live` badge.
3. Close the panel. Verify the map returns to unselected state.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-016 — Register station watch

**User story:** As a system, I want station panel opening to create a station watch, so that the backend refreshes data only for stations users care about.

### Acceptance criteria

- Opening panel creates/refreshes station watch.
- Multiple users share same watch.
- Closing expires/deprioritizes watch.

### Black-box test scenarios

1. Open a station in Browser A. In admin Watches, verify one active station watch exists.
2. Open the same station in Browser B. Verify client count increases, not duplicate unrelated watch rows.
3. Close the station panel in both browsers and wait past TTL. Verify the watch disappears or becomes expired.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-017 — Show station loading state

**User story:** As a public user, I want to see an elegant loading state after clicking a station, so that I know the app is working.

### Acceptance criteria

- Panel shows station name, registering live watch, skeletons for details/departures/vehicles.

### Black-box test scenarios

1. Enable slow network or use test delay toggle. Click a station. Verify skeleton loaders appear.
2. Verify the selected station marker remains visible while loading.
3. When loading completes, verify skeletons are replaced by real empty/fresh/stale/error state.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-018 — Show fresh departures

**User story:** As a public user, I want to see upcoming departures for a selected station, so that I can understand what is leaving soon.

### Acceptance criteria

- Rows show time, line, destination, status.
- Delayed/cancelled/scheduled rows styled distinctly.

### Black-box test scenarios

1. Open a station with known departures. Verify departure rows show time, line, destination, and status.
2. Use a fixture/test station with delayed and cancelled departures. Verify colors/badges distinguish them.
3. Click a departure row if interactive. Verify it either opens details or shows a clear non-interactive cursor/state.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-019 — Show empty departure state

**User story:** As a public user, I want a clear empty state when no departures are available, so that I do not mistake quiet periods for an app error.

### Acceptance criteria

- Empty message appears for no upcoming departures.
- Not styled as error.

### Black-box test scenarios

1. Open a station/test fixture with no upcoming departures. Verify the exact no-departures message appears.
2. Verify nearby vehicles section can independently show empty or data.
3. Verify the selected station keeps a visible `Data updated …` age; the current, honest empty result must not create a `Live` badge or global warning.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-020 — Show stale station data

**User story:** As a public user, I want stale station data to be clearly marked, so that I know I am seeing last-known information.

### Acceptance criteria

- Amber Stale badge.
- Last updated time and warning banner.
- Old data visible but muted.

### Black-box test scenarios

1. Use a stale data fixture or block Entur temporarily after station data loads. Verify station panel changes to amber stale state.
2. Verify previous departures remain visible but muted.
3. Restore data. Verify stale state returns to fresh when new update arrives.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-021 — Show station error state

**User story:** As a public user, I want station request failures to be contained in the station panel, so that I can retry without losing map context.

### Acceptance criteria

- Error panel has station name, Error badge, message, Retry and Close.
- Map remains usable.

### Black-box test scenarios

1. Trigger a station error fixture. Verify panel shows `Could not load station details.` and retry/close buttons.
2. Drag/zoom the map while error panel remains open. Verify map works.
3. Click Close panel. Verify the error panel closes cleanly.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-022 — Retry station request

**User story:** As a public user, I want to retry failed station loading, so that temporary Entur/backend issues can recover.

### Acceptance criteria

- Retry attempts request again.
- Loading state shown.
- Final state reflects result.

### Black-box test scenarios

1. In station error state, click Retry. Verify the button shows loading/disabled state briefly.
2. If backend recovers, verify station data appears.
3. If backend still fails, verify error returns without duplicating panels/messages.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-023 — Show nearby vehicles for station

**User story:** As a public user, I want to see nearby live vehicles for a selected station, so that I can choose a vehicle to inspect or follow.

### Acceptance criteria

- Nearby vehicles show line, location relation, last seen, optional delay.
- Rows are clickable.

### Black-box test scenarios

1. Open a station with known nearby vehicles. Verify the Nearby vehicles list appears.
2. Click a vehicle row. Verify vehicle panel opens and marker is highlighted on the map.
3. Verify last-seen times update or stale correctly during refresh.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-024 — Show no nearby vehicles state

**User story:** As a public user, I want a clear message when no live vehicles are reported nearby, so that I understand this is not necessarily an error.

### Acceptance criteria

- A completed zero-result response shows `No nearby vehicles reported.` instead of a blank list.
- Supporting copy says that no live vehicle positions were found within the 5 km station search radius reported by the HTTP resource.
- The completed empty state appears in both the Departures view's nearby section and the dedicated Vehicles view; loading, refreshing, paused, and unavailable source states never claim that the search completed successfully.
- Departures may still show normally.

### Black-box test scenarios

1. Open a station fixture with no nearby vehicles (departures may still be present). In the Departures view, verify `No nearby vehicles reported.` and the 5 km search radius are shown.
2. Switch to the Vehicles view. Verify the same completed empty state appears instead of a blank list; switch to loading and paused fixtures and verify neither claims the search is complete.
3. Verify no error color/badge is used for the empty vehicle section.
4. If vehicles later appear, verify the empty section turns into rows.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-025 — Keep station data fresh while watched

**User story:** As a public user, I want selected station data to refresh while I keep the panel open, so that I see current departures and nearby vehicles.

### Acceptance criteria

- Open panel keeps refresh/watch active.
- Updates arrive through realtime or fallback.
- Resource age and exceptional freshness warnings update correctly.

### Black-box test scenarios

1. Open a station and leave it open for several refresh intervals. Verify Last updated changes.
2. Open admin Watches and verify the station watch remains active while panel is open.
3. Disconnect realtime to trigger fallback. Verify periodic station refresh continues.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
