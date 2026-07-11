import type { PublicHealth, ServiceHealthStatus, SourceState, Telemetry } from "../types/domain";

function isAvailable(status: ServiceHealthStatus): boolean {
  return status === "healthy" || status === "configured";
}

function isUnavailable(status: ServiceHealthStatus): boolean {
  return status === "unavailable" || status === "misconfigured";
}

function backendState(health: PublicHealth): Telemetry["backend"] {
  if (isUnavailable(health.dependencies.http.status)) return "offline";
  return isAvailable(health.dependencies.http.status) && isAvailable(health.dependencies.surrealdb.status)
    ? "ok"
    : "degraded";
}

function idleRealtimeState(health: PublicHealth): Telemetry["realtime"] {
  if (isAvailable(health.dependencies.realtime.status)) return "idle";
  if (isUnavailable(health.dependencies.realtime.status)) return "offline";
  return "reconnecting";
}

function bridgeState(health: PublicHealth): Telemetry["liveQueryBridge"] {
  const status = health.dependencies.liveQueryBridge.status;
  if (isAvailable(status)) return "connected";
  if (status === "reconnecting") return "reconnecting";
  if (isUnavailable(status)) return "offline";
  return "degraded";
}

function enturState(health: PublicHealth): Telemetry["entur"] {
  if (health.dataMode === "fake") return "not_used";
  const status = health.dependencies.entur.status;
  if (isAvailable(status)) return "ok";
  if (status === "unknown") return "idle";
  if (isUnavailable(status)) return "offline";
  return "delayed";
}

/**
 * Maps service availability without pretending that this browser has opened a
 * WebSocket or received transport data. Browser connection state and resource
 * timestamps remain authoritative once a watch exists.
 */
export function telemetryFromHealth(current: Telemetry, health: PublicHealth): Telemetry {
  return {
    ...current,
    backend: backendState(health),
    realtime: current.realtime === "idle" ? idleRealtimeState(health) : current.realtime,
    entur: enturState(health),
    liveQueryBridge: bridgeState(health),
    refreshMode: health.mode === "fallback_polling" ? "polling" : current.refreshMode,
  };
}

export function enturStateFromStation(
  state: SourceState,
  dataMode: "real" | "fake" | "unknown",
  serverState: Telemetry["entur"] | null,
): Telemetry["entur"] {
  if (dataMode === "fake") return "not_used";
  if (dataMode === "unknown") return serverState ?? "idle";

  const resourceState: Telemetry["entur"] = (() => {
    if (state === "fresh" || state === "empty") return "ok";
    if (state === "rate_limited") return "rate_limited";
    if (state === "backoff") return "backoff";
    if (state === "error" || state === "unavailable") return "offline";
    if (state === "stale" || state === "refreshing") return "delayed";
    return "idle";
  })();

  if (serverState === "offline" || resourceState === "offline") return "offline";
  if (resourceState === "rate_limited" || resourceState === "backoff") return resourceState;
  if (serverState === "rate_limited" || serverState === "backoff") return serverState;
  if (serverState === "delayed" || resourceState === "delayed") return "delayed";
  if (resourceState === "ok") return "ok";
  return serverState ?? resourceState;
}

export function newestTimestamp(...values: readonly (string | null)[]): string | null {
  let newest: string | null = null;
  let newestTime = Number.NEGATIVE_INFINITY;
  for (const value of values) {
    if (value === null) continue;
    const time = Date.parse(value);
    if (Number.isFinite(time) && time > newestTime) {
      newest = value;
      newestTime = time;
    }
  }
  return newest;
}

/** A bridge heartbeat must never erase or age-regress an authoritative snapshot timestamp. */
export function mergeTelemetryTick(current: Telemetry, tick: Telemetry): Telemetry {
  return {
    ...current,
    ...tick,
    lastUpdateAt: newestTimestamp(current.lastUpdateAt, tick.lastUpdateAt),
  };
}
