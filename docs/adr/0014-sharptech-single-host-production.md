# ADR 0014 — Sharptech single-host production and recovery boundary

## Status

Accepted for the first production rollout. Deployment acceptance is still
pending Gate 0 staging, backup/restore and exact-SHA evidence.

## Context

ADR 0007 selected a single-host v1 topology but did not bind it to a provider.
The actual host is now a Sharptech Medium VPS in Norway at
`185.248.146.194`, running Ubuntu 24.04 on x86_64 with 4 vCPU, 8 GiB marketed
RAM and 100 GB NVMe. It was provisioned as a normal server, so Coolify must be
installed manually.

Sharptech does not document a provider firewall or infrastructure automation
API for this service. Its terms make the customer responsible for backups and
describe provider operational backups as best effort rather than guaranteed
recovery. The shared-resource policy also prohibits extended sustained CPU use
above 30% of the allocated resources, and the plan includes 1 TiB transfer.
FjordPulse is currently a public demo with reproducible application code and
non-valuable transport cache/state. The operator explicitly accepts rebuilding
it after total host or disk loss instead of creating another storage-provider
account.

## Decision

Use this Sharptech VPS for the first FjordPulse production deployment with the
existing single-host v1 boundary:

- install and operate Coolify manually after SSH and host hardening;
- make Coolify's proxy the only public application entry point on TCP 80/443;
- enforce a Docker-aware host firewall and externally verify listeners because
  no provider firewall is assumed;
- run exactly one realtime replica;
- use the pinned SurrealDB 3.2.0 image with RocksDB on a stable named volume;
- bind SurrealDB only to server loopback `127.0.0.1:18000` and reach it through
  an SSH tunnel;
- bootstrap a separate database-scoped `VIEWER` for routine Surrealist/CLI
  inspection, keeping root as break-glass and the app identity application-only;
- store encrypted checksum-backed logical exports in a second stable named
  volume on the same VPS with Restic, retain three daily, one weekly and three
  pre-release snapshots, and prove restore into an isolated target;
- do not create an external object-storage account for this demo; keep the
  Coolify configuration reproducible and its private secrets in 1Password;
- treat Sharptech's operational backup as optional convenience, not as the
  application backup or release evidence;
- permit deployment only through the serialized, quality-gated workflow that
  creates an immutable per-SHA release branch, points Coolify at that branch,
  polls the deployment's reported commit/status, and checks public readiness
  for the exact tested `main` SHA after its secrets are intentionally enabled.

This same-host copy protects against accidental database changes, a bad
migration, and application-level corruption only. It does **not** protect
against VPS deletion, disk failure, provider loss, or compromise of the host;
those events may lose both SurrealDB and every Restic snapshot. That accepted
demo limitation must remain visible in the runbook and must not be described as
disaster recovery.

No application deployment, DNS change or production credential load is implied
by accepting this architecture decision. The first release remains no-go until
the production plan's Gate 0, disposable-host proof, external listener scan,
live backup/restore drill, public/Admin/realtime smoke and exact GitHub SHA gate
all pass.

## Consequences

The selected host has enough nominal memory and disk for the demo-oriented v1
shape, keeps the database private, and avoids adding a second event bus or
managed database. It is still one failure domain with no high availability.

Operations must serialize builds, full station imports, backups and restore
drills; monitor CPU, RAM, swap, disk and monthly transfer; and resize or migrate
if ordinary workload approaches Sharptech's sustained-use or transfer limits.
The same-host repository consumes additional local disk, so disk alerts and
short retention are part of the deployment boundary. A provider outage or disk
loss requires a clean rebuild from the recorded application release and
1Password-held secrets; cached/current database state and Coolify-local state
may be lost. If FjordPulse ever stores valuable or irreplaceable data, an
independent off-host backup becomes a prerequisite before that scope change.
