# AGENTS.md — FjordPulse

## Mission

Build FjordPulse, a realtime Norwegian public transport explorer that demonstrates modern, typed, asynchronous PHP.

## Target stack

```text
Frontend:
  SolidJS + TypeScript + Vite + MapLibre GL JS

HTTP/control:
  CakePHP 6
  PHP 8.5
  FrankenPHP normal/as-is mode

Realtime:
  CakePHP command using AMPHP/Revolt
  AMPHP HTTP/WebSocket server and clients

Persistence/event stream:
  SurrealDB PHP SDK v2 alpha
  Runtime::sync() for short CakePHP request paths
  Runtime::amp() for long-running realtime workers

Deployment later:
  Hetzner CX33 + Coolify + Docker Compose
  fjordpulse.kavik.cz
```

## Read first

```text
GOAL.md
FINAL_READINESS_REVIEW.md
docs/AI_CONTEXT.md
docs/ARCHITECTURE.md
docs/SURREALDB_LIVE_QUERY_FLOW.md
docs/DEPENDENCY_SPIKES.md
docs/02_development_phases.md
docs/03_api_contract.md
docs/04_realtime_protocol.md
docs/05_testing_strategy.md
docs/design/00_README.md
docs/user-stories/00_README.md
```

## Non-negotiable rules

1. Browser never calls Entur directly.
2. Browser never calls SurrealDB directly.
3. Production mode never fakes or interpolates vehicle movement.
4. Fake third-party adapters are dev/test-only and implement the same interfaces as real adapters.
5. CakePHP controllers never own long-lived WebSocket connections.
6. Realtime runs as `bin/cake realtime start` using AMPHP/Revolt.
7. Realtime service has exactly one replica in v1.
8. No Redis, queue broker, Cloudflare runtime service, or second event bus in v1.
9. SurrealDB is behind typed repository/client interfaces.
10. Use SurrealDB live queries as the primary database-to-WebSocket event path.
11. Do not directly broadcast immediately after a database write; avoid dual event paths and duplicates.
12. If the live-query bridge is unhealthy, expose degraded health and use HTTP snapshot/poll fallback.
13. Authoritative current state lives in SurrealDB; realtime events are notifications, not the source of truth.
14. On subscription/reconnect, send authoritative snapshots and use versions to ignore old/duplicate events.
15. Use UTC/RFC3339 at boundaries and display transport times in `Europe/Oslo`.
16. Pin exact dev/alpha dependency versions and commit lockfiles.
17. Do not invent SDK APIs: verify symbols against the installed exact package.

## Canonical realtime data flow

```text
Entur/fake adapter
  -> typed DTO
  -> repository writes station_snapshot/current_vehicle
  -> SurrealDB DEFINE EVENT creates realtime_event atomically
  -> one global LIVE SELECT on realtime_event
  -> Runtime::amp() event bridge validates RealtimeEvent DTO
  -> room registry broadcasts by scope
  -> SolidJS applies only newer versions
```

Frontend commands flow back as:

```text
watch_station/watch_vehicle/focus_vehicle
  -> WebSocket validator/handler
  -> in-memory room + watch registry
  -> durable SurrealDB watch record
  -> AMPHP timer scheduler refreshes due scopes
```

## SurrealDB SDK imports to verify

The current official v2 alpha docs use:

```php
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Runtime\Runtime;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Auth\DatabaseAuth;
use SurrealDB\SDK\Reconnect\ExponentialBackoffReconnect;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Live\LiveAction;
```

The async instance is created with:

```php
$db = new Surreal(Runtime::amp());
```

The Amp runtime currently suggests:

```text
revolt/event-loop
amphp/http-client
amphp/websocket-client
```

Verify these against the exact installed alpha and update docs if needed.

## Database event model

Use canonical tables roughly like:

```text
station
station_snapshot
current_vehicle
vehicle_observation
watch
realtime_event
entur_request_log
system_status
schema_migration
```

Use `DEFINE EVENT` on `station_snapshot` and `current_vehicle` to append compact `realtime_event` records when semantic content changes. Do not define an event on `realtime_event` itself.

## Live-query reliability

- Use a dedicated SurrealDB WebSocket connection for the live query.
- Use a separate async connection for writes/queries in the realtime process.
- Check `Features::liveQueries()` before starting.
- Supervise the live-query loop and recreate `LIVE SELECT` after reconnect/termination; do not assume an alpha SDK restores unmanaged queries.
- Kill the query on graceful shutdown when possible.
- Surface bridge status in `/admin/status` and health endpoints.
- Re-send snapshots after browser reconnect or bridge recovery.

## Contracts

Canonical files:

```text
contracts/http/openapi.yaml
contracts/realtime/envelope.schema.json
contracts/realtime/client-message.schema.json
contracts/realtime/server-message.schema.json
```

When a contract changes, update schemas, PHP DTOs/validators, TypeScript types/validators, fake adapters, real adapters, tests, and docs.

## Quality commands expected by the finished repository

```bash
make install
make dev
make typecheck
make phpstan
make test
make e2e
make visual
make build
```

## Coding rules

- `declare(strict_types=1);`
- readonly DTOs/value objects where practical.
- enums for protocol and known states.
- no raw third-party arrays outside adapters/mappers.
- no blocking I/O in the AMPHP event loop.
- deterministic fixture scenarios for every visual state.
- structured logs with request/event/scope IDs.
- update `PROGRESS.md` throughout implementation.

## Done means

A story is done only when visible behavior, acceptance criteria, and black-box scenarios pass and contracts/types/tests/docs are updated.
