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
   desktop states
   mobile states
   admin states

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
desktop_station_empty
desktop_station_stale
desktop_station_error
desktop_vehicle_selected
desktop_vehicle_focus
desktop_vehicle_paused
desktop_vehicle_stale
desktop_vehicle_lost
desktop_fallback
desktop_search_results
desktop_search_empty
mobile_default
mobile_station_sheet
mobile_station_full
mobile_vehicle_focus
mobile_vehicle_lost
admin_status
admin_watches
admin_entur_log
```

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
fallback
entur_backoff
```

---

# Testing Strategy — Final Tooling

```text
PHPUnit:
  PHP unit/integration/contract tests

Vitest:
  SolidJS stores, reducers, components

Playwright:
  black-box E2E
  desktop/mobile visual regression
  WebSocket/fallback browser scenarios

GitHub Actions:
  typecheck
  PHPStan
  tests
  build
```

Every deterministic UI state must be selectable from a dev/test scenario without source-code modification.
Fixture scenario routes use their canonical fixture timestamp rather than wall time, and visual capture waits for the public map to report a ready state before comparing pixels.

## Resilience timing contract

- Realtime disconnect: show `reconnecting` within 10 seconds.
- Full FjordPulse HTTP + realtime outage: show backend-degraded plus offline/polling within 25 seconds while preserving the map, selection, and last authoritative snapshot.
- FjordPulse restoration: recover backend health, a new socket, active-watch acknowledgement, and realtime mode in the same page within 30 seconds.
- Transient Entur connection/5xx failure: isolate Journey Planner and Vehicle Positions attempts, preserve cached values for the failed source while accepting independently refreshed values from the other, publish a stale/degraded snapshot, and schedule the failed watch's next backend attempt 15 seconds later, plus at most one scheduler tick; if upstream is restored before that attempt, recover within 20 seconds without restarting PHP.
- Entur 429: obey `Retry-After` instead of the ordinary delay and never retry early.

Production never uses outage fixtures or synthesizes movement. Local/staging fault injection must occur at the backend's Entur transport boundary, and browser traffic must remain same-origin FjordPulse traffic throughout.
