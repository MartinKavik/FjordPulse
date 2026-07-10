# ADR 0006 — SurrealDB runtime split

## Status

Accepted. Publication-path details are superseded by ADR 0010.

## Decision

Use the SurrealDB PHP SDK v2 alpha with explicit runtimes:

```text
CakePHP short web requests:
  Runtime::sync()

AMPHP realtime command:
  Runtime::amp()
```

Run long-lived live queries only in the realtime process or another dedicated worker, never inside a normal web request.

Use separate async command/query and live-query connections in the realtime process.

## Publication path

ADR 0010 defines SurrealDB database events plus live queries as the primary realtime publication path. Direct post-write broadcast is not used in v1.

## Fallback

If the alpha SDK proves unusable, retain repository/event-bridge interfaces, record a replacement ADR, and preserve the HTTP/realtime contracts.
