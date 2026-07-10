# Codex Prompt — Phase 6 Real Backend with Fake Third Parties

Implement the real backend architecture while keeping third-party services fake.

Required:

- CakePHP 6 HTTP app.
- FrankenPHP normal-mode compatible container.
- `bin/cake realtime start` using AMPHP/Revolt.
- SurrealDB migration runner and repositories.
- real watch registry and room registry.
- fake Entur clients behind final interfaces.
- admin status/watches/request-log APIs.

Do not connect real Entur yet.

All fake service behavior must use the same interface as real service behavior.
