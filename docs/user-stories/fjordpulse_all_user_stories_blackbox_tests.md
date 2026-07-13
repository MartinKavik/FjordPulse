# FjordPulse User Stories + Black-Box Test Scenarios

This package contains the full production-complete user story backlog for FjordPulse, extended with black-box test scenarios.

The tests are intentionally written for a human or AI browser agent using only visible behavior:
- mouse/touch interaction
- keyboard interaction
- browser UI
- public pages
- admin/operator pages
- visible logs/status pages
- browser DevTools network/WS inspection where explicitly useful

The tester should not read application source code to decide whether a story passes.

## Files

- `00_black_box_testing_guide.md` — how to execute and record tests.
- `00_manifest.json` — story IDs, epics, and file mapping.
- `01_epic_A_public_app_shell_and_map_foundation.md`
- `02_epic_B_search_and_discovery.md`
- `03_epic_C_station_details_and_departure_boards.md`
- `04_epic_D_vehicle_details_and_focus_mode.md`
- `05_epic_E_realtime_transport_and_message_protocol.md`
- `06_epic_F_entur_integration_and_data_freshness.md`
- `07_epic_G_surrealdb_persistence_and_migrations.md`
- `08_epic_H_admin_and_observability.md`
- `09_epic_I_frontend_visual_states_and_responsiveness.md`
- `10_epic_J_security_abuse_prevention_and_privacy.md`
- `11_epic_K_deployment_and_operations.md`
- `12_epic_L_testing_and_quality.md`
- `13_epic_M_documentation_and_handoff.md`
- `fjordpulse_all_user_stories_blackbox_tests.md` — monolithic combined version.
- `traceability_matrix.csv` — compact story/test inventory.

## Definition of done

FjordPulse is production-complete only when every story has:
1. implemented behavior,
2. passing acceptance criteria,
3. passing black-box scenarios,
4. visual state verified where applicable,
5. documentation updated where applicable,
6. production or staging evidence captured.

---

# Black-Box Testing Guide

## Goal

Validate FjordPulse without reading source code. The tester should interact with the system the way a real user, admin, or operator would.

## Allowed tools

- Browser mouse/touch interactions.
- Keyboard navigation.
- Browser responsive/mobile emulation.
- Browser DevTools Network and WebSocket panels for behavior verification.
- Admin UI pages.
- Coolify UI/log views for operator stories.
- Public health/status endpoints.
- Real device testing where available.
- Test fixtures or admin toggles exposed through the app, if implemented.

## Not allowed

- Reading application source code to decide whether a story passes.
- Manually querying private databases to bypass the UI, unless a story explicitly exposes an admin diagnostic page.
- Calling Entur directly from the browser as part of normal app verification.
- Accepting behavior that only works with fake transport data in production.

## Test evidence format

For each story, record:

```text
Story ID:
Environment: local / staging / production
Browser/device:
Tester:
Date/time:
Scenario(s) run:
Result: Pass / Fail / Blocked
Evidence: screenshot, video, logs link, or notes
Defects found:
```

## Recommended execution order

1. Public app load and map.
2. Search.
3. Station panel states.
4. Station-serving and nearby vehicles, then vehicle selection.
5. Focus mode states.
6. Fallback/error/stale states.
7. Mobile states.
8. Admin/operator pages.
9. Security/privacy checks.
10. Deployment/ops smoke.
11. Visual and accessibility checks.

## Test data guidance

Production should use real Entur-backed data only. For stale/error/lost/fallback visual states, use one of:
- controlled staging fixture mode,
- operator/admin toggle,
- mocked local environment,
- service outage simulation in staging,
- network blocking in browser/devtools.

Never require fake vehicles in production to pass a production story.

## Outage and recovery method

Use the term **black-box E2E test** when the assertions come from the public browser, public health endpoints, or exposed operator controls without inspecting application internals. A backend-only Entur retry test is instead a **service-boundary integration/fault-injection test**; both belong to the resilience suite, but they prove different boundaries.

- Stop and restore FjordPulse HTTP/realtime with an external local/staging operator control. Do not navigate, reload, recreate the page, or press Retry during the recovery assertion.
- Simulate Entur loss only at the backend's controlled upstream HTTP boundary. The browser must continue to call FjordPulse only; routing browser requests to an Entur stub would violate the architecture and is not valid evidence.
- Take the initial snapshot from a real adapter or the deterministic backend adapter in local/test. During an outage, assert that previous authoritative values remain unchanged; do not generate intermediate vehicle positions or advance source timestamps.
- Measure outage bounds from confirmation that the service/upstream was stopped, and recovery bounds from confirmation that it was restored. Preserve a page-lifetime sentinel or equivalent trace evidence to prove the same document survived.
- Default bounds are: realtime `reconnecting` within 10 seconds; a full FjordPulse backend outage visible as backend-degraded plus offline/polling within 25 seconds; full backend recovery within 30 seconds; and a transient non-rate-limited Entur retry scheduled after 15 seconds and recovered within 20 seconds when upstream is restored before that attempt.
- For Entur 429, `Retry-After` overrides the ordinary 15-second failure delay. Assert no early request and allow the scheduler/network margin only after that timestamp.

---

# Epic A — Public app shell and map foundation

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-001 — Load the public app

**User story:** As a public user, I want to open `fjordpulse.kavik.cz` and see the FjordPulse app quickly, so that I can start browsing transport data.

### Acceptance criteria

- Public URL loads without login in Norwegian Bokmål (`nb`) by default, regardless of the browser's preferred language.
- Top bar, map, and navigation are visible; update health appears only when it adds useful rider context.
- Optional realtime failure does not prevent the shell from rendering.
- The first-visit desktop introduction can be collapsed to reclaim the map and restored from a small labelled control. An easily reached `NO`/`EN` switcher changes all visible application chrome immediately and updates the document language; both preferences remember only explicit choices, while unavailable or invalid storage falls back safely to Norwegian and the normal introduction default.

### Black-box test scenarios

1. Open `https://fjordpulse.kavik.cz` in a fresh English-configured browser profile. Verify the page starts in Norwegian, shows the FjordPulse brand, map area, and navigation without duplicate `Live ready`/`Realtime ready` chrome; switch to `EN`, verify the visible UI and document language update without reloading, then reload and verify English is restored.
2. Throttle the network to Slow 3G or reload while backend realtime is restarting. Verify a usable shell appears before live data finishes loading.
3. Disable cookies/local storage and reload. Verify public browsing still loads in Norwegian with no forced login and the language control remains usable for the current page.
4. Collapse the desktop introduction, reload, and restore it. Verify the map gains the released width, the explicit choice survives reload, keyboard focus follows the control, and station/vehicle detail panels still take priority in both Norwegian and English.

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

## FP-007 — Show contextual update health

**User story:** As a public user, I want concise update health when it affects what I am viewing, so that I can distinguish current, reconnecting, periodically refreshed, and saved data without reading operator diagnostics.

### Acceptance criteria

- Healthy default and selected-resource views have no persistent ready/connected/`Live` global badge: the selected panel owns its resource age and exceptional freshness warning. One desktop/mobile notice remains available with a detail panel open and uses `Reconnecting to live updates…`, `Live connection interrupted · Updating periodically`, or `Updates temporarily unavailable · Showing saved information` as applicable; component diagnostics remain in protected Admin instead of permanent public footer cells.
- Source provenance is separate from health: real mode shows neutral `Transport data: Entur`, while fake mode keeps a prominent `Demo data` badge.

### Black-box test scenarios

1. Open desktop/mobile default views, then select a fresh resource. Verify there is no ready/connected/healthy `Live` global badge, the selected panel has a changing resource age, and real mode shows neutral `Transport data: Entur`.
2. Stop realtime and then public HTTP while the resource remains selected. Verify the one notice progresses through the three canonical messages while the panel, map, and saved data remain visible, including with a mobile detail sheet open; verify the public app never becomes a raw service matrix.
3. Restore services and the active watch. Verify the notice clears and selection survives; switch to fake mode and verify the prominent `Demo data` badge replaces Entur attribution.

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


---

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


---

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


---

# Epic D — Vehicle details and Focus mode

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-026 — Select a station vehicle

**User story:** As a public user, I want to click a vehicle serving the selected station or another vehicle nearby, so that I can inspect its details and locate it on the map.

### Acceptance criteria

- Clicking vehicle row/marker opens panel.
- Map highlights vehicle.
- While coordinates are known, a dedicated selected-vehicle pin remains above clusters and provider labels at every zoom; the pin tip, rather than its centre, marks the reported coordinate and its single mode/line label never intersects the pin.

### Black-box test scenarios

1. Open a station with a matched vehicle outside the nearby radius and click its station-serving row. Verify the vehicle panel opens and the map brings its selected marker into view without zooming out.
2. Click a visible vehicle marker on the map. Verify the same vehicle panel opens.
3. Verify station context remains visible or can be navigated back to.
4. Zoom in and out while the vehicle panel remains open. Verify its selected pin stays visible above ordinary map context, its tip remains on the reported road position, and the mode/line label stays outside the pin on desktop and mobile.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-027 — Show selected vehicle details

