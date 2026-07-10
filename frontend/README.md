# FjordPulse frontend

SolidJS, strict TypeScript, Vite, and MapLibre GL JS implement the public map and protected admin surfaces. Browser traffic uses only the FjordPulse-origin `/api` and `/live` boundaries.

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

The deterministic map style is inline and performs no tile requests. A real deployment may set `VITE_MAP_STYLE_URL` to a same-origin path such as `/map/style.json`; external URLs are rejected and configured styles render their own attribution control.
