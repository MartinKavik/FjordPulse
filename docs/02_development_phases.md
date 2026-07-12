# FjordPulse Development Phases

The consolidated repository already completes the former file-collection phase. Development begins with verification and dependency spikes.

## Phase 0 — Verify the consolidated inputs

```bash
make verify-planning
```

Done when:

- 25 design PNGs and 25 design notes are present,
- 108 user stories are present,
- no nested ZIPs remain,
- `AGENTS.md`, `GOAL.md`, contracts, and canonical architecture docs exist.

## Phase 1 — Dependency spikes and runnable skeleton

Prove:

- exact CakePHP 6 version on PHP 8.5,
- FrankenPHP normal mode,
- AMPHP WebSocket command inside CakePHP,
- SurrealDB `Runtime::sync()` and `Runtime::amp()`,
- non-blocking live query,
- `DEFINE EVENT` -> `realtime_event` -> live query,
- live-query recovery after reconnect,
- initial real Entur probes.

Create frontend/backend/local-infra skeleton and root quality commands.

Gate: health endpoint, WebSocket ping/pong, SurrealDB spike tests, lockfiles.

## Phase 2 — SolidJS visual prototype

Implement every design state in `docs/design/` with deterministic TypeScript fixtures:

- desktop public states,
- mobile responsive states,
- admin states,
- design-system components,
- Norwegian Bokmål as the default locale plus an accessible persistent `NO`/`EN` switcher on public and admin surfaces.

Gate: deterministic routes/scenarios and Playwright visual baselines for every state in both locales, with responsive localized-copy overflow checks.

## Phase 3 — Contract-complete fake mode

Complete HTTP/OpenAPI and realtime JSON schemas. Implement dev/test fake source adapters with the final interfaces and deterministic scenarios.

The fake mode must use the same DTOs, repositories, database events, live-query bridge, WebSocket protocol, and frontend services as real mode whenever integration mode is enabled.

Gate: frontend works end to end against fake mode through HTTP/WebSocket, not local fixtures.

## Phase 4 — CakePHP HTTP/control plane

Implement:

- public APIs,
- health/readiness,
- validation,
- admin auth and pages/APIs,
- structured logs,
- commands/configuration,
- snapshot endpoints,
- fallback polling.

Gate: HTTP contract tests and black-box station/search/admin scenarios.

## Phase 5 — AMPHP/Revolt realtime service

Implement `bin/cake realtime start` with:

- WebSocket server,
- message validation/router,
- rooms,
- watch/focus lifecycle,
- scheduler/timers,
- health and graceful shutdown.

Gate: reconnect, room isolation, focus lifecycle, fallback behavior.

## Phase 6 — SurrealDB canonical state and live-query event path

Implement:

```text
canonical state write
  -> DEFINE EVENT
  -> realtime_event
  -> one global LIVE SELECT
  -> PHP room broadcast
```

Use `Runtime::sync()` in short request paths and two `Runtime::amp()` connections in realtime: command/query plus dedicated live stream.

Gate: an integration test proves database write all the way to visible SolidJS update.

## Phase 7 — Real backend with fake third-party services

Use fake Entur adapters behind production interfaces while all other architecture is real: SurrealDB, watches, events, live queries, WebSockets, admin diagnostics.

Gate: all deterministic black-box scenarios pass without frontend-local fixtures.

## Phase 8 — Real Entur integration

Implement:

- Stop Place Register import,
- Geocoder v3 search,
- Journey Planner v3 departures,
- Vehicle Positions strategy selected by spike,
- caching, freshness, budgets, request logs, 429/backoff.

Gate: real backend-only Entur smoke tests and no browser-to-Entur traffic.

## Phase 9 — Full local quality and production-ready configuration

Complete:

- PHPUnit,
- PHPStan,
- Vitest,
- Playwright E2E/visual,
- contract tests,
- user-story traceability,
- local Compose/configuration,
- deployment-ready documentation.

Gate:

```bash
make typecheck
make phpstan
make test
make e2e
make visual
make build
```

## Phase 10 — Deployment later

Not part of `GOAL.md` execution:

- provision Hetzner CX33,
- install/configure Coolify,
- configure `fjordpulse.kavik.cz`,
- load production secrets,
- run production smoke/black-box tests.