**User story:** As a public user, I want selected vehicle details, so that I can understand what the vehicle is and where it is going.

### Acceptance criteria

- Panel identifies the authoritative transport mode (bus, coach, ferry, metro, tram, train, air, taxi, or Vehicle only when Entur reports no recognised mode) and shows line, route, status, last seen, delay, vehicle ID, next stop, and previous stop when journey progress makes it available. Raw compass bearing is not presented as a rider-facing summary field.
- When the backend classifies the current movement as `non_passenger`, the live position, vehicle identity, trail, selection, and Focus controls remain available, but the panel and marker say `Not in passenger service`. Operational line/route/destination metadata, delay, previous/next stops, journey progress, and raw provider warnings are not presented as passenger information.
- `unknown` passenger-service state remains neutral and is never relabelled as `non_passenger` by the browser. A missing Journey Planner result for an otherwise canonical passenger journey is presented as unavailable journey details, not proof of a dead run.
- A known vehicle remains discoverable by its exact identifier after its position becomes lost; ordinary line, route, destination, and fuzzy searches do not surface lost vehicles.
- The Journey progress rail passes through the exact horizontal centre of both ordinary and current-stop circles at desktop and mobile widths.

### Black-box test scenarios

1. Select bus, ferry, and train fixtures. Verify the panel eyebrow and accessible label identify the correct mode and still show line, route, Live/Stale/Lost state, last seen, delay, and ID.
2. Use a journey fixture with known calls and progress. Verify Previous stop names the nearest non-cancelled call before the current/next matched call, cancelled calls do not appear as next/upcoming stops, and the vertical progress rail crosses the centre of every differently sized stop circle on desktop and expanded mobile; use missing journey progress and verify `Not available` instead of a compass direction or invented stop.
3. Verify the Focus button is visible for a selectable live vehicle and unknown upstream mode is labelled generically rather than inferred from line or station data.
4. Return an older completed public journey and a newer internal/dead-run record with the same physical vehicle ID. Verify the newer live marker wins in either array order, the panel says `Not in passenger service`, only position status and Last seen remain, and no operational line, delay, route, stop list, stale-schedule claim, or raw Entur warning is shown.
5. Begin Focus on the public journey, transition the same vehicle ID to `non_passenger`, and then provide a newer public journey. Verify the same Focus watch remains active while the marker and copy switch to the operational state, then normal line/journey information returns automatically without reload or reselection.
6. Persist a vehicle, let it become lost, and search its exact identifier. Verify the last-known vehicle remains selectable while searches for its former line, route, or destination do not surface it.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-028 — Show recent vehicle trail

**User story:** As a public user, I want to see a short recent trail for a selected vehicle, so that I can understand its movement.

### Acceptance criteria

- Map shows trail polyline/fading dots.
- Panel shows trail preview/summary.
- Old points de-emphasized.

### Black-box test scenarios

1. Select a vehicle with stored observations. Verify a trail appears behind the marker.
2. Wait for a new vehicle update. Verify a new point appears and older points fade.
3. Select another vehicle. Verify the previous trail is cleared or de-emphasized.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-029 — Start Focus mode

**User story:** As a public user, I want to click “Focus” on a vehicle, so that the map follows the vehicle live.

### Acceptance criteria

- Focus creates high-priority watch.
- Map pans/zooms to vehicle.
- Floating focus pill appears.

### Black-box test scenarios

1. Select a vehicle and click Focus. Verify map centers/zooms on the vehicle.
2. Verify the green following pill appears.
3. Open admin Watches and verify a focus/high-priority vehicle watch appears.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-030 — Follow vehicle movement

**User story:** As a focus user, I want the map to follow the selected vehicle as new positions arrive, so that I can monitor it live.

### Acceptance criteria

- Vehicle_moved updates marker/trail.
- Map pans smoothly unless paused.

### Black-box test scenarios

1. Enter Focus mode for a vehicle. Wait for a new update or trigger mock update in test environment.
2. Verify marker position and trail change.
3. Verify the map pans smoothly rather than jumping abruptly.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-031 — Show Focus mode pill

**User story:** As a focus user, I want a clear Focus mode indicator, so that I know the app is following a vehicle.

### Acceptance criteria

- Pill shows Following Line 100, last seen, Pause, Unfocus.

### Black-box test scenarios

1. Start Focus mode. Verify pill contains `Following Line 100`, a last-seen value, Pause, and Unfocus.
2. Verify pill remains visible while right panel is open/closed.
3. Resize browser. Verify pill remains usable and does not cover critical controls.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-032 — Pause Focus when user moves map

**User story:** As a focus user, I want the app to pause auto-follow when I manually pan or zoom, so that the app does not fight me.

### Acceptance criteria

- Manual pan/zoom changes Focus to paused.
- Pill shows paused message and Resume/Unfocus.

### Black-box test scenarios

1. Start Focus mode. Drag the map away from the vehicle. Verify pill changes to `Follow paused`.
2. Wait for a vehicle update. Verify the map does not auto-pan while paused.
3. Verify the vehicle marker/trail can still update if visible.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-033 — Resume Focus mode

**User story:** As a focus user, I want to resume following after I move the map manually, so that I can return to live tracking.

### Acceptance criteria

- Resume recenters on vehicle and returns to following.

### Black-box test scenarios

1. Pause Focus by moving the map. Click Resume. Verify the map recenters on the vehicle.
2. Wait for the next update. Verify map follows again.
3. Verify button labels return to Pause/Unfocus.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-034 — Unfocus vehicle

**User story:** As a focus user, I want to stop following a vehicle, so that I can return to normal map browsing.

### Acceptance criteria

- Unfocus exits Focus mode.
- Backend focus watch expires/downgrades.
- Map stops auto-panning.

### Black-box test scenarios

1. Start Focus mode and click Unfocus. Verify the focus pill disappears.
2. Move the map. Verify no auto-pan occurs on subsequent updates.
3. Open admin Watches. Verify focus watch disappears or becomes non-focus.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-035 — Show stale vehicle state

**User story:** As a focus user, I want a stale vehicle state when positions stop updating briefly, so that I understand the vehicle may still exist but data is old.

### Acceptance criteria

- Amber Stale badge.
- Last seen 2 min ago.
- Refresh position / Stop watching buttons.
- Map marker faded.

### Black-box test scenarios

1. Use a stale vehicle fixture or pause vehicle updates. Verify panel shows amber Stale badge and `Last seen 2 min ago`.
2. Verify the map marker is faded/greyed with muted trail.
3. Verify Refresh position and Stop watching buttons are visible and their actions are visually distinct.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-036 — Refresh stale vehicle position

**User story:** As a focus user, I want to request a fresh position for a stale vehicle, so that the action clearly describes what happens while my existing watch remains active.

### Acceptance criteria

- Refresh position performs the existing bounded retry and keeps the current vehicle watch active.
- Fresh data returns UI to live/following.
- While the request is running, the action is disabled and says it is refreshing. If it fails, the stale panel remains visible with the last known position and an explicit retry error.

### Black-box test scenarios

1. In stale vehicle state, click Refresh position. Verify a bounded refresh is requested and the panel remains in watching/stale mode rather than closing.
2. Restore or simulate a fresh vehicle update. Verify the UI returns to live/following.
3. Fail the refresh. Verify the last known vehicle remains visible, an error explains that the position was not refreshed, and the action can be tried again.
4. Verify admin Watches still shows an active focus/vehicle watch.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-037 — Stop watching stale vehicle

**User story:** As a focus user, I want to stop watching a stale vehicle, so that I can avoid wasting refresh budget.

### Acceptance criteria

- Stop watching expires vehicle watch and stops high-priority refresh.

### Black-box test scenarios

1. In stale vehicle state, click Stop watching. Verify the panel closes or returns to normal selected state.
2. Open admin Watches. Verify the vehicle/focus watch is gone or expired.
3. Wait one refresh interval. Verify no new high-priority updates appear for that vehicle.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-038 — Show lost vehicle state

**User story:** As a focus user, I want a clear unavailable-position state after a prolonged reporting gap, so that I understand the uncertainty without mistaking it for an app disconnection.

### Acceptance criteria

- Position-unavailable badge, truthful feed-gap explanation, automatic continued checking, Stop following, Try again, Last seen, and dimmed last position.
- One successful nationwide response that temporarily omits the vehicle does not immediately mark it lost: the authoritative observation remains live through 30 seconds, stale through five minutes, and unavailable only after that grace expires.

### Black-box test scenarios

