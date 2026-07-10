# FjordPulse

FjordPulse is an experimental realtime Norwegian public transport explorer.

The repository is intentionally prepared for an AI coding agent to implement the complete local application—frontend, PHP backend, fake services, real Entur adapters, SurrealDB live-query flow, tests, and documentation—while leaving actual Hetzner/Coolify deployment for a later step.

## Start here

```bash
make verify-planning
cat GOAL.md
```

Then start Codex from the repository root and paste the single command in `CODEX_GOAL_PROMPT.txt`. The command tells Codex to read `AGENTS.md` and the detailed `GOAL.md`.

## Target stack

```text
Frontend:       SolidJS + TypeScript + Vite + MapLibre GL JS
HTTP/control:   CakePHP 6 + PHP 8.5 + FrankenPHP normal mode
Realtime:       CakePHP command + AMPHP/Revolt + WebSockets
Persistence:    SurrealDB + PHP SDK v2 alpha
Data:           Entur open services, backend-only
Tests:          PHPUnit + PHPStan + Vitest + Playwright
Deployment:     later, Hetzner CX33 + Coolify
```

## Key design rule

The primary realtime path is database-driven:

```text
Entur/fake source
  -> typed PHP adapter
  -> canonical SurrealDB state
  -> SurrealDB DEFINE EVENT creates realtime_event
  -> SurrealDB LIVE SELECT streams event to PHP Runtime::amp() bridge
  -> PHP broadcasts to relevant browser WebSocket room
  -> SolidJS updates map/panels
```

Browser commands flow back through PHP and update durable watches:

```text
SolidJS watch/focus command
  -> PHP WebSocket handler
  -> in-memory room/watch registry + SurrealDB watch record
  -> AMPHP scheduler refreshes requested Entur scope
```

## Documentation

- `AGENTS.md` — coding-agent rules.
- `GOAL.md` — one comprehensive implementation goal.
- `FINAL_READINESS_REVIEW.md` — audited readiness summary.
- `docs/ARCHITECTURE.md` — canonical architecture.
- `docs/SURREALDB_LIVE_QUERY_FLOW.md` — detailed realtime data flow.
- `docs/design/` — 23 visual states and descriptions.
- `docs/user-stories/` — 108 stories with black-box tests.
- `contracts/` — draft machine-readable HTTP/realtime contracts.
