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
