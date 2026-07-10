# Codex Prompt — Phase 8 Real Entur Integration

Replace fake Entur clients with real backend-only Entur clients.

Rules:

- Browser must never call Entur.
- Every Entur request must use configured client identity.
- Enforce global and per-API request budgets.
- Implement cache/fresh/stale/backoff states.
- Log Entur requests in admin request log.
- Preserve the existing frontend and realtime contracts.

If Entur returns no vehicle data, show honest empty/stale/unavailable/lost states. Do not simulate movement.
