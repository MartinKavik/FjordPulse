import type {
  AdminDatabaseMigrations,
  AdminDatabaseSchema,
  AdminStatus,
  Departure,
  EnturLogRow,
  FocusState,
  MapItem,
  SearchResult,
  Station,
  StationSnapshot,
  Telemetry,
  VehicleState,
  WatchRow,
} from "../types/domain";

export const VISUAL_SCENARIO_IDS = [
  "desktop_default_map",
  "desktop_station_fresh",
  "desktop_station_loading",
  "desktop_station_empty",
  "desktop_station_stale",
  "desktop_station_error",
  "desktop_vehicle_selected",
  "desktop_vehicle_non_passenger",
  "desktop_vehicle_focus_following",
  "desktop_vehicle_focus_paused",
  "desktop_vehicle_stale",
  "desktop_vehicle_lost",
  "desktop_degraded_fallback",
  "desktop_search_results",
  "desktop_search_empty",
  "mobile_default_map",
  "mobile_station_sheet",
  "mobile_station_full_sheet",
  "mobile_vehicle_focus",
  "mobile_vehicle_non_passenger",
  "mobile_vehicle_lost",
  "admin_status",
  "admin_infrastructure",
  "admin_watches",
  "admin_entur_log",
  "admin_database",
  "design_system_components",
] as const;

export type VisualScenarioId = (typeof VISUAL_SCENARIO_IDS)[number];
export type PublicScenarioId = Exclude<VisualScenarioId, `admin_${string}` | "design_system_components">;

export interface PublicScenario {
  readonly id: PublicScenarioId;
  readonly mapItems: readonly MapItem[];
  readonly stationSnapshot: StationSnapshot | null;
  readonly vehicle: VehicleState | null;
  readonly focus: FocusState;
  readonly searchQuery: string;
  readonly searchResults: readonly SearchResult[];
  readonly searchOpen: boolean;
  readonly telemetry: Telemetry;
  readonly mobileSheet: "none" | "half" | "full";
}

const NOW = "2026-07-10T18:42:30Z";
export const FIXTURE_NOW_MS = Date.parse(NOW);
const AGO_8S = "2026-07-10T18:42:22Z";
const AGO_2M = "2026-07-10T18:40:30Z";

export const fordeStation: Station = {
  id: "NSR:StopPlace:36025",
  name: "Førde rutebilstasjon",
  latitude: 61.4522,
  longitude: 5.8572,
  kind: "bus_station",
  locality: "Førde",
  municipality: "Sunnfjord",
  transportModes: ["bus"],
  importedAt: "2026-07-10T06:00:00Z",
};

const mapItems: readonly MapItem[] = [
  { kind: "cluster", id: "cluster-tromso", count: 28, latitude: 69.6492, longitude: 18.9553, bounds: { minLongitude: 17, minLatitude: 68, maxLongitude: 21, maxLatitude: 71 } },
  { kind: "cluster", id: "cluster-trondheim", count: 36, latitude: 63.4305, longitude: 10.3951, bounds: { minLongitude: 9, minLatitude: 62, maxLongitude: 12, maxLatitude: 65 } },
  { kind: "cluster", id: "cluster-forde", count: 18, latitude: 61.4522, longitude: 5.8572, bounds: { minLongitude: 5, minLatitude: 60, maxLongitude: 7, maxLatitude: 63 } },
  { kind: "cluster", id: "cluster-bergen", count: 42, latitude: 60.3913, longitude: 5.3221, bounds: { minLongitude: 4, minLatitude: 59, maxLongitude: 7, maxLatitude: 62 } },
  { kind: "cluster", id: "cluster-oslo", count: 55, latitude: 59.9139, longitude: 10.7522, bounds: { minLongitude: 9, minLatitude: 58, maxLongitude: 12, maxLatitude: 61 } },
  { kind: "cluster", id: "cluster-stavanger", count: 31, latitude: 58.97, longitude: 5.7331, bounds: { minLongitude: 4, minLatitude: 58, maxLongitude: 7, maxLatitude: 60 } },
  { kind: "station", id: fordeStation.id, name: fordeStation.name, latitude: fordeStation.latitude, longitude: fordeStation.longitude, transportModes: fordeStation.transportModes },
  {
    kind: "station",
    id: "NSR:StopPlace:58370", name: "Skei", latitude: 61.572, longitude: 6.481, transportModes: ["bus"],
  },
  {
    kind: "station",
    id: "NSR:StopPlace:58372", name: "Sandane rutebilstasjon", latitude: 61.7726, longitude: 6.2149, transportModes: ["bus"],
  },
];

