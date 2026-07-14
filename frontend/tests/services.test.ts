import { afterEach, describe, expect, it, vi } from "vitest";
import { HttpClient } from "../src/services/httpClient";
import { adminDatabaseMigrationsFixture, adminDatabaseSchemaFixture } from "../src/fixtures/scenarios";
import { RealtimeClient, reconnectDelay, websocketUrl } from "../src/services/realtimeClient";
import { BASEMAP_STORAGE_KEY, initialBasemap, isAllowedMapTilerStyleUrl, mapConfigSchema, rememberBasemap, styleUrlFor } from "../src/services/mapStyle";

const meta = { requestId: "req_1", updatedAt: "2026-07-10T10:00:00Z" };
const response = (data: unknown, status = 200) => new Response(JSON.stringify({ ok: true, data, meta }), { status, headers: { "Content-Type": "application/json" } });

afterEach(() => { vi.unstubAllGlobals(); vi.useRealTimers(); });

describe("same-origin service boundaries", () => {
  it("constructs only same-origin WebSocket URLs", () => {
    expect(websocketUrl("/live", "secret-token-value-123", { protocol: "https:", host: "fjordpulse.test" })).toBe("wss://fjordpulse.test/live?token=secret-token-value-123");
    expect(() => websocketUrl("https://external.test/live", null, { protocol: "https:", host: "fjordpulse.test" })).toThrow(/same-origin/);
    expect(() => new HttpClient("https://external.test/api")).toThrow(/same-origin/);
  });

  it("accepts only the two protected MapTiler style paths", () => {
    expect(isAllowedMapTilerStyleUrl("https://api.maptiler.com/maps/hybrid-v4/style.json?key=test-key", "satellite")).toBe(true);
    expect(isAllowedMapTilerStyleUrl("https://api.maptiler.com/maps/streets-v4/style.json?key=test-key", "streets")).toBe(true);
    expect(isAllowedMapTilerStyleUrl("https://api.maptiler.com/maps/streets-v4/style.json?key=test-key", "satellite")).toBe(false);
    expect(isAllowedMapTilerStyleUrl("https://evil.test/maps/hybrid-v4/style.json?key=test-key", "satellite")).toBe(false);
    expect(isAllowedMapTilerStyleUrl("http://api.maptiler.com/maps/hybrid-v4/style.json?key=test-key", "satellite")).toBe(false);
    expect(isAllowedMapTilerStyleUrl("https://api.maptiler.com/maps/hybrid-v4/style.json", "satellite")).toBe(false);
    expect(isAllowedMapTilerStyleUrl("https://api.maptiler.com/maps/hybrid-v4/style.json?key=first&key=second", "satellite")).toBe(false);
    expect(isAllowedMapTilerStyleUrl("https://api.maptiler.com/maps/hybrid-v4/style.json?key=test-key&redirect=evil", "satellite")).toBe(false);
  });

  it("honours the backend default and remembers only a successful basemap selection", () => {
    const config = mapConfigSchema.parse({
      provider: "maptiler",
      defaultBasemap: "satellite",
      basemaps: [
        { id: "satellite", label: "Satellite", styleUrl: "https://api.maptiler.com/maps/hybrid-v4/style.json?key=test-key" },
        { id: "streets", label: "Map", styleUrl: "https://api.maptiler.com/maps/streets-v4/style.json?key=test-key" },
      ],
    });
    const values = new Map<string, string>();
    const storage = { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => values.set(key, value) };
    expect(initialBasemap(config, storage)).toBe("satellite");
    expect(initialBasemap({ ...config, defaultBasemap: "streets" }, storage)).toBe("streets");
    values.set(BASEMAP_STORAGE_KEY, "terrain");
    expect(initialBasemap(config, storage)).toBe("satellite");
    rememberBasemap("streets", storage);
    expect(initialBasemap(config, storage)).toBe("streets");
    expect(styleUrlFor(config, "streets")).toContain("/streets-v4/");
  });

  it("fetches and validates same-origin map configuration", async () => {
    const config = {
      provider: "maptiler",
      defaultBasemap: "satellite",
      basemaps: [
        { id: "satellite", label: "Satellite", styleUrl: "https://api.maptiler.com/maps/hybrid-v4/style.json?key=test-key" },
        { id: "streets", label: "Map", styleUrl: "https://api.maptiler.com/maps/streets-v4/style.json?key=test-key" },
      ],
    };
    const fetchMock = vi.fn().mockResolvedValue(response(config));
    vi.stubGlobal("fetch", fetchMock);
    await expect(new HttpClient("/api").getMapConfig()).resolves.toEqual(config);
    expect(fetchMock).toHaveBeenCalledWith("/api/map/config", expect.objectContaining({ credentials: "same-origin" }));
  });

  it("fully validates public health dependencies instead of discarding them", async () => {
    const checkedAt = "2026-07-10T10:00:00Z";
    const service = (status: "healthy" | "configured" | "unknown") => ({ status, checkedAt, lastSuccessAt: null, message: null, latencyMs: null });
    const health = {
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
    };
    const fetchMock = vi.fn().mockResolvedValue(response(health));
    vi.stubGlobal("fetch", fetchMock);
    await expect(new HttpClient("/api").getHealth()).resolves.toEqual(health);

    fetchMock.mockResolvedValueOnce(response({ dataMode: "real" }));
    await expect(new HttpClient("/api").getHealth()).rejects.toMatchObject({ code: "invalid_contract" });
  });

  it("retains admin build, data, import, and event diagnostics", async () => {
    const checkedAt = "2026-07-10T10:00:00Z";
    const service = (status: "healthy" | "configured" | "unknown", message: string) => ({ status, checkedAt, lastSuccessAt: checkedAt, message, latencyMs: null });
    const data = {
      build: { version: "1.2.3-test", environment: "staging", dataMode: "real" },
      database: { engine: "surrealdb" as const, endpointOrigin: "wss://surrealdb.staging.test:8000", namespace: "fjordpulse", name: "fjordpulse_staging", warning: null },
      resources: {
        checkedAt,
        cpu: { usagePercent: 42.5, load1: 1.2, load5: 1, load15: 0.8, logicalCores: 4 },
        memory: { totalBytes: 8_000_000_000, availableBytes: 3_000_000_000, usedBytes: 5_000_000_000, usedPercent: 62.5, scope: "cgroup" as const },
        disk: { path: "/", totalBytes: 100_000_000_000, freeBytes: 40_000_000_000, usedBytes: 60_000_000_000, usedPercent: 60 },
      },
      services: {
        backend: service("healthy", "HTTP is serving."),
        realtime: service("healthy", "Realtime is serving."),
        surrealdb: service("healthy", "Database is reachable."),
        entur: service("unknown", "No recent source request."),
        liveQueryBridge: service("healthy", "Live query is subscribed."),
        mapTiles: service("configured", "Map tiles are configured."),
      },
      metrics: { activeClients: 2, stationWatches: 3, vehicleWatches: 4, focusWatches: 1, messagesPerMinute: 12 },
      dataCounts: { stations: 57_964, stationSnapshots: 8, currentVehicles: 6, vehicleObservations: 40, watches: 7, realtimeEvents: 30, enturRequestLogs: 20 },
      stationImport: { count: 57_964, lastImportedAt: "2026-07-10T08:00:00Z", sourceVersion: "netex-2026-07-10" },
      enturBudgets: [
        { service: "global", limit: 60, remaining: 52, windowSeconds: 60, backoffUntil: null },
        { service: "stop_place_register", limit: 60, remaining: 60, windowSeconds: 60, backoffUntil: null },
        { service: "geocoder", limit: 20, remaining: 18, windowSeconds: 60, backoffUntil: null },
        { service: "journey_planner", limit: 30, remaining: 26, windowSeconds: 60, backoffUntil: null },
        { service: "vehicle_positions", limit: 30, remaining: 28, windowSeconds: 60, backoffUntil: null },
      ],
      recentEvents: [
        { eventId: "evt-stale", type: "vehicle_stale", scope: "vehicle:1", entityId: "1", version: "2026-07-10T09:59:58Z", source: "current_vehicle", payload: { state: "stale", lastSeenAt: "2026-07-10T09:59:00Z" }, createdAt: "2026-07-10T09:59:58Z" },
        { eventId: "evt-lost", type: "vehicle_lost", scope: "vehicle:2", entityId: "2", version: "2026-07-10T09:59:59Z", source: "current_vehicle", payload: { state: "lost" }, createdAt: "2026-07-10T09:59:59Z" },
      ],
    };
    const fetchMock = vi.fn().mockResolvedValue(response(data));
    vi.stubGlobal("fetch", fetchMock);

    const status = await new HttpClient("/api").getAdminStatus();

    expect(status.build).toEqual(data.build);
    expect(status.database).toEqual(data.database);
    expect(status.resources).toEqual(data.resources);
    expect(status.dataCounts).toEqual(data.dataCounts);
    expect(status.stationImport).toEqual(data.stationImport);
    expect(status.enturBudgets).toEqual(data.enturBudgets);
    expect(status.dependencies.find((dependency) => dependency.name === "Entur API")).toMatchObject({
      state: "idle",
      detail: "No recent source request.",
    });
    expect(status.dependencies.some((dependency) => dependency.state === "degraded")).toBe(false);
    expect(status.metrics.find((metric) => metric.label === "Active WebSocket clients")).toMatchObject({
      value: "2",
      detail: "12/min messages · connections, not unique visitors",
    });
    expect(status.metrics.find((metric) => metric.label === "Active vehicle watches")).toMatchObject({
      value: "4",
      detail: "Shared selected-vehicle scopes",
    });
    expect(status.metrics.find((metric) => metric.label === "Active Focus sessions")).toMatchObject({
      value: "1",
      detail: "One high-priority watch per focused browser session",
    });
    expect(status.metrics.some((metric) => metric.label.includes("p95"))).toBe(false);
    expect(status.metrics.some((metric) => metric.label.includes("rate budget"))).toBe(false);
    expect(status.events).toEqual([
      expect.objectContaining({ id: "evt-stale", entityId: "1", version: "2026-07-10T09:59:58Z", source: "current_vehicle", payload: data.recentEvents[0]!.payload, status: "warning" }),
      expect.objectContaining({ id: "evt-lost", entityId: "2", source: "current_vehicle", payload: data.recentEvents[1]!.payload, status: "warning" }),
    ]);

    fetchMock.mockResolvedValueOnce(response({
      ...data,
      database: { ...data.database, endpointOrigin: "wss://database-user:database-secret@surrealdb.staging.test:8000/rpc?token=secret" },
    }));
    await expect(new HttpClient("/api").getAdminStatus()).rejects.toMatchObject({ code: "invalid_contract" });

    fetchMock.mockResolvedValueOnce(response({
      ...data,
      recentEvents: Array.from({ length: 6 }, (_, index) => ({
        ...data.recentEvents[0]!,
        eventId: `event-${index}`,
      })),
    }));
    await expect(new HttpClient("/api").getAdminStatus()).rejects.toMatchObject({ code: "invalid_contract" });
  });

  it("validates read-only schema and migration diagnostics on their canonical GET endpoints", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(response(adminDatabaseSchemaFixture))
      .mockResolvedValueOnce(response(adminDatabaseMigrationsFixture));
    vi.stubGlobal("fetch", fetchMock);
    const http = new HttpClient("/api");

    await expect(http.getAdminDatabaseSchema()).resolves.toEqual(adminDatabaseSchemaFixture);
    await expect(http.getAdminDatabaseMigrations()).resolves.toEqual(adminDatabaseMigrationsFixture);
    expect(fetchMock).toHaveBeenNthCalledWith(1, "/api/admin/database/schema", expect.objectContaining({ method: "GET", credentials: "same-origin" }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, "/api/admin/database/migrations", expect.objectContaining({ method: "GET", credentials: "same-origin" }));

    fetchMock.mockResolvedValueOnce(response({ ...adminDatabaseSchemaFixture, readOnly: false }));
    await expect(http.getAdminDatabaseSchema()).rejects.toMatchObject({ code: "invalid_contract" });

    fetchMock.mockResolvedValueOnce(response({
      ...adminDatabaseSchemaFixture,
      tables: [{ ...adminDatabaseSchemaFixture.tables[0]!, permissions: { ...adminDatabaseSchemaFixture.tables[0]!.permissions, select: "owner" } }],
    }));
    await expect(http.getAdminDatabaseSchema()).rejects.toMatchObject({ code: "invalid_contract" });
  });

  it("accepts only explicit public Admin demo-credential states", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(response({ enabled: true, username: "demo", password: "fjordpulse-demo" }))
      .mockResolvedValueOnce(response({ enabled: false }))
      .mockResolvedValueOnce(response({ enabled: false, username: "operator", password: "secret" }));
    vi.stubGlobal("fetch", fetchMock);
    const http = new HttpClient("/api");

    await expect(http.getAdminDemoCredentials()).resolves.toEqual({ enabled: true, username: "demo", password: "fjordpulse-demo" });
    await expect(http.getAdminDemoCredentials()).resolves.toEqual({ enabled: false });
    await expect(http.getAdminDemoCredentials()).rejects.toMatchObject({ code: "invalid_contract" });
    expect(fetchMock).toHaveBeenNthCalledWith(1, "/api/admin/demo-credentials", expect.objectContaining({ method: "GET", credentials: "same-origin" }));
  });

  it("requires the server-authored Admin session access role", async () => {
    const session = { authenticated: true, username: "demo", access: "demo", expiresAt: "2026-07-13T20:00:00Z" } as const;
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(response(session))
      .mockResolvedValueOnce(response({ authenticated: true, username: "demo", expiresAt: "2026-07-13T20:00:00Z" }));
    vi.stubGlobal("fetch", fetchMock);
    const http = new HttpClient("/api");

    await expect(http.getAdminSession()).resolves.toEqual(session);
    await expect(http.getAdminSession()).rejects.toMatchObject({ code: "invalid_contract" });
  });

  it("uses bounded reconnect backoff", () => {
    expect(reconnectDelay(0)).toBe(500);
    expect(reconnectDelay(3)).toBe(4000);
    expect(reconnectDelay(20)).toBe(15000);
  });

  it("parses the canonical flat station-map response with its server update time", async () => {
    const fetchMock = vi.fn().mockResolvedValue(response({ bounds: { minLongitude: 4, minLatitude: 58, maxLongitude: 8, maxLatitude: 63 }, zoom: 5, dataSource: "surrealdb", items: [{ kind: "station", id: "NSR:StopPlace:548", name: "Førde rutebilstasjon", latitude: 61.45, longitude: 5.85, transportModes: ["bus"] }] }));
    vi.stubGlobal("fetch", fetchMock);
    const result = await new HttpClient("/api").getStationMap([4, 58, 8, 63], 5);
    expect(result.items[0]).toMatchObject({ kind: "station", id: "NSR:StopPlace:548" });
    expect(result.updatedAt).toBe(meta.updatedAt);
    expect(String(fetchMock.mock.calls[0]?.[0]).startsWith("/api/stations?")).toBe(true);
  });

  it("maps the authoritative station envelope and subresources", async () => {
    const station = { id: "NSR:StopPlace:548", name: "Førde rutebilstasjon", kind: "bus_station", latitude: 61.45, longitude: 5.85, locality: "Førde", municipality: "Sunnfjord", transportModes: ["bus"], importedAt: "2026-07-10T09:00:00Z" };
    const departure = { id: "dep-1", serviceJourneyId: null, lineId: null, lineCode: "100", destination: "Sandane", aimedDepartureAt: "2026-07-10T10:10:00Z", expectedDepartureAt: "2026-07-10T10:12:00Z", status: "delayed", delaySeconds: 120, platform: null, realtime: true };
    const snapshot = { stationId: station.id, state: "fresh", version: "2026-07-10T10:00:00Z", updatedAt: "2026-07-10T10:00:00Z", lastSuccessfulAt: "2026-07-10T10:00:00Z", warning: null, departures: [departure], departureBoard: { windowStart: "2026-07-10T10:00:00Z", windowEnd: "2026-07-10T22:00:00Z", limit: 20, hasMore: true }, nearbyVehicles: [], servingVehicles: [], servingVehicleCoverage: { windowStart: "2026-07-10T04:00:00Z", windowEnd: "2026-07-10T16:00:00Z", candidateJourneyCount: 0, queriedJourneyCount: 0, truncated: false } };
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.endsWith("/departures")) return response({
        stationId: station.id,
        state: snapshot.state,
        version: snapshot.version,
        updatedAt: snapshot.updatedAt,
        lastSuccessfulAt: snapshot.lastSuccessfulAt,
        warning: null,
        mode: "preview",
        date: "2026-07-10",
        timeZone: "Europe/Oslo",
        windowStart: "2026-07-10T10:00:00Z",
        windowEnd: "2026-07-10T22:00:00Z",
        page: { limit: 20, hasMore: true, nextCursor: null },
        complete: false,
        totalCount: null,
        departures: [departure],
      });
      if (url.endsWith("/nearby-vehicles")) return response({ stationId: station.id, state: "fresh", version: snapshot.version, updatedAt: snapshot.updatedAt, lastSuccessfulAt: snapshot.lastSuccessfulAt, warning: null, searchRadiusMeters: 5_000, vehicles: [] });
      return response({ station, snapshot });
    });
    vi.stubGlobal("fetch", fetchMock);
    const result = await new HttpClient("/api").getStation(station.id);
    expect(result.station.name).toBe("Førde rutebilstasjon");
    expect(result.departures[0]?.delaySeconds).toBe(120);
    expect(result.departureBoard).toEqual({ windowStart: "2026-07-10T10:00:00Z", windowEnd: "2026-07-10T22:00:00Z", limit: 20, hasMore: true });
    expect(result.nearbyVehicleSearchRadiusMeters).toBe(5_000);
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it("loads a bounded same-origin day timetable with date, limit, and opaque cursor", async () => {
    const departure = { id: "dep-day-51", serviceJourneyId: null, lineId: null, lineCode: "100", destination: "Sandane", aimedDepartureAt: "2026-07-10T15:10:00Z", expectedDepartureAt: null, status: "scheduled", delaySeconds: null, platform: null, realtime: false };
    const dayResponse = {
      stationId: "NSR:StopPlace:36025",
      state: "fresh",
      version: "2026-07-10T10:00:00Z",
      updatedAt: "2026-07-10T10:00:00Z",
      lastSuccessfulAt: "2026-07-10T10:00:00Z",
      warning: null,
      mode: "day",
      date: "2026-07-10",
      timeZone: "Europe/Oslo",
      windowStart: "2026-07-09T22:00:00Z",
      windowEnd: "2026-07-10T22:00:00Z",
      departures: [departure],
      page: { limit: 50, hasMore: true, nextCursor: "opaque_cursor_2" },
      complete: false,
      totalCount: null,
    };
    const fetchMock = vi.fn().mockImplementation(async () => response(dayResponse));
    vi.stubGlobal("fetch", fetchMock);

    const result = await new HttpClient("/api").getStationDepartureBoard("NSR:StopPlace:36025", "2026-07-10", 50, "opaque_cursor_1");

    expect(result.departures[0]).toMatchObject({ id: "dep-day-51", destination: "Sandane" });
    expect(result.page).toEqual({ limit: 50, hasMore: true, nextCursor: "opaque_cursor_2" });
    expect(fetchMock).toHaveBeenCalledWith("/api/stations/NSR%3AStopPlace%3A36025/departures?date=2026-07-10&limit=50&cursor=opaque_cursor_1", expect.objectContaining({ credentials: "same-origin" }));

    await new HttpClient("/api").getStationDepartureBoard("NSR:StopPlace:36025", "2026-07-10", 50, null, undefined, true);
    expect(fetchMock).toHaveBeenLastCalledWith("/api/stations/NSR%3AStopPlace%3A36025/departures?date=2026-07-10&limit=50&refresh=true", expect.objectContaining({ credentials: "same-origin" }));
  });
});