1. Use the unavailable-position fixture. Verify the panel avoids claiming that the vehicle left a watched area, names a possible temporary feed gap, and says following resumes automatically when a new position arrives.
2. Verify last known marker is dimmed on the map.
3. Verify Stop following and Try again buttons are available; deliver a new authoritative observation and verify the same open watch returns to Live without a browser or WebSocket reconnect.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-039 — Try again for lost vehicle

**User story:** As a focus user, I want to retry tracking a lost vehicle, so that temporary gaps can recover.

### Acceptance criteria

- Try again performs bounded refresh and resolves to live/stale/lost.

### Black-box test scenarios

1. In lost state, click Try again. Verify a searching/loading indicator appears.
2. If test fixture returns a vehicle, verify UI returns to live or stale state.
3. If still lost, verify lost state returns without duplicate notifications.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.


---

# Epic E — Realtime transport and message protocol

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-040 — Connect browser WebSocket

**User story:** As a public user, I want the app to connect to realtime updates when needed, so that station and vehicle data can update without manual refresh.

### Acceptance criteria

- WebSocket opens when station/Focus active.
- Exceptional connection states are visible through the single contextual public notice; healthy idle/connected states do not create permanent badges.

### Black-box test scenarios

1. Open the app without selecting anything. Verify no application WebSocket opens and no ready badge is shown.
2. Open a station. Verify a WebSocket opens, its watch is acknowledged, and station data updates without adding a global connected badge.
3. Use browser network offline/online or restart realtime service. Verify `Reconnecting to live updates…`, `Live connection interrupted · Updating periodically`, and `Updates temporarily unavailable · Showing saved information` as applicable.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-041 — Use backend-only Entur communication

**User story:** As an operator, I want all Entur communication to happen server-side, so that browser clients never directly call Entur.

### Acceptance criteria

- Browser makes no direct Entur calls.
- Backend controls headers and rate limits.

### Black-box test scenarios

1. Open browser DevTools Network tab and use the app normally: search, select station, focus vehicle.
2. Verify no browser request goes to an Entur hostname; only FjordPulse domains are contacted.
3. Verify admin Entur log shows server-side requests corresponding to actions.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-042 — Define typed WebSocket message protocol

**User story:** As a developer, I want a typed WebSocket message protocol, so that realtime behavior is easy to test and invalid messages are rejected.

### Acceptance criteria

- Messages have id/type/payload.
- Errors are structured.
- Types documented in app behavior/docs.

### Black-box test scenarios

1. Use the app normally and observe WS frames in DevTools. Verify outgoing messages have id/type/payload.
2. Using a browser console or test harness, send malformed/unknown message. Verify a structured error frame appears and connection remains open.
3. Send oversized or invalid JSON if test harness permits. Verify connection is safely rejected or error returned.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-043 — Watch station over WebSocket

**User story:** As a public user, I want station selection to create a live watch, so that updates are pushed while I view it.

### Acceptance criteria

- watch_station validates, joins station room, ack returned, refresh starts.

### Black-box test scenarios

1. Open a station and observe WS frames. Verify a watch_station message and acknowledgement.
2. Open admin Watches. Verify the station room/watch is active.
3. Wait for a departure update or trigger fixture. Verify panel updates without full page reload.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-044 — Watch vehicle over WebSocket

**User story:** As a public user, I want vehicle selection to create a live watch, so that vehicle updates can be pushed.

### Acceptance criteria

- watch_vehicle validates, joins vehicle room, latest state returned.

### Black-box test scenarios

1. Click a nearby vehicle. Verify a vehicle watch is registered and acknowledged.
2. Verify the vehicle panel receives latest known state.
3. Open same vehicle in two tabs. Verify client count increases for same watch/room.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-045 — Focus vehicle over WebSocket

**User story:** As a focus user, I want Focus mode to be controlled over realtime messages, so that the backend can prioritize active tracking.

### Acceptance criteria

- focus_vehicle creates high-priority focus watch and emits focus_started/error.

### Black-box test scenarios

1. Click Focus on a vehicle. Observe UI and admin Watches. Verify high priority/focus state is visible.
2. Attempt Focus on an invalid/stale/lost test vehicle if available. Verify structured error or intended state.
3. Unfocus and verify priority changes.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-046 — Broadcast events to rooms

**User story:** As a system, I want updates to be broadcast only to relevant rooms, so that users receive only data they requested.

### Acceptance criteria

- Station updates only station subscribers.
- Vehicle updates only vehicle/focus subscribers.
- Telemetry scoped appropriately.

### Black-box test scenarios

1. Open two browsers on different stations. Trigger/update one station. Verify only that station panel changes.
2. Focus a vehicle in Browser A. Leave Browser B on a different station. Verify Browser B does not show unrelated vehicle focus updates.
3. Open admin/status subscriber if applicable. Verify operator telemetry can be shown separately.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-047 — Reconnect realtime connection

**User story:** As a public user, I want realtime to reconnect automatically, so that temporary network failures do not break the app.

### Acceptance criteria

- Unexpected disconnect enters reconnecting.
- Backoff used.
- Active watches resubscribe after reconnect.
- Recovery happens in the same browser page without Reload, a new navigation, or a manual Retry action.
- With the default local/production timings, reconnecting is visible within 10 seconds and a restored realtime service is connected with its active watch acknowledged within 30 seconds.

### Black-box test scenarios

1. Open a station, record its selected context and WebSocket acknowledgement, then stop the realtime service. Within 10 seconds, verify the single contextual notice says `Reconnecting to live updates…` while the station, map, and last authoritative data remain visible.
2. Leave the page open until fallback starts. Verify the browser polls a same-origin FjordPulse HTTP endpoint and never sends a request to Entur or SurrealDB.
3. Restore realtime without reloading or navigating. Within 30 seconds, verify a new socket connects, the active station/vehicle watch is acknowledged again, realtime updates resume, the exceptional notice clears, and the original selection remains open.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-048 — Fallback to periodic refresh

**User story:** As a public user, I want the app to fall back to polling when WebSocket is unavailable, so that the app remains usable.

### Acceptance criteria

- A contextual periodic-refresh notice appears after WebSocket failure.
- HTTP refresh continues station/departure data.
- Public UI says `Live connection interrupted · Updating periodically`; it does not require riders to interpret backend/realtime service cells.
- If both FjordPulse HTTP and realtime are unavailable, the map, selection, and last authoritative data remain visible while same-origin retries continue.
- Restoring both services recovers the existing page automatically; polling stops only after realtime reconnects and active watches resubscribe.
- With default timings, a full backend outage shows the unavailable/saved-information notice and reaches offline/polling plus backend-degraded in the client health model within 25 seconds; restoration returns backend/realtime to healthy within 30 seconds.

### Black-box test scenarios

1. Open a station, then stop realtime only. Within 10 seconds verify `Reconnecting to live updates…`; within 25 seconds verify `Live connection interrupted · Updating periodically` while successful same-origin station polling continues.
2. Stop FjordPulse HTTP as well and keep the complete outage active across at least one polling interval. Within 25 seconds, verify `Updates temporarily unavailable · Showing saved information`, the same station/map remain visible, failed polls are contained, the previous snapshot is not replaced with invented data, and retries target only same-origin FjordPulse endpoints.
3. Restart both services without using Reload, a new navigation, or a manual Retry button. Within 30 seconds, verify public health returns healthy, the exceptional notice clears, and a new WebSocket watch acknowledgement is received for the still-open station.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.


---

# Epic F — Entur integration and data freshness

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-049 — Configure Entur client identity

**User story:** As an operator, I want Entur requests to use a configured client name, so that the app behaves responsibly.

### Acceptance criteria

- Entur client identity configured.
- Missing config fails health.
- All requests use backend identity.

### Black-box test scenarios

1. Open admin status page in production. Verify Entur client identity/config status is OK.
2. Use admin Entur log after a station request. Verify server recorded an Entur request.
3. In staging/test with missing config, verify health page shows misconfiguration rather than silently running.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-050 — Fetch station departures

**User story:** As a system, I want to fetch departures from Entur Journey Planner for watched stations, so that station panels show real transport data.

### Acceptance criteria

- Watched station with stale cache triggers fetch.
- Data normalized/stored/emits event.
- A transient Journey Planner or Vehicle Positions failure is isolated: the failed source retains its previous authoritative values, the other source still refreshes, station identity remains available, and the aggregate snapshot becomes honestly stale/rate-limited rather than discarding usable data. The failed outcome is recorded and the active watch is marked failed/degraded.
- Each failed active watch schedules its next backend-originated Entur attempt 15 seconds later, plus at most one scheduler tick; shared request budgets still cap all attempts and prevent a busy retry loop.
- If Entur is restored before that due attempt, the same backend process and open browser page return to fresh data within 20 seconds without Reload or a manual Retry action.
- Every Entur attempt originates in the backend; the browser never switches to direct Entur access during either failure or recovery.

### Black-box test scenarios

