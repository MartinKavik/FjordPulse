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

## FP-097 — Integration-test fallback mode

**User story:** As a developer, I want fallback-mode tests, so that the app remains usable when realtime is down.

### Acceptance criteria

- Tests verify fallback when WS unavailable and HTTP refresh continues.

### Black-box test scenarios

1. In staging, stop realtime service. Verify frontend enters fallback mode.
2. Keep station panel open for a polling interval. Verify data refreshes or Last update changes.
3. Open CI report. Verify fallback integration test exists and passes.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-098 — Visual-test core desktop states

**User story:** As a developer, I want visual tests for core desktop states, so that UI regressions are caught.

### Acceptance criteria

- Visual tests cover all listed desktop states.

### Black-box test scenarios

1. Open visual regression report. Verify screenshots exist for all desktop states.
2. Compare current screenshots with approved baselines. Verify differences are intentional.
3. Manually open each fixture state in browser and compare to design bundle.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-099 — Visual-test mobile states

**User story:** As a developer, I want visual tests for mobile states, so that the app remains usable on phones.

### Acceptance criteria

- Visual tests cover mobile default, station sheets, vehicle focus, vehicle lost.

### Black-box test scenarios

1. Open mobile visual regression report. Verify all five mobile states exist.
2. Run on real mobile or browser mobile emulation. Verify layouts match design bundle.
3. Rotate or test common viewport heights. Verify no critical buttons are hidden.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-100 — Accessibility baseline

**User story:** As a public user with accessibility needs, I want FjordPulse to be keyboard-usable and readable, so that the app is not mouse-only.

### Acceptance criteria

- Search/list keyboard access.
- Visible focus.
- Contrast.
- Non-color-only status.
- Accessible button labels.

### Black-box test scenarios

1. Use keyboard only from page load: open search, navigate results, open station, close panel.
2. Use browser accessibility/contrast checker. Verify text and buttons meet baseline contrast.
3. Use screen reader or accessibility tree inspector. Verify important buttons/statuses have meaningful labels.

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
