import { z } from "zod";
import type { AdminDatabaseMigrations, AdminDatabaseSchema, AdminDemoCredentials, AdminEnturLog, AdminRealtime, AdminSession, AdminStatus, ApiEnvelope, EnturLogRow, MapConfig, MapItem, PublicHealth, RealtimeEventRow, SearchResult, StationDepartureBoard, StationSnapshot, VehicleState, WatchRow } from "../types/domain";
import { mapConfigSchema } from "./mapStyle";
import {
  apiEnvelopeSchema,
  mapStationDepartureBoard,
  nearbyVehiclesDataSchema,
  searchDataSchema,
  stationDataSchema,
  stationDepartureBoardDataSchema,
  stationDeparturesDataSchema,
  stationMapDataSchema,
  toMapItems,
  toStationSnapshot,
  toVehicleState,
  vehicleDataSchema,
} from "../types/validators";

const rfc3339 = z.string().refine((value) => Number.isFinite(Date.parse(value)));
const serviceHealthSchema = z.object({
  status: z.enum(["healthy", "configured", "degraded", "reconnecting", "unavailable", "misconfigured", "unknown"]),
  checkedAt: rfc3339,
  lastSuccessAt: rfc3339.nullable().optional(),
  message: z.string().nullable().optional(),
  latencyMs: z.number().nonnegative().nullable().optional(),
}).strict();

const realtimeEventRowSchema = z.object({
  eventId: z.string(), type: z.enum(["station_snapshot_changed", "vehicle_moved", "vehicle_stale", "vehicle_lost"]), scope: z.string(), entityId: z.string(), version: rfc3339,
  source: z.enum(["station_snapshot", "current_vehicle"]), payload: z.record(z.string(), z.unknown()), createdAt: rfc3339,
}).strict();

