# ADR 0012 — Exact experimental dependency pins

## Status

Accepted.

## Decision

The v1 experiment uses exact, lockfile-backed versions for every runtime and
development dependency. The important runtime surface is:

```text
FrankenPHP       1.12.4 (embedded PHP 8.5.8)
CakePHP          6.x commit 39f5594eb9c79e3ec46aa786b617af0a622b72d3
SurrealDB server 3.2.0
SurrealDB PHP SDK 2.0.0-alpha.1
Revolt event loop 1.0.9
AMPHP            exact versions recorded in backend/composer.lock
Node.js          22.22.0
SolidJS          1.9.14
MapLibre GL JS   5.24.0
Vite             8.1.4
```

CakePHP has no tagged 6.x release at the time of this spike, so Composer pins
the official `6.x` branch to the tested commit. Portable project wrappers
download FrankenPHP, Composer, and SurrealDB by exact version and verify their
SHA-256 checksums before execution.

## Rationale

CakePHP 6 and the SurrealDB SDK v2 are pre-release surfaces. Exact pins and
committed lockfiles make the successful spike reproducible and prevent an
upstream alpha or branch change from silently changing APIs.

The exact PHP SDK alpha does not expose its WebSocket `kill` RPC. On the tested
SDK/server pair, sending SurrealQL `KILL` through the generic query RPC cannot
resolve the active connection-owned live-query id. FjordPulse therefore owns a
dedicated live-query WebSocket and closes that connection during graceful
shutdown, which releases its live query without affecting command/query work.
The supervisor path is covered by real integration and local orchestration
tests. Re-evaluate a direct SDK kill method on every SDK upgrade.

The portable FrankenPHP `php-cli` binary also faults when an operating-system
termination signal is delivered directly while the alpha live connection is
active. `scripts/realtime.sh` is therefore the container/local supervisor: it
translates `SIGINT`/`SIGTERM` into a private shutdown file, lets the CakePHP
command close the dedicated connection and AMPHP server in-process, and then
returns the child's status. `make dev`/`make stop` and Compose use this wrapper;
the application process still runs the canonical `bin/cake realtime start`
command underneath it.

## Upgrade policy

An upgrade is an explicit change: update the pin, regenerate the applicable
lockfile, inspect installed symbols, and rerun all dependency, integration,
contract, and browser gates before merging.
