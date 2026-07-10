# FjordPulse

FjordPulse is a realtime Norwegian public transport explorer built to demonstrate modern typed, asynchronous PHP with a SolidJS map interface.

The local application is implemented end to end:

```text
SolidJS + TypeScript + Vite + MapLibre GL JS
CakePHP 6 + PHP 8.5 + FrankenPHP normal mode
AMPHP/Revolt browser WebSocket service
SurrealDB canonical state + DEFINE EVENT + LIVE SELECT
typed fake and real Entur adapters
PHPUnit + PHPStan + Vitest + Playwright
```

Actual Hetzner/Coolify provisioning, DNS changes, production secrets, and rollout are intentionally deferred.

## Quick start

Install the exact lockfile-backed dependencies and project-managed tools:

```bash
make install
```

Start a local fake-data development stack:

```bash
make dev
```

This applies SurrealDB migrations, imports deterministic stations, and starts SurrealDB, CakePHP/FrankenPHP, `bin/cake realtime start`, and Vite. Default local URLs are:

```text
Public app:       http://127.0.0.1:5173
CakePHP/built UI: http://127.0.0.1:8080
Realtime health: http://127.0.0.1:8081/health/realtime
Admin:            http://127.0.0.1:5173/admin/status
```

`make dev` stays attached so service failure is visible. Press Ctrl-C, or run `make stop` from another terminal.

Development defaults come from `.env.example`. Fake adapters and development scenarios are allowed only in development/test; production configuration requires `DATA_MODE=real`.

## Quality gates

```bash
make verify-planning
make typecheck
make phpstan
make test
make e2e
make visual
make build
```

`make e2e` runs both browser layers:

- deterministic fixture UI behavior/accessibility; and
- a clean-stack proof that boots real SurrealDB, applies migrations, starts CakePHP HTTP and the AMPHP realtime command, runs Vite with `VITE_DATA_MODE=api`, and verifies database-originated visible updates.

Run only the final-path clean-stack proof with:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" npm run e2e:live
```

The ordinary test suite does not require live Entur. To explicitly probe the real backend-only Entur adapters:

```bash
make smoke-entur
```

See `tests/README.md` for the test-layer matrix and `PROGRESS.md` for the latest verified gate state.

## Architecture invariant

The sole realtime publication path is database-driven:

```text
Entur/fake source
  -> typed PHP DTO
  -> canonical SurrealDB state write
  -> DEFINE EVENT creates realtime_event
  -> one global LIVE SELECT reaches the PHP bridge
  -> scoped WebSocket room broadcast
  -> SolidJS applies newer versions
```

Browser commands flow back through PHP and create durable demand:

```text
watch/focus command
  -> signed PHP WebSocket handler
  -> in-memory room/watch registry + SurrealDB watch record
  -> AMPHP scheduler refreshes the requested scope
```

The browser never calls Entur or SurrealDB directly. HTTP snapshots remain authoritative, and degraded realtime falls back to polling without introducing a second event bus.

## Repository map

- `frontend/` — SolidJS application, API/realtime clients, deterministic visual states, and Vitest tests.
- `backend/` — CakePHP HTTP/control plane, AMPHP realtime service, typed adapters/repositories, migrations, PHPUnit, and PHPStan.
- `contracts/` — canonical OpenAPI and realtime JSON Schemas plus fixtures and traceability.
- `infra/` — Caddy/FrankenPHP, Dockerfile, and Compose artifacts for later deployment work.
- `tests/` — cross-service fixture E2E, clean-stack E2E, and visual browser tests.
- `docs/` — architecture, protocol, dependency, ADR, design, and user-story documentation.

Start with `AGENTS.md`, `GOAL.md`, `docs/ARCHITECTURE.md`, and `FINAL_READINESS_REVIEW.md` when changing the system.

## Deployment boundary

The checked-in environment examples contain local placeholders, not production credentials. A later deployment phase must provision Hetzner/Coolify, configure `fjordpulse.kavik.cz`, supply strong secrets, enforce one realtime replica, configure backups/TLS/monitoring, and run deployed smoke tests.
