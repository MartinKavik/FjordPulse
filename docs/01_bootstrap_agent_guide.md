# FjordPulse Bootstrap Agent Guide

## Mission

Create a runnable FjordPulse skeleton after reading the planning/design/test inputs.

## Read first

```text
AGENTS.md
FINAL_READINESS_REVIEW.md
docs/AI_CONTEXT.md
docs/ARCHITECTURE.md
docs/DEPENDENCY_SPIKES.md
docs/02_development_phases.md
docs/03_api_contract.md
docs/04_realtime_protocol.md
docs/05_testing_strategy.md
docs/design/00_README.md
docs/user-stories/00_README.md
```

## Phase 2 deliverables

```text
frontend/
backend/
contracts/
infra/
Makefile
README.md
.env.example
.github/workflows/quality.yml
```

Contracts:

```text
contracts/http/openapi.yaml
contracts/realtime/envelope.schema.json
contracts/realtime/client-message.schema.json
contracts/realtime/server-message.schema.json
```

Frontend:

- SolidJS,
- TypeScript strict mode,
- Vite,
- Vitest,
- Playwright scaffold.

Backend:

- CakePHP 6 resolved/pinned by spike,
- PHP 8.5,
- PHPStan,
- PHPUnit,
- `/api/health`,
- placeholder `bin/cake realtime start`.

## Rules

- no real Entur calls yet,
- no Redis,
- no browser-to-SurrealDB,
- no floating dev/alpha dependency after resolution,
- commit lockfiles,
- fake adapters must later use final contracts,
- do not implement full business logic in bootstrap.

## Required commands

```bash
make install
make dev
make typecheck
make phpstan
make test
make e2e
make visual
make build
```

## Output report

After bootstrap, report:

1. created files,
2. exact dependency versions,
3. how to run,
4. passing checks,
5. stubs/TODOs,
6. next phase.
