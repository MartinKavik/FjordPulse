# Dependency Spikes

Run these before deep implementation and record outcomes in `PROGRESS.md`.

## 1 — CakePHP 6 + PHP 8.5

Pass if an exact version can be installed/pinned, health route works, PHPStan can analyze the skeleton, and lockfile is committed.

## 2 — FrankenPHP normal mode

Pass if CakePHP routes, static assets, errors, and CLI PHP all use compatible PHP 8.5 environments.

## 3 — AMPHP WebSocket command

Pass if `bin/cake realtime start` accepts multiple browser clients, ping/pong remains responsive, disconnects cleanly, and timers run.

## 4 — SurrealDB Runtime::amp()

Pass if the exact pinned alpha supports:

```php
use SurrealDB\SDK\Runtime\Runtime;
new Surreal(Runtime::amp());
```

and requires only the documented compatible Amp/Revolt packages.

## 5 — Non-blocking live query

Pass if a live query consumed in an Amp task does not delay a high-frequency WebSocket ping/pong test.

## 6 — Database event -> live query

Pass if a canonical record update triggers a `realtime_event` and the live query receives it only after a successful commit.

## 7 — Live-query reconnect supervisor

Pass if restarting SurrealDB causes visible reconnecting state, then a new live subscription is registered and future events arrive without restarting the PHP process.

## 8 — Entur APIs

Probe Stop Place Register, Geocoder v3, Journey Planner v3, and Vehicle Positions with backend-only `ET-Client-Name`.

## 9 — Vehicle Positions strategy

Compare upstream GraphQL WebSocket subscriptions with bounded demand-driven query/polling. Choose the simplest reliable adapter and record an ADR.

## 10 — MapLibre source

Pass if local/dev UI and Playwright tests render deterministically without relying on prohibited bulk public OSM tile usage.