1. Open a station not recently viewed. Verify departures load after initial loading state and the admin Entur log shows a backend Journey Planner request; reload soon afterward and verify a fresh cache hit does not force another upstream request.
2. In local/staging, fail only Journey Planner after one successful station snapshot while Vehicle Positions remains available. Keep the station open and verify its identity and saved departures/station-service coverage remain visible, nearby vehicles still refresh, the state is stale/degraded, the watch and admin log record the failure, and no browser request targets Entur. Repeat with only Vehicle Positions failing and verify fresh departures plus saved station-serving and nearby positions remain available.
3. Verify no new upstream attempt occurs before the configured 15-second retry delay and that the shared request budget remains enforced.
4. Restore the controlled Entur upstream without restarting FjordPulse or touching the page. Within 20 seconds, verify the next scheduled attempt succeeds, the watch error clears, fresh data/Last update advances on the same open station, and no synthetic observation was introduced.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-051 — Fetch station-serving and nearby vehicles

**User story:** As a system, I want to fetch reporting vehicles matched to services at a watched station and other vehicles near it, so that the station panel can distinguish route relevance from physical proximity.

### Acceptance criteria

- A watched station requests Journey Planner service calls from six hours before through six hours after refresh time, de-duplicates dated service journeys, prioritizes upcoming departures, and queries Vehicle Positions for at most 200 selected journeys alongside the exact 5 km nearby search.
- The snapshot separates matched passenger-service station vehicles (starting, approaching, at station, passed, or serving) from other nearby vehicles and exposes window/candidate/queried/truncated coverage. It includes only currently reporting Vehicle Positions results and never claims exhaustive all-Norway coverage. Non-passenger, lost, missing-identity, or changed-journey positions cannot retain an old station-serving relation during degraded refresh, though a current position may remain nearby.

### Black-box test scenarios

1. Open a controlled station with a reporting vehicle on a dated service that calls there but is more than 5 km away, plus an unrelated vehicle within 5 km. Verify both appear after the watch in their separate station-serving and other-nearby groups.
2. Use a station with more than 200 candidate dated journeys. Verify upcoming departures are prioritized and the public coverage warning reports queried versus candidate counts; move the map away without selecting a new station and verify unrelated refreshes are not triggered.
3. Open admin Entur log or inspect the backend request boundary. Verify one station refresh uses the bounded ±6-hour service-call candidates and a Vehicle Positions request combining at most 200 dated journey references with the station bounding box; verify the browser itself never calls Entur. While Journey Planner is unavailable, change a matched same-ID position to non-passenger or another journey and verify the stale serving relation is removed without hiding a genuinely nearby position.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-052 — Refresh focused vehicle

**User story:** As a system, I want to refresh focused vehicles more frequently than normal station data, so that Focus mode feels live.

### Acceptance criteria

- Focus refresh has higher cadence within rate limits.
- Stale/lost transitions emitted.
- Vehicle Positions movements are classified independently from position freshness as `passenger`, `non_passenger`, or `unknown`. Canonical service journeys remain passenger movements even when their Journey Planner lookup is temporarily unavailable; explicit dead runs and bounded provider-specific garage/internal movements are non-passenger.
- A `non_passenger` movement keeps its authoritative position and watch cadence but does not trigger repeated Journey Planner lookups for an identifier that is not a public service journey.

### Black-box test scenarios

1. Focus a vehicle and watch bottom/admin telemetry. Verify vehicle updates are more frequent than normal station departures.
2. Pause incoming vehicle fixture. Verify stale then lost transitions happen at configured thresholds.
3. Unfocus. Verify refresh cadence drops.
4. While focused, replace a completed passenger record with a newer explicit dead run/internal garage movement using the same vehicle ID. Verify the backend keeps refreshing its position without querying Journey Planner for that operational identifier, and automatically resumes passenger-journey enrichment if the same vehicle later reports a canonical service journey.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-053 — Apply global Entur rate limits

**User story:** As an operator, I want strict internal rate limits for Entur, so that the app does not abuse public APIs.

### Acceptance criteria

- Global and per-API budgets enforced.
- The configured global and per-API rolling allowances are visible in admin with their exact `ENTUR_*_REQUESTS_PER_MINUTE` settings, window, backend-only scope, and the distinction between FjordPulse's internal safeguard and Entur's provider-side quota.
- Excess requests delayed/skipped.

### Black-box test scenarios

1. Open Entur request log. Verify `Internal Entur request limit` identifies a FjordPulse backend safeguard, shows shared and per-service rolling limits with the corresponding `ENTUR_*_REQUESTS_PER_MINUTE` settings, links to the request history and official Entur rate-limit documentation, and does not describe the value as Entur's account balance or a browser-request limit.
2. Rapidly open many different stations in separate tabs. Verify budget does not exceed configured maximum in admin UI.
3. Verify excess requests show queued/skipped/backoff status rather than sending unlimited requests.

### Pass evidence

- Screenshot/video or Entur request-log observation proving the scenario passed.

## FP-054 — Handle Entur 429/backoff

**User story:** As an operator, I want rate-limit responses to trigger backoff, so that the app recovers safely.

### Acceptance criteria

- 429 triggers backoff.
- UI/admin shows rate-limited.
- Cached data used when available.
- An active watch retries automatically at or after Entur's `Retry-After`, never before it, and can recover the same open page without restarting the backend.

### Black-box test scenarios

1. Use a test backend mode that simulates Entur 429. Open a station. Verify admin Entur log shows Backoff/Rate limited.
2. Verify public UI shows stale/cached/backoff message, not a crash.
3. Keep the page open until `Retry-After` expires. Without Reload or a manual Retry action, verify the backend makes the scheduled attempt, updates state on success, and does not send an early request.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-055 — Distinguish fresh, stale, empty, unavailable

**User story:** As a public user, I want the UI to distinguish no data from old data and failed data, so that I can trust what I see.

### Acceptance criteria

- Resources use loading/fresh/refreshing/empty/stale/unavailable/error states.
- Visual states differ.
- Cached journey calls may be described as saved and possibly outdated only when a prior successful snapshot exists. A successful Journey Planner lookup returning no referenced journey, and a failed lookup with no cached success, use unavailable-details copy instead of claiming that a schedule is stale.
- Passenger-service classification is a separate typed dimension from vehicle position freshness and journey-source availability. The browser never derives `non_passenger` from warning text or from a null journey alone.

### Black-box test scenarios

1. Test station fresh, empty, stale, and error fixtures. Verify each state has distinct copy and styling.
2. Test vehicle fresh, stale, and lost fixtures. Verify each state is visually distinct.
3. Ask a non-developer tester what each state means. Verify meaning is understandable without explanation.
4. Compare a passenger journey with cached calls after a failed refresh, a canonical journey whose successful lookup returns no result, and a backend-classified non-passenger movement. Verify the UI respectively says saved schedule, unavailable journey details, and not in passenger service without exposing raw provider errors.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-056 — Do not fake transport data

**User story:** As a public user, I want FjordPulse to show only real public transport data, so that the app is trustworthy.

### Acceptance criteria

- Production never simulates vehicles.
- Missing data shown honestly.
- Mock data only in dev/visual tests.

### Black-box test scenarios

1. In production, open app during quiet period. Verify the app shows empty/stale states rather than invented movement.
2. During a controlled Entur vehicle-position outage in local/staging, observe a focused vehicle across at least two expected refreshes. Verify its marker/trail and source timestamp do not advance without a new upstream observation; only honest stale/lost state may advance.
3. Confirm any visual-test/mock mode is inaccessible or clearly disabled in production UI.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.


---

# Epic G — SurrealDB persistence and migrations

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-057 — Run SurrealDB in production

**User story:** As an operator, I want SurrealDB to run as a persistent service, so that FjordPulse data survives restarts.

### Acceptance criteria

- SurrealDB service persistent.
- Secrets configured.
- App health depends on DB connectivity.

### Black-box test scenarios

1. Open admin status. Verify SurrealDB status is OK.
2. Restart backend app service through Coolify UI. Verify station data still appears after reload.
3. Temporarily stop SurrealDB service in staging. Verify app/admin health shows DB unavailable.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-058 — Apply database migrations

**User story:** As a developer, I want versioned SurrealDB migrations, so that schema changes are repeatable.

### Acceptance criteria

- Successful migrations are recorded with name, checksum, and applied time; the CLI runner records bounded attempt state/time separately so a failed transaction remains diagnosable.
- Failures stop startup/deploy without committing a partial schema change.
- The CLI runner applies bundled migrations in order and is the only surface that can execute them.
- The protected Database/Migrations tab classifies `applied`, `pending`, `checksum_mismatch`, `orphaned`, and `failed`, compares release and database checksums, and shows applied/last-attempted times.
- Human descriptions, structured affected schema objects, bounded failure evidence, and bundled source are inspectable read-only; Admin has no Apply, Retry, Edit, Rollback, arbitrary path, or query control.

### Black-box test scenarios

