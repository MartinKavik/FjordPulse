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

FjordPulse also applies its own rolling backend request safeguard. It is not an
Entur-reported account quota and does not limit incoming browser requests. The
shared `ENTUR_GLOBAL_REQUESTS_PER_MINUTE` allowance and the narrower
`ENTUR_STOP_PLACE_REQUESTS_PER_MINUTE`, `ENTUR_GEOCODER_REQUESTS_PER_MINUTE`,
`ENTUR_JOURNEY_REQUESTS_PER_MINUTE`, and
`ENTUR_VEHICLE_REQUESTS_PER_MINUTE` allowances come from `.env`; each outbound
source call consumes the shared allowance and its service allowance for the
rolling 60-second window. The admin Entur request log shows current headroom,
the configured per-service limits, and the matching request evidence. System
status links to that dedicated page. Entur's separate provider-side Journey Planner
limits and response headers are documented in [Entur's rate-limit guidance](https://developer.entur.no/docs/open-services/journey-planner/rate-limiting).

The repository's headless Playwright scripts deliberately remove `DISPLAY`
from the Chromium process environment. This keeps map tests on Chromium's
bundled surfaceless SwiftShader path even when a Wayland desktop exports an
unusable Xwayland display; it does not affect the manually opened browser or
`make dev`.

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

## Manual phone testing on the local network

Use the explicit LAN mode instead of weakening the normal loopback-only
development defaults:

```bash
make dev-mobile
```

The command runs the normal real-Entur profile, detects the PC's LAN IPv4
address, binds Vite to all interfaces on TCP 5173, adds exactly the detected
`http://<LAN-IP>:5173` origin to CakePHP and realtime allowlists, and prints the
phone URL. CakePHP, the AMPHP realtime listener, and SurrealDB remain on
loopback; the phone reaches them only through Vite's same-origin `/api` and
`/live` proxies. Use `FJORDPULSE_LAN_IP=<address> make dev-mobile` when a VPN or
unusual routing makes automatic detection choose the wrong interface.

The PC and phone must be on the same trusted LAN; a wired PC and Wi-Fi phone are
fine when the router bridges them normally. Do not use a guest network with
client isolation. TCP 5173 must be allowed by the host firewall. This mode also
exposes the local read-only demo Admin login, so stop it with `make stop` when
manual testing is complete. HTTPS is not required because FjordPulse does not
request geolocation or another secure-context-only browser feature.

The configured MapTiler browser key must allow the printed LAN origin if that
key uses URL/referrer restrictions. Entur still requires no account or key.
Useful deterministic phone states remain available in development at
`/__scenarios`, including the mobile map, station sheet, vehicle focus, lost,
and non-passenger scenarios.

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
Infrastructure:   http://127.0.0.1:5173/admin/infrastructure
Database schema:  http://127.0.0.1:5173/admin/database/schema
Migrations:       http://127.0.0.1:5173/admin/database/migrations
Logs:             .run/logs/
```

The Admin login shows **Fyll inn demoopplysninger** / **Fill demo credentials**
next to the public-map return link. `make dev` and `make dev-demo` explicitly
enable the separate `demo` identity, so manual testing does not require
remembering the operator password; the configuration default remains off. It
is server-enforced as read-only and may only read Admin diagnostics or log out.

The Database pages are diagnostics only. CakePHP executes one fixed,
allowlisted schema-structure query and maps safe fields before responding; the
browser never receives a SurrealDB connection or arbitrary-query capability.
The Migrations tab compares bundled files with the ledger and attempt audit but
cannot execute them. `make dev` invokes the deployment CLI migration runner
before web startup; only that CLI path writes migration/attempt records.

Use the standalone Surrealist desktop/web application when an operator needs
to inspect records or run a query. Connect it through the local/private
operator path (or an authenticated tunnel to a deployed private SurrealDB), not
through FjordPulse Admin and not by publishing the database port. Never paste
root or database credentials into the FjordPulse browser UI; it has no field or
endpoint for them.

Both commands remain attached. Press Ctrl-C in that terminal or run
`make stop` elsewhere. Stopping preserves `.data/surreal-real` and deletes the
ephemeral demo store.

If the UI says **Map service is not configured**, configure
`MAPTILER_API_KEY`; it is unrelated to Entur. If the first real startup appears
to pause before the URLs are printed, read the page-by-page import JSON in the
same terminal and allow the complete catalog bootstrap to finish.