const departures: readonly Departure[] = [
  {
    id: "dep-100-sandane",
    lineCode: "100",
    destination: "Sandane",
    aimedDepartureAt: "2026-07-10T18:43:00Z",
    expectedDepartureAt: "2026-07-10T18:45:00Z",
    status: "delayed",
    delaySeconds: 120,
    platform: "1",
  },
  {
    id: "dep-110-skei",
    lineCode: "110",
    destination: "Skei",
    aimedDepartureAt: "2026-07-10T18:50:00Z",
    expectedDepartureAt: "2026-07-10T18:50:00Z",
    status: "realtime",
    delaySeconds: 0,
    platform: "2",
  },
  {
    id: "dep-nw400-bergen",
    lineCode: "NW400",
    destination: "Bergen",
    aimedDepartureAt: "2026-07-10T19:05:00Z",
    expectedDepartureAt: "2026-07-10T19:08:00Z",
    status: "delayed",
    delaySeconds: 180,
    platform: "3",
  },
  {
    id: "dep-100-nordfjordeid",
    lineCode: "100",
    destination: "Nordfjordeid",
    aimedDepartureAt: "2026-07-10T19:20:00Z",
    expectedDepartureAt: null,
    status: "scheduled",
    delaySeconds: null,
    platform: null,
  },
];

export const line100Vehicle: VehicleState = {
  id: "SKY:Vehicle:100-2142",
  transportMode: "bus",
  passengerServiceState: "passenger",
  lineCode: "100",
  routeName: "Sandane → Nordfjordeid",
  state: "live",
  latitude: 61.636,
  longitude: 6.216,
  bearing: 32,
  delaySeconds: 120,
  lastSeenAt: "2026-07-10T18:42:24Z",
  refreshedAt: "2026-07-10T18:42:24Z",
  version: "2026-07-10T18:42:24.000Z",
  nextStop: { stopPlaceId: "NSR:StopPlace:58370", quayId: "NSR:Quay:58370", name: "Skei", order: 1, latitude: 61.572, longitude: 6.481, aimedArrivalAt: "2026-07-10T19:20:00Z", expectedArrivalAt: "2026-07-10T19:22:00Z", aimedDepartureAt: "2026-07-10T19:21:00Z", expectedDepartureAt: "2026-07-10T19:23:00Z", realtime: true, cancellation: false },
  journeyReference: { serviceJourneyId: "SKY:ServiceJourney:100", operatingDate: "2026-07-10", datedServiceJourneyId: null, originRef: "NSR:StopPlace:36025", originName: "Førde rutebilstasjon", destinationRef: "NSR:StopPlace:35453", destinationName: "Nordfjordeid" },
  monitoredCall: { stopPointRef: "NSR:Quay:58370", order: 1, vehicleAtStop: false },
  progressBetweenStops: { linkDistance: 12_000, percentage: 0.58 },
  journeyVersion: "2026-07-10T18:42:24.000Z",
  routeProgress: 0.42,
  trail: [
    { latitude: 61.574, longitude: 6.104, observedAt: "2026-07-10T18:39:30Z" },
    { latitude: 61.596, longitude: 6.142, observedAt: "2026-07-10T18:40:30Z" },
    { latitude: 61.618, longitude: 6.183, observedAt: "2026-07-10T18:41:30Z" },
    { latitude: 61.636, longitude: 6.216, observedAt: "2026-07-10T18:42:24Z" },
  ],
  journey: {
    serviceJourneyId: "SKY:ServiceJourney:100",
    operatingDate: "2026-07-10",
    datedServiceJourneyId: null,
    version: "2026-07-10T18:42:24.000Z",
    state: "fresh",
    route: { type: "LineString", coordinates: [[5.8572, 61.4522], [6.104, 61.574], [6.216, 61.636], [6.481, 61.572], [6.2149, 61.7726], [6.073, 61.906]], distanceMeters: 86_000 },
    calls: [
      { stopPlaceId: "NSR:StopPlace:36025", quayId: "NSR:Quay:36025", name: "Førde rutebilstasjon", order: 0, latitude: 61.4522, longitude: 5.8572, aimedArrivalAt: null, expectedArrivalAt: null, aimedDepartureAt: "2026-07-10T18:20:00Z", expectedDepartureAt: "2026-07-10T18:22:00Z", realtime: true, cancellation: false },
      { stopPlaceId: "NSR:StopPlace:58370", quayId: "NSR:Quay:58370", name: "Skei", order: 1, latitude: 61.572, longitude: 6.481, aimedArrivalAt: "2026-07-10T19:20:00Z", expectedArrivalAt: "2026-07-10T19:22:00Z", aimedDepartureAt: "2026-07-10T19:21:00Z", expectedDepartureAt: "2026-07-10T19:23:00Z", realtime: true, cancellation: false },
      { stopPlaceId: "NSR:StopPlace:58372", quayId: "NSR:Quay:58372", name: "Sandane rutebilstasjon", order: 2, latitude: 61.7726, longitude: 6.2149, aimedArrivalAt: "2026-07-10T19:50:00Z", expectedArrivalAt: "2026-07-10T19:52:00Z", aimedDepartureAt: "2026-07-10T19:52:00Z", expectedDepartureAt: "2026-07-10T19:54:00Z", realtime: true, cancellation: false },
      { stopPlaceId: "NSR:StopPlace:35453", quayId: "NSR:Quay:35453", name: "Nordfjordeid", order: 3, latitude: 61.906, longitude: 6.073, aimedArrivalAt: "2026-07-10T20:10:00Z", expectedArrivalAt: "2026-07-10T20:12:00Z", aimedDepartureAt: null, expectedDepartureAt: null, realtime: true, cancellation: false },
    ],
    refreshedAt: "2026-07-10T18:42:24Z",
    lastSuccessfulAt: "2026-07-10T18:42:24Z",
    warning: null,
  },
  upcomingStops: [
    { id: "stop-skei", name: "Skei", expectedAt: "2026-07-10T19:20:00Z", current: true },
    { id: "stop-reed", name: "Reed", expectedAt: "2026-07-10T19:34:00Z" },
    { id: "stop-sandane", name: "Sandane rutebilstasjon", expectedAt: "2026-07-10T19:52:00Z" },
    { id: "stop-nordfjordeid", name: "Nordfjordeid", expectedAt: "2026-07-10T20:10:00Z" },
  ],
};

