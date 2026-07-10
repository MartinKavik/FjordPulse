# FjordPulse test layers

FjordPulse separates fast contract/unit checks, service integration, deterministic browser behavior, the real local data path, and visual regression.

| Layer | Location | What it proves |
|---|---|---|
| Contracts | `contracts/fixtures`, `scripts/validate-contracts.mjs` | OpenAPI validity plus accepted/rejected HTTP and realtime fixtures. |
| PHP unit/integration | `backend/tests` | DTO/mappers/validation, HTTP/OpenAPI behavior, realtime protocol and rooms, migrations/repositories, real SurrealDB events/live queries/reconnect, and WebSocket delivery. |
| Frontend unit | `frontend/tests` | Validators, version ordering, services, scenarios, and SolidJS components. |
| Fixture E2E | `tests/e2e` | Deterministic public/mobile/admin interactions, route inventory, accessibility, and forbidden browser destinations. |
| Clean-stack E2E | `tests/live` | Real local SurrealDB -> migrations -> CakePHP HTTP -> `bin/cake realtime start` -> Vite API mode -> visible SolidJS updates. |
| Visual | `tests/visual` | Reviewed screenshots for all 23 desktop/mobile/admin/design-system scenarios. |

## Standard commands

```bash
make typecheck
make phpstan
make test
make e2e
make visual
make build
```

`make test` runs contract validation, PHPUnit, and Vitest. The ordinary suite is deterministic/offline with respect to Entur; SurrealDB integration tests start isolated real local server processes.

`make e2e` runs both Playwright projects in sequence:

```bash
npm run e2e:fixture
npm run e2e:live
```

## Clean-stack browser proof

Run the final-path project directly with:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  npx playwright test --config=playwright.live.config.ts
```

The stack helper uses isolated local ports `19000`, `19073`, `19080`, and `19081` and a fresh ignored `.data/playwright-live` database. It:

1. starts the pinned real SurrealDB server;
2. applies every migration and bootstraps the database-scoped app user;
3. imports deterministic fake-adapter station data through backend code;
4. starts the actual AMPHP CakePHP realtime command;
5. starts FrankenPHP/CakePHP HTTP;
6. starts Vite with `VITE_DATA_MODE=api` and frontend fixtures disabled;
7. exercises station, vehicle, focus, degraded/fallback/reconnect, and protected admin behavior;
8. asserts browser traffic never reaches Entur or SurrealDB directly;
9. terminates every spawned service after the run.

This project is intentionally fake only at the third-party adapter boundary. It does not use frontend-local fixture routes to satisfy data assertions.

## Visual baselines

`make visual` compares current rendering with reviewed baselines. Create or change baselines only as an intentional design review action:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  npx playwright test --project=visual --update-snapshots
```

Review every changed PNG before accepting it; a newly generated image is not proof by itself.

## External Entur smoke

Real Entur access is backend-only and opt-in:

```bash
make smoke-entur
```

The smoke requires network access and the configured `ET-Client-Name` identity. It is deliberately skipped by the ordinary offline PHPUnit run.

Playwright traces, screenshots, videos, and temporary data are written under ignored test-output paths. Planning verification excludes those generated paths while still rejecting ZIP files accidentally retained in the source corpus.