describe("realtime reconnect lifecycle", () => {
  it("leaves fallback mode immediately when the live-query bridge recovers", async () => {
    class FakeSocket {
      public readyState = 0;
      public readonly sent: string[] = [];
      private readonly listeners = new Map<string, ((event: { readonly data?: string }) => void)[]>();
      public addEventListener(type: string, listener: (event: { readonly data?: string }) => void): void { this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener]); }
      public send(value: string): void { this.sent.push(value); }
      public close(): void { this.readyState = 3; }
      public emit(type: string, event: { readonly data?: string } = {}): void { for (const listener of this.listeners.get(type) ?? []) listener(event); }
      public open(): void { this.readyState = 1; this.emit("open"); }
    }
    const states: string[] = [];
    const socket = new FakeSocket();
    const onFallback = vi.fn();
    const client = new RealtimeClient({
      path: "/live",
      webSocketFactory: () => socket as unknown as WebSocket,
      onState: (state) => states.push(state),
      onFallback,
    });
    client.send("watch_vehicle", { vehicleId: "SKY:Vehicle:12345" }, true);
    await client.connect();
    socket.open();

    socket.emit("message", { data: JSON.stringify({
      protocolVersion: 1,
      type: "realtime_degraded",
      createdAt: "2026-07-10T10:00:19Z",
      payload: { reason: "The database live-query bridge is reconnecting.", fallbackPolling: true, bridgeStatus: "reconnecting" },
    }) });
    expect(client.connectionState).toBe("degraded");
    expect(onFallback).toHaveBeenCalledOnce();

    socket.emit("message", { data: JSON.stringify({
      protocolVersion: 1,
      type: "resync_required",
      createdAt: "2026-07-10T10:00:20Z",
      payload: { reason: "bridge_recovered", scopes: ["vehicle:SKY:Vehicle:12345"] },
    }) });

    expect(client.connectionState).toBe("connected");
    expect(states).toEqual(["connecting", "connected", "degraded", "connected"]);
    expect(socket.sent.map((raw) => JSON.parse(raw) as { type: string }).map(({ type }) => type)).toEqual(["watch_vehicle", "watch_vehicle"]);
    client.close();
  });

  it("does not advance reconnect state for a malformed station event", async () => {
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
    const onMessage = vi.fn();
    const client = new RealtimeClient({
      path: "/live",
      webSocketFactory: () => { const socket = new FakeSocket(); sockets.push(socket); return socket as unknown as WebSocket; },
      onMessage,
    });
    client.send("watch_station", { stationId: "NSR:StopPlace:548" }, true);
    await client.connect();
    sockets[0]!.open();

    sockets[0]!.emit("message", { data: JSON.stringify({
      protocolVersion: 1,
      type: "station_snapshot_changed",
      scope: "station:NSR:StopPlace:548",
      entityId: "NSR:StopPlace:548",
      eventId: "evt_invalid_station",
      version: "2026-07-10T10:00:01Z",
      createdAt: "2026-07-10T10:00:01Z",
      payload: { stationId: "NSR:StopPlace:548", state: "fresh", version: "2026-07-10T10:00:01Z", updatedAt: "2026-07-10T10:00:01Z", departures: [], nearbyVehicles: [], servingVehicles: [{ callRole: "calls_here" }] },
    }) });
    expect(onMessage).not.toHaveBeenCalled();

    sockets[0]!.disconnect();
    await vi.advanceTimersByTimeAsync(1_000);
    sockets[1]!.open();
    const resent = sockets[1]!.sent.map((raw) => JSON.parse(raw) as { payload: Record<string, string> });
    expect(resent[0]?.payload).toEqual({ stationId: "NSR:StopPlace:548" });
    client.close();
  });

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