export const nonPassengerVehicle: VehicleState = {
  ...line100Vehicle,
  id: "3350447622",
  passengerServiceState: "non_passenger",
  lineCode: "4",
  routeName: "Flaktveit - Hesjaholtet",
  delaySeconds: 18 * 60,
  nextStop: null,
  journeyReference: {
    serviceJourneyId: "21255797_200969",
    operatingDate: "2026-07-11",
    datedServiceJourneyId: null,
    originRef: null,
    originName: "Flaktveit",
    destinationRef: "GAR4.402",
    destinationName: "skyss.no",
  },
  monitoredCall: { stopPointRef: "GAR4.402", order: 0, vehicleAtStop: false },
  progressBetweenStops: null,
  journeyVersion: null,
  routeProgress: null,
  journey: null,
  upcomingStops: [],
};

const nearbyVehicles = [
  {
    id: "SKY:Vehicle:590-903",
    transportMode: "bus",
    passengerServiceState: "passenger",
    lineCode: "FB59",
    relation: "within the station search area",
    lastSeenAt: "2026-07-10T18:42:12Z",
    delaySeconds: 0,
    state: "live" as const,
    latitude: 61.4522,
    longitude: 5.8572,
  },
  {
    id: "SKY:Vehicle:110-872",
    transportMode: "bus",
    passengerServiceState: "passenger",
    lineCode: "110",
    relation: "near Hafstad",
    lastSeenAt: "2026-07-10T18:42:08Z",
    delaySeconds: 0,
    state: "live" as const,
    latitude: 61.438,
    longitude: 5.89,
  },
] as const;

const servingVehicles = [
  {
    id: line100Vehicle.id,
    transportMode: "bus",
    passengerServiceState: "passenger",
    lineCode: "100",
    callRole: "starts_here" as const,
    progress: "after_station" as const,
    stationCallAt: "2026-07-10T18:22:00Z",
    lastSeenAt: "2026-07-10T18:42:18Z",
    delaySeconds: 120,
    state: "live" as const,
    latitude: 61.636,
    longitude: 6.216,
  },
  {
    id: "SKY:Vehicle:590-903",
    transportMode: "bus",
    passengerServiceState: "passenger",
    lineCode: "FB59",
    callRole: "starts_here" as const,
    progress: "before_station" as const,
    stationCallAt: "2026-07-10T19:25:00Z",
    lastSeenAt: "2026-07-10T18:42:12Z",
    delaySeconds: 0,
    state: "live" as const,
    latitude: 61.4522,
    longitude: 5.8572,
  },
  {
    id: "SKY:Vehicle:90-901",
    transportMode: "bus",
    passengerServiceState: "passenger",
    lineCode: "90",
    callRole: "calls_here" as const,
    progress: "before_station" as const,
    stationCallAt: "2026-07-10T19:35:00Z",
    lastSeenAt: "2026-07-10T18:42:16Z",
    delaySeconds: 60,
    state: "live" as const,
    latitude: 61.325,
    longitude: 5.42,
  },
] as const;

export const freshStationSnapshot: StationSnapshot = {
  station: fordeStation,
  stationId: fordeStation.id,
  state: "fresh",
  version: AGO_8S,
  updatedAt: AGO_8S,
  departures,
  departureBoard: {
    windowStart: "2026-07-10T18:42:30Z",
    windowEnd: "2026-07-10T22:00:00Z",
    limit: 20,
    hasMore: true,
  },
  nearbyVehicles,
  servingVehicles,
  servingVehicleCoverage: {
    windowStart: "2026-07-10T12:42:24Z",
    windowEnd: "2026-07-11T00:42:24Z",
    candidateJourneyCount: 18,
    queriedJourneyCount: 18,
    truncated: false,
  },
  nearbyVehicleSearchRadiusMeters: 5_000,
};

