# FjordPulse infrastructure

The actionable Sharptech/manual-Coolify/Netlify rollout sequence, host
hardening, production blockers, private Surrealist tunnel, demo-Admin decision,
backup proof, and rollback gates live in
[`docs/PRODUCTION_DEPLOYMENT_PLAN.md`](../docs/PRODUCTION_DEPLOYMENT_PLAN.md).
The Sharptech host exists, but the plan must be completed before FjordPulse is
used in production.

Two profiles serve different purposes:

- `compose.yaml` is the generic single-host/development-oriented topology. It
  retains explicit networks and a directly published application port.
- `compose.coolify.yaml` is the production candidate. It lets Coolify own the
  deployment network/proxy, exposes only app container port 8080 to that proxy,
  keeps exactly one realtime replica, stores SurrealDB in a stable named volume
  with RocksDB, and binds the database only to host
  `127.0.0.1:18000` for an SSH tunnel.

The Coolify profile is production-only. Its stable global volume names and
loopback port `18000` intentionally prevent an accidental same-host clone from
being treated as isolated. Run staging on a separate disposable host; do not
launch a second Compose resource from this profile on the production server.

Neither profile makes Entur or SurrealDB browser-accessible. Startup is
deliberately ordered:

```text
healthy SurrealDB
  -> checksum-verified migrations + database-scoped app/viewer users
  -> idempotent canonical station import
  -> one healthy AMPHP/Revolt realtime replica
  -> FrankenPHP/CakePHP HTTP + SPA + /live proxy
```

## Required production values

Copy `.env.example` to an environment managed by Coolify or another secret store. At minimum, replace:

- `APP_ORIGIN` and `ALLOWED_ORIGINS` with the final HTTPS origin;
- `TRUSTED_PROXIES` with the exact Coolify proxy-network CIDR observed on the
  deployed host, never a guessed broad private range;
- all SurrealDB root/application passwords;
- `SURREAL_OPERATOR_PASSWORD` with an independent viewer-only database secret;
- `ADMIN_PASSWORD` and `ADMIN_SESSION_SECRET`;
- `ENTUR_CLIENT_NAME` with an identifiable owner/application value (this is a
  non-secret `ET-Client-Name` identity header, not an API key or OAuth client);
- `REALTIME_PUBLIC_URL` with the final `wss://.../live` URL;
- `MAPTILER_API_KEY` with a dedicated read-only browser key restricted to the
  deployed HTTPS origin;
- Restic plus S3-compatible repository credentials scoped to an independent
  private backup prefix.

Never commit the resulting `.env`. Production must use `APP_ENV=production`, `APP_DEBUG=false`, and `DATA_MODE=real`; runtime configuration rejects fake production mode and weak/default secrets. MapTiler configuration is delivered to the browser by `/api/map/config`; visitors never provide their own keys.

Public Admin demo access is a separate opt-in in the application runtime. The
Coolify production profile deliberately sets `ADMIN_DEMO_ACCESS=true` for this
public demonstration; a private deployment should override it to `false`. Set
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

Validate both topology and backup tooling before any deployment:

```bash
npm run infra
```

For local/generic topology work only:

```bash
docker compose -f infra/compose.yaml build
docker compose -f infra/compose.yaml up -d
docker compose -f infra/compose.yaml ps
curl --fail https://fjordpulse.example/api/health
curl --fail https://fjordpulse.example/api/readiness
```

Production uses `infra/compose.coolify.yaml` through Coolify after Gate 0. It is
not a substitute for the disposable-host Compose proof, exact-SHA CI run,
external listener scan, or deployed smoke suite in the production plan.

The `migrate` and `stations` containers are successful one-shot prerequisites, not long-running replicas. The station prerequisite imports the complete catalog (no fixed record cap) before application startup. The realtime service is fixed at one replica for v1. `/api/health` exposes degraded fallback status; `/api/readiness` returns failure when the authoritative database is unavailable. Operator diagnostics live under `/admin`.

HTTP abuse budgets are stored in lock-protected files so FrankenPHP classic
requests share one limiter state. This v1 design assumes the single `app`
replica in the Compose profile; a container replacement can reset at most the
current 60-second window. Scaling the HTTP service requires replacing this
single-host store with an explicitly designed shared limiter first.

## Persistence, operator access, backup, and rollback

The stable `fjordpulse-production-surreal-data` RocksDB volume is authoritative.
SurrealDB has no public domain; routine Surrealist/CLI inspection uses the
database-scoped `VIEWER` identity through SSH forwarding to
`127.0.0.1:18000`. The root and application identities are not desktop-tool
credentials.

`Dockerfile.backup` and `infra/scripts/` create checksum-backed logical exports
and encrypted Restic snapshots in independent S3-compatible storage. Restore
requires a distinct `RESTORE_HTTP_URL`, dedicated restore-root credentials and
an empty target database; it refuses the configured source endpoint even when
the namespace/database differs, and rejects source-root username or password
reuse so a syntactically different endpoint alias cannot bypass that boundary.
Backup and restore share one maintenance lock,
so a scheduled export cannot overlap a recovery drill. Static validation is not
recovery evidence: a real off-host backup, retention run and isolated app-level
restore smoke are release gates. Sharptech's best-effort provider backup is
optional convenience, not FjordPulse recovery.

Migrations are checksum-verified and forward-only; rollback means restoring the
matching protected pre-release logical backup and previous application image,
not editing an applied migration.

Before a release, run all root quality gates and verify the live Entur smoke from an approved backend network. After release, confirm health and live demand in `/admin/status`, deployment/database/resource identity in `/admin/infrastructure`, realtime bridge detail in `/admin/realtime`, station freshness in the public detail surface, and internal Entur limits beside request evidence in `/admin/entur-log`.

The provisioned Sharptech Medium VPS currently has Ubuntu, verified key-only
SSH, UFW, Fail2ban, a 2 GiB swap file, and unattended security updates. Docker
and Coolify are not installed yet. Persistent Docker-aware forwarding rules,
TLS/domain setup, DNS changes, production secrets, independent S3
configuration, live backup/restore proof, staging, and rollout remain
intentionally unperformed.