1. From the deployment CLI/operator task, run the migration command. Verify it reports no pending migrations or applies migrations in filename order; verify Admin cannot invoke the command.
2. Open Database > Migrations. Verify the compatibility banner, five row states, both checksums, timestamps, descriptions, affected objects, and bundled read-only source are understandable, and that `/admin/migrations` resolves compatibly to this tab.
3. In staging/test with a deliberate bad migration, verify the deployment task fails visibly, the schema transaction does not partially commit, and the later read-only Admin row reports the failed attempt without offering Retry or exposing arbitrary files.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-059 — Define core tables

**User story:** As a developer, I want core SurrealDB tables defined, so that data has a consistent shape.

### Acceptance criteria

- Tables exist for stations, departures, vehicles, observations, watches, events, logs, health.
- Database > Current schema exposes the effective table, field, index, event, and normalized permission structure through one fixed, typed backend query only.

### Black-box test scenarios

1. Use Infrastructure for canonical catalog/state counts and Database > Current schema for effective structure. Expand and filter schema rows; verify fields, indexes, events, and permissions are readable without a query editor, direct database connection, or exposed users/password hashes.
2. Perform station and vehicle interactions. Verify relevant counts/events increase in admin views.
3. Restart services and verify counts remain available.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-060 — Store station data

**User story:** As a system, I want imported station data stored locally, so that the map can load without Entur calls on every pan.

### Acceptance criteria

- Station records contain id/name/type/coords/search/import time.
- Map uses local storage.

### Black-box test scenarios

1. Load the app and pan/zoom without selecting stations. Verify station clusters load even if Entur live API is delayed.
2. Open admin data/status. Verify station import count and last import time.
3. Search for a known station. Verify it appears from local index quickly.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-061 — Store current vehicle state

**User story:** As a system, I want current vehicle state stored, so that panels and watches can resume after reconnects.

### Acceptance criteria

- Vehicle state includes id, line, location, last seen, delay, bearing, freshness.

### Black-box test scenarios

1. Select a live vehicle, then refresh the browser. Verify latest known vehicle state can reappear if still watched/available.
2. Open admin vehicle/watch diagnostics. Verify current vehicle state is visible.
3. Disconnect/reconnect realtime. Verify vehicle panel resumes with last known state before fresh update.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-062 — Store recent vehicle observations

**User story:** As a public user, I want recent trail data for vehicles, so that I can understand recent movement.

### Acceptance criteria

- Observation retention bounded.
- Recent trail queries ordered/deterministic.

### Black-box test scenarios

1. Focus a vehicle and wait for several updates. Verify trail grows in ordered sequence.
2. Reload the page while vehicle is selected/focused. Verify recent trail can be restored if still within retention.
3. After retention period in staging/test, verify old trail points disappear.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-063 — Store realtime events

**User story:** As a developer/operator, I want realtime events persisted, so that debugging and reconnect behavior are easier.

### Acceptance criteria

- Events store type/scope/payload/time/source.
- Admin can inspect recent events.
- Retention cleanup exists.

### Black-box test scenarios

1. Open admin Recent events. Perform search, station watch, vehicle focus. Verify corresponding events appear.
2. Verify event timestamps and scopes are readable.
3. Use cleanup/maintenance in staging. Verify old events are removed according to policy.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.


---

# Epic H — Admin and observability

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-064 — Admin authentication

**User story:** As an admin, I want admin pages protected, so that internal system information is not public.

### Acceptance criteria

- Admin requires login; public users cannot access admin pages unless the operator explicitly enables the separate public demo identity.
- The login panel keeps `Return to public map` visible and, when demo access is enabled, offers a small localized `Fill demo credentials` action that never exposes the real operator credential.
- Production demo access defaults off. When explicitly enabled it uses a separate identity whose server session is restricted to an explicit allowlist of Admin diagnostic GETs plus logout, so future reads and mutations fail closed; the signed-in UI remains labelled as a public read-only demo.
- Session/token behavior documented.
- The signed-in identity and `Log out` action are visually separate; logout uses an explicit text label and exit icon, never a navigation chevron or account-detail treatment.

### Black-box test scenarios

1. Open `/admin/status` in a private browser. Verify the login screen and public-map return link appear. With demo access disabled, verify no demo action appears; enable it, verify `Fill demo credentials` fills only the separate demo identity, log in, and verify the persistent read-only-demo label and allowlisted diagnostics while hypothetical future read and mutation routes are rejected.
2. Log out, then log in as the real operator. Verify the admin dashboard loads without the operator password ever appearing in the demo-credential response or frontend source.
3. Verify the sidebar shows a non-interactive signed-in identity and a clearly labelled `Log out` button with an exit icon. Log out and use browser Back/Refresh; verify admin data is not visible.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-065 — Admin diagnostics information architecture

**User story:** As an admin, I want focused health, infrastructure, and database pages, so that I can quickly triage failures and inspect what is deployed.

### Acceptance criteria

- System status is a focused triage page with an explicit overall state, compact environment/data-mode/build context, Backend, grouped Realtime delivery, SurrealDB, Entur, and one neutral live-demand panel for browser connections plus active station/vehicle/Focus scopes. Realtime-server and database-event-bridge contract signals remain independent but appear as one card with separate state/latency subchecks and explicit failure detail. Healthy prose is suppressed; demand-driven Entur inactivity is neutral `NOT RECENTLY USED`, while only recent failures/backoff are degraded. Metrics without a real source are omitted and connection/watch counts are never presented as unique visitors.
- A distinct Infrastructure page owns the timestamped CPU usage/load, logical CPU count, free/used memory and host/cgroup scope, free/used application-filesystem space and inspected path, build/environment/data mode, credential-free SurrealDB origin/namespace/database and loopback warning, map-provider configuration boundary, catalog provenance/import age, and canonical data inventory. The internal FjordPulse-to-Entur rolling allowance is shown with the request evidence on Entur request log; routine and abnormal database notifications plus raw evidence remain on Persisted events.
- An active-watch count requires at least one persisted client, a future expiry, and a non-expired state. Zero-client disconnect-grace records are never reported as active; crash-era leases cease to count no later than the configured TTL, and startup prunes only past-expiry records so it cannot erase another process's still-valid demand.
- Navigation exposes one canonical `Systemstatus` / `System status` destination and one genuinely distinct `Infrastruktur` / `Infrastructure` destination. It does not render `Oversikt` / `Overview` as a second label for System status; `/admin` and the former `/admin/overview` shape may resolve compatibly. At mobile widths a labelled modal drawer keeps all destinations, connection state, signed-in identity, and Log out reachable, contains keyboard focus while open, closes from the scrim or Escape, and restores focus to Menu.
- Signed-in Admin navigation updates real, shareable URLs without reloading the document. The sidebar, identity, and session remain mounted while only page data changes; Back/Forward restores the correct active page or Database tab. A pending route retains the previous page inert beneath a small progress indicator, a failed route renders and focuses Retry inside the Admin content area, and late responses from an older route cannot replace the current page. Initial session loading and errors use a centred dark state card with safe mobile padding and reduced-motion support.
- A distinct Database destination uses URL-backed Current schema and Migrations tabs. Both are protected GET-only diagnostics: one fixed backend-owned, allowlisted INFO structure query is mapped by PHP instead of returning raw database metadata, migration source is limited to bundled files, users/password hashes/credentials never cross the boundary, and the UI has no query or mutation controls. Copy directs free-form record/query work to Surrealist through a private operator connection rather than embedding it in FjordPulse.

### Black-box test scenarios

1. Log in and open System Status. Verify it fits essentially one desktop viewport, presents the overall state before four user-facing service cards, and contains one active System Status destination plus a distinct Infrastructure destination and no Overview alias. Verify Realtime delivery exposes separate Server and Database events state/latency checks; degrade either signal and verify the aggregate and failing subcheck are explicit without hiding the other signal. Verify normal persisted-event rows, resource meters, database inventory, and the full Entur limit table are absent and replaced by clear links to their owning pages.
2. Generate activity by opening a station and focusing a vehicle in another tab. Verify connected-client and relevant active-watch counts change; close that tab and verify zero-client or expired watches no longer count as active. Restart realtime and verify past-expiry previous-process rows are pruned, any still-valid crash-era lease stops counting by its TTL, and a still-open browser reconnects and re-registers. No WebSocket/watch value is labelled unique visitors or unique people.
3. Open Infrastructure. Verify build/environment/data mode, map configuration boundary, refreshed CPU/load, memory, disk, sanitized database target, catalog import/source, and stored-data counts are visible and grouped separately. Refresh and verify the resource timestamp advances; unavailable metrics are omitted, credentials/RPC/query/fragment never appear, and staging/production loopback configuration produces a warning.
4. Open Entur request log and verify the internal allowance explains its rolling shared/per-service limits, exact settings, provider documentation, and non-quota meaning next to request evidence. Open Persisted events and verify lost/stale state, source, entity/version, explanation, and raw payload. Open Database and switch its URL-backed Current schema/Migrations tabs; verify the URL changes without a document reload, the same sidebar/session remains visible, Back/Forward restores the active tab, and copied/reloaded URLs still work. Verify the read-only boundary, private-Surrealist guidance, and absence of query/mutation controls or sensitive raw INFO metadata. Delay and fail one page request; verify the persistent-shell progress, that retained page controls cannot be activated while stale, and that focus moves to the padded in-content Retry state, then recover without signing in again. At 390 px open Menu, verify every diagnostics destination plus identity/state and Log out is reachable, tab forward/backward without escaping to page content, close from the scrim, reopen, then press Escape and verify focus returns to Menu without horizontal overflow.