const liveTelemetry: Telemetry = {
  backend: "ok",
  realtime: "connected",
  entur: "not_used",
  liveQueryBridge: "connected",
  refreshMode: "realtime",
  lastUpdateAt: AGO_8S,
};

const idleTelemetry: Telemetry = {
  backend: "ok",
  realtime: "idle",
  entur: "not_used",
  liveQueryBridge: "connected",
  refreshMode: "realtime",
  lastUpdateAt: NOW,
  message: "Station clusters loaded",
};

const searchResults: readonly SearchResult[] = [
  { id: fordeStation.id, type: "station", label: "Førde rutebilstasjon", secondaryText: "Station · Vestland", stationId: fordeStation.id, lineCode: null, latitude: 61.4522, longitude: 5.8572 },
  { id: "NSR:StopPlace:58362", type: "station", label: "Førde ferjekai", secondaryText: "Ferry terminal · Vestland", stationId: "NSR:StopPlace:58362", lineCode: null, latitude: 61.448, longitude: 5.844 },
  { id: "place-forde", type: "place", label: "Førde sentrum", secondaryText: "Place · Sunnfjord", stationId: fordeStation.id, lineCode: null, latitude: 61.452, longitude: 5.86 },
  { id: "line-100", type: "line", label: "Line 100", secondaryText: "Førde · Sandane · Nordfjordeid", stationId: null, lineCode: "100", latitude: null, longitude: null },
];

function publicScenario(
  id: PublicScenarioId,
  overrides: Partial<Omit<PublicScenario, "id">> = {},
): PublicScenario {
  return {
    id,
    mapItems,
    stationSnapshot: null,
    vehicle: null,
    focus: "none",
    searchQuery: "",
    searchResults: [],
    searchOpen: false,
    telemetry: idleTelemetry,
    mobileSheet: "none",
    ...overrides,
  };
}

const loadingSnapshot: StationSnapshot = {
  ...freshStationSnapshot,
  state: "loading",
  departures: [],
  nearbyVehicles: [],
  servingVehicles: [],
  message: "Registering live watch…",
};

const staleSnapshot: StationSnapshot = {
  ...freshStationSnapshot,
  state: "stale",
  version: AGO_2M,
  updatedAt: AGO_2M,
  message: "Live updates are delayed. Showing the last known departures.",
};

const staleVehicle: VehicleState = {
  ...line100Vehicle,
  state: "stale",
  lastSeenAt: AGO_2M,
  version: AGO_2M,
};

const lostVehicle: VehicleState = {
  ...line100Vehicle,
  state: "lost",
  lastSeenAt: AGO_2M,
  version: AGO_2M,
};

