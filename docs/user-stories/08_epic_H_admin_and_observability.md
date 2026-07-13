# Epic H — Admin and observability

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-064 — Admin authentication

**User story:** As an admin, I want admin pages protected, so that internal system information is not public.

### Acceptance criteria

- Admin requires login.
- Public cannot access admin pages.
- Session/token behavior documented.
- The signed-in identity and `Log out` action are visually separate; logout uses an explicit text label and exit icon, never a navigation chevron or account-detail treatment.

### Black-box test scenarios

1. Open `/admin/status` in a private browser. Verify login/unauthorized screen appears.
2. Log in as admin. Verify admin dashboard loads.
3. Verify the sidebar shows a non-interactive signed-in identity and a clearly labelled `Log out` button with an exit icon. Log out and use browser Back/Refresh; verify admin data is not visible.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-065 — System status page

**User story:** As an admin, I want a system status page, so that I can quickly see if FjordPulse is healthy.

### Acceptance criteria

- Shows backend, realtime, SurrealDB, Entur, connected clients, truthful active watches, an explained internal FjordPulse-to-Entur rolling allowance, a latest-five persisted-event preview, readable status/detail typography, build/environment/data mode, catalog provenance/import age and canonical data counts; shows a timestamped current resource snapshot with sampled CPU usage/load, logical CPU count, free/used memory and host/cgroup scope, plus free/used application-filesystem space and inspected path; the protected database diagnostic shows the credential-free endpoint origin, namespace, and name, strips credentials/path/query/fragment, and warns on staging/production loopback targets; metrics without a real data source are omitted; realtime-server and database-event-bridge contract signals remain independent but appear as one compact `Realtime delivery` card with two state/latency subchecks and a diagnostics link; a failing subcheck remains explicit; a demand-driven Entur source with no request in five minutes is neutral `IDLE`, while only recent failures/backoff are `DEGRADED`; `LOST`/`STALE` summaries use semantic labels and link to the full Persisted events page, where source, entity/version, explanation, and raw payload evidence are inspectable; WebSocket/watch counts are never presented as unique visitors, which require separate privacy-reviewed anonymous-session instrumentation.
- An active-watch count requires at least one persisted client, a future expiry, and a non-expired state. Zero-client disconnect-grace records are never reported as active; crash-era leases cease to count no later than the configured TTL, and startup prunes only past-expiry records so it cannot erase another process's still-valid demand.
- The sidebar exposes one canonical `Systemstatus` / `System status` destination for this operator dashboard. It does not render a second `Oversikt` / `Overview` label for the same route; `/admin` and the former `/admin/overview` shape may resolve compatibly without becoming duplicate navigation items.

### Black-box test scenarios

1. Log in and open System Status. Verify the sidebar contains one active System Status destination and no parallel Overview link to the same page. At normal desktop zoom, verify service states, details, and metric explanations are comfortably readable. Verify realtime appears once as `Realtime delivery`, with separately labelled Server and Database events state/latency checks and a Realtime diagnostics link; degrade either backend signal and verify that subcheck and the aggregate become degraded without hiding the other signal. Verify build, environment, data mode, CPU usage/load, free/used memory and scope, free/used application disk and path, the sanitized SurrealDB endpoint origin/namespace/name, catalog import, and canonical data counts are visible. Refresh and verify the resource measurement timestamp advances; no unavailable metric is rendered as a permanent placeholder. Verify the database target contains no credentials, RPC path, query, or fragment; a staging/production loopback target must show a warning.
2. Generate activity by opening a station and focusing a vehicle in another tab. Verify connected-client and relevant active-watch counts change; close that tab and verify zero-client or expired watches no longer count as active. Restart realtime and verify past-expiry previous-process rows are pruned, any still-valid crash-era lease stops counting by its TTL, and a still-open browser reconnects and re-registers. No WebSocket/watch value is labelled unique visitors or unique people.
3. Verify the Entur allowance explicitly identifies itself as a FjordPulse backend safeguard, explains its rolling window and shared/per-service limits, and links to the request log and official provider documentation without claiming to be Entur's account quota. Let the demand-driven Entur request log age beyond five minutes and verify the service card says `IDLE` without degrading the system.
4. Produce more than five persisted events, including stale/lost vehicle events. Verify System status shows at most the latest five summaries, uses `STALE`/`LOST` instead of generic `WARNING`, and links to Persisted events; verify the full page exposes source, entity/version, explanation, and raw persisted payload.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

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
