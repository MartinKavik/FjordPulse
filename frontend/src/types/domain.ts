export const PROTOCOL_VERSION = 1 as const;

export type SourceState =
  | "loading"
  | "fresh"
  | "refreshing"
  | "empty"
  | "stale"
  | "unavailable"
  | "error"
  | "backoff"
  | "rate_limited";

export type VehicleStatus = "live" | "stale" | "lost";
export type VehicleTransportMode = "air" | "bus" | "coach" | "ferry" | "metro" | "taxi" | "tram" | "rail" | "unknown";
export type PassengerServiceState = "passenger" | "non_passenger" | "unknown";
export type DepartureStatus = "scheduled" | "realtime" | "delayed" | "cancelled" | "departed" | "unknown";
export type ServiceState = "ok" | "idle" | "connecting" | "connected" | "reconnecting" | "delayed" | "offline" | "degraded";
export type FocusState = "none" | "following" | "paused";
export type MobileSheetState = "none" | "peek" | "half" | "full";
export type BasemapId = "satellite" | "streets";
export type MapLoadState = "loading" | "ready" | "error";

export interface BasemapStyle {
  readonly id: BasemapId;
  readonly label: string;
  readonly styleUrl: string;
}

export interface MapConfig {
  readonly provider: "maptiler";
  readonly defaultBasemap: BasemapId;
  readonly basemaps: readonly BasemapStyle[];
}

export interface Coordinate {
  readonly latitude: number;
  readonly longitude: number;
}

export interface Station extends Coordinate {
  readonly id: string;
  readonly name: string;
  readonly kind: "stop_place" | "station" | "bus_station" | "ferry_terminal" | "rail_station" | "tram_stop" | "metro_station" | "airport" | "unknown";
  readonly locality: string | null;
  readonly municipality: string | null;
  readonly transportModes: readonly string[];
  readonly importedAt: string;
}

export interface BoundingBox {
  readonly minLongitude: number;
  readonly minLatitude: number;
  readonly maxLongitude: number;
  readonly maxLatitude: number;
}

export interface StationCluster extends Coordinate {
  readonly kind: "cluster";
  readonly id: string;
  readonly count: number;
  readonly bounds: BoundingBox;
}

export type MapItem =
  | ({ readonly kind: "station" } & Pick<Station, "id" | "name" | "latitude" | "longitude" | "transportModes">)
  | StationCluster;

export interface Departure {
  readonly id: string;
  readonly lineCode: string | null;
  readonly destination: string | null;
  readonly aimedDepartureAt: string;
  readonly expectedDepartureAt: string | null;
  readonly status: DepartureStatus;
  readonly delaySeconds: number | null;
  readonly platform: string | null;
}

export interface DepartureBoardPreview {
  readonly windowStart: string;
  readonly windowEnd: string;
  readonly limit: number;
  readonly hasMore: boolean;
}

export interface DeparturePage {
  readonly limit: number;
  readonly hasMore: boolean;
  readonly nextCursor: string | null;
}

export interface StationDepartureBoard {
  readonly stationId: string;
  readonly mode: "preview" | "day";
  readonly date: string;
  readonly timeZone: "Europe/Oslo";
  readonly windowStart: string;
  readonly windowEnd: string;
  readonly departures: readonly Departure[];
  readonly page: DeparturePage;
  readonly complete: boolean;
  readonly totalCount: number | null;
}

export interface NearbyVehicle {
  readonly id: string;
  readonly transportMode: VehicleTransportMode;
  readonly passengerServiceState: PassengerServiceState;
  readonly lineCode: string | null;
  readonly relation: string;
  readonly lastSeenAt: string;
  readonly delaySeconds: number | null;
  readonly state: VehicleStatus;
  readonly latitude: number | null;
  readonly longitude: number | null;
}

export type StationVehicleCallRole = "starts_here" | "calls_here";
export type StationVehicleProgress = "at_station" | "before_station" | "after_station" | "unknown";

export interface StationVehicle extends Omit<NearbyVehicle, "relation"> {
  readonly callRole: StationVehicleCallRole;
  readonly progress: StationVehicleProgress;
  readonly stationCallAt: string | null;
}

