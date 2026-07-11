import { describe, expect, it } from "vitest";
import { mergeTelemetryTick, newestTimestamp, telemetryFromHealth } from "../src/state/telemetry";
import type { PublicHealth, ServiceHealth, ServiceHealthStatus, Telemetry } from "../src/types/domain";

const checkedAt = "2026-07-10T10:00:00Z";

function service(status: ServiceHealthStatus): ServiceHealth {
  return { status, checkedAt, lastSuccessAt: null, message: null, latencyMs: null };
}

function health(overrides: Partial<PublicHealth> = {}): PublicHealth {
  return {
    status: "healthy",
    mode: "normal",
    dataMode: "real",
    checkedAt,
    version: "dev",
    fallbackAvailable: true,
    dependencies: {
      http: service("healthy"),
      realtime: service("healthy"),
      surrealdb: service("healthy"),
      entur: service("unknown"),
      liveQueryBridge: service("healthy"),
      mapTiles: service("configured"),
    },
    ...overrides,
  };
}

const initial: Telemetry = {
  backend: "checking",
  realtime: "idle",
  entur: "offline",
  liveQueryBridge: "offline",
  refreshMode: "realtime",
  lastUpdateAt: null,
};

describe("truthful public telemetry", () => {
  it("maps healthy service availability without claiming a browser connection or Entur request", () => {
    expect(telemetryFromHealth(initial, health())).toEqual({
      backend: "ok",
      realtime: "idle",
      entur: "idle",
      liveQueryBridge: "connected",
      refreshMode: "realtime",
      lastUpdateAt: null,
    });
  });

  it("maps dependency failures and demo provenance explicitly", () => {
    const dependencies = {
      ...health().dependencies,
      realtime: service("unavailable"),
      surrealdb: service("degraded"),
      entur: service("degraded"),
      liveQueryBridge: service("reconnecting"),
    };
    expect(telemetryFromHealth(initial, health({ status: "degraded", mode: "fallback_polling", dependencies }))).toMatchObject({
      backend: "degraded",
      realtime: "offline",
      entur: "delayed",
      liveQueryBridge: "reconnecting",
      refreshMode: "polling",
    });
    expect(telemetryFromHealth(initial, health({ dataMode: "fake" })).entur).toBe("not_used");
  });

  it("never erases or regresses the latest authoritative resource timestamp", () => {
    const resource: Telemetry = { ...initial, backend: "ok", realtime: "connected", entur: "ok", liveQueryBridge: "connected", lastUpdateAt: "2026-07-10T10:05:00Z" };
    const nullTick: Telemetry = { ...resource, lastUpdateAt: null };
    const olderTick: Telemetry = { ...resource, lastUpdateAt: "2026-07-10T10:04:00Z" };
    const newerTick: Telemetry = { ...resource, lastUpdateAt: "2026-07-10T10:06:00Z" };
    expect(mergeTelemetryTick(resource, nullTick).lastUpdateAt).toBe("2026-07-10T10:05:00Z");
    expect(mergeTelemetryTick(resource, olderTick).lastUpdateAt).toBe("2026-07-10T10:05:00Z");
    expect(mergeTelemetryTick(resource, newerTick).lastUpdateAt).toBe("2026-07-10T10:06:00Z");
    expect(newestTimestamp(null, "invalid", "2026-07-10T10:05:00Z")).toBe("2026-07-10T10:05:00Z");
  });
});
