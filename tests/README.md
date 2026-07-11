# FjordPulse test layers

FjordPulse separates fast contract/unit checks, service integration, deterministic browser behavior, the real local data path, and visual regression.

| Layer | Location | What it proves |
|---|---|---|
| Contracts | `contracts/fixtures`, `scripts/validate-contracts.mjs` | OpenAPI validity plus accepted/rejected HTTP and realtime fixtures. |
| PHP unit/integration | `backend/tests` | DTO/mappers/validation, HTTP/OpenAPI behavior, realtime protocol and rooms, migrations/repositories, real SurrealDB events/live queries/reconnect, WebSocket delivery, and controlled Entur outage/retry recovery. |
| Frontend unit | `frontend/tests` | Validators, version ordering, services, scenarios, and SolidJS components. |
| Fixture E2E | `tests/e2e` | Deterministic public/mobile/admin interactions, route inventory, accessibility, and forbidden browser destinations. |
| Clean-stack E2E | `tests/live` | Real local SurrealDB -> migrations -> CakePHP HTTP -> `bin/cake realtime start` -> Vite API mode -> visible SolidJS updates, plus an intercepted MapTiler provider boundary. |
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
7. exercises station, vehicle, focus, degraded/fallback/reconnect, a full HTTP + realtime process outage with same-page recovery, and protected admin behavior;
8. proves satellite-first loading, pan/zoom tile changes, shareable/reload-safe camera URLs, malformed-camera fallback, guarded town/village/place label phasing on both provider styles, Streets switching, transport-overlay survival, persistence, and retryable provider failure against deterministic MapTiler mocks;
9. asserts browser traffic never reaches Entur or SurrealDB directly and permits only the mocked approved map-provider URLs;
10. terminates every spawned service after the run.

This project is intentionally fake only at the third-party adapter boundary. It does not use frontend-local fixture routes to satisfy data assertions.

## Outage and automatic-recovery gates

The full FjordPulse backend scenario is a browser black-box E2E test: Playwright externally stops the actual local HTTP and realtime processes while leaving one station page open, then restores both. The test requires reconnecting within 10 seconds, backend-degraded plus offline/polling within 25 seconds, and backend/realtime recovery with a new socket and resubscribed station watch within 30 seconds. A DOM page-lifetime sentinel, the selected station, and the rendered map prove that recovery did not use Reload or navigation.

Run only that scenario with:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  npx playwright test --config=playwright.live.config.ts \
  tests/live/lifecycle.spec.ts --grep "full backend outage"
```

Entur recovery is deliberately a backend service-boundary integration/fault-injection test, not a browser-direct Entur test. A controlled local upstream succeeds once, becomes unavailable/5xx, and is restored. The gate proves that the previous authoritative snapshot and last-success time survive, attempts remain budgeted, the next attempt is scheduled 15 seconds after failure, and the same PHP process clears the watch error on success without inventing transport observations.

Run only that scenario with:

```bash
cd backend
../tools/php vendor/bin/phpunit tests/Integration/EnturOutageRecoveryIntegrationTest.php
```

`make test` includes the Entur boundary gate, while `make e2e` includes the full-backend browser gate. The external `make smoke-entur` command proves real Entur reachability and contract compatibility only; it does not intentionally disrupt Entur and is not outage-recovery evidence.

## Visual baselines

`make visual` compares current rendering with reviewed baselines. Create or change baselines only as an intentional design review action:

The application self-hosts its exact Inter Variable font files, and every visual scenario asserts that the bundled face is loaded before comparison. This keeps line wrapping and font weights independent of fonts installed on the developer machine or CI runner.

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
