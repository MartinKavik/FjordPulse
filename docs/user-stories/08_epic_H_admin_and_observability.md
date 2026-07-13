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
- A distinct Database destination uses URL-backed Current schema and Migrations tabs. Both are protected GET-only diagnostics: one fixed backend-owned, allowlisted INFO structure query is mapped by PHP instead of returning raw database metadata, migration source is limited to bundled files, users/password hashes/credentials never cross the boundary, and the UI has no query or mutation controls. Copy directs free-form record/query work to Surrealist through a private operator connection rather than embedding it in FjordPulse.

### Black-box test scenarios

1. Log in and open System Status. Verify it fits essentially one desktop viewport, presents the overall state before four user-facing service cards, and contains one active System Status destination plus a distinct Infrastructure destination and no Overview alias. Verify Realtime delivery exposes separate Server and Database events state/latency checks; degrade either signal and verify the aggregate and failing subcheck are explicit without hiding the other signal. Verify normal persisted-event rows, resource meters, database inventory, and the full Entur limit table are absent and replaced by clear links to their owning pages.
2. Generate activity by opening a station and focusing a vehicle in another tab. Verify connected-client and relevant active-watch counts change; close that tab and verify zero-client or expired watches no longer count as active. Restart realtime and verify past-expiry previous-process rows are pruned, any still-valid crash-era lease stops counting by its TTL, and a still-open browser reconnects and re-registers. No WebSocket/watch value is labelled unique visitors or unique people.
3. Open Infrastructure. Verify build/environment/data mode, map configuration boundary, refreshed CPU/load, memory, disk, sanitized database target, catalog import/source, and stored-data counts are visible and grouped separately. Refresh and verify the resource timestamp advances; unavailable metrics are omitted, credentials/RPC/query/fragment never appear, and staging/production loopback configuration produces a warning.
4. Open Entur request log and verify the internal allowance explains its rolling shared/per-service limits, exact settings, provider documentation, and non-quota meaning next to request evidence. Open Persisted events and verify lost/stale state, source, entity/version, explanation, and raw payload. Open Database and switch its URL-backed Current schema/Migrations tabs; verify refresh/back/share behavior, the read-only boundary, the private-Surrealist guidance, and absence of query/mutation controls or sensitive raw INFO metadata. At 390 px open Menu, verify every diagnostics destination plus identity/state and Log out is reachable, tab forward/backward without escaping to page content, close from the scrim, reopen, then press Escape and verify focus returns to Menu without horizontal overflow.

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
