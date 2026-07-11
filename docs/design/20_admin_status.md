# 20_admin_status: Admin system status

**Image:** `20_admin_status.png`  
**Category:** Admin/dev  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Operator dashboard showing deployment identity, backend, realtime, SurrealDB/catalog state, Entur, watches, useful operational metrics, data counts, and inspectable recent events.

## Why this screen matters

This page is crucial for debugging the realtime architecture and demonstrating operational maturity.

## Key visual elements

- Admin left navigation.
- Status cards for Backend, Realtime server, SurrealDB, Entur API.
- Deployment identity: application version, environment, and real/fake data mode.
- The signed-in operator identity is a non-interactive card; a separate red-outlined `Log out` button with an exit icon makes the session-ending action explicit and visually distinct from navigation or account details.
- Credential-free database target identity: engine, WebSocket origin, namespace, database name, and any deployment warning.
- Metrics: clients, station watches, vehicle watches, and current rate budget.
- Current host/container resources: sampled CPU utilisation with 1/5/15-minute load, free and used memory with its host/cgroup scope, and free and used application-filesystem space with the inspected path. Accessible utilisation bars change tone at warning/danger thresholds.
- SurrealDB/catalog diagnostics: canonical table counts, station import count and time, and source version.
- Recent events table with state-specific `LOST`/`STALE` labels and expandable source, entity, version, explanation, and persisted payload evidence.

## Implementation notes

- Service status, detail, metric explanation, and table text must remain comfortably readable at normal desktop zoom. Supporting diagnostic text is body content, not decorative microcopy: use at least 14 px for details/table text and at least 12 px for compact status labels, with sufficient contrast and meaning that does not depend on color alone.
- This is backed by protected health, canonical SurrealDB diagnostics, build/configuration metadata, and recent event data; do not replace missing values with fixture claims.
- The protected database target displays only its WebSocket origin, namespace, and database name. The backend strips credentials, `/rpc`, query, and fragment before the value reaches the browser. A loopback target in staging or production is highlighted because `localhost` resolves inside the running service/container and commonly indicates a deployment misconfiguration.
- Metrics without a real data source are omitted; the dashboard must not reserve space for permanently empty placeholders.
- Entur is demand-driven: when no request was recorded in the last five minutes, the card is neutral `IDLE` and says availability will be measured on the next request. Only a recent failure, timeout, rate limit, or backoff is `DEGRADED`.
- Resource values are a timestamped current snapshot refreshed on demand, not a historical chart. Unavailable measurements are omitted rather than rendered as permanent dashes. The disk card names the inspected application filesystem and must not imply it is a separate database volume when deployment isolates that volume.
- A recent event row is expandable. The summary uses its semantic state (`LOST` or `STALE`, rather than generic `WARNING`) and the detail exposes the persisted source, entity/version, human explanation, and raw payload needed to diagnose it.
- Active WebSocket clients and watches are operational concurrency/demand metrics, not unique people. Do not infer or label them as unique visitors. Visitor counts require separately designed, privacy-reviewed anonymous-session instrumentation, documented retention, and clear wording that sessions are not people.
- Keep it available behind admin auth only.
- Use it during demos to prove the architecture is demand-driven and healthy.
- Should not depend on mock data once backend exists.

## Suggested visual/regression scenarios

- `admin_status`
- `backend ok card`
- `realtime connected card`
- `deployment and SurrealDB diagnostics`
- `recent event expanded payload`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
