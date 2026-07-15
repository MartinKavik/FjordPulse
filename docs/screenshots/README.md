# Production screenshots

These images were captured directly from `https://fjordpulse.kavik.cz` with a
fresh Playwright browser context. No fixture route, mocked response, generated
map, or image-generation tool was used.

The repeatable capture command is:

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  FJORDPULSE_CAPTURE_EXPECTED_VERSION="$(git rev-parse HEAD)" \
  xvfb-run -a node scripts/capture-production-screenshots.mjs
```

The script refuses a non-production host, a build that differs from the exact
expected commit, an unhealthy real-data stack, incomplete visible evidence, or
a build change during capture. It stages and validates all six PNGs before
publishing them with a machine-readable [capture manifest](capture.json).
For a later gallery refresh, deploy the intended commit and update the build
provenance in this file first; a mismatch deliberately prevents publication.

| File | Production state | Capture |
|---|---|---|
| `production-focus-line-1-alesund.png` | A Møre og Romsdal Line 1 bus reporting at capture time, in Focus mode with satellite imagery and its real journey | 15 July 2026 · build `bf23cc80895da35df1fb9ff0aeee862efc29c8fe` · 1440×900 |
| `production-forde-station.png` | Førde rutebilstasjon, satellite imagery, and the live departure board | 15 July 2026 · build `bf23cc80895da35df1fb9ff0aeee862efc29c8fe` · 1440×900 |
| `production-admin-status.png` | Read-only production health and active-demand summary while the Line 1 browser watch was open | 15 July 2026 · build `bf23cc80895da35df1fb9ff0aeee862efc29c8fe` · 1440×900 |
| `production-admin-realtime.png` | Healthy production realtime bridge, a confirmed active browser, rolling message count, and the Line 1 rooms | 15 July 2026 · build `bf23cc80895da35df1fb9ff0aeee862efc29c8fe` · 1440×900 |
| `production-admin-infrastructure.png` | Production identity, CPU, free memory, disk, and SurrealDB inventory | 15 July 2026 · build `bf23cc80895da35df1fb9ff0aeee862efc29c8fe` · 1440×900 |
| `production-mobile-map.png` | Default Norwegian mobile map at Ålesund with the public Admin destination visible in the bottom navigation | 15 July 2026 · build `bf23cc80895da35df1fb9ff0aeee862efc29c8fe` · 390×844 |

The map and transport values are a truthful point-in-time record, not a promise
that the same vehicle, delay, departures, or resource usage will remain present
later. Deterministic visual-regression baselines remain under
`tests/visual/__snapshots__`; they serve a different testing purpose and are not
presented as live production captures.
