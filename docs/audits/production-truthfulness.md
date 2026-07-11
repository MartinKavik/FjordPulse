# Production truthfulness audit

Date: 2026-07-10

## Why the earlier readiness result was insufficient

The earlier gates proved that contracts, deterministic scenarios, database events, WebSockets, and rendered states worked mechanically. They did not prove that the normal application obtained all visible values from authoritative sources.

Four gaps allowed the repository to overstate readiness:

1. The shared fake/real vehicle interface did not contain a service-journey identity, route geometry, or ordered calls. Shape compatibility therefore could not prove semantic completeness.
2. Visual tests asserted static fixture text and pixels. They did not advance a clock or resolve a real vehicle through Vehicle Positions into Journey Planner data.
3. The live Entur smoke tested APIs independently instead of testing the vehicle-to-journey join used by the product.
4. Local `make dev` defaulted to fake adapters without a persistent public demo label, so deterministic transport was easy to mistake for live Entur data.

Passing those checks was real evidence, but it was evidence for an incomplete specification. `FINAL_READINESS_REVIEW.md` and `PROGRESS.md` must not call the application complete until the corrected semantic gates pass.

## Production-reachable defects and disposition

| Area | Defect found | Required/implemented disposition |
|---|---|---|
| Vehicle loading | API failure substituted the Line 100 fixture and marked it lost. | Normal routes use explicit loading, error, retry, and not-found states; no fixture DTO is substituted. |
| Station loading | A requested station temporarily inherited Førde fixture metadata and coordinates. | Loading state holds only the requested identity/label and does not recenter until authoritative coordinates arrive. |
| Line results | Every line opened the same three fixed western-Norway stations. | The fixed route context is removed; line selection targets a real active vehicle or reports that none is active. |
| Fixture isolation | Normal public/admin modules statically imported deterministic scenarios. | Scenario code is behind a development-only lazy boundary; the production build is scanned for fixture sentinels. |
| Data provenance | Fake mode rendered an `Entur OK` presentation. | Public health exposes data mode, fake mode displays `Demo data`, and real mode carries Entur attribution. |
| Relative time | Station, vehicle, focus, nearby rows, and telemetry contained literal ages. | One shared reactive clock and one formatter derive every age from RFC3339 timestamps. |
| Direction/delay | Direction always ended in `NE`; negative delay rendered as `+-N min`. | A tested 16-point compass and early/on-time/delayed formatter own these values. |
| Station/source state | Every station said `Vestland`; several states fell through to `Stale` or green. | Locality/municipality and an exhaustive source-state mapping drive the UI. |
| Search failure | Network failure was presented as a successful empty station search. | Idle, loading, error, and empty multi-type results are distinct; superseded requests are aborted. |
| Broad/typo search | Alphabetical database capping could discard Førde before relevance ranking, and five literal Geocoder matches suppressed every fuzzy station. | Exact, prefix/token-prefix, contains, and fuzzy station lanes are bounded separately and ranked before the API cap; one valid fuzzy station slot survives unrelated literal place results. |
| Place/line action | Place results opened an arbitrary nearest station; lines had fake route buttons. | Places recenter to their own coordinates; lines target authoritative active vehicles. |
| Vehicle/line search | A fresh real database could not discover a line, old opportunistic vehicle rows could appear forever, and line-companion insertion could evict a station from an ordinary place query. | Explicit line/code/vehicle intent uses the coalesced nationwide source, persists only bounded matches, filters real candidates by the lost threshold, and reserves companion vehicles only for explicit line/code intent. |
| Navigation | Saved, Alerts, Menu, geolocation, and admin time-range controls had no working behavior. | Unimplemented controls are removed until a user story supplies behavior and tests. |
| Mobile status | CSS injected `station clusters loaded` regardless of runtime state. | Injected operational content is removed; only telemetry payloads provide status. |
| Admin status | Health label, identity, Oslo clock, Entur metrics, and several timestamps were fabricated. | The authenticated username, live clock, API metrics, nullable measurements, and Oslo formatters drive the admin UI. |
| Map default | The frontend preferred satellite without consulting `defaultBasemap`. | Stored successful choice wins, then the backend default is used. |
| Vehicle timestamps | Entur source and collection timestamps were swapped. | `lastSeenAt` is upstream `lastUpdated`; `refreshedAt` is collection time. |
| Route/stops | Breadcrumb observations were presented as the only path and upcoming stops were derived from a permanently null `nextStop`. | Vehicle journey references join to cached Journey Planner geometry/calls; route, stops, and progress have typed contracts. Missing monitored calls infer the next call from route position/time, while only confirmed completion produces an empty list. |
| Realtime stop progress | Compact movement events retained the initially loaded stop list forever and journey removal could leave stale calls visible. | Every newer compact event recomputes calls from `nextStop`/monitored progress; journey identity/version changes clear cached route and stops before authoritative refresh. |
| Async ordering | A slow HTTP response or old-scope WebSocket event could overwrite a newer selected entity. | Resource requests are cancelled on selection/close and all HTTP/WS commits are guarded by entity identity plus RFC3339 version. |
| Route stop roles | Vehicles with inferred next stops had a correct panel but no highlighted stop on the map. | Stop roles prefer the authoritative/inferred `nextStop` and fall back to monitored-call progress. |
| Station catalog | Any non-empty database skipped import, including a fake catalog in real mode. | Profile-isolated databases plus source/catalog state prevent partial or cross-mode catalogs from becoming ready. |
| Station map | A silent 2,000-row read cap could make cluster totals incomplete; removing it by hydrating the full 57,964-row catalog exhausted PHP's 128 MB request limit and returned a fatal HTML page. | SurrealDB performs bounds-aware marker projection and cell aggregation. Adaptive cell sizing preserves every matched station in at most 2,000 response items without full-catalog PHP hydration; a 58,500-row black-box regression enforces JSON content type, complete totals, and stable bounds. |
| Map context legibility | Opaque equal-sized cluster bubbles and raw five-digit counts were appended above provider symbols, hiding the town/place names needed to locate them; zoom 8 could expose nearly one thousand overlapping station dots. | Count-scaled translucent rings, compact counts, and ordinary stations render below provider symbols while selected transport remains above. Invisible 36 px hit targets preserve cluster interaction; direct markers require zoom 9+ and at most 300 stations in view. FP-002 now makes label legibility explicit. |
| Public value proposition | Welcome and action copy promoted internal request scope, clustering, and high-priority watches as if those were rider benefits. | Rider-facing copy now leads with finding a station, seeing upcoming departures and nearby vehicles, and following a route. Loading, empty, stale, and follow hints use plain rider language; a unit and browser assertion prevent the internal-benefit phrases from returning. |
| Public telemetry | Pessimistic startup placeholders could survive a failed map parse, health dependency states were discarded, idle demand-driven realtime looked broken, a successful local map implied Entur success, and last update could remain empty or regress. | A strict health schema feeds one telemetry reducer; startup is checking, healthy idle realtime is ready/on-demand, Entur without a recent request is standby, only real resource success advances last update, and newer timestamps cannot be erased by polling. |
| Local readiness | `make dev` treated any HTTP response as ready, including a PHP fatal HTML body with status 200, and an initial JSON validator relied on unsupported FrankenPHP CLI argument globals. | Startup validates the realtime bridge, aggregate dependency health, and a non-empty bounded Norway map by JSON shape. The validator passes its shape through an environment value supported by the pinned runtime, and any mismatch stops the stack with logs instead of printing ready. |
| Long-running database authentication | An overnight app-user JWT expiry left the dedicated live-query bridge connected but caused command-side status, watch, and source writes to return HTTP 401, making aggregate health stale and degraded. | The long-running AMPHP command connection recognizes only the exact expired-token failure, coordinates a fresh authenticated replacement, and retries the interrupted query once. Unrelated 401s and replacement failures remain visible; the live-query connection keeps its independent reconnect supervisor. |
| Dependency outage recovery | Realtime-only fallback did not prove that a still-open browser survives loss of all FjordPulse backend processes, and simulated source states did not prove recovery after an actual Entur HTTP process disappears. | Clean-stack Playwright externally stops/restores CakePHP HTTP plus realtime and proves same-document selection/overlay preservation, new WebSocket creation, watch resubscription, and healthy recovery. A backend boundary test establishes a real Amp connection, stops/unbinds Entur, preserves cached authoritative data through explicit 15-second backoff, restarts the same endpoint, and proves automatic budgeted recovery without a PHP restart. |
| Measurements | Unmeasured HTTP and empty Entur p95 values were reported as zero. | Unknown measurements are nullable and render as an em dash. |
| Source attribution | Health failure defaulted the public badge to real/Entur, and demo map loads could write `Entur OK`. | Provenance begins as unknown, renders no Entur claim until health confirms it, and fake mode consistently reports `not_used`. |
| Entur health age | The last request outcome remained healthy or degraded indefinitely. | Outcomes older than five minutes become unknown and lose their latency claim. |
| Production bootstrap | Compose capped the catalog below its current size and isolated Entur-calling workers from outbound networking. | The station prerequisite imports without a record cap; station/realtime workers share private service networking plus non-published egress. Infrastructure validation enforces both. |

## Legitimate static values

The audit does not prohibit constants. These remain legitimate when named, typed, and covered by tests:

- `Europe/Oslo` for transport display and UTC/RFC3339 at boundaries.
- Norway’s initial camera/bounds and documented station/focus zoom levels.
- MapTiler provider/style allowlists, guarded Hybrid layer signatures, and the satellite-first default.
- Refresh priorities, scheduler cadence, request/message safety limits, retention, TTLs, and one realtime replica.
- Recent breadcrumb retention; it is separate from scheduled route geometry.
- Deterministic coordinates, IDs, timestamps, and source states inside explicit fake/test/scenario boundaries.

Operational constants must have one owner in typed runtime configuration or a named domain constant. A frontend build-time duplicate must not override a backend runtime value.

## Enforcement

`scripts/audit-production-truth.mjs` fails the production build when:

- a production-reachable TypeScript module imports fixture data;
- literal user-facing relative ages appear outside explicit scenario files; or
- known fixture vehicle/station sentinels appear in built JavaScript, CSS, or HTML.

Behavioral tests additionally advance the shared clock, exercise Norwegian character folding and mild typo matching, force API failure states, and verify route/cartography behavior without relying on live provider pixels.
