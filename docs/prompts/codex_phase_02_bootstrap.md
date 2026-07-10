# Codex Prompt — Phase 2 Skeleton

You are working in the FjordPulse repository.

Read:

```text
AGENTS.md
docs/AI_CONTEXT.md
docs/ARCHITECTURE.md
docs/02_development_phases.md
docs/03_api_contract.md
docs/04_realtime_protocol.md
docs/05_testing_strategy.md
```

Task:

Create the initial project skeleton only.

Do not implement full business logic.

Deliver:

```text
frontend/
backend/
infra/
Makefile
README.md
.env.example
```

Requirements:

- frontend uses SolidJS + TypeScript + Vite.
- backend is prepared for CakePHP 6 + PHP 8.5.
- add placeholder health endpoint.
- add placeholder realtime command.
- add PHPStan config.
- add Makefile commands.
- document how to run.

Do not call real Entur.
Do not add Redis.
Do not connect browser to Entur or SurrealDB.
