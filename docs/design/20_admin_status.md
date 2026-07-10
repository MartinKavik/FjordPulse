# 20_admin_status: Admin system status

**Image:** `20_admin_status.png`  
**Category:** Admin/dev  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Operator dashboard showing backend, realtime, SurrealDB, Entur, watches, latency, and recent events.

## Why this screen matters

This page is crucial for debugging the realtime architecture and demonstrating operational maturity.

## Key visual elements

- Admin left navigation.
- Status cards for Backend, Realtime server, SurrealDB, Entur API.
- Metrics: clients, station watches, vehicle watches, rate budget, latency.
- Recent events table.

## Implementation notes

- This can be backed by health endpoints and recent event log data.
- Keep it available behind admin auth only.
- Use it during demos to prove the architecture is demand-driven and healthy.
- Should not depend on mock data once backend exists.

## Suggested visual/regression scenarios

- `admin_status`
- `backend ok card`
- `realtime connected card`
- `recent events table`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
