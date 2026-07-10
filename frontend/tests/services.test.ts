import { afterEach, describe, expect, it, vi } from "vitest";
import { HttpClient } from "../src/services/httpClient";
import { RealtimeClient, reconnectDelay, websocketUrl } from "../src/services/realtimeClient";
import { resolveMapStyleUrl } from "../src/services/mapStyle";

const meta = { requestId: "req_1", updatedAt: "2026-07-10T10:00:00Z" };
const response = (data: unknown, status = 200) => new Response(JSON.stringify({ ok: true, data, meta }), { status, headers: { "Content-Type": "application/json" } });

afterEach(() => { vi.unstubAllGlobals(); vi.useRealTimers(); });

describe("same-origin service boundaries", () => {
  it("constructs only same-origin WebSocket URLs", () => {
    expect(websocketUrl("/live", "secret-token-value-123", { protocol: "https:", host: "fjordpulse.test" })).toBe("wss://fjordpulse.test/live?token=secret-token-value-123");
    expect(() => websocketUrl("https://external.test/live", null, { protocol: "https:", host: "fjordpulse.test" })).toThrow(/same-origin/);
    expect(() => new HttpClient("https://external.test/api")).toThrow(/same-origin/);
  });

  it("allows only same-origin deployment map styles and keeps fixtures local", () => {
    expect(resolveMapStyleUrl("/map/style.json", false)).toBe("/map/style.json");
    expect(() => resolveMapStyleUrl("https://tile.openstreetmap.org/style.json", false)).toThrow(/same-origin/);
    expect(resolveMapStyleUrl("/map/style.json", true)).toBeNull();
  });

  it("uses bounded reconnect backoff", () => {
    expect(reconnectDelay(0)).toBe(500);
    expect(reconnectDelay(3)).toBe(4000);
    expect(reconnectDelay(20)).toBe(15000);
  });

  it("parses the canonical flat station-map response", async () => {
    const fetchMock = vi.fn().mockResolvedValue(response({ bounds: { minLongitude: 4, minLatitude: 58, maxLongitude: 8, maxLatitude: 63 }, zoom: 5, dataSource: "surrealdb", items: [{ kind: "station", id: "NSR:StopPlace:548", name: "Førde rutebilstasjon", latitude: 61.45, longitude: 5.85, transportModes: ["bus"] }] }));
    vi.stubGlobal("fetch", fetchMock);
    const items = await new HttpClient("/api").getStations([4, 58, 8, 63], 5);
    expect(items[0]).toMatchObject({ kind: "station", id: "NSR:StopPlace:548" });
    expect(String(fetchMock.mock.calls[0]?.[0]).startsWith("/api/stations?")).toBe(true);
  });

  it("maps the authoritative station envelope and subresources", async () => {
    const station = { id: "NSR:StopPlace:548", name: "Førde rutebilstasjon", kind: "bus_station", latitude: 61.45, longitude: 5.85, locality: "Førde", municipality: "Sunnfjord", transportModes: ["bus"], importedAt: "2026-07-10T09:00:00Z" };
    const departure = { id: "dep-1", serviceJourneyId: null, lineId: null, lineCode: "100", destination: "Sandane", aimedDepartureAt: "2026-07-10T10:10:00Z", expectedDepartureAt: "2026-07-10T10:12:00Z", status: "delayed", delaySeconds: 120, platform: null, realtime: true };
    const snapshot = { stationId: station.id, state: "fresh", version: "2026-07-10T10:00:00Z", updatedAt: "2026-07-10T10:00:00Z", lastSuccessfulAt: "2026-07-10T10:00:00Z", warning: null, departures: [departure], nearbyVehicles: [] };
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.endsWith("/departures")) return response({ ...snapshot, nearbyVehicles: undefined });
      if (url.endsWith("/nearby-vehicles")) return response({ stationId: station.id, state: "fresh", version: snapshot.version, updatedAt: snapshot.updatedAt, lastSuccessfulAt: snapshot.lastSuccessfulAt, warning: null, vehicles: [] });
      return response({ station, snapshot });
    });
    vi.stubGlobal("fetch", fetchMock);
    const result = await new HttpClient("/api").getStation(station.id);
    expect(result.station.name).toBe("Førde rutebilstasjon");
    expect(result.departures[0]?.delaySeconds).toBe(120);
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });
});

describe("realtime reconnect lifecycle", () => {
  it("resubscribes Focus with known state and preserves a user pause", async () => {
    vi.useFakeTimers();
    class FakeSocket {
      public readyState = 0;
      public readonly sent: string[] = [];
      private readonly listeners = new Map<string, ((event: { readonly data?: string }) => void)[]>();
      public addEventListener(type: string, listener: (event: { readonly data?: string }) => void): void { this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]); }
      public send(value: string): void { this.sent.push(value); }
      public close(): void { this.readyState = 3; }
      public emit(type: string, event: { readonly data?: string } = {}): void { for (const listener of this.listeners.get(type) ?? []) listener(event); }
      public open(): void { this.readyState = 1; this.emit("open"); }
      public disconnect(): void { this.readyState = 3; this.emit("close"); }
    }
    const sockets: FakeSocket[] = [];
    const client = new RealtimeClient({
      path: "/live",
      webSocketFactory: () => { const socket = new FakeSocket(); sockets.push(socket); return socket as unknown as WebSocket; },
    });
    client.send("focus_vehicle", { vehicleId: "SKY:Vehicle:12345" }, true);
    client.send("pause_focus", { vehicleId: "SKY:Vehicle:12345" }, true);
    await client.connect();
    sockets[0]!.open();
    expect(sockets[0]!.sent.map((raw) => JSON.parse(raw) as { type: string }).map(({ type }) => type)).toEqual(["focus_vehicle", "pause_focus"]);

    sockets[0]!.emit("message", { data: JSON.stringify({ protocolVersion: 1, type: "vehicle_moved", scope: "vehicle:SKY:Vehicle:12345", entityId: "SKY:Vehicle:12345", eventId: "evt_1", version: "2026-07-10T10:00:01Z", createdAt: "2026-07-10T10:00:01Z", payload: {} }) });
    sockets[0]!.disconnect();
    await vi.advanceTimersByTimeAsync(1_000);
    expect(sockets).toHaveLength(2);
    sockets[1]!.open();
    const resent = sockets[1]!.sent.map((raw) => JSON.parse(raw) as { type: string; payload: Record<string, string> });
    expect(resent[0]?.payload).toMatchObject({ vehicleId: "SKY:Vehicle:12345", knownVersion: "2026-07-10T10:00:01Z", lastEventId: "evt_1" });
    expect(resent[1]).toMatchObject({ type: "pause_focus", payload: { vehicleId: "SKY:Vehicle:12345" } });
    client.close();
  });
});
