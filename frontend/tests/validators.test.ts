import { describe, expect, it } from "vitest";
import { clientMessageSchema, parseServerMessage, searchResultSchema, serverMessageSchema } from "../src/types/validators";

describe("realtime contract validation", () => {
  it("accepts all protocol v1 client commands with typed identifiers", () => {
    const commands = ["watch_vehicle", "unwatch_vehicle", "focus_vehicle", "unfocus_vehicle", "pause_focus", "resume_focus"] as const;
    for (const type of commands) {
      expect(clientMessageSchema.safeParse({ protocolVersion: 1, id: `msg_${type}`, type, payload: { vehicleId: "SKY:Vehicle:12345" } }).success).toBe(true);
    }
    expect(clientMessageSchema.safeParse({ protocolVersion: 1, id: "msg_station", type: "watch_station", payload: { stationId: "NSR:StopPlace:548" } }).success).toBe(true);
    expect(clientMessageSchema.safeParse({ protocolVersion: 1, id: "msg_ping", type: "ping", payload: {} }).success).toBe(true);
  });

  it("rejects missing entity IDs and unsupported protocol versions", () => {
    expect(clientMessageSchema.safeParse({ protocolVersion: 1, id: "msg_1", type: "watch_station", payload: {} }).success).toBe(false);
    expect(clientMessageSchema.safeParse({ protocolVersion: 2, id: "msg_2", type: "ping", payload: {} }).success).toBe(false);
  });

  it("requires createdAt on every server message", () => {
    expect(serverMessageSchema.safeParse({ protocolVersion: 1, type: "telemetry_tick", payload: {} }).success).toBe(false);
    expect(serverMessageSchema.safeParse({ protocolVersion: 1, type: "pong", id: "msg_1", createdAt: "2026-07-10T10:00:00Z", payload: { serverTime: "2026-07-10T10:00:00Z", echoedSentAt: null } }).success).toBe(true);
  });

  it("requires database identity fields on persistent events", () => {
    const base = { protocolVersion: 1, type: "vehicle_moved", createdAt: "2026-07-10T10:00:01Z", payload: {} };
    expect(serverMessageSchema.safeParse(base).success).toBe(false);
    expect(serverMessageSchema.safeParse({ ...base, scope: "vehicle:SKY:Vehicle:123", entityId: "SKY:Vehicle:123", eventId: "evt_1", version: "2026-07-10T10:00:01Z" }).success).toBe(true);
  });

  it("supports both documented station compatibility notifications", () => {
    for (const type of ["station_departures_changed", "nearby_vehicles_changed"] as const) {
      expect(serverMessageSchema.safeParse({ protocolVersion: 1, type, createdAt: "2026-07-10T10:00:01Z", scope: "station:NSR:StopPlace:548", entityId: "NSR:StopPlace:548", eventId: `evt_${type}`, version: "2026-07-10T10:00:01Z", payload: {} }).success).toBe(true);
    }
  });

  it("returns null for malformed or contract-invalid frames", () => {
    expect(parseServerMessage("not json")).toBeNull();
    expect(parseServerMessage(JSON.stringify({ protocolVersion: 99, type: "pong", createdAt: "today" }))).toBeNull();
  });
});

describe("HTTP DTO validation", () => {
  it("uses the canonical search result field names and nullable coordinates", () => {
    const parsed = searchResultSchema.safeParse({ type: "line", id: "line-100", label: "Line 100", secondaryText: "Førde → Nordfjordeid", stationId: null, lineCode: "100", latitude: null, longitude: null });
    expect(parsed.success).toBe(true);
    expect(searchResultSchema.safeParse({ type: "line", id: "line-100", title: "Wrong fields", subtitle: "old draft" }).success).toBe(false);
  });
});
