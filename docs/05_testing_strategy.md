# FjordPulse Testing Strategy

## Testing layers

```text
1. Static checks
   PHPStan
   TypeScript

2. Unit tests
   DTOs
   validators
   Entur mappers
   state reducers

3. Integration tests
   fake backend contract
   realtime protocol
   watch lifecycle
   SurrealDB migrations

4. Service-boundary resilience tests
   controlled Entur HTTP outage/5xx
   authoritative snapshot preservation
   budgeted scheduled retry without backend restart

5. Visual tests
   every desktop/mobile/admin/design-system route in Norwegian and English
   secondary Vehicles and Details station-tab states on desktop and mobile
   localized layout and overflow checks

6. Black-box QA
   user story scenarios from docs/user-stories
   actual FjordPulse HTTP/realtime process outage and same-page recovery

7. Production smoke tests
   public app
   health endpoint
   search
   station
   realtime
   admin
```

## Visual state priority

Implement screenshot/visual checks for:

```text
desktop_default_map
desktop_station_fresh
desktop_station_loading
desktop_station_empty
desktop_station_stale
desktop_station_error
desktop_vehicle_selected
desktop_vehicle_focus
desktop_vehicle_paused
desktop_vehicle_stale
desktop_vehicle_lost
desktop_vehicle_non_passenger
desktop_fallback
desktop_search_results
desktop_search_empty
mobile_default
mobile_station_sheet
mobile_station_full
mobile_vehicle_focus
mobile_vehicle_lost
mobile_vehicle_non_passenger
admin_status
admin_infrastructure
admin_database
admin_watches
admin_entur_log
design_system_components
```

The canonical inventory contains 27 deterministic scenario routes. Capture each
route once with `nb` selected and once with `en` selected for 54 base
comparisons. The desktop fresh-station and mobile full-station routes also
capture their Vehicles and Details tabs in both languages, adding eight
secondary-tab baselines. Mobile System status, the closed Infrastructure
resource hierarchy, and the open Infrastructure navigation drawer add six more
bilingual captures. Expanded Database schema and migration details add six
desktop/mobile bilingual captures, for 74 locale-aware visual comparisons
overall. These do not add scenario routes. Every capture asserts the matching `<html lang>`
value and uses the same fixture clock and map-ready boundary. The generated
coded baselines, rather than the English text in the original design PNGs,
define the exact localized copy and geometry.

Database contract tests validate both protected canonical GET endpoints and the
legacy migrations alias. Schema fixtures prove the response is a typed
allowlist and never contains raw INFO users, password hashes, credentials, or
authentication definitions. Migration fixtures cover `applied`, `pending`,
`checksum_mismatch`, `orphaned`, and `failed`, plus release/database checksums,
attempt times, affected objects, and bounded bundled source. Browser tests prove
that the Database tabs, filtering, and disclosures are read-only and that no
query, Apply, Retry, Edit, or Rollback control exists. Migration-runner tests
separately prove that only the CLI records attempts and that a failed attempt
survives its rolled-back schema transaction.

Admin-authentication tests treat public demo access as a distinct security
boundary. Production-default coverage requires credential discovery to return
disabled; enabled coverage proves the response contains only the separate demo
identity, the localized login action fills it without exposing the operator
password, and its signed session can read explicitly allowlisted diagnostics
and log out. Middleware tests route hypothetical future Admin read and mutation
endpoints and prove both are rejected before their handlers run; they also prove
that disabling demo access revokes an existing demo cookie and that encoded
login paths share one rate-limit bucket. Clean-stack browser coverage checks the
login actions at 320 px, including their 32 px minimum target height and absence
of horizontal overflow.

Admin-navigation tests distinguish same-document routing from a page that only
appears to preserve Back/Forward by performing full reloads. Unit coverage keeps
the authenticated shell mounted through pending, error, Retry, and deliberately
out-of-order page responses; it also proves that the retained page is visible but
inert while stale and that route-error focus is recovered. Clean-stack browser coverage carries a DOM sentinel
through sidebar links, and additionally counts main-document requests and carries
DOM/window sentinels through Database tabs and history traversal. A separate 320
px fault-injection scenario verifies the
initial dark loading card, safe top padding, in-shell error containment, Retry,
computed reduced-motion durations, short-landscape control clearance, and the
desktop progress bar's alignment with the content edge.

In addition to pixel comparison at the canonical 1440 x 900 desktop and 390 x
844 mobile sizes, browser checks exercise both locales at supported narrow and
intermediate widths. They fail when a localized button, tab, status chip,
navigation item, card, or table cell clips its label, overlaps another control,
or creates unintended horizontal viewport scrolling.

The desktop and mobile non-passenger captures also assert that the selected
marker and active Focus controls survive while operational line, route,
destination, delay, previous/next stop, journey-progress, stale-schedule, and
raw Entur-warning content remains absent. Both locales run this state through
the horizontal-overflow guard, including the 320 px mobile width.

## Station-panel tab contract

- Departures owns only the departure board: time, line, destination, platform
  when reported, and status. Its badge equals the rendered upcoming-row count.
- Vehicles owns the de-duplicated station-serving and other-nearby lists. Its
  badge equals the number of unique rendered vehicle rows. Plain-language scope
  remains visible while exact coverage-window and candidate/queried/truncated
  diagnostics are collapsed by default.
- Details replaces Info. It prioritizes stable place, station-type, and
  transport-mode facts plus a rider-readable explanation of data scope. Stop
  ID, coordinates, and timezone remain available in a collapsed technical
  disclosure. Missing locality/municipality fields are omitted instead of
  producing repeated placeholder cards.
