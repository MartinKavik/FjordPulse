# Epic C — Station details and departure boards

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-015 — Open station panel

**User story:** As a public user, I want to click a station and see its details, so that I can inspect departures and vehicles relevant to that station.

### Acceptance criteria

- Panel shows the station name, updated age or exceptional freshness warning, and three non-overlapping tabs: Departures, Vehicles, and Details. Departures owns only the departure board, Vehicles owns station-serving and other-nearby positions, and Details owns stable station and data-scope facts.
- Departures and Vehicles show compact count badges. The vehicle count is the number of unique rendered vehicles, not the sum of overlapping source arrays.

### Black-box test scenarios

1. Zoom to a region with station markers and click a station. Verify the station panel opens.
2. Switch through Departures, Vehicles, and Details. Verify the departure board and its count appear only under Departures; the de-duplicated station-serving and other-nearby groups and their count appear only under Vehicles; stable station facts appear under Details; and no redundant healthy `Live` badge appears.
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

- Panel shows the station name and registering-live-watch progress. Departures and Vehicles each show loading copy and skeletons scoped to that tab; cached station facts remain usable in Details instead of being replaced by transport-data skeletons.

### Black-box test scenarios

1. Enable slow network or use a test delay toggle. Click a station, then switch between Departures and Vehicles. Verify each tab shows only its own loading copy and skeletons, with no completed-empty claim.
2. Verify the selected station marker remains visible while loading.
3. Open Details while transport data is loading and verify known station facts remain usable; when loading completes, verify the active transport tab replaces skeletons with its real empty/fresh/stale/error state.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-018 — Show fresh departures

**User story:** As a public user, I want to see upcoming departures for a selected station, so that I can understand what is leaving soon.

### Acceptance criteria

- Rows show time, line, destination, platform when reported, and status.
- Delayed/cancelled/scheduled rows styled distinctly.
- The Departures count badge matches the rendered upcoming rows, and no station-serving or nearby vehicle list is duplicated below the board.

### Black-box test scenarios

1. Open a station with known departures. Verify departure rows show time, line, destination, platform when reported, and status, and the Departures badge matches the number of rows.
2. Use a fixture/test station with delayed and cancelled departures. Verify colors/badges distinguish them.
3. Click a departure row if interactive. Verify it either opens details or shows a clear non-interactive cursor/state, then verify full vehicle lists are absent from Departures and available under Vehicles.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-019 — Show empty departure state

**User story:** As a public user, I want a clear empty state when no departures are available, so that I do not mistake quiet periods for an app error.

### Acceptance criteria

- Empty message and a zero Departures badge appear for no upcoming departures.
- Not styled as error.

### Black-box test scenarios

1. Open a station/test fixture with no upcoming departures. Verify the exact no-departures message appears.
2. Switch to Vehicles. Verify station-serving and other-nearby sections can each independently show an empty state or rows without being duplicated in Departures.
3. Verify the selected station keeps a visible `Data updated …` age; the current, honest empty result must not create a `Live` badge or global warning.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-020 — Show stale station data

**User story:** As a public user, I want stale station data to be clearly marked, so that I know I am seeing last-known information.

### Acceptance criteria

- Amber Stale badge.
- Last updated time and a warning scoped to the affected transport content.
- Old data visible but muted.
- Stable Details content remains available.

### Black-box test scenarios

1. Use a stale data fixture or block Entur temporarily after station data loads. Verify the affected Departures or Vehicles content changes to an amber stale state without disabling Details.
2. Verify previous departures remain visible but muted under Departures and saved vehicle positions remain in their own Vehicles groups when available.
3. Restore data. Verify stale state returns to fresh when new update arrives.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-021 — Show station error state

**User story:** As a public user, I want station request failures to be contained in the station panel, so that I can retry without losing map context.

### Acceptance criteria

- The affected Departures or Vehicles tab has an Error badge, concise message, Retry and Close; it does not repeat disabled content from the other tab.
- Known stable station facts remain usable under Details during a transport-source failure; a compact live-content notice keeps Retry available without replacing those facts.
- Map remains usable.

### Black-box test scenarios

1. Trigger a station error fixture. Verify the active Departures or Vehicles tab shows a scoped unavailable message and retry/close buttons, then switch tabs and verify it does not repeat both unavailable sections together.
2. Drag/zoom the map while error panel remains open. Verify map works.
3. Open Details and verify known station facts remain usable, missing locality fields do not become duplicate placeholder cards, and the compact live-content Retry remains available; then click Close panel and verify the panel closes cleanly.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-022 — Retry station request

**User story:** As a public user, I want to retry failed station loading, so that temporary Entur/backend issues can recover.

### Acceptance criteria

