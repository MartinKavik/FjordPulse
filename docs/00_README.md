# FjordPulse Documentation Index

This repository is already consolidated. No other planning ZIPs need to be unpacked.

Start with:

```text
../AGENTS.md
../GOAL.md
../FINAL_READINESS_REVIEW.md
AI_CONTEXT.md
ARCHITECTURE.md
SURREALDB_LIVE_QUERY_FLOW.md
DEPENDENCY_SPIKES.md
LOCAL_DEVELOPMENT.md
COMPATIBILITY.md
ADMIN_MEASUREMENTS.md
PRODUCTION_DEPLOYMENT_PLAN.md
02_development_phases.md
03_api_contract.md
04_realtime_protocol.md
05_testing_strategy.md
```

Reference collections:

```text
design/         25 paired references plus 2 coded-only page specifications
user-stories/   108 production stories with black-box tests
adr/            architecture decisions
prompts/        optional phase-specific prompts
```

Production hosting is now concretized by
[ADR 0014](adr/0014-sharptech-single-host-production.md): a provisioned
Sharptech Medium VPS, manual Coolify, private RocksDB-backed SurrealDB,
encrypted same-host demo backups with an explicit total-host-loss limitation,
and tested restore gates. The [real-data production demo](https://fjordpulse.kavik.cz)
is live through Coolify-managed Traefik.
