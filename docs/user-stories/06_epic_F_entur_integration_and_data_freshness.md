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
