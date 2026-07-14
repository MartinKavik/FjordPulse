# ADR 0001 — Stack choice

## Status

Accepted for the v1 application-stack experiment. The original Hetzner hosting
clause is superseded by [ADR 0014](0014-sharptech-single-host-production.md);
the remaining stack decision stays accepted.

## Decision

Use:

```text
SolidJS + TypeScript + Vite + MapLibre
CakePHP 6 + PHP 8.5
FrankenPHP normal mode
AMPHP/Revolt realtime command
SurrealDB
Hetzner CX33 + Coolify
```

The hosting line above records the original decision. The current production
host is the Sharptech Medium VPS selected by ADR 0014.

## Context

The project is not primarily an ORM/CRUD demo. It is a modern PHP realtime experiment. The company context includes CakePHP, so CakePHP remains valuable as the HTTP/control framework, but realtime is handled by AMPHP/Revolt.

## Consequences

Positive:

- tests CakePHP in a modern architecture,
- demonstrates PHP realtime via AMPHP/Revolt,
- keeps frontend modern and reactive,
- keeps deployment understandable through Coolify,
- avoids browser-to-third-party coupling.

Negative:

- CakePHP 6 is dev/experimental,
- SurrealDB PHP SDK v2 alpha may be unstable,
- AMPHP integration requires careful async discipline,
- not the simplest production stack.

## Revisit when

- CakePHP 6 causes significant blocking issues,
- SurrealDB SDK/runtime becomes too unstable,
- realtime requires multiple nodes,
- production traffic outgrows single VPS.
