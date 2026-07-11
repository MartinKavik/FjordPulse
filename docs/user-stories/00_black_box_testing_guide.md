# Black-Box Testing Guide

## Goal

Validate FjordPulse without reading source code. The tester should interact with the system the way a real user, admin, or operator would.

## Allowed tools

- Browser mouse/touch interactions.
- Keyboard navigation.
- Browser responsive/mobile emulation.
- Browser DevTools Network and WebSocket panels for behavior verification.
- Admin UI pages.
- Coolify UI/log views for operator stories.
- Public health/status endpoints.
- Real device testing where available.
- Test fixtures or admin toggles exposed through the app, if implemented.

## Not allowed

- Reading application source code to decide whether a story passes.
- Manually querying private databases to bypass the UI, unless a story explicitly exposes an admin diagnostic page.
- Calling Entur directly from the browser as part of normal app verification.
- Accepting behavior that only works with fake transport data in production.

## Test evidence format

For each story, record:

```text
Story ID:
Environment: local / staging / production
Browser/device:
Tester:
Date/time:
Scenario(s) run:
Result: Pass / Fail / Blocked
Evidence: screenshot, video, logs link, or notes
Defects found:
```

## Recommended execution order

1. Public app load and map.
2. Search.
3. Station panel states.
4. Nearby vehicles and vehicle selection.
5. Focus mode states.
6. Fallback/error/stale states.
7. Mobile states.
8. Admin/operator pages.
9. Security/privacy checks.
10. Deployment/ops smoke.
11. Visual and accessibility checks.

## Test data guidance

Production should use real Entur-backed data only. For stale/error/lost/fallback visual states, use one of:
- controlled staging fixture mode,
- operator/admin toggle,
- mocked local environment,
- service outage simulation in staging,
- network blocking in browser/devtools.

Never require fake vehicles in production to pass a production story.

## Outage and recovery method

Use the term **black-box E2E test** when the assertions come from the public browser, public health endpoints, or exposed operator controls without inspecting application internals. A backend-only Entur retry test is instead a **service-boundary integration/fault-injection test**; both belong to the resilience suite, but they prove different boundaries.

- Stop and restore FjordPulse HTTP/realtime with an external local/staging operator control. Do not navigate, reload, recreate the page, or press Retry during the recovery assertion.
- Simulate Entur loss only at the backend's controlled upstream HTTP boundary. The browser must continue to call FjordPulse only; routing browser requests to an Entur stub would violate the architecture and is not valid evidence.
- Take the initial snapshot from a real adapter or the deterministic backend adapter in local/test. During an outage, assert that previous authoritative values remain unchanged; do not generate intermediate vehicle positions or advance source timestamps.
- Measure outage bounds from confirmation that the service/upstream was stopped, and recovery bounds from confirmation that it was restored. Preserve a page-lifetime sentinel or equivalent trace evidence to prove the same document survived.
- Default bounds are: realtime `reconnecting` within 10 seconds; a full FjordPulse backend outage visible as backend-degraded plus offline/polling within 25 seconds; full backend recovery within 30 seconds; and a transient non-rate-limited Entur retry scheduled after 15 seconds and recovered within 20 seconds when upstream is restored before that attempt.
- For Entur 429, `Retry-After` overrides the ordinary 15-second failure delay. Assert no early request and allow the scheduler/network margin only after that timestamp.
