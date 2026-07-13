# 20_admin_status: Admin system status

**Image:** `20_admin_status.png`  
**Category:** Admin/dev  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Operator dashboard showing deployment identity, backend, realtime, SurrealDB/catalog state, Entur, truthful active demand, useful operational metrics, data counts, and a compact recent-activity preview.

## Why this screen matters

This page is crucial for debugging the realtime architecture and demonstrating operational maturity.

## Key visual elements

- Admin left navigation.
- One canonical `Systemstatus` / `System status` navigation item represents this operator dashboard. Do not add a parallel `Oversikt` / `Overview` link to the same route; a second item is appropriate only if it gains a distinct operator task and page.
- An easily reached `NO`/`EN` switcher in the admin header and login surface, including when the desktop sidebar is hidden on mobile.
- Compact status cards for Backend, realtime delivery, SurrealDB, Entur API, and map tiles. The realtime-delivery card groups the server and database-event bridge as two separately labelled checks, with their own state and latency, and links to Realtime diagnostics.
- Deployment identity: application version, environment, and real/fake data mode.
- The signed-in operator identity is a non-interactive card; a separate red-outlined `Log out` button with an exit icon makes the session-ending action explicit and visually distinct from navigation or account details.
- Credential-free database target identity: engine, WebSocket origin, namespace, database name, and any deployment warning.
- Metrics: connected clients and actively watched station/vehicle scopes. A selected-vehicle watch is shared by scope; Focus watches are per connected browser session. Zero-client or expired records are not counted as active.
- A dedicated `FjordPulse → Entur allowance` card explains the app-configured rolling request safeguard, shows the global available amount and every per-service limit with its exact `ENTUR_*_REQUESTS_PER_MINUTE` setting, and links to the request log and Entur's provider documentation. It must never imply that this internal allowance is an Entur account quota or an incoming-browser rate limit.
- Current host/container resources: sampled CPU utilisation with 1/5/15-minute load, free and used memory with its host/cgroup scope, and free and used application-filesystem space with the inspected path. Accessible utilisation bars change tone at warning/danger thresholds.
- SurrealDB/catalog diagnostics: canonical table counts, station import count and time, and source version.
- A latest-five database-notification preview with state-specific `LOST`/`STALE` labels and a clear link to the complete Persisted events page. The overview does not duplicate the full raw diagnostic log.

## Implementation notes

- Service status, detail, metric explanation, and table text must remain comfortably readable at normal desktop zoom. Supporting diagnostic text is body content, not decorative microcopy: use at least 14 px for details/table text and at least 12 px for compact status labels, with sufficient contrast and meaning that does not depend on color alone.
- Localize operator-facing headings, states, explanations, controls, and table labels reactively; keep provider/product names, identifiers, URLs, scopes, timestamps, and raw payload evidence unchanged. Norwegian and English copy may wrap but must not clip cards, navigation, metrics, actions, or tables.
- This is backed by protected health, canonical SurrealDB diagnostics, build/configuration metadata, and recent event data; do not replace missing values with fixture claims.
- Keep the backend health contract's realtime-server and live-query-bridge fields separate because they can fail independently. Group them only in the System-status presentation: use the plain-language `Realtime delivery` / `Sanntidslevering` label once, show `Server` and `Database events` as compact subchecks, surface a failing subcheck's explanation, and keep the full technical detail on Realtime diagnostics.
- The protected database target displays only its WebSocket origin, namespace, and database name. The backend strips credentials, `/rpc`, query, and fragment before the value reaches the browser. A loopback target in staging or production is highlighted because `localhost` resolves inside the running service/container and commonly indicates a deployment misconfiguration.
- Metrics without a real data source are omitted; the dashboard must not reserve space for permanently empty placeholders.
- Entur is demand-driven: when no request was recorded in the last five minutes, the card is neutral `IDLE` and says availability will be measured on the next request. Only a recent failure, timeout, rate limit, or backoff is `DEGRADED`.
- Resource values are a timestamped current snapshot refreshed on demand, not a historical chart. Unavailable measurements are omitted rather than rendered as permanent dashes. The disk card names the inspected application filesystem and must not imply it is a separate database volume when deployment isolates that volume.
- The overview shows no more than five recent event summaries and uses their semantic states (`LOST` or `STALE`, rather than generic `WARNING`). Source, entity/version, human explanation, and raw persisted payload remain inspectable on the dedicated Persisted events page.
- The Entur allowance is FjordPulse's own configurable safeguard over backend-originated calls. The displayed window is rolling, so it must not claim that all requests reset at one future instant. A backend call consumes both the shared allowance and its service-specific allowance; fake adapters do not consume Entur allowance.
- Active WebSocket clients and watches are operational concurrency/demand metrics, not unique people. Do not infer or label them as unique visitors. Visitor counts require separately designed, privacy-reviewed anonymous-session instrumentation, documented retention, and clear wording that sessions are not people.
- Active-watch counts require a persisted client count, a future expiry, and a non-expired state. Disconnect-grace records may remain briefly visible as expiring diagnostics but are not active. Startup prunes only past-expiry records left by previous realtime processes—never another process's still-valid lease—while any crash-era lease ages out within the configured TTL and a still-open browser re-registers after reconnecting.
- Keep it available behind admin auth only.
- Use it during demos to prove the architecture is demand-driven and healthy.
- Should not depend on mock data once backend exists.

## Suggested visual/regression scenarios

- `admin_status`
- `backend ok card`
- `realtime connected card`
- `deployment and SurrealDB diagnostics`
- `latest-five event preview and full-history link`
- `Norwegian and English status layouts`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
