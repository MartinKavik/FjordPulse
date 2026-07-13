# FjordPulse infrastructure

`compose.yaml` is the deployment-ready, single-host v1 topology. SurrealDB stays on the internal network. The station importer and realtime worker also join a non-published egress network so they can call Entur, but neither publishes a host port; only FrankenPHP/Caddy is exposed. Startup is deliberately ordered:

```text
healthy SurrealDB
  -> checksum-verified migrations + database-scoped app user
  -> idempotent canonical station import
  -> one healthy AMPHP/Revolt realtime replica
  -> FrankenPHP/CakePHP HTTP + SPA + /live proxy
```

## Required production values

Copy `.env.example` to an environment managed by Coolify or another secret store. At minimum, replace:

- `APP_ORIGIN` and `ALLOWED_ORIGINS` with the final HTTPS origin;
- all SurrealDB root/application passwords;
- `ADMIN_PASSWORD` and `ADMIN_SESSION_SECRET`;
- `ENTUR_CLIENT_NAME` with an identifiable owner/application value (this is a
  non-secret `ET-Client-Name` identity header, not an API key or OAuth client);
- `REALTIME_PUBLIC_URL` with the final `wss://.../live` URL;
- `MAPTILER_API_KEY` with a dedicated read-only browser key restricted to the
  deployed HTTPS origin.

Never commit the resulting `.env`. Production must use `APP_ENV=production`, `APP_DEBUG=false`, and `DATA_MODE=real`; runtime configuration rejects fake production mode and weak/default secrets. MapTiler configuration is delivered to the browser by `/api/map/config`; visitors never provide their own keys.

Public Admin demo access is a separate opt-in. Production defaults
`ADMIN_DEMO_ACCESS=false`. A public demonstration may set it to `true` and set
`ADMIN_DEMO_USERNAME` / `ADMIN_DEMO_PASSWORD`; those demo values are
intentionally returned to the login panel and must never equal the real
operator username/password, Admin session secret, or SurrealDB application
password. Demo sessions are middleware-limited to an explicit allowlist of
Admin diagnostic `GET` routes plus logout. Enabling this still publishes operational diagnostics,
request evidence, watch scopes, schema, and bundled migration source to every
visitor, so leave it off for a private operator console. It does not enable
fake transport data in production.

The Entur APIs used by FjordPulse are open and require no signup or Entur
credential. MapTiler is the only browser map provider requiring an operator
key. The public UI retains neutral `Transport data: Entur` attribution in real
mode and makes fake development mode explicit with a prominent Demo data badge.

## Bring-up and checks

```bash
docker compose -f infra/compose.yaml build
docker compose -f infra/compose.yaml up -d
docker compose -f infra/compose.yaml ps
curl --fail https://fjordpulse.example/api/health
curl --fail https://fjordpulse.example/api/readiness
```

The `migrate` and `stations` containers are successful one-shot prerequisites, not long-running replicas. The station prerequisite imports the complete catalog (no fixed record cap) before application startup. The realtime service is fixed at one replica for v1. `/api/health` exposes degraded fallback status; `/api/readiness` returns failure when the authoritative database is unavailable. Operator diagnostics live under `/admin`.

## Persistence, backup, and rollback

The `surreal-data` volume is authoritative. Snapshot or stop-and-copy it before schema changes, test restoration regularly, and retain the exact image/application version with each backup. Migrations are checksum-verified and forward-only; rollback means restoring the matching volume backup and previous application image, not editing an applied migration.

Before a release, run all root quality gates and verify the live Entur smoke from an approved backend network. After release, confirm health and live demand in `/admin/status`, deployment/database/resource identity in `/admin/infrastructure`, realtime bridge detail in `/admin/realtime`, station freshness in the public detail surface, and internal Entur limits beside request evidence in `/admin/entur-log`.

Actual Hetzner provisioning, Coolify installation, TLS/domain setup, DNS changes, production secrets, backup scheduling, and rollout are intentionally not performed by this repository task.
