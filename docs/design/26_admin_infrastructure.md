# 26_admin_infrastructure: Admin infrastructure

**Image:** coded-state reference; reviewed Playwright baselines live under `tests/visual/__snapshots__`  
**Category:** Admin/dev  
**State represented:** Deployment identity, host/container capacity, sanitized database target, map configuration, catalog provenance, and stored-data inventory.

## Why this screen matters

Infrastructure answers a different operator question from System status: what
is deployed here, where is it connected, and is it near a capacity or data
configuration limit?

## Content ownership

- Deployment identity: environment, build, real/demo data mode, and whether
  Entur calls are disabled by the demo profile.
- Credential-free SurrealDB WebSocket origin, namespace, database name, and any
  staging/production loopback warning. Credentials, `/rpc`, query, and fragment
  never reach the browser.
- MapTiler configuration state with copy that says provider reachability is
  checked in the browser, not by the protected status endpoint.
- One timestamped resource snapshot: sampled CPU utilization and load,
  logical-core count, free/used memory and host/cgroup scope, and free/used
  application-filesystem space with the inspected path.
- Station-catalog count, import time, source version, current vehicle/snapshot
  and retained-observation inventory, persisted-event count, and backend Entur
  request-log count.
- Links delegate event evidence and source-request evidence to their dedicated
  pages. Migration checks remain on Migrations.

## Presentation rules

- Deployment identity, resources, and stored data are three visibly separated
  sections with 28–32 px vertical rhythm.
- Unavailable resource measurements are omitted instead of becoming permanent
  dash cards. Resource meters use warning/danger thresholds and accessible
  progress values.
- Norwegian and English cards reflow without clipped labels or horizontal page
  scrolling. The modal mobile Menu drawer keeps this route and Log out
  reachable, contains keyboard focus, and closes from its scrim or Escape.
- The page reuses the protected `AdminStatus` response; no second backend
  contract or duplicate source of truth is introduced.

## Suggested visual/regression scenarios

- `admin_infrastructure` in Norwegian and English
- open mobile Infrastructure drawer in Norwegian and English
- local/demo and staging/loopback-warning states
- desktop and 390 px no-overflow checks
- omitted unavailable resource measurements