const PUBLIC_SCENARIOS: Record<PublicScenarioId, PublicScenario> = {
  desktop_default_map: publicScenario("desktop_default_map"),
  desktop_station_fresh: publicScenario("desktop_station_fresh", { stationSnapshot: freshStationSnapshot, telemetry: liveTelemetry }),
  desktop_station_loading: publicScenario("desktop_station_loading", {
    stationSnapshot: loadingSnapshot,
    telemetry: { ...liveTelemetry, realtime: "connecting", entur: "delayed", message: "Registering station watch" },
  }),
  desktop_station_empty: publicScenario("desktop_station_empty", {
    stationSnapshot: {
      ...freshStationSnapshot,
      state: "empty",
      departures: [],
      departureBoard: { ...freshStationSnapshot.departureBoard, hasMore: false },
      nearbyVehicles: [],
      servingVehicles: [],
    },
    telemetry: liveTelemetry,
  }),
  desktop_station_stale: publicScenario("desktop_station_stale", {
    stationSnapshot: staleSnapshot,
    telemetry: { ...liveTelemetry, realtime: "reconnecting", liveQueryBridge: "reconnecting", entur: "delayed", lastUpdateAt: AGO_2M },
  }),
  desktop_station_error: publicScenario("desktop_station_error", {
    stationSnapshot: {
      ...freshStationSnapshot,
      state: "error",
      departures: [],
      nearbyVehicles: [],
      servingVehicles: [],
      message: "Could not load station details.",
    },
    telemetry: { ...liveTelemetry, entur: "delayed" },
  }),
  desktop_vehicle_selected: publicScenario("desktop_vehicle_selected", { stationSnapshot: freshStationSnapshot, vehicle: line100Vehicle, telemetry: liveTelemetry }),
  desktop_vehicle_non_passenger: publicScenario("desktop_vehicle_non_passenger", { vehicle: nonPassengerVehicle, focus: "following", telemetry: liveTelemetry }),
  desktop_vehicle_focus_following: publicScenario("desktop_vehicle_focus_following", { vehicle: line100Vehicle, focus: "following", telemetry: liveTelemetry }),
  desktop_vehicle_focus_paused: publicScenario("desktop_vehicle_focus_paused", { vehicle: line100Vehicle, focus: "paused", telemetry: liveTelemetry }),
  desktop_vehicle_stale: publicScenario("desktop_vehicle_stale", {
    vehicle: staleVehicle,
    focus: "paused",
    telemetry: { ...liveTelemetry, entur: "delayed", lastUpdateAt: AGO_2M },
  }),
  desktop_vehicle_lost: publicScenario("desktop_vehicle_lost", {
    vehicle: lostVehicle,
    focus: "none",
    telemetry: { ...liveTelemetry, realtime: "connected", entur: "delayed", lastUpdateAt: AGO_2M },
  }),
  desktop_degraded_fallback: publicScenario("desktop_degraded_fallback", {
    stationSnapshot: staleSnapshot,
    telemetry: {
      backend: "ok",
      realtime: "offline",
      entur: "ok",
      liveQueryBridge: "offline",
      refreshMode: "polling",
      lastUpdateAt: AGO_2M,
      message: "Live updates unavailable. Refreshing periodically.",
    },
  }),
  desktop_search_results: publicScenario("desktop_search_results", {
    searchOpen: true,
    searchQuery: "førde",
    searchResults,
  }),
  desktop_search_empty: publicScenario("desktop_search_empty", {
    searchOpen: true,
    searchQuery: "xyzabc",
    searchResults: [],
  }),
  mobile_default_map: publicScenario("mobile_default_map"),
  mobile_station_sheet: publicScenario("mobile_station_sheet", { stationSnapshot: freshStationSnapshot, telemetry: liveTelemetry, mobileSheet: "half" }),
  mobile_station_full_sheet: publicScenario("mobile_station_full_sheet", { stationSnapshot: freshStationSnapshot, telemetry: liveTelemetry, mobileSheet: "full" }),
  mobile_vehicle_focus: publicScenario("mobile_vehicle_focus", { vehicle: line100Vehicle, focus: "following", telemetry: liveTelemetry, mobileSheet: "half" }),
  mobile_vehicle_non_passenger: publicScenario("mobile_vehicle_non_passenger", { vehicle: nonPassengerVehicle, focus: "following", telemetry: liveTelemetry, mobileSheet: "half" }),
  mobile_vehicle_lost: publicScenario("mobile_vehicle_lost", { vehicle: lostVehicle, focus: "none", telemetry: { ...liveTelemetry, entur: "delayed" }, mobileSheet: "half" }),
};

export function getPublicScenario(id: PublicScenarioId): PublicScenario {
  return PUBLIC_SCENARIOS[id];
}

export function isVisualScenarioId(value: string): value is VisualScenarioId {
  return (VISUAL_SCENARIO_IDS as readonly string[]).includes(value);
}

export function isPublicScenarioId(value: string): value is PublicScenarioId {
  return isVisualScenarioId(value) && !value.startsWith("admin_") && value !== "design_system_components";
}