export interface ServingVehicleCoverage {
  readonly windowStart: string | null;
  readonly windowEnd: string | null;
  readonly candidateJourneyCount: number;
  readonly queriedJourneyCount: number;
  readonly truncated: boolean;
}

export interface VehicleObservation extends Coordinate {
  readonly observedAt: string;
}

export interface UpcomingStop {
  readonly id: string;
  readonly name: string;
  readonly expectedAt: string | null;
  readonly current?: boolean | undefined;
}

export interface StopCall {
  readonly stopPlaceId: string | null;
  readonly quayId: string | null;
  readonly name: string;
  readonly order: number;
  readonly latitude: number | null;
  readonly longitude: number | null;
  readonly aimedArrivalAt: string | null;
  readonly expectedArrivalAt: string | null;
  readonly aimedDepartureAt: string | null;
  readonly expectedDepartureAt: string | null;
  readonly realtime: boolean;
  readonly cancellation: boolean;
}

export interface VehicleJourneyReference {
  readonly serviceJourneyId: string;
  readonly operatingDate: string;
  readonly datedServiceJourneyId: string | null;
  readonly originRef: string | null;
  readonly originName: string | null;
  readonly destinationRef: string | null;
  readonly destinationName: string | null;
}

export interface MonitoredCallReference {
  readonly stopPointRef: string | null;
  readonly order: number;
  readonly vehicleAtStop: boolean;
}

export interface ProgressBetweenStops {
  readonly linkDistance: number | null;
  readonly percentage: number | null;
}

export interface JourneyRoute {
  readonly type: "LineString";
  readonly coordinates: readonly (readonly [number, number])[];
  readonly distanceMeters: number | null;
}

export interface JourneySnapshot {
  readonly serviceJourneyId: string;
  readonly operatingDate: string;
  readonly datedServiceJourneyId: string | null;
  readonly version: string;
  readonly state: SourceState;
  readonly route: JourneyRoute | null;
  readonly calls: readonly StopCall[];
  readonly refreshedAt: string;
  readonly lastSuccessfulAt: string | null;
  readonly warning: string | null;
}

export interface VehicleState {
  readonly id: string;
  readonly transportMode: VehicleTransportMode;
  readonly passengerServiceState: PassengerServiceState;
  readonly lineCode: string | null;
  readonly routeName: string | null;
  readonly state: VehicleStatus;
  readonly latitude: number | null;
  readonly longitude: number | null;
  readonly bearing: number | null;
  readonly delaySeconds: number | null;
  readonly lastSeenAt: string;
  readonly refreshedAt: string;
  readonly version: string;
  readonly nextStop: StopCall | null;
  readonly journeyReference: VehicleJourneyReference | null;
  readonly monitoredCall: MonitoredCallReference | null;
  readonly progressBetweenStops: ProgressBetweenStops | null;
  readonly journeyVersion: string | null;
  readonly routeProgress: number | null;
  readonly trail: readonly VehicleObservation[];
  readonly journey: JourneySnapshot | null;
  readonly upcomingStops: readonly UpcomingStop[];
}

export interface StationSnapshot {
  readonly station: Station;
  readonly stationId: string;
  readonly state: SourceState;
  readonly version: string;
  readonly updatedAt: string;
  readonly departures: readonly Departure[];
  readonly departureBoard: DepartureBoardPreview;
  readonly nearbyVehicles: readonly NearbyVehicle[];
  readonly servingVehicles: readonly StationVehicle[];
  readonly servingVehicleCoverage: ServingVehicleCoverage;
  readonly nearbyVehicleSearchRadiusMeters: number | null;
  readonly message?: string | undefined;
}

export type SearchResultType = "station" | "place" | "line" | "vehicle";

export interface SearchResult {
  readonly id: string;
  readonly type: SearchResultType;
  readonly label: string;
  readonly secondaryText: string | null;
  readonly stationId: string | null;
  readonly lineCode: string | null;
  readonly latitude: number | null;
  readonly longitude: number | null;
  readonly transportMode?: VehicleTransportMode | null | undefined;
}

