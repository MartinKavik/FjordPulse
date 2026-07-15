# Admin measurement reference

FjordPulse Admin is a read-only operational view. Every number below comes
from a named runtime or repository source; the UI deliberately omits visitor
analytics and other values for which the application has no collector.

## System status

| Display | Source and meaning | Freshness or window |
|---|---|---|
| Overall and service health | CakePHP combines HTTP availability, the persisted realtime and live-query-bridge heartbeats, station-catalog readiness, the latest non-cache Entur source evidence, and map-key configuration. MapTiler availability itself is checked by the browser, not this endpoint. | Computed for each request. Realtime and bridge heartbeats are degraded after 20 seconds without a fresh report. Entur becomes `unknown` when no non-cache source attempt or source-state record has been observed in five minutes because collection is demand-driven. |
| Browser connections | Open WebSocket connections in the single realtime process. A person may create more than one connection, so this is not a visitor count. | Realtime process state, copied into its status heartbeat every 10 seconds. Resets on process restart; stale heartbeats are shown as degraded with zero live-process counters rather than replaying old connections. |
| Watched stations and vehicles | Durable watch scopes that have at least one attached browser, a future lease, and a non-expired state. Multiple browsers can share one scope. | Current SurrealDB watch rows at request time. |
| Focus sessions | Active per-browser Focus watch scopes. | Current SurrealDB watch rows at request time. |
| WebSocket messages | Client messages received plus server messages successfully delivered to browsers. A broadcast with no recipient is not counted as delivered. | Exact rolling previous 60 seconds in the realtime process; resets on process restart. A heartbeat older than 20 seconds is not treated as a current measurement. |

## Infrastructure

| Display | Source and meaning |
|---|---|
| CPU usage | Two aggregate `/proc/stat` samples 50 ms apart; load averages and logical CPUs come from `/proc/loadavg` and `/proc/stat`. A quiet interval can truthfully read `0.0%`. |
| Memory | Container cgroup v2 limit/current usage when a finite limit exists; otherwise host `MemTotal` and `MemAvailable` from `/proc/meminfo`. The card labels the scope. |
| Application disk | `disk_total_space()` and `disk_free_space()` for the filesystem containing the application. It is not a sum of every host disk. |
| Stored data | Typed SurrealDB repository counts for stations, snapshots, current vehicles, retained observations, watches, realtime events, and Entur request logs. |
| Build and configuration | Server environment, exact `APP_VERSION`, data mode, sanitized database target, station-import provenance, and whether browser map configuration is present. Secrets are never returned. |

Resource readings and repository counts are taken when the Admin status request
runs. They are point samples, not time-series charts.

## Watches

The System status totals count only actively connected demand. The Watches page
also keeps unexpired zero-client rows visible as **disconnect grace** so an
operator can see the automatic-reconnect lease before its 60-second TTL is
collected. These rows are labelled as expiring and do not inflate the active
totals.

Refresh and expiry timestamps come from the durable SurrealDB watch record.
Priority describes scheduler cadence; it is not a user or business priority.

## Entur requests

| Display | Source and meaning | Scope |
|---|---|---|
| Internal request allowance | FjordPulse's shared SurrealDB reservation ledger and configured global/per-service caps. | Rolling 60 seconds across the HTTP and realtime processes. It is a FjordPulse safeguard, not an Entur account quota. |
| Outbound calls | Actual Entur transport attempts in the previous 60 seconds. Cache hits, internal budget skips, and already-active backoff skips are excluded. | A separately database-filtered outbound sample uses the current API filters, so a busy cache cannot crowd actual calls out of this number. The configured cap keeps a full minute within the 1,000-row bound. |
| Cache-hit rate | Share of returned log rows served from FjordPulse cache. | Current bounded server-returned sample, before browser table filtering. |
| p95 latency | Nearest-rank 95th percentile of actual outbound-attempt latency. Cache hits and internal skips are excluded. | Current database-filtered outbound sample, bounded at 1,000 attempts; `Not measured` when it contains no outbound attempt. |
| Backoff | Whether a returned row has a retry deadline later than the response time. | Active deadline only; an old rate-limit row does not leave the card stuck in backoff. |

A recent cache hit proves that FjordPulse has saved data, not that Entur is
currently reachable. Health therefore uses the latest non-cache request as its
upstream evidence.

## Realtime diagnostics

- **Active clients**, **rooms**, and **WebSocket messages** use the same
  realtime heartbeat described above.
- A heartbeat older than 20 seconds makes the service visibly degraded and
  clears live-process counters, rooms, and last-delivery time; FjordPulse does
  not present the stopped process's final heartbeat as current activity.
- **Last delivered broadcast** advances only when at least one browser receives
  a room or all-client broadcast.
- **Database-bridge recoveries** count successful live-query recoveries since
  the realtime process started.
- **Delivery failures** combine supervised live-query bridge failures and
  failed WebSocket sends since process start.

Process-lifetime recovery and failure counters reset when the single realtime
replica restarts. Persisted events are separate: they are durable SurrealDB
notifications created by the canonical database event path, not another copy
of the WebSocket throughput counter.

## Database

Schema and migration pages execute fixed, allowlisted, read-only backend
inspection. Counts, checksums, applied/attempted times, permissions, and
bundled migration source are live release/database evidence. The browser has no
query console, mutation endpoint, or direct SurrealDB connection.

## Intentionally absent

FjordPulse does not currently store unique visitors, page-view history,
retention, or host-resource time series. WebSocket connections and watches are
operational demand signals and must not be relabelled as analytics. Adding
visitor or historical charts requires a privacy decision, retention policy,
and a real persistent collector first.
