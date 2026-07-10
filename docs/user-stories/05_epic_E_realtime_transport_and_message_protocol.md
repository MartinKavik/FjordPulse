# Epic E — Realtime transport and message protocol

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-040 — Connect browser WebSocket

**User story:** As a public user, I want the app to connect to realtime updates when needed, so that station and vehicle data can update without manual refresh.

### Acceptance criteria

- WebSocket opens when station/Focus active.
- Connection status states are visible.

### Black-box test scenarios

1. Open the app without selecting anything. Verify realtime is idle/disabled if that is intended.
2. Open a station. Verify status changes to connecting then connected.
3. Use browser network offline/online or restart realtime service. Verify reconnecting/offline/fallback states.

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

### Black-box test scenarios

1. Open a station, then briefly disable network. Verify reconnecting state appears and old data remains.
2. Re-enable network. Verify state returns to connected and station updates resume.
3. Verify selected station/vehicle context is preserved after reconnect.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-048 — Fallback to periodic refresh

**User story:** As a public user, I want the app to fall back to polling when WebSocket is unavailable, so that the app remains usable.

### Acceptance criteria

- Fallback mode appears after WS failure.
- HTTP refresh continues station/departure data.
- UI says fallback/polling.

### Black-box test scenarios

1. Stop realtime service or block WS endpoint. Open station. Verify app enters fallback mode.
2. Wait for the polling interval. Verify station data refreshes via visible last update.
3. Restore realtime. Verify app reconnects or prompts to return to live mode.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
