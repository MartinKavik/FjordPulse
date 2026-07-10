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
