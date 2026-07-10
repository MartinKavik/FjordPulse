# ADR 0009 — Machine-readable contracts and browser testing

## Status

Accepted.

## Decision

Canonical contracts:

```text
contracts/http/openapi.yaml
contracts/realtime/envelope.schema.json
contracts/realtime/client-message.schema.json
contracts/realtime/server-message.schema.json
```

Testing:

```text
PHPUnit:
  PHP unit/integration tests

Vitest:
  frontend unit/state tests

Playwright:
  black-box E2E and visual tests
```

## Rationale

The fake backend and real backend must remain interchangeable. Machine-readable contracts and contract tests prevent silent divergence.
