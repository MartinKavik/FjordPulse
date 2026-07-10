# Epic M — Documentation and handoff

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-103 — Architecture documentation

**User story:** As a developer, I want architecture documentation, so that the project can be understood and maintained.

### Acceptance criteria

- Docs describe frontend, CakePHP, AMPHP, SurrealDB, Entur, watches, deployment.

### Black-box test scenarios

1. Open architecture docs. Verify all major components are described with diagrams or clear text.
2. Ask a reviewer to explain the data flow from station click to vehicle update using only docs.
3. Verify docs mention why CakePHP does HTTP/control and AMPHP does realtime.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-104 — Local development documentation

**User story:** As a developer, I want local setup instructions, so that I can run the project from scratch.

### Acceptance criteria

- Docs include versions, commands, local DB, migrations, mock mode, HTTP app, realtime process.

### Black-box test scenarios

1. On a clean machine/container, follow docs step by step without asking the original author.
2. Verify local app loads and mock backend states work.
3. Verify realtime process can be started and browser connects locally.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-105 — Deployment documentation

**User story:** As an operator, I want deployment documentation, so that production can be recreated.

### Acceptance criteria

- Docs include Hetzner, Coolify, domain, env vars, services, deploy, rollback.

### Black-box test scenarios

1. Open deployment docs. Verify they include server size, domain, service list, environment variables, and deploy commands.
2. Have a reviewer use docs to create a staging deployment.
3. Verify rollback procedure can be followed in staging.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-106 — Entur usage documentation

**User story:** As a maintainer, I want Entur integration documented, so that API usage remains responsible.

### Acceptance criteria

- Docs cover APIs, backend-only rule, client identity, budgets, caching, stale/backoff.

### Black-box test scenarios

1. Open Entur docs page. Verify Journey Planner and Vehicle Positions usage are explained.
2. Verify rate budget/caching/backoff values are listed.
3. Verify public-browser-to-Entur is explicitly forbidden.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-107 — UI state documentation

**User story:** As a frontend developer, I want UI states documented, so that implementation matches the visual design.

### Acceptance criteria

- Docs map each UI state to screenshot, component state, data state, action, backend behavior.

### Black-box test scenarios

1. Open UI state docs. Verify every packaged mockup has a corresponding state description.
2. Choose three states and manually trigger them in the app.
3. Verify data state, visible UI, and backend behavior match the docs.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-108 — Production readiness checklist

**User story:** As the project owner, I want a final checklist, so that I know when FjordPulse is complete.

### Acceptance criteria

- Checklist includes stories, tests, visual tests, deploy, admin, Entur, fake-data ban, docs, backups, security.

### Black-box test scenarios

1. Open readiness checklist. Verify all required areas are listed with checkbox/status.
2. Before launch, mark each item Pass/Fail with evidence link or screenshot.
3. Do not consider production complete until all critical items are Pass.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