- Loading, refreshing, empty, and error copy is scoped to the affected
  Departures or Vehicles tab. Known Details facts remain usable and switching
  tabs must not cause a refetch by itself.
- Component tests assert the allocation, authoritative count derivation,
  accessible count descriptions, platform rendering, disclosure toggles, tab
  keyboard linkage, missing station metadata, and truthful loading/error/empty
  copy. Fixture and clean-stack browser tests switch through all tabs, preserve
  station/map context, and open vehicle rows only from Vehicles. Desktop/mobile
  secondary-tab screenshots and serious-accessibility audits cover Vehicles and
  Details in Norwegian and English without changing the 27-route inventory.
  Mobile vehicle rows give relation/call time two lines and move last-seen age
  to secondary metadata.

## Black-box principle

Every story should be testable by a human or AI browser agent without reading source code.

An outage test is browser black-box only when its assertions use visible/public behavior. The Entur retry scheduler cannot be tested by making the browser call Entur—that is forbidden—so deterministic Entur outage/recovery is a backend service-boundary fault-injection test, paired with a browser assertion that no Entur or SurrealDB destination appears.

Use fixtures/dev scenarios for states that are hard to trigger naturally:

```text
station_empty
station_stale
station_error
vehicle_stale
vehicle_lost
vehicle_non_passenger
fallback
entur_backoff
```

---

# Testing Strategy — Final Tooling

```text
PHPUnit:
  PHP unit/integration/contract tests

Vitest:
  SolidJS stores, reducers, components, locale persistence and fallback

Playwright:
  black-box E2E
  bilingual desktop/mobile/admin/design-system visual regression
  language-switch persistence, document language and responsive overflow
  WebSocket/fallback browser scenarios

GitHub Actions:
  typecheck
  PHPStan
  tests
  build
```

Every deterministic UI state must be selectable from a dev/test scenario without source-code modification.
Fixture scenario routes use their canonical fixture timestamp rather than wall time, and visual capture waits for the public map to report a ready state before comparing pixels.

## Localization contract

- Norwegian Bokmål (`nb`) is deterministic default UI language, even when the browser prefers English.
- The visible `NO`/`EN` switch updates the current document without navigation and exposes its selected state to assistive technology.
- A valid explicit choice is restored from `fjordpulse.locale.v1`; missing, invalid, or inaccessible local storage falls back to Norwegian without preventing the app from loading.
- Switching updates `<html lang>` and all reactive public, admin, map, search, status, fixture, and accessibility labels. Proper names, provider names, transport identifiers, URLs, scopes, and raw diagnostic payloads remain authoritative data rather than translated copy.
- Unit/behavior tests cover the default, both switch directions, persistence, invalid/blocked storage, and interpolation. Browser tests cover keyboard access, reload persistence, representative public/admin navigation, and layout geometry in both languages.

## Resilience timing contract

- Realtime disconnect: show `reconnecting` within 10 seconds.
- Full FjordPulse HTTP + realtime outage: show backend-degraded plus offline/polling within 25 seconds while preserving the map, selection, and last authoritative snapshot.
- FjordPulse restoration: recover backend health, a new socket, active-watch acknowledgement, and realtime mode in the same page within 30 seconds.
- Transient Entur connection/5xx failure: isolate Journey Planner and Vehicle Positions attempts, preserve cached values for the failed source while accepting independently refreshed values from the other, publish a stale/degraded snapshot, and schedule the failed watch's next backend attempt 15 seconds later, plus at most one scheduler tick; if upstream is restored before that attempt, recover within 20 seconds without restarting PHP.
- Entur 429: obey `Retry-After` instead of the ordinary delay and never retry early.
- Vehicle observation gap: remain live through 30 seconds, then stale through five minutes; a successful nationwide response that omits one vehicle follows the same age policy and never jumps directly to unavailable. A new observation restores the same open focus watch without a browser/WebSocket reconnect.
- Repeated degraded journey refreshes with identical cached route/calls and warning must retain one semantic version/content hash so they do not manufacture repeated stale/lost vehicle events.
- Duplicate vehicle identity across a completed passenger journey and a newer operational/dead-run record is tested in both input orders. The newest observation remains authoritative, its live marker and Focus watch survive, and `passengerServiceState=non_passenger` suppresses passenger line/delay/stop presentation and Journey Planner enrichment. A later canonical journey restores the passenger UI on the same watch.
- HTTP, realtime JSON Schema, and browser Zod validators reject a non-passenger full snapshot that contains a journey or upcoming stops. Station-serving contracts likewise reject non-passenger rows, while nearby lists intentionally retain them with destination-neutral accessibility copy.
- Ordinary line, route, destination, and fuzzy search excludes lost vehicles. A clean-stack browser check confirms that an exact known vehicle ID remains discoverable after loss, without making normal place searches trigger Vehicle Positions work; Norwegian search translates the operational status.
- During Journey Planner failure, a saved station-serving relation survives only for a fresh same-ID vehicle that is not non-passenger and has the same non-null journey identity. Lost, non-passenger, missing-identity, and changed-journey positions lose the serving relation but may remain in the nearby result.
- Journey copy is tested as a matrix: cached successful calls may be shown as saved/possibly outdated; a successful negative lookup or a failed lookup without cached success is unavailable, never merely `stale`; raw upstream warnings remain diagnostic rather than rider copy.

Production never uses outage fixtures or synthesizes movement. Local/staging fault injection must occur at the backend's Entur transport boundary, and browser traffic must remain same-origin FjordPulse traffic throughout.