export const adminStatusFixture: AdminStatus = {
  build: { version: "1.0.0-dev", environment: "development", dataMode: "fake" },
  database: { engine: "surrealdb", endpointOrigin: "ws://surrealdb:8000", namespace: "fjordpulse", name: "fjordpulse_demo", warning: null },
  resources: {
    checkedAt: "2026-07-10T18:42:30Z",
    cpu: { usagePercent: 28.4, load1: 1.18, load5: 0.94, load15: 0.81, logicalCores: 8 },
    memory: { totalBytes: 17_179_869_184, availableBytes: 10_737_418_240, usedBytes: 6_442_450_944, usedPercent: 37.5, scope: "cgroup" },
    disk: { path: "/", totalBytes: 536_870_912_000, freeBytes: 354_334_801_920, usedBytes: 182_536_110_080, usedPercent: 34 },
  },
  dataCounts: { stations: 12_000, stationSnapshots: 24, currentVehicles: 7, vehicleObservations: 84, watches: 4, realtimeEvents: 128, enturRequestLogs: 42 },
  stationImport: { count: 12_000, lastImportedAt: "2026-07-10T08:00:00Z", sourceVersion: "fixture-2026-07-10" },
  enturBudgets: [
    { service: "global", limit: 30, remaining: 26, windowSeconds: 60, backoffUntil: null },
    { service: "stop_place_register", limit: 10, remaining: 10, windowSeconds: 60, backoffUntil: null },
    { service: "geocoder", limit: 10, remaining: 9, windowSeconds: 60, backoffUntil: null },
    { service: "journey_planner", limit: 15, remaining: 13, windowSeconds: 60, backoffUntil: null },
    { service: "vehicle_positions", limit: 15, remaining: 14, windowSeconds: 60, backoffUntil: null },
  ],
  dependencies: [
    { name: "Backend", state: "ok", detail: "All HTTP services healthy", latencyMs: 18 },
    { name: "Realtime server", state: "ok", detail: "Realtime service and live-query bridge are healthy.", latencyMs: 31 },
    { name: "SurrealDB", state: "ok", detail: "Authoritative state database is reachable; the fake station catalog contains 12000 records.", latencyMs: 12 },
    { name: "Entur API", state: "idle", detail: "No Entur request recorded in five minutes. Availability will be checked on the next demand-driven request." },
    { name: "Live-query bridge", state: "ok", detail: "SurrealDB live-query bridge is subscribed and receiving database events.", latencyMs: 9 },
    { name: "Map tiles", state: "ok", detail: "MapTiler browser configuration is present; provider availability is verified by the browser at load time, not by this endpoint." },
  ],
  metrics: [
    { label: "Active WebSocket clients", value: "18", detail: "42/min messages · connections, not unique visitors", tone: "info" },
    { label: "Active station watches", value: "24", detail: "Shared monitored scopes", tone: "info" },
    { label: "Active vehicle watches", value: "7", detail: "Shared selected-vehicle scopes", tone: "info" },
    { label: "Active Focus sessions", value: "2", detail: "One high-priority watch per focused browser session", tone: "info" },
  ],
  events: [
    { id: "event-1", type: "station_watch_started", scope: "station:NSR:StopPlace:36025", entityId: "NSR:StopPlace:36025", version: "2026-07-10T18:42:27Z", source: "station_snapshot", payload: { state: "fresh" }, createdAt: "2026-07-10T18:42:27Z", status: "ok" },
    { id: "event-2", type: "vehicle_moved", scope: "vehicle:SKY:Vehicle:100-2142", entityId: "SKY:Vehicle:100-2142", version: "2026-07-10T18:41:58Z", source: "current_vehicle", payload: { state: "live" }, createdAt: "2026-07-10T18:41:58Z", status: "ok" },
    { id: "event-3", type: "station_snapshot_changed", scope: "station:NSR:StopPlace:36025", entityId: "NSR:StopPlace:36025", version: "2026-07-10T18:41:49Z", source: "station_snapshot", payload: { state: "fresh" }, createdAt: "2026-07-10T18:41:49Z", status: "ok" },
    { id: "event-4", type: "entur_request_ok", scope: "journey-planner:Førde", entityId: "NSR:StopPlace:36025", version: "2026-07-10T18:41:41Z", source: "station_snapshot", payload: { state: "fresh" }, createdAt: "2026-07-10T18:41:41Z", status: "ok" },
    { id: "event-5", type: "focus_started", scope: "vehicle:SKY:Vehicle:100-2142", entityId: "SKY:Vehicle:100-2142", version: "2026-07-10T18:41:35Z", source: "current_vehicle", payload: { state: "live" }, createdAt: "2026-07-10T18:41:35Z", status: "ok" },
  ],
};

const noTablePermissions = { select: "none", create: "none", update: "none", delete: "none" } as const;
const noFieldPermissions = { select: "none", create: "none", update: "none" } as const;

export const adminDatabaseSchemaFixture: AdminDatabaseSchema = {
  readOnly: true,
  checkedAt: NOW,
  tables: [
    {
      name: "current_vehicle",
      kind: "normal",
      schemaMode: "schemafull",
      permissions: noTablePermissions,
      fields: [
        { name: "vehicle_id", type: "string", readonly: false, assertion: null, defaultValue: null, permissions: noFieldPermissions },
        { name: "state", type: "string", readonly: false, assertion: '$value IN ["live", "stale", "lost"]', defaultValue: null, permissions: noFieldPermissions },
        { name: "updated_at", type: "datetime", readonly: false, assertion: null, defaultValue: "time::now()", permissions: noFieldPermissions },
      ],
      indexes: [
        { name: "current_vehicle_vehicle_id_unique", fields: ["vehicle_id"], unique: true, mode: null },
        { name: "current_vehicle_state_updated_at", fields: ["state", "updated_at"], unique: false, mode: null },
      ],
      events: [
        { name: "publish_current_vehicle", condition: '$event = "CREATE" OR $before.content_hash != $after.content_hash', actions: ["CREATE realtime_event CONTENT { type: 'vehicle_moved', entity_id: $after.vehicle_id };"] },
      ],
    },
    {
      name: "realtime_event",
      kind: "normal",
      schemaMode: "schemafull",
      permissions: noTablePermissions,
      fields: [
        { name: "event_id", type: "string", readonly: false, assertion: null, defaultValue: null, permissions: noFieldPermissions },
        { name: "scope", type: "string", readonly: false, assertion: null, defaultValue: null, permissions: noFieldPermissions },
        { name: "created_at", type: "datetime", readonly: false, assertion: null, defaultValue: "time::now()", permissions: noFieldPermissions },
      ],
      indexes: [{ name: "realtime_event_scope_created", fields: ["scope", "created_at"], unique: false, mode: null }],
      events: [],
    },
    {
      name: "schema_migration",
      kind: "normal",
      schemaMode: "schemafull",
      permissions: noTablePermissions,
      fields: [
        { name: "name", type: "string", readonly: false, assertion: null, defaultValue: null, permissions: noFieldPermissions },
        { name: "checksum", type: "string", readonly: false, assertion: null, defaultValue: null, permissions: noFieldPermissions },
        { name: "applied_at", type: "datetime", readonly: false, assertion: null, defaultValue: null, permissions: noFieldPermissions },
      ],
      indexes: [{ name: "schema_migration_name_unique", fields: ["name"], unique: true, mode: null }],
      events: [],
    },
  ],
};