- Retry attempts the affected station transport resource again.
- A loading state is shown only in the active Departures or Vehicles tab; Details remains usable.
- Final state reflects result.

### Black-box test scenarios

1. In a station transport-tab error state, click Retry. Verify the button shows loading/disabled state briefly and unrelated tab content is not replaced by duplicate loading blocks.
2. If backend recovers, verify station data appears.
3. If backend still fails, verify error returns without duplicating panels/messages.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-023 — Show station-serving and nearby vehicles

**User story:** As a public user, I want to see reporting vehicles that serve a selected station as well as other nearby vehicles, so that I can inspect an incoming, starting, passed, or local vehicle on the map.

### Acceptance criteria

- Vehicles is the sole tab for live vehicle lists. Its station-serving section shows currently reporting passenger-service vehicles matched by dated service journey to a station call in the reported six-hours-before/six-hours-after window. Rows show authoritative vehicle type, line, station relation/call time, and last seen; matched vehicles may be outside the nearby radius. On-the-way/at-station, unknown-progress, and already-passed matches are grouped separately so schedule-only evidence is never presented as live approach progress. A same-ID vehicle that becomes non-passenger or changes/loses its journey identity is removed from this section while remaining eligible for the nearby list.
- Other vehicles within the server-reported 5 km radius remain a separate list. Every row opens the existing vehicle detail/selection flow, duplicate vehicles are not repeated across the two sections, and the Vehicles badge reports the resulting unique row count.
- A short plain-language coverage summary is visible in Vehicles. Exact time window and candidate/queried/truncated diagnostics live in a collapsed coverage disclosure so they remain available without dominating the primary list.

### Black-box test scenarios

1. Open a fixture station with starting, approaching, at-station, unknown-progress, passed, and unrelated nearby vehicles, including a matched vehicle outside 5 km. Switch to Vehicles and verify the first five appear under Vehicles serving this station in truthful progress groups, only the unrelated local vehicle appears under Other nearby vehicles, and the unique-row badge is correct.
2. Click a far-away station-serving row, then an other-nearby row. Verify each opens the same vehicle panel and highlights or pans to its selected map marker without reducing the current zoom.
3. Expand coverage details and verify a busy-station fixture reports its partial coverage instead of implying that every Norway-wide vehicle was searched; collapse it and verify the lists remain unchanged. Verify last-seen times update or become stale correctly, then transition one matched vehicle to non-passenger or another journey while Journey Planner is unavailable and verify its old serving relation disappears but its current nearby position remains available.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-024 — Show empty station-vehicle states

**User story:** As a public user, I want clear completed states when no reporting vehicles can be matched to station services or found nearby, so that I understand what was searched without mistaking an empty list for loading or an error.

### Acceptance criteria

- A completed zero-result response shows separate `No station-serving vehicle reported now.` and `No nearby vehicles reported.` states instead of blank lists.
- Station-serving copy explains that no currently reporting position matched the dated services in the reported ±6-hour window; it does not claim that no scheduled service exists or that every vehicle in Norway was searched. Nearby copy states that no live vehicle position was found within the 5 km station search radius reported by the HTTP resource.
- Both completed vehicle empty states appear only in Vehicles, alongside its zero badge; loading, refreshing, paused, stale, rate-limited, and unavailable source states never claim that a search completed successfully.
- Departures may still show normally.

### Black-box test scenarios

1. Open a station fixture with departures but no matched reporting vehicle and no nearby vehicle. Verify Departures shows only its normal board and count; switch to Vehicles and verify its zero badge, both explicit empty headings, bounded service-match copy, and 5 km nearby radius.
2. In Vehicles, switch through loading, stale, and rate-limited fixtures and verify none falsely claims a fresh completed search; open Details in each state and verify stable station facts remain usable.
3. Verify neither completed empty section uses an error color/badge and neither claims that the scheduled departures were cancelled.
4. If a station-serving or nearby vehicle later appears, verify only the corresponding empty state becomes clickable rows.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-025 — Keep station data fresh while watched

**User story:** As a public user, I want selected station data to refresh while I keep the panel open, so that I see current departures, station-serving vehicles, and other nearby vehicles.

### Acceptance criteria

- Open panel keeps refresh/watch active across all three tabs without refetching merely because the user switches tabs.
- Updates arrive through realtime or fallback.
- Resource age and exceptional freshness warnings update correctly.

### Black-box test scenarios

1. Open a station, switch among Departures, Vehicles, and Details, and leave it open for several refresh intervals. Verify Last updated and the two resource counts change from authoritative updates without duplicating content across tabs.
2. Open admin Watches and verify the station watch remains active while panel is open.
3. Disconnect realtime to trigger fallback. Verify periodic station refresh continues.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
