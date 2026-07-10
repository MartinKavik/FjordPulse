# Epic H — Admin and observability

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-064 — Admin authentication

**User story:** As an admin, I want admin pages protected, so that internal system information is not public.

### Acceptance criteria

- Admin requires login.
- Public cannot access admin pages.
- Session/token behavior documented.

### Black-box test scenarios

1. Open `/admin/status` in a private browser. Verify login/unauthorized screen appears.
2. Log in as admin. Verify admin dashboard loads.
3. Log out and use browser Back/Refresh. Verify admin data is not visible.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-065 — System status page

**User story:** As an admin, I want a system status page, so that I can quickly see if FjordPulse is healthy.

### Acceptance criteria

- Shows backend, realtime, SurrealDB, Entur, clients, watches, budget, latency, recent events.

### Black-box test scenarios

1. Log in and open System Status. Verify all specified cards are visible.
2. Generate activity by opening a station in another tab. Verify active watch/client counts change.
3. Simulate Entur delay/realtime offline in staging. Verify status cards change color/text.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-066 — Active watches page

**User story:** As an admin, I want to see active station and vehicle watches, so that I can verify demand-driven collection works.

### Acceptance criteria

- Watches table shows type, scope, clients, priority, last/next refresh, state.

### Black-box test scenarios

1. Open Active watches page. Select a station publicly. Verify a station watch row appears.
2. Focus a vehicle. Verify a vehicle/focus watch row appears with high priority.
3. Close public tabs. Wait TTL. Verify rows expire or state changes.

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