export const adminDatabaseMigrationsFixture: AdminDatabaseMigrations = {
  readOnly: true,
  checkedAt: NOW,
  state: "in_sync",
  counts: { applied: 3, pending: 0, checksumMismatch: 0, orphaned: 0, failed: 0 },
  lastAppliedAt: "2026-07-10T08:00:00Z",
  migrations: [
    {
      name: "001_core_schema.surql",
      description: "FjordPulse canonical state, diagnostics, and migration ledger.",
      state: "applied",
      releaseChecksum: "3a9f7d07f12df0f641ac65414cc64a3ce16850ecbbba23e70284b9a9c0ed52ef",
      databaseChecksum: "3a9f7d07f12df0f641ac65414cc64a3ce16850ecbbba23e70284b9a9c0ed52ef",
      appliedAt: "2026-07-10T07:58:00Z",
      lastAttemptedAt: "2026-07-10T07:58:00Z",
      failureMessage: null,
      source: "-- FjordPulse canonical state, diagnostics, and migration ledger.\n\nDEFINE TABLE OVERWRITE schema_migration SCHEMAFULL PERMISSIONS NONE;",
      affectedObjects: [
        { kind: "table", name: "schema_migration", table: null, operation: "define" },
        { kind: "field", name: "name", table: "schema_migration", operation: "define" },
        { kind: "index", name: "schema_migration_name_unique", table: "schema_migration", operation: "define" },
      ],
    },
    {
      name: "002_realtime_events.surql",
      description: "Publish canonical state changes through database events.",
      state: "applied",
      releaseChecksum: "49ac05bbcc2bb84f6c79896b2a901ad6a6781b5c3e14a60a6b8ff06a63e6a977",
      databaseChecksum: "49ac05bbcc2bb84f6c79896b2a901ad6a6781b5c3e14a60a6b8ff06a63e6a977",
      appliedAt: "2026-07-10T07:59:00Z",
      lastAttemptedAt: "2026-07-10T07:59:00Z",
      failureMessage: null,
      source: "-- Synchronous database events are the canonical publication path.\n\nDEFINE EVENT OVERWRITE publish_current_vehicle ON TABLE current_vehicle THEN (CREATE realtime_event);",
      affectedObjects: [{ kind: "event", name: "publish_current_vehicle", table: "current_vehicle", operation: "define" }],
    },
    {
      name: "003_semantic_event_filter.surql",
      description: "Publish semantic changes rather than timestamp-only refreshes.",
      state: "applied",
      releaseChecksum: "3938ef24f00c9a405c5dc5bc4f10495f5ff4a53aa66b2f009e0669477658e1bd",
      databaseChecksum: "3938ef24f00c9a405c5dc5bc4f10495f5ff4a53aa66b2f009e0669477658e1bd",
      appliedAt: "2026-07-10T08:00:00Z",
      lastAttemptedAt: "2026-07-10T08:00:00Z",
      failureMessage: null,
      source: "-- Publication is semantic, not clock-driven.\n\nDEFINE EVENT OVERWRITE publish_current_vehicle ON TABLE current_vehicle WHEN $before.content_hash != $after.content_hash THEN (CREATE realtime_event);",
      affectedObjects: [{ kind: "event", name: "publish_current_vehicle", table: "current_vehicle", operation: "define" }],
    },
  ],
};