const adminStatusContractSchema = z.object({
  build: z.object({ version: z.string(), environment: z.enum(["local", "development", "test", "staging", "production"]), dataMode: z.enum(["fake", "real"]) }).strict(),
  database: z.object({ engine: z.literal("surrealdb"), endpointOrigin: z.string().url().regex(/^wss?:\/\/[^/@?#]+$/), namespace: z.string().min(1), name: z.string().min(1), warning: z.string().nullable() }).strict(),
  resources: z.object({
    checkedAt: rfc3339,
    cpu: z.object({ usagePercent: z.number().min(0).max(100).nullable(), load1: z.number().nonnegative().nullable(), load5: z.number().nonnegative().nullable(), load15: z.number().nonnegative().nullable(), logicalCores: z.number().int().positive().nullable() }).strict(),
    memory: z.object({ totalBytes: z.number().int().nonnegative().nullable(), availableBytes: z.number().int().nonnegative().nullable(), usedBytes: z.number().int().nonnegative().nullable(), usedPercent: z.number().min(0).max(100).nullable(), scope: z.enum(["host", "cgroup"]) }).strict(),
    disk: z.object({ path: z.string().min(1), totalBytes: z.number().int().nonnegative().nullable(), freeBytes: z.number().int().nonnegative().nullable(), usedBytes: z.number().int().nonnegative().nullable(), usedPercent: z.number().min(0).max(100).nullable() }).strict(),
  }).strict(),
  services: z.object({ backend: serviceHealthSchema, realtime: serviceHealthSchema, surrealdb: serviceHealthSchema, entur: serviceHealthSchema, liveQueryBridge: serviceHealthSchema, mapTiles: serviceHealthSchema }).strict(),
  metrics: z.object({ activeClients: z.number().int().nonnegative(), stationWatches: z.number().int().nonnegative(), vehicleWatches: z.number().int().nonnegative(), focusWatches: z.number().int().nonnegative(), messagesPerMinute: z.number().nonnegative() }).strict(),
  dataCounts: z.object({ stations: z.number().int().nonnegative(), stationSnapshots: z.number().int().nonnegative(), stationTimetables: z.number().int().nonnegative(), currentVehicles: z.number().int().nonnegative(), journeySnapshots: z.number().int().nonnegative(), vehicleObservations: z.number().int().nonnegative(), watches: z.number().int().nonnegative(), realtimeEvents: z.number().int().nonnegative(), enturRequestLogs: z.number().int().nonnegative() }).strict(),
  stationImport: z.object({ count: z.number().int().nonnegative(), lastImportedAt: rfc3339.nullable(), sourceVersion: z.string().nullable() }).strict(),
  enturBudgets: z.array(z.object({ service: z.enum(["global", "stop_place_register", "geocoder", "journey_planner", "vehicle_positions"]), limit: z.number().int().nonnegative(), remaining: z.number().int().nonnegative(), windowSeconds: z.number().int().positive(), backoffUntil: rfc3339.nullable() }).strict()).length(5),
  recentEvents: z.array(realtimeEventRowSchema).max(5),
}).strict();

const watchContractSchema = z.object({
  id: z.string(), type: z.enum(["station", "vehicle", "focus"]), scope: z.string(), entityId: z.string(), clientCount: z.number().int().nonnegative(),
  priority: z.enum(["background", "station", "selected_vehicle", "focus"]), state: z.enum(["active", "stale", "backoff", "failed", "expired"]),
  lastRefreshAt: rfc3339.nullable(), nextRefreshAt: rfc3339.nullable(), expiresAt: rfc3339, lastErrorCode: z.string().nullable().optional(),
}).strict();
const watchesDataSchema = z.object({ summary: z.object({ total: z.number().int(), focus: z.number().int(), expiringSoon: z.number().int(), failed: z.number().int() }).strict(), watches: z.array(watchContractSchema) }).strict();

const enturLogContractSchema = z.object({
  id: z.string(), requestedAt: rfc3339, service: z.enum(["stop_place_register", "geocoder", "journey_planner", "vehicle_positions"]), scope: z.string(),
  outcome: z.enum(["success", "cache_hit", "skipped_budget", "rate_limited", "backoff", "timeout", "error"]), httpStatus: z.number().int().min(100).max(599).nullable(),
  latencyMs: z.number().nonnegative().nullable(), itemCount: z.number().int().nonnegative().nullable(), cache: z.enum(["hit", "miss", "bypassed", "stale"]), retryAt: rfc3339.nullable(), requestId: z.string(), errorCode: z.string().nullable().optional(),
}).strict();
const enturLogDataSchema = z.object({ metrics: z.object({ requestsPerMinute: z.number(), cacheHitRate: z.number(), p95LatencyMs: z.number().nullable(), inBackoff: z.boolean() }).strict(), entries: z.array(enturLogContractSchema) }).strict();
const adminSessionSchema = z.object({ authenticated: z.literal(true), username: z.string().min(1), access: z.enum(["operator", "demo"]), expiresAt: rfc3339 }).strict();
const adminDemoCredentialsSchema = z.discriminatedUnion("enabled", [
  z.object({ enabled: z.literal(false) }).strict(),
  z.object({ enabled: z.literal(true), username: z.string().min(1).max(200), password: z.string().min(1).max(1024) }).strict(),
]);
const adminRealtimeContractSchema = z.object({
  server: serviceHealthSchema, liveQueryBridge: serviceHealthSchema, activeClients: z.number().int().nonnegative(), rooms: z.array(z.object({ scope: z.string(), clientCount: z.number().int().nonnegative() }).strict()), messagesPerMinute: z.number().nonnegative(), reconnectCount: z.number().int().nonnegative(), failureCount: z.number().int().nonnegative(), lastBroadcastAt: rfc3339.nullable(),
}).strict();
const adminEventsDataSchema = z.object({ events: z.array(realtimeEventRowSchema) }).strict();
const databasePermissionModeSchema = z.enum(["full", "none", "conditional"]);
const adminDatabaseSchemaDataSchema = z.object({
  readOnly: z.literal(true),
  checkedAt: rfc3339,
  tables: z.array(z.object({
    name: z.string().min(1).max(300),
    kind: z.enum(["normal", "relation", "any"]),
    schemaMode: z.enum(["schemafull", "schemaless"]),
    permissions: z.object({ select: databasePermissionModeSchema, create: databasePermissionModeSchema, update: databasePermissionModeSchema, delete: databasePermissionModeSchema }).strict(),
    fields: z.array(z.object({
      name: z.string().min(1).max(300), type: z.string().min(1).max(1_000), readonly: z.boolean(), assertion: z.string().max(10_000).nullable(), defaultValue: z.string().max(10_000).nullable(), computedValue: z.string().max(10_000).nullable(), valueExpression: z.string().max(10_000).nullable(), referenceOnDelete: z.string().max(100).nullable(),
      permissions: z.object({ select: databasePermissionModeSchema, create: databasePermissionModeSchema, update: databasePermissionModeSchema }).strict(),
    }).strict()),
    indexes: z.array(z.object({ name: z.string().min(1).max(300), fields: z.array(z.string().min(1).max(300)), unique: z.boolean(), mode: z.string().max(1_000).nullable() }).strict()),
    events: z.array(z.object({ name: z.string().min(1).max(300), condition: z.string().max(20_000).nullable(), actions: z.array(z.string().min(1).max(20_000)) }).strict()),
  }).strict()),
}).strict();
const adminDatabaseMigrationsDataSchema = z.object({
  readOnly: z.literal(true),
  checkedAt: rfc3339,
  state: z.enum(["in_sync", "pending", "drift", "failed"]),
  counts: z.object({ applied: z.number().int().nonnegative(), pending: z.number().int().nonnegative(), checksumMismatch: z.number().int().nonnegative(), orphaned: z.number().int().nonnegative(), failed: z.number().int().nonnegative() }).strict(),
  lastAppliedAt: rfc3339.nullable(),
  migrations: z.array(z.object({
    name: z.string().min(1).max(300), description: z.string().max(2_000), state: z.enum(["applied", "pending", "checksum_mismatch", "orphaned", "failed"]),
    releaseChecksum: z.string().min(1).max(128).nullable(), databaseChecksum: z.string().min(1).max(128).nullable(), appliedAt: rfc3339.nullable(), lastAttemptedAt: rfc3339.nullable(), failureMessage: z.string().max(2_000).nullable(), source: z.string().max(250_000).nullable(),
    affectedObjects: z.array(z.object({ kind: z.enum(["table", "field", "index", "event"]), name: z.string().min(1).max(300), table: z.string().min(1).max(300).nullable(), operation: z.enum(["define", "remove", "rebuild"]) }).strict()),
  }).strict()),
}).strict();
const publicHealthSchema = z.object({
  status: z.enum(["healthy", "degraded", "unhealthy"]),
  mode: z.enum(["normal", "fallback_polling"]),
  dataMode: z.enum(["real", "fake"]),
  checkedAt: rfc3339,
  version: z.string().min(1).max(128),
  fallbackAvailable: z.boolean(),
  dependencies: z.object({
    http: serviceHealthSchema,
    realtime: serviceHealthSchema,
    surrealdb: serviceHealthSchema,
    entur: serviceHealthSchema,
    liveQueryBridge: serviceHealthSchema,
    mapTiles: serviceHealthSchema,
  }).strict(),
}).strict();

export class ApiClientError extends Error {
  public constructor(message: string, public readonly status: number, public readonly code: string, public readonly details: Readonly<Record<string, unknown>> = {}) {
    super(message); this.name = "ApiClientError";
  }
}

interface RequestOptions { readonly method?: "GET" | "POST" | undefined; readonly body?: unknown; readonly signal?: AbortSignal | undefined; }

function cleanBase(base: string): string {
  if (!base.startsWith("/") || base.startsWith("//") || base.includes("://")) throw new Error("FjordPulse API base must be a same-origin absolute path");
  return base.replace(/\/$/, "");
}

function serviceState(status: z.infer<typeof serviceHealthSchema>["status"], unknownState: "idle" | "degraded" = "degraded"): "ok" | "idle" | "reconnecting" | "degraded" | "offline" {
  if (status === "healthy" || status === "configured") return "ok";
  if (status === "reconnecting") return "reconnecting";
  if (status === "unavailable" || status === "misconfigured") return "offline";
  if (status === "unknown") return unknownState;
  return "degraded";
}

function toAdminStatus(data: z.infer<typeof adminStatusContractSchema>): AdminStatus {
  const serviceEntries = [
    ["Backend", data.services.backend], ["Realtime server", data.services.realtime], ["SurrealDB", data.services.surrealdb], ["Entur API", data.services.entur], ["Live-query bridge", data.services.liveQueryBridge], ["Map tiles", data.services.mapTiles],
  ] as const;
  return {
    build: data.build,
    database: data.database,
    resources: data.resources,
    dataCounts: data.dataCounts,
    stationImport: data.stationImport,
    enturBudgets: data.enturBudgets,
    dependencies: serviceEntries.map(([name, service]) => ({ name, state: serviceState(service.status, name === "Entur API" ? "idle" : "degraded"), detail: service.message ?? `${name} ${service.status}`, ...(service.latencyMs === null || service.latencyMs === undefined ? {} : { latencyMs: service.latencyMs }) })),
    metrics: [
      { label: "Active WebSocket clients", value: String(data.metrics.activeClients), detail: `${data.metrics.messagesPerMinute} WebSocket messages in the last 60 seconds · connections, not unique visitors`, tone: "info" },
      { label: "Active station watches", value: String(data.metrics.stationWatches), detail: "Shared station scopes", tone: "info" },
      { label: "Active vehicle watches", value: String(data.metrics.vehicleWatches), detail: "Shared selected-vehicle scopes", tone: "info" },
      { label: "Active Focus sessions", value: String(data.metrics.focusWatches), detail: "One high-priority watch per focused browser session", tone: "info" },
    ],
    events: data.recentEvents.map((event) => ({
      id: event.eventId,
      type: event.type,
      scope: event.scope,
      entityId: event.entityId,
      version: event.version,
      source: event.source,
      payload: event.payload,
      createdAt: event.createdAt,
      status: event.type === "vehicle_stale" || event.type === "vehicle_lost" ? "warning" : "ok",
    })),
  };
}

function toWatchRow(row: z.infer<typeof watchContractSchema>): WatchRow {
  return {
    id: row.id, type: row.type, scope: row.scope, clients: row.clientCount,
    priority: row.priority === "focus" ? "critical" : row.priority === "selected_vehicle" ? "high" : "normal",
    lastRefreshAt: row.lastRefreshAt, nextRefreshAt: row.nextRefreshAt,
    state: row.state === "expired" ? "expiring" : row.state === "backoff" ? "stale" : row.state,
  };
}

const serviceLabels = { stop_place_register: "Stop Place Register", geocoder: "Geocoder", journey_planner: "Journey Planner", vehicle_positions: "Vehicle Positions" } as const;
function toEnturLogRow(row: z.infer<typeof enturLogContractSchema>): EnturLogRow {
  const status = row.outcome === "success" || row.outcome === "cache_hit" ? "ok" : row.outcome === "rate_limited" ? "rate_limited" : row.outcome === "backoff" || row.outcome === "skipped_budget" ? "backoff" : "error";
  return { id: row.id, createdAt: row.requestedAt, api: serviceLabels[row.service], scope: row.scope, status, latencyMs: row.latencyMs, requestCount: row.itemCount, cache: row.cache === "bypassed" ? "miss" : row.cache, retryAt: row.retryAt };
}

export class HttpClient {
  private readonly base: string;
  public constructor(base = import.meta.env.VITE_API_BASE ?? "/api") { this.base = cleanBase(base); }

  private async requestWithMeta<T>(path: string, schema: z.ZodType<T>, options: RequestOptions = {}): Promise<{ readonly data: T; readonly updatedAt: string }> {
    const init: RequestInit = { method: options.method ?? "GET", credentials: "same-origin", headers: options.body === undefined ? { Accept: "application/json" } : { Accept: "application/json", "Content-Type": "application/json" }, ...(options.body === undefined ? {} : { body: JSON.stringify(options.body) }), ...(options.signal === undefined ? {} : { signal: options.signal }) };
    const response = await fetch(`${this.base}${path}`, init);
    let raw: unknown;
    try { raw = await response.json(); } catch { throw new ApiClientError("The server returned an unreadable response.", response.status, "invalid_response"); }
    const result = apiEnvelopeSchema(z.unknown()).safeParse(raw);
    if (!result.success) throw new ApiClientError("The server response did not match the FjordPulse contract.", response.status, "invalid_contract", { issues: result.error.issues });
    const envelope = result.data as ApiEnvelope<unknown>;
    if (!response.ok || !envelope.ok) { if (!envelope.ok) throw new ApiClientError(envelope.error.message, response.status, envelope.error.code, envelope.error.details); throw new ApiClientError(`Request failed with HTTP ${response.status}.`, response.status, "http_error"); }
    const parsed = schema.safeParse(envelope.data);
    if (!parsed.success) throw new ApiClientError("The server data did not match the FjordPulse contract.", response.status, "invalid_contract", { issues: parsed.error.issues });
    return { data: parsed.data, updatedAt: envelope.meta.updatedAt };
  }

  private async request<T>(path: string, schema: z.ZodType<T>, options: RequestOptions = {}): Promise<T> {
    return (await this.requestWithMeta(path, schema, options)).data;
  }

  public getHealth(signal?: AbortSignal): Promise<PublicHealth> { return this.request("/health", publicHealthSchema, { signal }); }
  public getMapConfig(signal?: AbortSignal): Promise<MapConfig> { return this.request("/map/config", mapConfigSchema, { signal }); }
  public getStationMap(bbox: readonly [number, number, number, number], zoom: number, signal?: AbortSignal): Promise<{ readonly items: readonly MapItem[]; readonly updatedAt: string }> {
    const params = new URLSearchParams({ bbox: bbox.join(","), zoom: String(zoom) });
    return this.requestWithMeta(`/stations?${params.toString()}`, stationMapDataSchema, { signal })
      .then(({ data, updatedAt }) => ({ items: toMapItems(data.items), updatedAt }));
  }
  public getStations(bbox: readonly [number, number, number, number], zoom: number, signal?: AbortSignal): Promise<readonly MapItem[]> {
    return this.getStationMap(bbox, zoom, signal).then(({ items }) => items);
  }
  public search(query: string, signal?: AbortSignal): Promise<readonly SearchResult[]> {
    const params = new URLSearchParams({ q: query });
    return this.request(`/search?${params.toString()}`, searchDataSchema, { signal }).then((data) => data.results);
  }
  public async getStation(stationIdValue: string, signal?: AbortSignal, refresh = false): Promise<StationSnapshot> {
    const encoded = encodeURIComponent(stationIdValue);
    const base = await this.request(`/stations/${encoded}${refresh ? "?refresh=true" : ""}`, stationDataSchema, { signal });
    const [departureResult, nearbyResult] = await Promise.allSettled([
      this.request(`/stations/${encoded}/departures`, stationDeparturesDataSchema, { signal }),
      this.request(`/stations/${encoded}/nearby-vehicles`, nearbyVehiclesDataSchema, { signal }),
    ]);
    const snapshot = {
      ...base.snapshot,
      departures: departureResult.status === "fulfilled" ? departureResult.value.departures : base.snapshot.departures,
      departureBoard: departureResult.status === "fulfilled" ? {
        windowStart: departureResult.value.windowStart,
        windowEnd: departureResult.value.windowEnd,
        limit: departureResult.value.page?.limit ?? Math.max(1, departureResult.value.departures.length),
        hasMore: departureResult.value.page?.hasMore ?? false,
      } : base.snapshot.departureBoard,
      nearbyVehicles: nearbyResult.status === "fulfilled" ? nearbyResult.value.vehicles : base.snapshot.nearbyVehicles,
    };
    return toStationSnapshot(
      base.station,
      snapshot,
      nearbyResult.status === "fulfilled" ? nearbyResult.value.searchRadiusMeters : null,
    );
  }
  public async getStationDepartureBoard(stationIdValue: string, date: string, limit = 50, cursor?: string | null, signal?: AbortSignal, refresh = false): Promise<StationDepartureBoard> {
    const params = new URLSearchParams({ date, limit: String(limit) });
    if (cursor !== undefined && cursor !== null) params.set("cursor", cursor);
    if (refresh) params.set("refresh", "true");
    const data = await this.request(`/stations/${encodeURIComponent(stationIdValue)}/departures?${params.toString()}`, stationDepartureBoardDataSchema, { signal });
    return mapStationDepartureBoard(data);
  }
  public async getVehicle(vehicleIdValue: string, signal?: AbortSignal, refresh = false): Promise<VehicleState> { return toVehicleState(await this.request(`/vehicles/${encodeURIComponent(vehicleIdValue)}${refresh ? "?refresh=true" : ""}`, vehicleDataSchema, { signal })); }
  public async createRealtimeToken(signal?: AbortSignal): Promise<string | null> { return (await this.request("/realtime-token", z.object({ token: z.string().min(20), expiresAt: rfc3339, webSocketUrl: z.string().url(), protocolVersion: z.literal(1) }).strict(), { method: "POST", body: {}, signal })).token; }
  public async getAdminStatus(signal?: AbortSignal): Promise<AdminStatus> { return toAdminStatus(await this.request("/admin/status", adminStatusContractSchema, { signal })); }
  public async getAdminWatches(signal?: AbortSignal): Promise<readonly WatchRow[]> { return (await this.request("/admin/watches", watchesDataSchema, { signal })).watches.map(toWatchRow); }
  public async getAdminEnturLog(signal?: AbortSignal): Promise<AdminEnturLog> { const data = await this.request("/admin/entur-log", enturLogDataSchema, { signal }); return { metrics: data.metrics, entries: data.entries.map(toEnturLogRow) }; }
  public async getAdminRealtime(signal?: AbortSignal): Promise<AdminRealtime> {
    const data = await this.request("/admin/realtime", adminRealtimeContractSchema, { signal });
    const dependency = (name: string, service: z.infer<typeof serviceHealthSchema>) => ({ name, state: serviceState(service.status), detail: service.message ?? `${name} ${service.status}`, ...(service.latencyMs === null || service.latencyMs === undefined ? {} : { latencyMs: service.latencyMs }) });
    return { ...data, server: dependency("Realtime server", data.server), liveQueryBridge: dependency("Live-query bridge", data.liveQueryBridge) };
  }
  public async getAdminEvents(signal?: AbortSignal): Promise<readonly RealtimeEventRow[]> { return (await this.request("/admin/events", adminEventsDataSchema, { signal })).events; }
  public getAdminDatabaseSchema(signal?: AbortSignal): Promise<AdminDatabaseSchema> { return this.request("/admin/database/schema", adminDatabaseSchemaDataSchema, { signal }); }
  public getAdminDatabaseMigrations(signal?: AbortSignal): Promise<AdminDatabaseMigrations> { return this.request("/admin/database/migrations", adminDatabaseMigrationsDataSchema, { signal }); }
  public getAdminDemoCredentials(signal?: AbortSignal): Promise<AdminDemoCredentials> { return this.request("/admin/demo-credentials", adminDemoCredentialsSchema, { signal }); }
  public getAdminSession(signal?: AbortSignal): Promise<AdminSession> { return this.request("/admin/session", adminSessionSchema, { signal }); }
  public loginAdmin(username: string, password: string, signal?: AbortSignal): Promise<AdminSession> { return this.request("/admin/session", adminSessionSchema, { method: "POST", body: { username, password }, signal }); }
  public async logoutAdmin(signal?: AbortSignal): Promise<{ readonly authenticated: false }> {
    const response = await fetch(`${this.base}/admin/session`, { method: "DELETE", credentials: "same-origin", ...(signal === undefined ? {} : { signal }) });
    if (response.status !== 204) throw new ApiClientError("Could not end the admin session.", response.status, "logout_failed");
    return { authenticated: false };
  }
  public setScenario(scenario: string, signal?: AbortSignal): Promise<{ readonly scenario: string }> { return this.request("/dev/scenario", z.object({ scenario: z.string() }), { method: "POST", body: { scenario }, signal }); }
  public getScenario(signal?: AbortSignal): Promise<{ readonly scenario: string }> { return this.request("/dev/scenario", z.object({ scenario: z.string(), activatedAt: rfc3339 }).strict(), { signal }); }
}

export const fjordPulseHttp = new HttpClient();