export interface Telemetry {
  readonly backend: "checking" | "ok" | "degraded" | "offline";
  readonly realtime: "idle" | "connecting" | "connected" | "reconnecting" | "offline";
  readonly entur: "ok" | "idle" | "delayed" | "backoff" | "rate_limited" | "offline" | "not_used";
  readonly liveQueryBridge: "connected" | "reconnecting" | "degraded" | "offline";
  readonly refreshMode: "realtime" | "polling";
  readonly lastUpdateAt: string | null;
  readonly message?: string | undefined;
}

export type ServiceHealthStatus = "healthy" | "configured" | "degraded" | "reconnecting" | "unavailable" | "misconfigured" | "unknown";

export interface ServiceHealth {
  readonly status: ServiceHealthStatus;
  readonly checkedAt: string;
  readonly lastSuccessAt?: string | null | undefined;
  readonly message?: string | null | undefined;
  readonly latencyMs?: number | null | undefined;
}

export interface PublicHealth {
  readonly status: "healthy" | "degraded" | "unhealthy";
  readonly mode: "normal" | "fallback_polling";
  readonly dataMode: "real" | "fake";
  readonly checkedAt: string;
  readonly version: string;
  readonly fallbackAvailable: boolean;
  readonly dependencies: {
    readonly http: ServiceHealth;
    readonly realtime: ServiceHealth;
    readonly surrealdb: ServiceHealth;
    readonly entur: ServiceHealth;
    readonly liveQueryBridge: ServiceHealth;
    readonly mapTiles: ServiceHealth;
  };
}

export interface PublicScenario {
  readonly id: string;
  readonly mapItems: readonly MapItem[];
  readonly stationSnapshot: StationSnapshot | null;
  readonly vehicle: VehicleState | null;
  readonly focus: FocusState;
  readonly searchQuery: string;
  readonly searchResults: readonly SearchResult[];
  readonly searchOpen: boolean;
  readonly telemetry: Telemetry;
  readonly mobileSheet: MobileSheetState;
}

export interface HealthDependency {
  readonly name: string;
  readonly state: ServiceState;
  readonly detail: string;
  readonly latencyMs?: number | undefined;
}

export interface AdminMetric {
  readonly label: string;
  readonly value: string;
  readonly detail: string;
  readonly tone: "positive" | "info" | "warning" | "danger";
}

export interface AdminEvent {
  readonly id: string;
  readonly type: string;
  readonly scope: string;
  readonly entityId: string;
  readonly version: string;
  readonly source: "station_snapshot" | "current_vehicle";
  readonly payload: Readonly<Record<string, unknown>>;
  readonly createdAt: string;
  readonly status: "ok" | "warning" | "error";
}

export interface AdminBuild {
  readonly version: string;
  readonly environment: "local" | "development" | "test" | "staging" | "production";
  readonly dataMode: "fake" | "real";
}

export type AdminDemoCredentials =
  | { readonly enabled: false }
  | { readonly enabled: true; readonly username: string; readonly password: string };

export interface AdminDataCounts {
  readonly stations: number;
  readonly stationSnapshots: number;
  readonly currentVehicles: number;
  readonly vehicleObservations: number;
  readonly watches: number;
  readonly realtimeEvents: number;
  readonly enturRequestLogs: number;
}

export interface AdminStationImport {
  readonly count: number;
  readonly lastImportedAt: string | null;
  readonly sourceVersion: string | null;
}

export interface AdminEnturBudget {
  readonly service: "global" | "stop_place_register" | "geocoder" | "journey_planner" | "vehicle_positions";
  readonly limit: number;
  readonly remaining: number;
  readonly windowSeconds: number;
  readonly backoffUntil: string | null;
}

export interface AdminDatabaseTarget {
  readonly engine: "surrealdb";
  readonly endpointOrigin: string;
  readonly namespace: string;
  readonly name: string;
  readonly warning: string | null;
}