### Pass evidence

- Screenshot/video or paired System status/Infrastructure observations proving the scenario passed.

## FP-066 — Active watches page

**User story:** As an admin, I want to see active station and vehicle watches, so that I can verify demand-driven collection works.

### Acceptance criteria

- Watches table shows type, scope, clients, priority, last/next refresh, and truthful lifecycle state; past-expiry rows are absent and zero-client grace rows are expiring, not active.

### Black-box test scenarios

1. Open Active watches page. Select a station publicly. Verify a station watch row appears.
2. Focus a vehicle. Verify a vehicle/focus watch row appears with high priority.
3. Close public tabs. Verify zero-client rows immediately stop contributing to active metrics, appear only as expiring during any configured grace period, and disappear after TTL. Restart realtime and verify past-expiry previous-process rows are removed while a deliberately unexpired lease is not destructively deleted.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-067 — Entur request log page

**User story:** As an admin, I want to inspect Entur requests, so that I can debug API issues and rate limits.

### Acceptance criteria

- Log shows time, API, scope, status, latency, count, cache, retry.
- Filters by type/status/scope/time.

### Black-box test scenarios

1. Open Entur request log. Click a station publicly. Verify a Journey Planner row appears.
2. Click/focus vehicle. Verify Vehicle Positions row appears.
3. Use filters to show only errors/backoff or API type. Verify table updates correctly.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-068 — Realtime diagnostics

**User story:** As an admin, I want realtime diagnostics, so that I can debug WebSocket and room behavior.

### Acceptance criteria

- Shows server status, clients, rooms, messages/min, reconnect/failures, last broadcast.

### Black-box test scenarios

1. Open realtime diagnostics. Open the public app in two tabs and select same station. Verify client and room counts change.
2. Close one tab. Verify counts decrease.
3. Restart realtime service in staging. Verify diagnostics show disconnect/reconnect/failure counters.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-069 — Health endpoint

**User story:** As an operator, I want machine-readable health endpoints, so that Coolify/monitoring can verify service status.

### Acceptance criteria

- HTTP health endpoint includes dependencies.
- Realtime status check exists.

### Black-box test scenarios

1. Visit `/api/health` in browser. Verify a readable JSON or status response appears.
2. Stop SurrealDB/realtime in staging and refresh health endpoint. Verify dependency status changes.
3. Verify Coolify/monitoring shows unhealthy when dependency is down, if configured.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-070 — Structured logs

**User story:** As a developer/operator, I want structured logs, so that production issues can be diagnosed quickly.

### Acceptance criteria

- Logs include request id/event type/scope/duration/status/error.
- No secrets.
- Accessible through Coolify.

### Black-box test scenarios

1. Open Coolify logs. Perform station search, station watch, vehicle focus. Verify meaningful log entries appear.
2. Trigger a validation error from UI/test harness. Verify structured error log appears.
3. Scan visible logs for secrets/tokens. Verify none are displayed.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.


---

# Epic I — Frontend visual states and responsiveness

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-071 — Implement generated desktop visual states

**User story:** As a user, I want desktop screens to match the approved mockup states, so that the product feels polished and predictable.

### Acceptance criteria

- Desktop states cover default, station, vehicle, fallback, search states, plus the expanded and collapsed introduction layout in Norwegian and English.
- The `NO`/`EN` switcher remains easy to reach, and localized headings, tabs, status chips, table cells, and action labels wrap or reflow without clipping, unintended horizontal scrolling, or obscuring map controls.

### Black-box test scenarios

1. Use the visual test scenario selector or fixtures to open each desktop state in both Norwegian and English. Compare visually to the packaged mockup and approved coded baselines.
2. Verify text, color, layout, and primary actions match the intended state.
3. Resize to common desktop widths in both languages. Verify localized text does not overflow buttons, cards, navigation, tables, or the viewport and panels do not overlap critical map controls.
4. Collapse and restore the introduction and change language with mouse and keyboard. Verify the map resizes, focus is preserved, and both explicit choices survive reload independently.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-072 — Implement mobile default map

**User story:** As a mobile user, I want a clean map-first mobile interface, so that FjordPulse works well on phones.

### Acceptance criteria

- Mobile default shows a full-screen map, compact top bar, clusters, bottom nav, a small labelled control for the collapsed introduction, and an always-reachable `NO`/`EN` switcher.
- Opening the introduction uses a compact bottom overlay; Norwegian and English text reflows without clipped controls or horizontal viewport overflow, and it is collapsed by default when no preference has been saved.

### Black-box test scenarios

1. Open on mobile viewport 390x844 or real phone in Norwegian and English. Verify the default map fills the screen and the language switcher remains visible without crowding the search control.
2. Verify bottom navigation has the corresponding localized Map, Search, Saved, Alerts, and Menu labels.
3. Verify no station panel is open initially.
4. Verify the introduction is initially collapsed, open it from the labelled restore control, and close it again in each language. Confirm text and actions remain fully visible, the map remains visible, and the explicit choices survive reload.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-073 — Implement mobile station bottom sheet

**User story:** As a mobile user, I want station details in a bottom sheet, so that the map remains visible while I inspect departures.

### Acceptance criteria

- Selecting a station opens a half-height bottom sheet with station, updated age or exceptional warning, count-badged Departures and Vehicles tabs, and Departures active by default. Full vehicle lists are not repeated below the departure board.

### Black-box test scenarios

1. On mobile, tap a station. Verify a half-height bottom sheet appears.
2. Verify selected marker remains visible above/behind the sheet.
3. Swipe/click controls and switch tabs inside the sheet. Verify large touch targets, stable station context, and no duplicated departure/vehicle lists.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-074 — Implement mobile station full sheet

**User story:** As a mobile user, I want to expand station details, so that I can inspect departures, relevant vehicles, and station facts without losing map context.

### Acceptance criteria

- Station sheet expands full height with non-overlapping Departures, Vehicles, and Details tabs usable by touch. Details keeps stable station facts readable while its plain-language data scope and collapsed technical fields avoid crowding the transport lists.

### Black-box test scenarios

1. With station sheet open, drag it upward or tap expand. Verify it becomes full-height.
2. Tap Departures, Vehicles, and Details. Verify content switches without losing station context; platform stays with departures, serving/nearby rows and collapsed coverage stay with Vehicles, and stable facts plus collapsed ID/coordinates/timezone stay with Details.
3. Collapse/close the sheet and verify map returns.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-075 — Implement mobile vehicle Focus mode

**User story:** As a mobile user, I want Focus mode to work naturally on a phone, so that I can follow a vehicle live.

### Acceptance criteria

- Mobile focus shows vehicle marker, trail, following pill, bottom sheet with details.

### Black-box test scenarios

1. On mobile, select vehicle and tap Focus. Verify centered vehicle marker and route/trail.
2. Verify green Following Line 100 pill and Pause control.
3. Verify bottom sheet shows line, route, last seen, delay, Unfocus/Details buttons.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-076 — Implement mobile vehicle lost state

**User story:** As a mobile user, I want a clear lost vehicle state, so that I know tracking has stopped or become unavailable.

### Acceptance criteria

- Mobile lost shows dimmed marker, warning sheet, last seen, Stop following/Try again.

### Black-box test scenarios

1. Use lost vehicle fixture on mobile. Verify bottom sheet warning appears.
2. Tap Try again. Verify retry/loading and outcome.
3. Tap Stop following. Verify lost state closes and map remains usable.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-077 — Implement design system components

**User story:** As a developer, I want reusable components for markers, chips, rows, banners, panels, and contextual update health, so that UI states remain consistent.

### Acceptance criteria

- Components exist for top bar, search, chips, markers, rows, pills, banners, skeletons, contextual update notices, source attribution, sheet header, and an accessible two-state language switcher shared by public, admin, and deterministic scenario surfaces.

### Black-box test scenarios

1. Open the component/storybook/design page if available. Verify each required component is shown in Norwegian and English, with `NO`/`EN` state exposed to assistive technology.
2. Compare components across desktop/mobile/admin screens and both languages for consistent colors/spacing and no label clipping or control overflow.
3. Change global status fixtures from healthy to reconnecting, periodic refresh, and unavailable. Verify healthy global chrome stays absent, one exceptional notice appears consistently, and resource-level ages/warnings remain independent.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.


