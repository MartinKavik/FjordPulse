# FjordPulse test layers

FjordPulse separates fast contract/unit checks, service integration, deterministic browser behavior, the real local data path, and visual regression.

| Layer | Location | What it proves |
|---|---|---|
| Contracts | `contracts/fixtures`, `scripts/validate-contracts.mjs` | OpenAPI validity plus accepted/rejected HTTP and realtime fixtures, including cross-field non-passenger journey/serving invariants. |
| PHP unit/integration | `backend/tests` | DTO/mappers/validation, HTTP/OpenAPI behavior, realtime protocol and rooms, migrations/repositories, real SurrealDB events/live queries/reconnect, WebSocket delivery, and controlled Entur outage/retry recovery. |
| Frontend unit | `frontend/tests` | Validators, version ordering, services, scenarios, SolidJS components, non-overlapping station-tab allocation/counts/disclosures, and Norwegian/English locale fallback/persistence. |
| Fixture E2E | `tests/e2e` | Deterministic public/mobile/admin interactions, station Departures/Vehicles/Details behavior, route inventory, truthful non-passenger vehicle presentation, locale switching/reload/document language, responsive localized layout, accessibility, and forbidden browser destinations. |
| Clean-stack E2E | `tests/live` | Real local SurrealDB -> migrations -> CakePHP HTTP -> `bin/cake realtime start` -> Vite API mode -> visible SolidJS updates, exact lost-vehicle ID discovery, plus an intercepted MapTiler provider boundary. |
| Visual | `tests/visual` | Norwegian and English screenshots for all 26 desktop/mobile/admin/design-system routes plus eight secondary station-tab and four mobile-admin hierarchy/drawer baselines (64 reviewed comparisons). |

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
8. proves satellite-first loading, pan/zoom tile changes, shareable/reload-safe camera URLs, malformed-camera fallback, context-preserving selection with a persistent pin across clustering, guarded town/village/place label phasing on both provider styles, Streets switching, transport-overlay survival, persistence, and retryable provider failure against deterministic MapTiler mocks;
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

`make visual` compares current rendering with reviewed baselines. Each of the 26
scenario routes is captured with deterministic `nb` and `en` storage state and
must report the matching document language, producing 52 base comparisons. The
desktop fresh-station and mobile full-station routes additionally capture their
Vehicles and Details tabs in both languages. Mobile System status and the open
Infrastructure drawer add four bilingual responsive captures, bringing the
reviewed total to 64 without adding routes. Create or change baselines only as
an intentional design review action:

The application self-hosts its exact Inter Variable font files, and every visual scenario asserts that the bundled face is loaded before comparison. This keeps line wrapping and font weights independent of fonts installed on the developer machine or CI runner.

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  npx playwright test --project=visual --update-snapshots
```

Review every changed PNG in both locales before accepting it; a newly generated
image is not proof by itself. Confirm that translated button, tab, status,
navigation, card, and table labels wrap or reflow without clipping, overlap, or
unintended horizontal viewport overflow.

The desktop and mobile non-passenger scenarios additionally require the live
selected marker and Focus controls to remain available while operational line,
route, destination, delay, previous/next stop, journey-progress, stale-schedule,
and raw Entur-warning content remains absent. Their Norwegian and English
captures must pass the same horizontal-overflow checks as the other public
surfaces.

Component behavior also advances one mounted, focused vehicle through
passenger -> non-passenger -> passenger. The test requires operational details
to disappear without losing Focus, then requires the later passenger journey
and upcoming stops to return without reselection.

## Station-panel tab gate

Station panel checks keep each resource in one predictable place:

- Departures contains only departure rows and renders platform when the source
  reports it; its badge equals the visible departure count.
- Vehicles contains station-serving and other-nearby rows once, reports their
  de-duplicated count, and keeps exact coverage diagnostics in a collapsed
  disclosure beneath a plain-language scope summary.
- Details replaces Info and keeps stable station facts plus plain-language data
  scope visible. Stop ID, coordinates, and timezone are collapsed by default.
- Loading and failure states are scoped to the selected transport tab. Known
  Details facts stay usable, and zero-result language is never shown before a
  search actually completes.
- Keyboard, desktop, mobile, Norwegian, and English checks switch through all
  three tabs without refetching, losing station/map context, clipping labels, or
  duplicating lists.

## External Entur smoke

Real Entur access is backend-only and opt-in:

```bash
make smoke-entur
```

The smoke requires network access and the configured `ET-Client-Name` identity. It is deliberately skipped by the ordinary offline PHPUnit run.

Playwright traces, screenshots, videos, and temporary data are written under ignored test-output paths. Planning verification excludes those generated paths while still rejecting ZIP files accidentally retained in the source corpus.
