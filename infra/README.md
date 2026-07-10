# FjordPulse infrastructure

`compose.yaml` is the deployment-ready, single-host v1 topology. It keeps SurrealDB and the realtime worker on an internal network and exposes only FrankenPHP/Caddy. Startup is deliberately ordered:

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
- `ENTUR_CLIENT_NAME` with an identifiable owner/application value;
- `REALTIME_PUBLIC_URL` with the final `wss://.../live` URL.

Never commit the resulting `.env`. Production must use `APP_ENV=production`, `APP_DEBUG=false`, and `DATA_MODE=real`; runtime configuration rejects fake production mode and weak/default secrets.

## Bring-up and checks

```bash
docker compose -f infra/compose.yaml build
docker compose -f infra/compose.yaml up -d
docker compose -f infra/compose.yaml ps
curl --fail https://fjordpulse.example/api/health
curl --fail https://fjordpulse.example/api/readiness
```

The `migrate` and `stations` containers are successful one-shot prerequisites, not long-running replicas. The realtime service is fixed at one replica for v1. `/api/health` exposes degraded fallback status; `/api/readiness` returns failure when the authoritative database is unavailable. Operator diagnostics live under `/admin`.

## Persistence, backup, and rollback

The `surreal-data` volume is authoritative. Snapshot or stop-and-copy it before schema changes, test restoration regularly, and retain the exact image/application version with each backup. Migrations are checksum-verified and forward-only; rollback means restoring the matching volume backup and previous application image, not editing an applied migration.

Before a release, run all root quality gates and verify the live Entur smoke from an approved backend network. After release, confirm health, readiness, realtime bridge status, station freshness, and Entur request budgets in `/admin/status`.

Actual Hetzner provisioning, Coolify installation, TLS/domain setup, DNS changes, production secrets, backup scheduling, and rollout are intentionally not performed by this repository task.