export const adminDatabaseMigrationStatesFixture: AdminDatabaseMigrations = {
  readOnly: true,
  checkedAt: NOW,
  state: "failed",
  counts: { applied: 1, pending: 1, checksumMismatch: 1, orphaned: 1, failed: 1 },
  lastAppliedAt: "2026-07-10T08:00:00Z",
  migrations: [
    adminDatabaseMigrationsFixture.migrations[0]!,
    {
      name: "010_pending_catalog_index.surql",
      description: "Add the next station catalog lookup index.",
      state: "pending",
      releaseChecksum: "1100000000000000000000000000000000000000000000000000000000000010",
      databaseChecksum: null,
      appliedAt: null,
      lastAttemptedAt: null,
      failureMessage: null,
      source: "-- Add the next station catalog lookup index.\nDEFINE INDEX station_catalog_lookup ON TABLE station FIELDS catalog_id;",
      affectedObjects: [{ kind: "index", name: "station_catalog_lookup", table: "station", operation: "define" }],
    },
    {
      name: "011_drifted_vehicle_index.surql",
      description: "Tighten the current-vehicle lookup path.",
      state: "checksum_mismatch",
      releaseChecksum: "2200000000000000000000000000000000000000000000000000000000000011",
      databaseChecksum: "99ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff11",
      appliedAt: "2026-07-10T08:04:00Z",
      lastAttemptedAt: "2026-07-10T08:04:00Z",
      failureMessage: null,
      source: "-- Tighten the current-vehicle lookup path.\nDEFINE INDEX current_vehicle_route ON TABLE current_vehicle FIELDS line_code, updated_at;",
      affectedObjects: [{ kind: "index", name: "current_vehicle_route", table: "current_vehicle", operation: "define" }],
    },
    {
      name: "012_database_only_legacy.surql",
      description: "Migration is recorded in the database but missing from this release.",
      state: "orphaned",
      releaseChecksum: null,
      databaseChecksum: "3300000000000000000000000000000000000000000000000000000000000012",
      appliedAt: "2026-07-10T08:05:00Z",
      lastAttemptedAt: "2026-07-10T08:05:00Z",
      failureMessage: null,
      source: null,
      affectedObjects: [],
    },
    {
      name: "013_failed_journey_event.surql",
      description: "Publish journey cache changes for diagnostics.",
      state: "failed",
      releaseChecksum: "4400000000000000000000000000000000000000000000000000000000000013",
      databaseChecksum: null,
      appliedAt: null,
      lastAttemptedAt: "2026-07-10T08:06:00Z",
      failureMessage: "Migration transaction rolled back after SurrealDB rejected the event condition.",
      source: "-- Publish journey cache changes for diagnostics.\nDEFINE EVENT publish_journey_snapshot ON TABLE journey_snapshot WHEN $after.version != NONE THEN (CREATE realtime_event);",
      affectedObjects: [{ kind: "event", name: "publish_journey_snapshot", table: "journey_snapshot", operation: "define" }],
    },
  ],
};

export const watchRowsFixture: readonly WatchRow[] = [
  { id: "watch-1", type: "station", scope: "station:Førde rutebilstasjon", clients: 4, priority: "normal", lastRefreshAt: "2026-07-10T18:42:22Z", nextRefreshAt: "2026-07-10T18:42:52Z", state: "active" },
  { id: "watch-2", type: "focus", scope: "vehicle:Line 100 / 2142", clients: 2, priority: "critical", lastRefreshAt: "2026-07-10T18:42:24Z", nextRefreshAt: "2026-07-10T18:42:29Z", state: "active" },
  { id: "watch-3", type: "vehicle", scope: "vehicle:Line 110 / 872", clients: 1, priority: "high", lastRefreshAt: "2026-07-10T18:40:30Z", nextRefreshAt: "2026-07-10T18:42:35Z", state: "stale" },
  { id: "watch-4", type: "station", scope: "station:Sandane rutebilstasjon", clients: 0, priority: "normal", lastRefreshAt: "2026-07-10T18:41:00Z", nextRefreshAt: "2026-07-10T18:43:00Z", state: "expiring" },
];

export const enturLogFixture: readonly EnturLogRow[] = [
  { id: "log-1", createdAt: "2026-07-10T18:42:24Z", api: "Vehicle Positions", scope: "vehicle:Line 100 / 2142", status: "ok", latencyMs: 142, requestCount: 1, cache: "miss", retryAt: null },
  { id: "log-2", createdAt: "2026-07-10T18:42:22Z", api: "Journey Planner", scope: "station:Førde rutebilstasjon", status: "ok", latencyMs: 213, requestCount: 1, cache: "hit", retryAt: null },
  { id: "log-3", createdAt: "2026-07-10T18:41:52Z", api: "Geocoder", scope: "search:førde", status: "ok", latencyMs: 87, requestCount: 1, cache: "hit", retryAt: null },
  { id: "log-4", createdAt: "2026-07-10T18:41:31Z", api: "Vehicle Positions", scope: "station:Skei", status: "backoff", latencyMs: 419, requestCount: 1, cache: "stale", retryAt: "2026-07-10T18:43:31Z" },
  { id: "log-5", createdAt: "2026-07-10T18:39:02Z", api: "Stop Place Register", scope: "import:Vestland", status: "ok", latencyMs: 681, requestCount: 1, cache: "miss", retryAt: null },
];

export const SCENARIO_ALIASES: Readonly<Record<string, VisualScenarioId>> = {
  normal: "desktop_station_fresh",
  station_empty: "desktop_station_empty",
  station_stale: "desktop_station_stale",
  station_error: "desktop_station_error",
  vehicle_live: "desktop_vehicle_focus_following",
  vehicle_stale: "desktop_vehicle_stale",
  vehicle_lost: "desktop_vehicle_lost",
  fallback: "desktop_degraded_fallback",
  entur_backoff: "desktop_station_stale",
  realtime_reconnect: "desktop_degraded_fallback",
};

export { mapItems, searchResults };
