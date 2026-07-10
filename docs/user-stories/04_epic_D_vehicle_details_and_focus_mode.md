# Epic D — Vehicle details and Focus mode

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-026 — Select a nearby vehicle

**User story:** As a public user, I want to click a nearby vehicle, so that I can inspect its details.

### Acceptance criteria

- Clicking vehicle row/marker opens panel.
- Map highlights vehicle.

### Black-box test scenarios

1. Open a station with nearby vehicles and click a vehicle row. Verify the vehicle panel opens.
2. Click a visible vehicle marker on the map. Verify the same vehicle panel opens.
3. Verify station context remains visible or can be navigated back to.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-027 — Show selected vehicle details

**User story:** As a public user, I want selected vehicle details, so that I can understand what the vehicle is and where it is going.

### Acceptance criteria

- Panel shows line, route, status, last seen, delay, bearing/direction, vehicle ID, next stop if available.

### Black-box test scenarios

1. Select a vehicle. Verify the panel shows Line 100, route text, Live/Stale/Lost badge, last seen, delay, and ID.
2. Use fixture with missing optional bearing/next stop. Verify panel handles missing fields gracefully.
3. Verify the Focus button is visible for selectable live/stale vehicles.

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
- Keep watching / Stop watching buttons.
- Map marker faded.

### Black-box test scenarios

1. Use a stale vehicle fixture or pause vehicle updates. Verify panel shows amber Stale badge and `Last seen 2 min ago`.
2. Verify the map marker is faded/greyed with muted trail.
3. Verify Keep watching and Stop watching buttons are visible.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-036 — Keep watching stale vehicle

**User story:** As a focus user, I want to keep watching a stale vehicle, so that I can wait for live data to resume.

### Acceptance criteria

- Keep watching continues watch.
- Fresh data returns UI to live/following.

### Black-box test scenarios

1. In stale vehicle state, click Keep watching. Verify the panel remains in watching/stale mode rather than closing.
2. Restore or simulate a fresh vehicle update. Verify the UI returns to live/following.
3. Verify admin Watches still shows an active focus/vehicle watch.

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

**User story:** As a focus user, I want a clear lost vehicle state when the vehicle disappears from the watched area, so that I know tracking is no longer active.

### Acceptance criteria

- Lost badge, explanation, Stop following, Try again, Last seen, dimmed last position.

### Black-box test scenarios

1. Use lost vehicle fixture. Verify panel shows Lost badge and message.
2. Verify last known marker is dimmed on the map.
3. Verify Stop following and Try again buttons are available.

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
