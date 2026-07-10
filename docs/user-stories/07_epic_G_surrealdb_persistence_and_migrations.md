# Epic G — SurrealDB persistence and migrations

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-057 — Run SurrealDB in production

**User story:** As an operator, I want SurrealDB to run as a persistent service, so that FjordPulse data survives restarts.

### Acceptance criteria

- SurrealDB service persistent.
- Secrets configured.
- App health depends on DB connectivity.

### Black-box test scenarios

1. Open admin status. Verify SurrealDB status is OK.
2. Restart backend app service through Coolify UI. Verify station data still appears after reload.
3. Temporarily stop SurrealDB service in staging. Verify app/admin health shows DB unavailable.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-058 — Apply database migrations

**User story:** As a developer, I want versioned SurrealDB migrations, so that schema changes are repeatable.

### Acceptance criteria

- Migrations recorded with name/checksum/time.
- Failures stop startup/deploy.
- Runner applies in order.

### Black-box test scenarios

1. From admin/deployment UI, run the migration task. Verify it reports no pending migrations or applied migrations.
2. Open admin status or migration page if present. Verify applied migration list is visible.
3. In staging with a deliberate bad migration, verify deployment/migration task fails visibly and does not partially hide failure.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-059 — Define core tables

**User story:** As a developer, I want core SurrealDB tables defined, so that data has a consistent shape.

### Acceptance criteria

- Tables exist for stations, departures, vehicles, observations, watches, events, logs, health.

### Black-box test scenarios

1. Use admin status/data diagnostics pages. Verify counts/sections exist for stations, departures, vehicles, observations, watches, events, logs.
2. Perform station and vehicle interactions. Verify relevant counts/events increase in admin views.
3. Restart services and verify counts remain available.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-060 — Store station data

**User story:** As a system, I want imported station data stored locally, so that the map can load without Entur calls on every pan.

### Acceptance criteria

- Station records contain id/name/type/coords/search/import time.
- Map uses local storage.

### Black-box test scenarios

1. Load the app and pan/zoom without selecting stations. Verify station clusters load even if Entur live API is delayed.
2. Open admin data/status. Verify station import count and last import time.
3. Search for a known station. Verify it appears from local index quickly.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-061 — Store current vehicle state

**User story:** As a system, I want current vehicle state stored, so that panels and watches can resume after reconnects.

### Acceptance criteria

- Vehicle state includes id, line, location, last seen, delay, bearing, freshness.

### Black-box test scenarios

1. Select a live vehicle, then refresh the browser. Verify latest known vehicle state can reappear if still watched/available.
2. Open admin vehicle/watch diagnostics. Verify current vehicle state is visible.
3. Disconnect/reconnect realtime. Verify vehicle panel resumes with last known state before fresh update.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-062 — Store recent vehicle observations

**User story:** As a public user, I want recent trail data for vehicles, so that I can understand recent movement.

### Acceptance criteria

- Observation retention bounded.
- Recent trail queries ordered/deterministic.

### Black-box test scenarios

1. Focus a vehicle and wait for several updates. Verify trail grows in ordered sequence.
2. Reload the page while vehicle is selected/focused. Verify recent trail can be restored if still within retention.
3. After retention period in staging/test, verify old trail points disappear.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-063 — Store realtime events

**User story:** As a developer/operator, I want realtime events persisted, so that debugging and reconnect behavior are easier.

### Acceptance criteria

- Events store type/scope/payload/time/source.
- Admin can inspect recent events.
- Retention cleanup exists.

### Black-box test scenarios

1. Open admin Recent events. Perform search, station watch, vehicle focus. Verify corresponding events appear.
2. Verify event timestamps and scopes are readable.
3. Use cleanup/maintenance in staging. Verify old events are removed according to policy.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
