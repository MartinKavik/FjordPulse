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
- A transient Entur connection or 5xx failure preserves the previous authoritative snapshot and `lastSuccessfulAt`, records the failed outcome, and marks the active watch failed/degraded.
- Each failed active watch schedules its next backend-originated Entur attempt 15 seconds later, plus at most one scheduler tick; shared request budgets still cap all attempts and prevent a busy retry loop.
- If Entur is restored before that due attempt, the same backend process and open browser page return to fresh data within 20 seconds without Reload or a manual Retry action.
- Every Entur attempt originates in the backend; the browser never switches to direct Entur access during either failure or recovery.

### Black-box test scenarios

1. Open a station not recently viewed. Verify departures load after initial loading state and the admin Entur log shows a backend Journey Planner request; reload soon afterward and verify a fresh cache hit does not force another upstream request.
2. In local/staging, make only the backend's controlled Entur upstream unavailable or return 5xx after one successful snapshot. Keep the station open and verify the prior data/time remains authoritative, degraded/unavailable state is honest, the admin log records the failure, and no browser request targets Entur.
3. Verify no new upstream attempt occurs before the configured 15-second retry delay and that the shared request budget remains enforced.
4. Restore the controlled Entur upstream without restarting FjordPulse or touching the page. Within 20 seconds, verify the next scheduled attempt succeeds, the watch error clears, fresh data/Last update advances on the same open station, and no synthetic observation was introduced.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-051 — Fetch nearby vehicles

**User story:** As a system, I want to fetch vehicles near watched stations, so that the app can show relevant live vehicles without loading all Norway.

### Acceptance criteria

- Watched station triggers bounded nearby vehicle refresh.
- Only nearby relevant vehicles shown.

### Black-box test scenarios

1. Open a station with nearby vehicles. Verify vehicle list appears after station watch.
2. Move map away without selecting stations. Verify new unrelated vehicle fetches are not triggered.
3. Open admin Entur log. Verify Vehicle Positions scope is station bbox or similar bounded scope.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-052 — Refresh focused vehicle

**User story:** As a system, I want to refresh focused vehicles more frequently than normal station data, so that Focus mode feels live.

### Acceptance criteria

- Focus refresh has higher cadence within rate limits.
- Stale/lost transitions emitted.

### Black-box test scenarios

1. Focus a vehicle and watch bottom/admin telemetry. Verify vehicle updates are more frequent than normal station departures.
2. Pause incoming vehicle fixture. Verify stale then lost transitions happen at configured thresholds.
3. Unfocus. Verify refresh cadence drops.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-053 — Apply global Entur rate limits

**User story:** As an operator, I want strict internal rate limits for Entur, so that the app does not abuse public APIs.

### Acceptance criteria

- Global and per-API budgets enforced.
- Budget visible in admin.
- Excess requests delayed/skipped.

### Black-box test scenarios

1. Open admin status/log page. Verify rate budget is displayed.
2. Rapidly open many different stations in separate tabs. Verify budget does not exceed configured maximum in admin UI.
3. Verify excess requests show queued/skipped/backoff status rather than sending unlimited requests.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

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

### Black-box test scenarios

1. Test station fresh, empty, stale, and error fixtures. Verify each state has distinct copy and styling.
2. Test vehicle fresh, stale, and lost fixtures. Verify each state is visually distinct.
3. Ask a non-developer tester what each state means. Verify meaning is understandable without explanation.

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
