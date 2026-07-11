# Local development profiles

FjordPulse has two explicit local profiles. Both exercise the real CakePHP,
SurrealDB, `DEFINE EVENT`/live-query, AMPHP WebSocket, and SolidJS application
paths; only the third-party transport adapters and database storage differ.

## Install and configure

```bash
make install
${EDITOR:-vi} .env
```

`make install` copies `.env.example` to the ignored `.env` when it does not
already exist. Set `MAPTILER_API_KEY` to a read-only MapTiler browser key. This
is the only map/provider key required for local development and should be
origin-restricted for deployment.

No Entur registration, API key, OAuth client, or access token is required under
[Entur's authentication guidance](https://developer.entur.org/pages-intro-authentication/).
FjordPulse uses Entur's open APIs from the PHP backend. Keep
`ENTUR_CLIENT_NAME` as a stable, identifiable `company-application` value; it is
sent as `ET-Client-Name` and is not a secret. The browser never calls Entur.

## Normal profile: real Entur

```bash
make dev
```

The command loads `.env` and then deliberately forces the normal profile:

```text
DATA_MODE=real
SCENARIO=normal
SURREAL_DATABASE=fjordpulse_real
SURREAL_DATA_PATH=.data/surreal-real
```

The database name and path may be changed with `SURREAL_REAL_DATABASE` and
`SURREAL_REAL_DATA_PATH`. A stale `DATA_MODE`, `SURREAL_DATABASE`, or
`SURREAL_DATA_PATH` in `.env` cannot silently turn the normal route into demo
mode or point it at fixture records.

Before the web services start, the command applies migrations and imports the
complete Entur [Stop Place catalog](https://api.entur.io/stop-places/v1/read/openapi.json).
The source contained 57,964 rows when
checked on 2026-07-10, so treat “about 58,000” as a changing operational size,
not a fixed product constant.

FjordPulse defaults to 1,000-source-row pages
(`STATION_IMPORT_PAGE_SIZE=1000`) and 1,000-record SurrealDB write chunks
(`STATION_IMPORT_WRITE_CHUNK_SIZE=1000`). Entur accepted 5,000 rows in a live
probe, and the runtime permits that configured maximum, but 5,000-row response
bodies exceeded the standard local PHP 128 MB memory limit during a complete
import. The conservative default completed the full catalog under that limit.
The importer prints a `station_import_progress` JSON line after each persisted page,
including `imported`, `nextOffset`, source identity, and completion state. It
prints `station_import_complete` at the end. The same progress and source
provenance are stored in `system_status:station_catalog`; an interrupted import
can resume from the persisted offset, while an already healthy matching catalog
is reused.

The public UI identifies this profile with neutral **Transport data: Entur**
attribution. It never uses that credit as a health indicator, never labels real
source failures as demo data, and never substitutes fixture vehicles.

## Demo profile: deterministic fake sources

```bash
make dev-demo
```

This profile forces:

```text
DATA_MODE=fake
SCENARIO=normal
SURREAL_DATABASE=fjordpulse_demo
SURREAL_DATA_PATH=.run/surreal-demo
```

The demo store is removed before startup and again when the stack stops, so it
cannot contaminate the persistent real catalog. Fake Stop Place, Geocoder,
Journey Planner, and Vehicle Positions adapters implement the same typed
interfaces and still pass through the canonical database/realtime pipeline.
The public UI displays **Demo data — Deterministic transport fixtures**, and
telemetry does not claim that Entur is healthy or in use.

## URLs and stopping

```text
Public app:       http://127.0.0.1:5173
CakePHP/built UI: http://127.0.0.1:8080
Realtime health: http://127.0.0.1:8081/health/realtime
Admin:            http://127.0.0.1:5173/admin/status
Logs:             .run/logs/
```

Both commands remain attached. Press Ctrl-C in that terminal or run
`make stop` elsewhere. Stopping preserves `.data/surreal-real` and deletes the
ephemeral demo store.

If the UI says **Map service is not configured**, configure
`MAPTILER_API_KEY`; it is unrelated to Entur. If the first real startup appears
to pause before the URLs are printed, read the page-by-page import JSON in the
same terminal and allow the complete catalog bootstrap to finish.