export interface AdminResourceSnapshot {
  readonly checkedAt: string;
  readonly cpu: {
    readonly usagePercent: number | null;
    readonly load1: number | null;
    readonly load5: number | null;
    readonly load15: number | null;
    readonly logicalCores: number | null;
  };
  readonly memory: {
    readonly totalBytes: number | null;
    readonly availableBytes: number | null;
    readonly usedBytes: number | null;
    readonly usedPercent: number | null;
    readonly scope: "host" | "cgroup";
  };
  readonly disk: {
    readonly path: string;
    readonly totalBytes: number | null;
    readonly freeBytes: number | null;
    readonly usedBytes: number | null;
    readonly usedPercent: number | null;
  };
}

export interface AdminStatus {
  readonly build: AdminBuild;
  readonly database: AdminDatabaseTarget;
  readonly resources: AdminResourceSnapshot;
  readonly dataCounts: AdminDataCounts;
  readonly stationImport: AdminStationImport;
  readonly dependencies: readonly HealthDependency[];
  readonly metrics: readonly AdminMetric[];
  readonly enturBudgets: readonly AdminEnturBudget[];
  readonly events: readonly AdminEvent[];
}

export interface WatchRow {
  readonly id: string;
  readonly type: "station" | "vehicle" | "focus";
  readonly scope: string;
  readonly clients: number;
  readonly priority: "normal" | "high" | "critical";
  readonly lastRefreshAt: string | null;
  readonly nextRefreshAt: string | null;
  readonly state: "active" | "stale" | "expiring" | "failed";
}

export interface EnturLogRow {
  readonly id: string;
  readonly createdAt: string;
  readonly api: "Stop Place Register" | "Geocoder" | "Journey Planner" | "Vehicle Positions";
  readonly scope: string;
  readonly status: "ok" | "error" | "backoff" | "rate_limited";
  readonly latencyMs: number | null;
  readonly requestCount: number | null;
  readonly cache: "hit" | "miss" | "stale";
  readonly retryAt: string | null;
}

export interface EnturLogMetrics {
  readonly requestsPerMinute: number;
  readonly cacheHitRate: number;
  readonly p95LatencyMs: number | null;
  readonly inBackoff: boolean;
}

export interface AdminEnturLog {
  readonly metrics: EnturLogMetrics;
  readonly entries: readonly EnturLogRow[];
}

export interface AdminSession {
  readonly authenticated: true;
  readonly username: string;
  readonly access: "operator" | "demo";
  readonly expiresAt: string;
}

export interface AdminRealtime {
  readonly server: HealthDependency;
  readonly liveQueryBridge: HealthDependency;
  readonly activeClients: number;
  readonly rooms: readonly { readonly scope: string; readonly clientCount: number }[];
  readonly messagesPerMinute: number;
  readonly reconnectCount: number;
  readonly failureCount: number;
  readonly lastBroadcastAt: string | null;
}

export interface RealtimeEventRow {
  readonly eventId: string;
  readonly type: "station_snapshot_changed" | "vehicle_moved" | "vehicle_stale" | "vehicle_lost";
  readonly scope: string;
  readonly entityId: string;
  readonly version: string;
  readonly source: "station_snapshot" | "current_vehicle";
  readonly payload: Readonly<Record<string, unknown>>;
  readonly createdAt: string;
}

export type DatabasePermissionMode = "full" | "none" | "conditional";

export interface DatabaseTablePermissions {
  readonly select: DatabasePermissionMode;
  readonly create: DatabasePermissionMode;
  readonly update: DatabasePermissionMode;
  readonly delete: DatabasePermissionMode;
}

export interface DatabaseFieldPermissions {
  readonly select: DatabasePermissionMode;
  readonly create: DatabasePermissionMode;
  readonly update: DatabasePermissionMode;
}

export interface DatabaseSchemaField {
  readonly name: string;
  readonly type: string;
  readonly readonly: boolean;
  readonly assertion: string | null;
  readonly defaultValue: string | null;
  readonly permissions: DatabaseFieldPermissions;
}

export interface DatabaseSchemaIndex {
  readonly name: string;
  readonly fields: readonly string[];
  readonly unique: boolean;
  readonly mode: string | null;
}

export interface DatabaseSchemaEvent {
  readonly name: string;
  readonly condition: string | null;
  readonly actions: readonly string[];
}

