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

4. Visual tests
   desktop states
   mobile states
   admin states

5. Black-box QA
   user story scenarios from docs/user-stories

6. Production smoke tests
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
