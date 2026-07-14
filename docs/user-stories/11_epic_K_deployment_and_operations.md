# Epic K — Deployment and operations

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-085 — Deploy on Sharptech with Coolify

**User story:** As an operator, I want FjordPulse deployed through Coolify on the selected Sharptech VPS, so that deployment is reproducible and manageable.

### Acceptance criteria

- Sharptech Medium VPS provisioned and hardened.
- Coolify installed.
- Compose services deployed.
- Domain points to app.

### Black-box test scenarios

1. Open Coolify dashboard. Verify services for frontend/app/realtime/SurrealDB are running.
2. Open public domain. Verify app loads.
3. Restart services through Coolify UI. Verify app recovers.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-086 — Configure domain

**User story:** As a public user, I want to access the app at `fjordpulse.kavik.cz`, so that the project has a stable URL.

### Acceptance criteria

- Domain resolves.
- HTTPS enabled.
- WSS works.
- HTTP redirects.

### Black-box test scenarios

1. Open `https://fjordpulse.kavik.cz`. Verify valid HTTPS lock.
2. Open `http://fjordpulse.kavik.cz`. Verify redirect to HTTPS.
3. Use a station/Focus feature. Verify realtime connects over WSS.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-087 — Run FrankenPHP HTTP app

**User story:** As a system, I want the CakePHP HTTP app served through FrankenPHP normal mode, so that HTTP routes are stable and modern.

### Acceptance criteria

- CakePHP 6 app runs PHP 8.5.
- FrankenPHP serves endpoints.
- Health works.
- Errors logged.

### Black-box test scenarios

1. Open `/api/health`. Verify it returns OK.
2. Open a valid API endpoint through the app. Verify JSON/data works.
3. Trigger a controlled HTTP error. Verify user receives structured response and logs show it.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-088 — Run AMPHP realtime process

**User story:** As a system, I want the realtime process to run continuously, so that WebSocket clients receive updates.

### Acceptance criteria

- Managed service runs/restarts.
- Health reported.
- Startup/shutdown/errors logged.

### Black-box test scenarios

1. Open admin realtime/status page. Verify realtime process is healthy.
2. Open public app and select station. Verify WebSocket connected.
3. Restart realtime process through Coolify. Verify clients reconnect and admin logs show restart.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-089 — Run SurrealDB with persistence

**User story:** As a system, I want SurrealDB data to persist across restarts and deploys, so that imported stations and history are not lost.

### Acceptance criteria

- Persistent volume used.
- Restart preserves data.
- Backup/restore documented.

### Black-box test scenarios

1. Record the station-catalog count from Infrastructure. Restart SurrealDB service. Verify the count remains.
2. Run a backup task or verify latest backup artifact exists.
3. In staging, restore backup and verify app can read stations.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-090 — Provide environment configuration

**User story:** As a developer/operator, I want all environment variables documented, so that deploys are reproducible.

### Acceptance criteria

- `.env.example` or docs exist.
- Startup fails clearly if critical vars missing.
- Secrets not committed.

### Black-box test scenarios

1. Open docs or deployment README. Verify required environment variables are listed with descriptions.
2. In staging, remove a required variable and restart. Verify health/startup error clearly identifies missing config.
3. Use public app and logs; verify secrets are not displayed.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-091 — Support zero/manual rollback

**User story:** As an operator, I want to roll back a bad deployment, so that production can recover quickly.

### Acceptance criteria

- Previous version can be restored.
- Migration rollback/forward policy documented.

### Black-box test scenarios

1. Perform a staging deployment. Then use Coolify/Git tag to redeploy previous version.
2. Verify app returns to previous version by visible version indicator or admin build info.
3. Review deployment docs to confirm DB migration policy is explicit.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-092 — Scheduled maintenance

**User story:** As a system, I want slow maintenance tasks to run outside the realtime hot path, so that realtime remains responsive.

### Acceptance criteria

- Cleanup/import/backup tasks scheduled.
- Run outside realtime hot path.

### Black-box test scenarios

1. Open Coolify scheduled tasks. Verify maintenance tasks exist.
2. Run cleanup/import task manually in staging. Verify app remains responsive during the task.
3. Check admin/logs after run. Verify task result is visible.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