---

# Epic J — Security, abuse prevention, and privacy

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-078 — Keep secrets server-side

**User story:** As an operator, I want secrets to remain server-side, so that users cannot access Entur configuration, admin tokens, or database credentials.

### Acceptance criteria

- No Entur/DB secrets in frontend.
- Secrets stored in Coolify env.
- Logs hide secrets.

### Black-box test scenarios

1. Open browser DevTools Sources and Network. Search visible frontend/network payloads for obvious secrets or DB credentials.
2. Use public app features. Verify frontend never receives Entur client secrets or SurrealDB credentials.
3. Open logs in Coolify and verify tokens/secrets are masked or absent.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-079 — Validate HTTP requests

**User story:** As a developer, I want all HTTP API inputs validated, so that malformed requests do not cause undefined behavior.

### Acceptance criteria

- Station IDs, vehicle IDs, bbox, zoom, filters validated.
- Invalid input returns 4xx JSON.

### Black-box test scenarios

1. In browser address bar or API client, request invalid station ID. Verify structured 4xx response.
2. Request invalid bbox/zoom values. Verify error response and no server crash.
3. Use UI after invalid requests. Verify app remains usable.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-080 — Validate WebSocket messages

**User story:** As a developer, I want all WebSocket messages validated, so that the realtime server is robust.

### Acceptance criteria

- Unknown/invalid/malformed/oversized messages handled safely.

### Black-box test scenarios

1. Use browser console/test harness to send unknown WS message type. Verify structured error response.
2. Send invalid payload for watch_station. Verify validation error and connection remains open.
3. Send malformed JSON/oversized message if harness supports it. Verify safe close/error.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-081 — Rate-limit public users

**User story:** As an operator, I want public user actions rate-limited, so that one user cannot overload the backend or Entur.

### Acceptance criteria

- Limits apply to search, watches, focus, retries, WS message frequency.
- Feedback shown.

### Black-box test scenarios

1. Rapidly type many searches or use automated keyboard input. Verify UI stays responsive and eventually throttles if needed.
2. Rapidly click many stations/focus buttons. Verify rate-limit feedback and admin budget protection.
3. Verify rate limit does not permanently block normal usage after cooldown.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-082 — Protect admin pages

**User story:** As an operator, I want admin pages protected from public access, so that internal telemetry is not exposed.

### Acceptance criteria

- Admin routes and APIs require auth.

### Black-box test scenarios

1. In private browser, open `/admin/status`, `/admin/watches`, and admin API endpoints. Verify access denied/login.
2. Log in, verify access. Log out, verify access removed.
3. Try direct refresh/deep links after logout. Verify protected.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-083 — Minimize personal data

**User story:** As a public user, I want the app to avoid unnecessary personal data collection, so that privacy risk is low.

### Acceptance criteria

- Public browsing no account.
- Random non-identifying sessions.
- No exact user location required.
- Privacy documentation distinguishes non-identifying UI preferences, including the stored `nb`/`en` language choice, from transport or account data.

### Black-box test scenarios

1. Open public app and use core features without signing in. Verify no account prompt.
2. Deny browser location permission if requested. Verify core features still work; ideally location is not requested.
3. Open privacy/about page. Verify data collection behavior is described and changing language stores only the selected locale value, not a user identity.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-084 — Handle CORS and origins correctly

**User story:** As an operator, I want allowed origins configured, so that only the intended frontend can use the API/WebSocket in production.

### Acceptance criteria

- Production CORS and WS origin checks enforced.
- Dev origins separate.

### Black-box test scenarios

1. From the production frontend domain, verify API and WebSocket work.
2. From an unauthorized origin/test page, attempt API/WS call. Verify request is rejected.
3. Verify dev/staging origins do not work against production unless intentionally allowed.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.


---

# Epic K — Deployment and operations

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-085 — Deploy on Hetzner CX33 with Coolify

**User story:** As an operator, I want FjordPulse deployed through Coolify on Hetzner, so that deployment is reproducible and manageable.

### Acceptance criteria

- CX33 provisioned.
- Coolify installed.
- Compose services deployed.
- Domain points to app.

### Black-box test scenarios

1. Open Coolify dashboard. Verify services for frontend/app/realtime/SurrealDB are running.
2. Open public domain. Verify app loads.
3. Restart services through Coolify UI. Verify app recovers.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-086 — Configure domain

**User story:** As a public user, I want to access the app at `fjordpulse.kavik.cz`, so that the project has a stable URL.

### Acceptance criteria

- Domain resolves.
- HTTPS enabled.
- WSS works.
- HTTP redirects.

### Black-box test scenarios

1. Open `https://fjordpulse.kavik.cz`. Verify valid HTTPS lock.
2. Open `http://fjordpulse.kavik.cz`. Verify redirect to HTTPS.
3. Use a station/Focus feature. Verify realtime connects over WSS.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-087 — Run FrankenPHP HTTP app

**User story:** As a system, I want the CakePHP HTTP app served through FrankenPHP normal mode, so that HTTP routes are stable and modern.

### Acceptance criteria

- CakePHP 6 app runs PHP 8.5.
- FrankenPHP serves endpoints.
- Health works.
- Errors logged.

### Black-box test scenarios

1. Open `/api/health`. Verify it returns OK.
2. Open a valid API endpoint through the app. Verify JSON/data works.
3. Trigger a controlled HTTP error. Verify user receives structured response and logs show it.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-088 — Run AMPHP realtime process

**User story:** As a system, I want the realtime process to run continuously, so that WebSocket clients receive updates.

### Acceptance criteria

- Managed service runs/restarts.
- Health reported.
- Startup/shutdown/errors logged.

### Black-box test scenarios

1. Open admin realtime/status page. Verify realtime process is healthy.
2. Open public app and select station. Verify WebSocket connected.
3. Restart realtime process through Coolify. Verify clients reconnect and admin logs show restart.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-089 — Run SurrealDB with persistence

**User story:** As a system, I want SurrealDB data to persist across restarts and deploys, so that imported stations and history are not lost.

### Acceptance criteria

- Persistent volume used.
- Restart preserves data.
- Backup/restore documented.

### Black-box test scenarios

1. Record the station-catalog count from Infrastructure. Restart SurrealDB service. Verify the count remains.
2. Run a backup task or verify latest backup artifact exists.
3. In staging, restore backup and verify app can read stations.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-090 — Provide environment configuration

**User story:** As a developer/operator, I want all environment variables documented, so that deploys are reproducible.

### Acceptance criteria

- `.env.example` or docs exist.
- Startup fails clearly if critical vars missing.
- Secrets not committed.

### Black-box test scenarios

1. Open docs or deployment README. Verify required environment variables are listed with descriptions.
2. In staging, remove a required variable and restart. Verify health/startup error clearly identifies missing config.
3. Use public app and logs; verify secrets are not displayed.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-091 — Support zero/manual rollback

**User story:** As an operator, I want to roll back a bad deployment, so that production can recover quickly.

### Acceptance criteria

- Previous version can be restored.
- Migration rollback/forward policy documented.

### Black-box test scenarios

1. Perform a staging deployment. Then use Coolify/Git tag to redeploy previous version.
2. Verify app returns to previous version by visible version indicator or admin build info.
3. Review deployment docs to confirm DB migration policy is explicit.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-092 — Scheduled maintenance

**User story:** As a system, I want slow maintenance tasks to run outside the realtime hot path, so that realtime remains responsive.

### Acceptance criteria

- Cleanup/import/backup tasks scheduled.
- Run outside realtime hot path.

### Black-box test scenarios

1. Open Coolify scheduled tasks. Verify maintenance tasks exist.
2. Run cleanup/import task manually in staging. Verify app remains responsive during the task.
3. Check admin/logs after run. Verify task result is visible.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.


---

# Epic L — Testing and quality

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-093 — Static analysis passes

**User story:** As a developer, I want PHPStan and TypeScript checks to pass, so that the codebase benefits from modern typing.

### Acceptance criteria

- PHPStan and TypeScript checks run and block failures.

### Black-box test scenarios

1. From CI/deployment UI, run quality checks. Verify PHPStan and TypeScript pass.
2. In staging/branch with intentional type error, verify checks fail visibly.
3. Verify release/deploy checklist includes static analysis status.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-094 — Unit-test Entur mappers

**User story:** As a developer, I want Entur response mappers tested, so that API shape changes or missing fields do not break the app silently.

### Acceptance criteria

- Tests cover normal/delayed/cancelled/scheduled/missing optional/vehicle variants.

### Black-box test scenarios

1. Open CI test report. Verify Entur mapper test suite exists and passes.
2. Use test fixture selector in app if available to display normal/delayed/cancelled data. Verify UI shows expected states.
3. Review test report names; no code inspection required.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-095 — Unit-test realtime message validation

