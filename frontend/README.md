# FjordPulse frontend

SolidJS, strict TypeScript, Vite, and MapLibre GL JS implement the public map and protected admin surfaces. Application data uses only the same-origin FjordPulse `/api` and `/live` boundaries: the browser never contacts Entur or SurrealDB. Normal map routes additionally load styles and tiles from the fixed, allowlisted `https://api.maptiler.com` provider configured by FjordPulse.

The npm lockfile pins SolidJS 1.9.14, MapLibre GL JS 5.24.0, Zod 4.4.3, Vite 8.1.4, TypeScript 7.0.2, and Vitest 4.1.10. Node.js 22.12 or newer is required.

```bash
npm ci
npm run dev
npm run typecheck
npm test
npm run build
```

Deterministic visual states are available at `/__scenario/<scenario-id>`; `/__scenarios` lists every route. For example:

```text
http://localhost:5173/__scenario/desktop_station_fresh
http://localhost:5173/__scenario/mobile_vehicle_lost
http://localhost:5173/__scenario/admin_status
http://localhost:5173/__scenario/design_system_components
```

The fixture routes are local/test surfaces. A normal route uses the CakePHP API and AMPHP WebSocket service. They are enabled automatically by Vite development and tests. A built visual-test preview must opt in with `VITE_ENABLE_FIXTURES=true`; production hosting must leave it false and use `VITE_DATA_MODE=api`.

Norwegian Bokmål (`nb`) is the default UI language, independent of the browser's preferred locale. The visible `NO`/`EN` control changes public, admin, and scenario chrome immediately, updates `<html lang>`, and stores an explicit selection under `fjordpulse.locale.v1`. Missing, invalid, or inaccessible local storage falls back safely to Norwegian. Proper names and transport/diagnostic identifiers remain authoritative data. Visual regression captures every deterministic scenario in both locales (25 routes x 2 languages = 50 base comparisons) plus eight responsive Vehicles/Details station-tab captures; layout checks guard translated controls from clipping or unintended horizontal overflow.

The deterministic fixture map is inline and performs no tile requests. Normal routes fetch operator-managed MapTiler configuration from the same-origin `/api/map/config` endpoint, start with labelled satellite imagery, and offer a Streets map through the layers control. The browser validates the two returned style URLs against the fixed MapTiler Hybrid v4 and Streets v4 HTTPS paths; users never supply an API key.

Use the root `make dev` command for the normal real-Entur profile and
`make dev-demo` for the isolated fake-source profile; running Vite alone does
not start the required API, realtime service, or database. The frontend reads
the active mode from `/api/health`. Real mode displays neutral **Transport data:
Entur** attribution; fake mode displays a persistent **Demo data — Deterministic
transport fixtures** badge and does not claim Entur is in use.

Healthy idle connection state does not occupy permanent public chrome. Selected
station/vehicle panels own resource age and exceptional warnings; one contextual
notice is shown only while delivery is reconnecting, periodically updating, or
unavailable. Component-level diagnostics remain in Admin.

Entur has no browser credential because every Entur request is backend-only.
`MAPTILER_API_KEY` is the sole browser provider key and is returned only through
the allowlisted map configuration contract.
