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