**User story:** As a developer, I want realtime message validators tested, so that invalid client messages are handled safely.

### Acceptance criteria

- Tests cover valid/invalid/unknown/malformed/oversized messages.

### Black-box test scenarios

1. Open CI test report. Verify realtime validation tests pass.
2. Use browser console/test harness to send invalid WS messages. Verify behavior matches test expectations.
3. Confirm realtime server remains connected after invalid non-fatal messages.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-096 — Integration-test watch lifecycle

**User story:** As a developer, I want watch lifecycle tests, so that station and vehicle demand-driven collection works correctly.

### Acceptance criteria

- Tests cover create/share/expire station watch, focus watch, stale/lost transitions.

### Black-box test scenarios

1. Open CI report. Verify watch lifecycle integration tests pass.
2. Manually reproduce: open same station in two tabs, check admin watch clients, close tabs, verify expiration.
3. Manually focus stale/lost fixture and verify state transitions.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-097 — Resilience-test backend and Entur recovery

**User story:** As a developer, I want deterministic outage and recovery tests, so that temporary FjordPulse or Entur failures recover automatically instead of requiring a reload or process restart.

### Acceptance criteria

- A clean-stack Playwright test stops the actual FjordPulse HTTP and realtime services externally, verifies the still-open page reaches fallback while preserving its map/selection/data, then restarts them and proves a new socket plus watch acknowledgement on the same document.
- The browser test enforces the default bounds: reconnecting within 10 seconds, backend-degraded plus offline/polling within 25 seconds, and complete same-page recovery within 30 seconds of service restoration.
- A backend service-boundary fault-injection test makes the controlled Entur HTTP upstream unavailable/5xx, proves the prior snapshot remains authoritative, and proves a budgeted scheduled retry succeeds after restoration without restarting PHP.
- Entur recovery attempts are backend-only, use the 15-second failure retry delay, and never synthesize production vehicle movement.

### Black-box test scenarios

1. Run the clean-stack full-backend outage scenario. Verify its trace shows one page/document retaining the selected station and map through the outage, bounded degraded/offline/polling states, service restart, a new WebSocket/watch acknowledgement, and backend/realtime recovery without `reload()` or a new navigation.
2. Run the Entur service-boundary outage scenario. Verify the controlled upstream transitions success -> unavailable/5xx -> success, the snapshot and last-success time are preserved during failure, no attempt occurs before 15 seconds, and the next scheduled attempt recovers within 20 seconds of restoration without a backend restart.
3. Inspect the browser requests and outage observations. Verify the browser contacted only FjordPulse (plus the approved map provider), never Entur or SurrealDB, and no vehicle position/timestamp advanced without an authoritative upstream observation.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-098 — Visual-test core desktop states

**User story:** As a developer, I want visual tests for core desktop states, so that UI regressions are caught.

### Acceptance criteria

- Visual tests cover every listed desktop, admin, and design-system state in both Norwegian and English, assert the matching document language, and guard localized control/card/table overflow at supported widths.

### Black-box test scenarios

1. Open the visual regression report. Verify every desktop/admin/design-system route has separate Norwegian and English screenshots.
2. Compare current screenshots with approved baselines. Verify differences are intentional and no translated label is clipped, overlaps another control, or introduces unexpected horizontal scrolling.
3. Manually open each fixture state in the browser, switch `NO`/`EN`, and compare both results to the design bundle and coded baselines.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-099 — Visual-test mobile states

**User story:** As a developer, I want visual tests for mobile states, so that the app remains usable on phones.

### Acceptance criteria

- Visual tests cover mobile default, station sheets, vehicle focus, and vehicle lost in both Norwegian and English at the supported mobile widths.

### Black-box test scenarios

1. Open the mobile visual regression report. Verify all five mobile states exist in both Norwegian and English.
2. Run on a real mobile device or browser mobile emulation. Switch `NO`/`EN` and verify both layouts match the design bundle without horizontal viewport overflow.
3. Rotate or test common viewport heights and narrow supported widths. Verify localized headings, navigation items, and action labels remain readable and no critical button is clipped or hidden.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-100 — Accessibility baseline

**User story:** As a public user with accessibility needs, I want FjordPulse to be keyboard-usable and readable, so that the app is not mouse-only.

### Acceptance criteria

- Search/list keyboard access.
- Visible focus.
- Contrast.
- Non-color-only status.
- Accessible button labels, an announced two-state language control, and a document language matching the selected locale.

### Black-box test scenarios

1. Use keyboard only from page load: switch `NO`/`EN`, open search, navigate results, open a station, and close the panel; verify the language choice is visibly selected and persists after reload.
2. Use browser accessibility/contrast checker. Verify text and buttons meet baseline contrast.
3. Use a screen reader or accessibility tree inspector in each language. Verify the document language changes and important buttons, statuses, and the language switcher have meaningful localized labels and state.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-101 — Performance baseline

**User story:** As a public user, I want the app to remain responsive, so that map interaction and live updates do not feel sluggish.

### Acceptance criteria

- Map responsive during refreshes.
- Marker counts bounded.
- Messages batched/throttled.
- Memory stable.

### Black-box test scenarios

1. Use performance profile while panning/zooming and receiving updates. Verify no long freezes.
2. Zoom to dense regions. Verify clusters prevent thousands of DOM markers.
3. Run a 30-minute Focus session in staging. Verify memory and FPS remain acceptable.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-102 — Production smoke test

**User story:** As an operator, I want a repeatable production smoke test, so that I can verify deploys quickly.

### Acceptance criteria

- Smoke verifies public load, health, WS, search, station, admin, status.

### Black-box test scenarios

1. After deployment, follow smoke checklist: open app, health endpoint, WebSocket connect, search `førde`, open station, admin login, status healthy.
2. Record pass/fail in release notes.
3. If any step fails, deployment is not considered complete.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.


---

# Epic M — Documentation and handoff

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-103 — Architecture documentation

**User story:** As a developer, I want architecture documentation, so that the project can be understood and maintained.

### Acceptance criteria

- Docs describe frontend, CakePHP, AMPHP, SurrealDB, Entur, watches, deployment.

### Black-box test scenarios

1. Open architecture docs. Verify all major components are described with diagrams or clear text.
2. Ask a reviewer to explain the data flow from station click to vehicle update using only docs.
3. Verify docs mention why CakePHP does HTTP/control and AMPHP does realtime.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-104 — Local development documentation

**User story:** As a developer, I want local setup instructions, so that I can run the project from scratch.

### Acceptance criteria

- Docs include versions, commands, local DB, migrations, mock mode, HTTP app, realtime process, and an explicit trusted-LAN phone-testing mode that exposes only the frontend proxy and prints the usable device URL.

### Black-box test scenarios

1. On a clean machine/container, follow docs step by step without asking the original author.
2. Verify local app loads and mock backend states work.
3. Verify realtime process can be started and browser connects locally. Start the documented phone-testing mode, then verify its printed LAN URL loads from a 390 px browser, proxies healthy HTTP and WebSocket traffic, renders configured cartography, and leaves database/backend/realtime listeners on loopback.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-105 — Deployment documentation

**User story:** As an operator, I want deployment documentation, so that production can be recreated.

### Acceptance criteria

- Docs include Hetzner, Coolify, domain, env vars, services, deploy, rollback.

### Black-box test scenarios

1. Open deployment docs. Verify they include server size, domain, service list, environment variables, and deploy commands.
2. Have a reviewer use docs to create a staging deployment.
3. Verify rollback procedure can be followed in staging.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-106 — Entur usage documentation

**User story:** As a maintainer, I want Entur integration documented, so that API usage remains responsible.

### Acceptance criteria

- Docs cover APIs, backend-only rule, client identity, budgets, caching, stale/backoff.

### Black-box test scenarios

1. Open Entur docs page. Verify Journey Planner and Vehicle Positions usage are explained.
2. Verify rate budget/caching/backoff values are listed.
3. Verify public-browser-to-Entur is explicitly forbidden.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-107 — UI state documentation

**User story:** As a frontend developer, I want UI states documented, so that implementation matches the visual design.

### Acceptance criteria

- Docs map each UI state to screenshot, component state, data state, action, backend behavior.

### Black-box test scenarios

1. Open UI state docs. Verify every packaged mockup has a corresponding state description.
2. Choose three states and manually trigger them in the app.
3. Verify data state, visible UI, and backend behavior match the docs.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-108 — Production readiness checklist

**User story:** As the project owner, I want a final checklist, so that I know when FjordPulse is complete.

### Acceptance criteria

- Checklist includes stories, tests, visual tests, deploy, admin, Entur, fake-data ban, docs, backups, security.

### Black-box test scenarios

1. Open readiness checklist. Verify all required areas are listed with checkbox/status.
2. Before launch, mark each item Pass/Fail with evidence link or screenshot.
3. Do not consider production complete until all critical items are Pass.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