export interface DatabaseSchemaTable {
  readonly name: string;
  readonly kind: "normal" | "relation" | "any";
  readonly schemaMode: "schemafull" | "schemaless";
  readonly permissions: DatabaseTablePermissions;
  readonly fields: readonly DatabaseSchemaField[];
  readonly indexes: readonly DatabaseSchemaIndex[];
  readonly events: readonly DatabaseSchemaEvent[];
}

export interface AdminDatabaseSchema {
  readonly readOnly: true;
  readonly checkedAt: string;
  readonly tables: readonly DatabaseSchemaTable[];
}

export type DatabaseMigrationState = "applied" | "pending" | "checksum_mismatch" | "orphaned" | "failed";

export interface DatabaseMigrationAffectedObject {
  readonly kind: "table" | "field" | "index" | "event";
  readonly name: string;
  readonly table: string | null;
  readonly operation: "define" | "remove";
}

export interface DatabaseMigration {
  readonly name: string;
  readonly description: string;
  readonly state: DatabaseMigrationState;
  readonly releaseChecksum: string | null;
  readonly databaseChecksum: string | null;
  readonly appliedAt: string | null;
  readonly lastAttemptedAt: string | null;
  readonly failureMessage: string | null;
  readonly source: string | null;
  readonly affectedObjects: readonly DatabaseMigrationAffectedObject[];
}

export interface AdminDatabaseMigrations {
  readonly readOnly: true;
  readonly checkedAt: string;
  readonly state: "in_sync" | "pending" | "drift" | "failed";
  readonly counts: {
    readonly applied: number;
    readonly pending: number;
    readonly checksumMismatch: number;
    readonly orphaned: number;
    readonly failed: number;
  };
  readonly lastAppliedAt: string | null;
  readonly migrations: readonly DatabaseMigration[];
}

export interface ApiMeta {
  readonly requestId: string;
  readonly updatedAt: string;
}

export interface ApiErrorMeta {
  readonly requestId: string;
  readonly retryAfterSeconds?: number | null | undefined;
}

export type ApiEnvelope<T> =
  | { readonly ok: true; readonly data: T; readonly meta: ApiMeta }
  | {
      readonly ok: false;
      readonly error: { readonly code: string; readonly message: string; readonly details: Readonly<Record<string, unknown>> };
      readonly meta: ApiErrorMeta;
    };

export type ClientMessageType =
  | "watch_station"
  | "unwatch_station"
  | "watch_vehicle"
  | "unwatch_vehicle"
  | "focus_vehicle"
  | "unfocus_vehicle"
  | "pause_focus"
  | "resume_focus"
  | "ping";

export interface ClientMessage {
  readonly protocolVersion: typeof PROTOCOL_VERSION;
  readonly id: string;
  readonly type: ClientMessageType;
  readonly payload: Readonly<Record<string, string>>;
}

export type ServerMessageType =
  | "watch_station_ack"
  | "unwatch_station_ack"
  | "watch_vehicle_ack"
  | "unwatch_vehicle_ack"
  | "focus_started"
  | "focus_stopped"
  | "focus_paused"
  | "focus_resumed"
  | "station_snapshot"
  | "station_snapshot_changed"
  | "station_departures_changed"
  | "nearby_vehicles_changed"
  | "vehicle_snapshot"
  | "vehicle_moved"
  | "vehicle_stale"
  | "vehicle_lost"
  | "source_backoff"
  | "rate_limited"
  | "telemetry_tick"
  | "realtime_degraded"
  | "resync_required"
  | "pong"
  | "error";

export interface ServerMessage {
  readonly protocolVersion: typeof PROTOCOL_VERSION;
  readonly id?: string | undefined;
  readonly type: ServerMessageType;
  readonly scope?: string | undefined;
  readonly entityId?: string | undefined;
  readonly eventId?: string | undefined;
  readonly version?: string | undefined;
  readonly createdAt: string;
  readonly payload?: unknown;
  readonly error?: {
    readonly code: string;
    readonly message: string;
    readonly details: Readonly<Record<string, unknown>>;
  } | undefined;
}
