import { z } from "zod";
import type { Departure, JourneySnapshot, MapItem, NearbyVehicle, Station, StationSnapshot, StationVehicle, StopCall, UpcomingStop, VehicleJourneyReference, VehicleState } from "./domain";
import { PROTOCOL_VERSION } from "./domain";

const rfc3339 = z.string().refine((value) => Number.isFinite(Date.parse(value)), "Expected an RFC3339 timestamp");
const nullableRfc3339 = rfc3339.nullable();
const stationId = z.string().regex(/^NSR:StopPlace:[A-Za-z0-9_-]+$/).max(200);
const vehicleId = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9:._-]{0,199}$/);
const latitude = z.number().min(-90).max(90);
const longitude = z.number().min(-180).max(180);
const nullableLatitude = latitude.nullable();
const nullableLongitude = longitude.nullable();
const sourceStateSchema = z.enum(["loading", "fresh", "refreshing", "empty", "stale", "unavailable", "error", "backoff", "rate_limited"]);
const transportModeSchema = z.enum(["bus", "coach", "tram", "rail", "metro", "water", "air", "taxi", "unknown"]);
export const vehicleTransportModeSchema = z.enum(["air", "bus", "coach", "ferry", "metro", "taxi", "tram", "rail", "unknown"]);
export const passengerServiceStateSchema = z.enum(["passenger", "non_passenger", "unknown"]);

export const stationSchema = z.object({
  id: stationId,
  name: z.string().min(1).max(300),
  kind: z.enum(["stop_place", "station", "bus_station", "ferry_terminal", "rail_station", "tram_stop", "metro_station", "airport", "unknown"]),
  latitude,
  longitude,
  locality: z.string().max(200).nullable(),
  municipality: z.string().max(200).nullable().default(null),
  transportModes: z.array(transportModeSchema),
  importedAt: rfc3339,
}).strict();

export const stationMarkerSchema = z.object({
  kind: z.literal("station"),
  id: stationId,
  name: z.string().min(1).max(300),
  latitude,
  longitude,
  transportModes: z.array(transportModeSchema),
}).strict();

const boundingBoxSchema = z.object({ minLongitude: longitude, minLatitude: latitude, maxLongitude: longitude, maxLatitude: latitude }).strict();
export const stationClusterSchema = z.object({
  kind: z.literal("cluster"), id: z.string().min(1).max(200), latitude, longitude, count: z.number().int().min(2), bounds: boundingBoxSchema,
}).strict();
export const mapItemSchema = z.discriminatedUnion("kind", [stationMarkerSchema, stationClusterSchema]);
export const stationMapDataSchema = z.object({
  bounds: boundingBoxSchema,
  zoom: z.number().min(0).max(24),
  dataSource: z.literal("surrealdb"),
  items: z.array(mapItemSchema).max(2000),
}).strict();

export const searchResultSchema = z.object({
  type: z.enum(["station", "place", "line", "vehicle"]),
  id: z.string().min(1).max(200),
  label: z.string().min(1).max(300),
  secondaryText: z.string().max(500).nullable(),
  stationId: z.string().max(200).nullable().default(null),
  lineCode: z.string().max(100).nullable().default(null),
  latitude: nullableLatitude.default(null),
  longitude: nullableLongitude.default(null),
  transportMode: vehicleTransportModeSchema.nullable().optional().default(null),
}).strict();
export const searchDataSchema = z.object({ query: z.string().min(1).max(200), results: z.array(searchResultSchema).max(50) }).strict();

export const departureSchema = z.object({
  id: z.string().min(1).max(300),
  serviceJourneyId: z.string().max(300).nullable().default(null),
  lineId: z.string().max(300).nullable().default(null),
  lineCode: z.string().max(100).nullable(),
  destination: z.string().max(300).nullable(),
  aimedDepartureAt: rfc3339,
  expectedDepartureAt: nullableRfc3339,
  status: z.enum(["scheduled", "realtime", "delayed", "cancelled", "departed", "unknown"]),
  delaySeconds: z.number().int().nullable().default(null),
  platform: z.string().max(100).nullable().default(null),
  realtime: z.boolean(),
}).strict();

export const vehicleSummarySchema = z.object({
  id: vehicleId,
  transportMode: vehicleTransportModeSchema,
  passengerServiceState: passengerServiceStateSchema,
  lineCode: z.string().max(100).nullable(),
  destination: z.string().max(300).nullable().default(null),
  state: z.enum(["live", "stale", "lost"]),
  latitude: nullableLatitude,
  longitude: nullableLongitude,
  bearing: z.number().min(0).max(360).nullable().default(null),
  delaySeconds: z.number().int().nullable().default(null),
  distanceMeters: z.number().min(0).nullable().default(null),
  lastSeenAt: rfc3339,
  version: rfc3339,
}).strict();

export const stationVehicleSchema = vehicleSummarySchema.extend({
  passengerServiceState: z.enum(["passenger", "unknown"]),
  relation: z.enum(["starting_here", "approaching", "at_station", "departed", "serves_station"]),
  stationCallAt: nullableRfc3339,
}).strict();

export const servingVehicleCoverageSchema = z.object({
  windowStart: nullableRfc3339,
  windowEnd: nullableRfc3339,
  candidateJourneyCount: z.number().int().nonnegative(),
  queriedJourneyCount: z.number().int().min(0).max(200),
  truncated: z.boolean(),
}).strict();

export const stationSnapshotPayloadSchema = z.object({
  stationId,
  state: sourceStateSchema,
  version: rfc3339,
  updatedAt: rfc3339,
  lastSuccessfulAt: nullableRfc3339.default(null),
  warning: z.string().max(500).nullable().default(null),
  departures: z.array(departureSchema),
  nearbyVehicles: z.array(vehicleSummarySchema),
  servingVehicles: z.array(stationVehicleSchema),
  servingVehicleCoverage: servingVehicleCoverageSchema,
}).strict();
export const stationDataSchema = z.object({ station: stationSchema, snapshot: stationSnapshotPayloadSchema }).strict();
export const stationDeparturesDataSchema = z.object({
  stationId, state: sourceStateSchema, version: rfc3339, updatedAt: rfc3339, lastSuccessfulAt: nullableRfc3339.default(null), warning: z.string().max(500).nullable().default(null), departures: z.array(departureSchema),
}).strict();
export const nearbyVehiclesEventDataSchema = z.object({
  stationId, state: sourceStateSchema, version: rfc3339, updatedAt: rfc3339, lastSuccessfulAt: nullableRfc3339.default(null), warning: z.string().max(500).nullable().default(null), vehicles: z.array(vehicleSummarySchema),
}).strict();
export const nearbyVehiclesDataSchema = nearbyVehiclesEventDataSchema.extend({
  searchRadiusMeters: z.number().int().positive().max(100_000),
}).strict();

export const stopCallSchema = z.object({
  stopPlaceId: z.string().max(200).nullable(),
  quayId: z.string().max(200).nullable(),
  name: z.string().min(1).max(300),
  order: z.number().int().min(0).max(999),
  latitude: nullableLatitude,
  longitude: nullableLongitude,
  aimedArrivalAt: nullableRfc3339,
  expectedArrivalAt: nullableRfc3339,
  aimedDepartureAt: nullableRfc3339,
  expectedDepartureAt: nullableRfc3339,
  realtime: z.boolean(),
  cancellation: z.boolean(),
}).strict();
export const vehicleJourneyReferenceSchema = z.object({
  serviceJourneyId: z.string().min(1).max(300),
  operatingDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  datedServiceJourneyId: z.string().max(300).nullable(),
  originRef: z.string().max(300).nullable(),
  originName: z.string().max(300).nullable(),
  destinationRef: z.string().max(300).nullable(),
  destinationName: z.string().max(300).nullable(),
}).strict();
export const monitoredCallSchema = z.object({ stopPointRef: z.string().max(300).nullable(), order: z.number().int().min(0).max(999), vehicleAtStop: z.boolean() }).strict();
export const progressBetweenStopsSchema = z.object({ linkDistance: z.number().nonnegative().nullable(), percentage: z.number().min(0).max(1).nullable() }).strict();
export const journeyRouteSchema = z.object({
  type: z.literal("LineString"),
  coordinates: z.array(z.tuple([longitude, latitude])).min(2).max(20_000),
  distanceMeters: z.number().nonnegative().nullable(),
}).strict();
export const journeySnapshotSchema = z.object({
  serviceJourneyId: z.string().min(1).max(300),
  operatingDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  datedServiceJourneyId: z.string().max(300).nullable(),
  version: rfc3339,
  state: sourceStateSchema,
  route: journeyRouteSchema.nullable(),
  calls: z.array(stopCallSchema).max(1_000),
  refreshedAt: rfc3339,
  lastSuccessfulAt: nullableRfc3339,
  warning: z.string().max(500).nullable(),
}).strict();
export const vehicleContractSchema = z.object({
  id: vehicleId,
  transportMode: vehicleTransportModeSchema,
  passengerServiceState: passengerServiceStateSchema,
  lineCode: z.string().max(100).nullable(),
  routeName: z.string().max(300).nullable(),
  destination: z.string().max(300).nullable().default(null),
  state: z.enum(["live", "stale", "lost"]),
  latitude: nullableLatitude,
  longitude: nullableLongitude,
  bearing: z.number().min(0).max(360).nullable().default(null),
  delaySeconds: z.number().int().nullable().default(null),
  distanceMeters: z.number().min(0).nullable().default(null),
  lastSeenAt: rfc3339,
  refreshedAt: rfc3339,
  version: rfc3339,
  nextStop: stopCallSchema.nullable(),
  journeyReference: vehicleJourneyReferenceSchema.nullable(),
  monitoredCall: monitoredCallSchema.nullable(),
  progressBetweenStops: progressBetweenStopsSchema.nullable(),
  journeyVersion: rfc3339.nullable(),
  routeProgress: z.number().min(0).max(1).nullable(),
}).strict();
export const vehicleObservationSchema = z.object({ latitude, longitude, bearing: z.number().min(0).max(360).nullable(), observedAt: rfc3339 }).strict();
export const vehicleDataSchema = z.object({ vehicle: vehicleContractSchema, trail: z.array(vehicleObservationSchema).max(500), journey: journeySnapshotSchema.nullable(), upcomingStops: z.array(stopCallSchema).max(1_000) }).strict().superRefine((snapshot, context) => {
  if (snapshot.vehicle.passengerServiceState !== "non_passenger") return;
  if (snapshot.journey !== null) context.addIssue({ code: "custom", message: "Non-passenger vehicles cannot expose a journey", path: ["journey"] });
  if (snapshot.upcomingStops.length > 0) context.addIssue({ code: "custom", message: "Non-passenger vehicles cannot expose upcoming stops", path: ["upcomingStops"] });
});
export const vehicleEventPayloadSchema = z.object({ vehicle: vehicleContractSchema, observation: vehicleObservationSchema.nullable() }).strict();

export const telemetryPayloadSchema = z.object({
  backend: z.enum(["ok", "degraded", "offline"]),
  realtime: z.enum(["idle", "connecting", "connected", "reconnecting", "offline"]),
  entur: z.enum(["ok", "idle", "delayed", "backoff", "rate_limited", "offline", "not_used"]),
  liveQueryBridge: z.enum(["connected", "reconnecting", "degraded", "offline"]),
  refreshMode: z.enum(["realtime", "polling"]),
  lastUpdateAt: nullableRfc3339,
}).strict();

export const apiMetaSchema = z.object({ requestId: z.string().min(1), updatedAt: rfc3339, nextCursor: z.string().nullable().optional() }).strict();
const apiErrorMetaSchema = z.object({ requestId: z.string().min(1), retryAfterSeconds: z.number().int().nonnegative().nullable().optional() }).strict();
const apiErrorSchema = z.object({ code: z.string().min(1), message: z.string().min(1), details: z.record(z.string(), z.unknown()) }).strict();
export const apiEnvelopeSchema = <T extends z.ZodType>(data: T) => z.union([
  z.object({ ok: z.literal(true), data, meta: apiMetaSchema }).strict(),
  z.object({ ok: z.literal(false), error: apiErrorSchema, meta: apiErrorMetaSchema }).strict(),
]);

export const clientMessageTypeSchema = z.enum(["watch_station", "unwatch_station", "watch_vehicle", "unwatch_vehicle", "focus_vehicle", "unfocus_vehicle", "pause_focus", "resume_focus", "ping"]);
export const clientMessageSchema = z.object({
  protocolVersion: z.literal(PROTOCOL_VERSION), id: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._:-]*$/).max(128), type: clientMessageTypeSchema, payload: z.record(z.string(), z.string()),
}).strict().superRefine((message, context) => {
  const needsStation = message.type === "watch_station" || message.type === "unwatch_station";
  const needsVehicle = ["watch_vehicle", "unwatch_vehicle", "focus_vehicle", "unfocus_vehicle", "pause_focus", "resume_focus"].includes(message.type);
  if (needsStation && !stationId.safeParse(message.payload.stationId).success) context.addIssue({ code: "custom", message: "stationId is required", path: ["payload", "stationId"] });
  if (needsVehicle && !vehicleId.safeParse(message.payload.vehicleId).success) context.addIssue({ code: "custom", message: "vehicleId is required", path: ["payload", "vehicleId"] });
  const allowed = message.type === "ping" ? new Set(["sentAt"]) : needsStation ? new Set(message.type === "watch_station" ? ["stationId", "knownVersion", "lastEventId"] : ["stationId"]) : new Set(message.type === "watch_vehicle" || message.type === "focus_vehicle" ? ["vehicleId", "knownVersion", "lastEventId"] : ["vehicleId"]);
  for (const key of Object.keys(message.payload)) if (!allowed.has(key)) context.addIssue({ code: "custom", message: `Unexpected payload field: ${key}`, path: ["payload", key] });
  if (message.payload.knownVersion !== undefined && !rfc3339.safeParse(message.payload.knownVersion).success) context.addIssue({ code: "custom", message: "knownVersion must be RFC3339", path: ["payload", "knownVersion"] });
  if (message.payload.lastEventId !== undefined && (message.payload.lastEventId.length < 1 || message.payload.lastEventId.length > 200)) context.addIssue({ code: "custom", message: "lastEventId is invalid", path: ["payload", "lastEventId"] });
  if (message.payload.sentAt !== undefined && !rfc3339.safeParse(message.payload.sentAt).success) context.addIssue({ code: "custom", message: "sentAt must be RFC3339", path: ["payload", "sentAt"] });
});

export const serverMessageTypeSchema = z.enum([
  "watch_station_ack", "unwatch_station_ack", "watch_vehicle_ack", "unwatch_vehicle_ack", "focus_started", "focus_stopped", "focus_paused", "focus_resumed",
  "station_snapshot", "station_snapshot_changed", "station_departures_changed", "nearby_vehicles_changed", "vehicle_snapshot", "vehicle_moved", "vehicle_stale", "vehicle_lost",
  "source_backoff", "rate_limited", "telemetry_tick", "realtime_degraded", "resync_required", "pong", "error",
]);
const persistentTypes = new Set(["station_snapshot_changed", "station_departures_changed", "nearby_vehicles_changed", "vehicle_moved", "vehicle_stale", "vehicle_lost"]);
const snapshotTypes = new Set(["station_snapshot", "vehicle_snapshot"]);
const ackTypes = new Set(["watch_station_ack", "unwatch_station_ack", "watch_vehicle_ack", "unwatch_vehicle_ack", "focus_started", "focus_stopped", "focus_paused", "focus_resumed", "pong"]);
const payloadTypes = new Set(["source_backoff", "rate_limited", "telemetry_tick", "realtime_degraded", "resync_required"]);
export const serverMessageSchema = z.object({
  protocolVersion: z.literal(PROTOCOL_VERSION),
  id: z.string().optional(),
  type: serverMessageTypeSchema,
  scope: z.string().optional(),
  entityId: z.string().optional(),
  eventId: z.string().optional(),
  version: rfc3339.optional(),
  createdAt: rfc3339,
  payload: z.unknown().optional(),
  error: apiErrorSchema.optional(),
}).strict().superRefine((message, context) => {
  const requireFields = (fields: readonly (keyof typeof message)[]) => { for (const key of fields) if (message[key] === undefined) context.addIssue({ code: "custom", message: `${key} is required`, path: [key] }); };
  const forbidFields = (fields: readonly (keyof typeof message)[]) => { for (const key of fields) if (message[key] !== undefined) context.addIssue({ code: "custom", message: `${key} is not allowed`, path: [key] }); };
  if (persistentTypes.has(message.type)) {
    requireFields(["scope", "entityId", "eventId", "version", "payload"]);
    forbidFields(["id", "error"]);
  } else if (snapshotTypes.has(message.type)) {
    requireFields(["scope", "entityId", "version", "payload"]);
    forbidFields(["id", "eventId", "error"]);
  } else if (ackTypes.has(message.type)) {
    requireFields(["id", "payload"]);
    forbidFields(["eventId", "version", "error"]);
    if (message.type === "pong") forbidFields(["scope", "entityId"]); else requireFields(["scope", "entityId"]);
  } else if (payloadTypes.has(message.type)) {
    requireFields(["payload"]);
    forbidFields(["id", "entityId", "eventId", "version", "error"]);
  } else if (message.type === "error") {
    requireFields(["error"]);
    forbidFields(["eventId", "version", "payload"]);
  }
});

type StationSnapshotPayload = z.infer<typeof stationSnapshotPayloadSchema>;
type VehicleData = z.infer<typeof vehicleDataSchema>;
type VehicleSummary = z.infer<typeof vehicleSummarySchema>;
type StationVehiclePayload = z.infer<typeof stationVehicleSchema>;

export function mapNearbyVehicle(vehicle: VehicleSummary): NearbyVehicle {
  const relation = vehicle.passengerServiceState !== "non_passenger" && vehicle.destination !== null
    ? `towards ${vehicle.destination}`
    : "within the station search area";
  return { id: vehicle.id, transportMode: vehicle.transportMode, passengerServiceState: vehicle.passengerServiceState, lineCode: vehicle.lineCode, relation, lastSeenAt: vehicle.lastSeenAt, delaySeconds: vehicle.delaySeconds, state: vehicle.state, latitude: vehicle.latitude, longitude: vehicle.longitude };
}

export function mapStationVehicle(vehicle: StationVehiclePayload): StationVehicle {
  return {
    id: vehicle.id,
    transportMode: vehicle.transportMode,
    passengerServiceState: vehicle.passengerServiceState,
    lineCode: vehicle.lineCode,
    relation: vehicle.relation,
    stationCallAt: vehicle.stationCallAt,
    lastSeenAt: vehicle.lastSeenAt,
    delaySeconds: vehicle.delaySeconds,
    state: vehicle.state,
    latitude: vehicle.latitude,
    longitude: vehicle.longitude,
  };
}

export function mapDeparture(departure: z.infer<typeof departureSchema>): Departure {
  const { id, lineCode, destination, aimedDepartureAt, expectedDepartureAt, status, delaySeconds, platform } = departure;
  return { id, lineCode, destination, aimedDepartureAt, expectedDepartureAt, status, delaySeconds, platform };
}

export function toStationSnapshot(station: Station, snapshot: StationSnapshotPayload, nearbyVehicleSearchRadiusMeters: number | null = null): StationSnapshot {
  return {
    station,
    stationId: snapshot.stationId,
    state: snapshot.state,
    version: snapshot.version,
    updatedAt: snapshot.updatedAt,
    departures: snapshot.departures.map(mapDeparture),
    nearbyVehicles: snapshot.nearbyVehicles.map(mapNearbyVehicle),
    servingVehicles: snapshot.servingVehicles.map(mapStationVehicle),
    servingVehicleCoverage: snapshot.servingVehicleCoverage,
    nearbyVehicleSearchRadiusMeters,
    ...(snapshot.warning === null ? {} : { message: snapshot.warning }),
  };
}

function journeyReferenceKey(reference: VehicleJourneyReference | null): string | null {
  return reference === null ? null : `${reference.serviceJourneyId}\u0000${reference.operatingDate}\u0000${reference.datedServiceJourneyId ?? ""}`;
}

function stopMatches(left: StopCall, right: StopCall): boolean {
  if (left.order !== right.order) return false;
  if (right.quayId !== null && left.quayId !== right.quayId) return false;
  if (right.stopPlaceId !== null && left.stopPlaceId !== right.stopPlaceId) return false;
  return true;
}

function mapUpcomingCall(stop: StopCall, index: number): UpcomingStop {
  return {
    id: stop.stopPlaceId ?? stop.quayId ?? `upcoming-${index}`,
    name: stop.name,
    expectedAt: stop.expectedArrivalAt ?? stop.aimedArrivalAt ?? stop.expectedDepartureAt ?? stop.aimedDepartureAt,
  };
}

function upcomingFromCompactEvent(journey: JourneySnapshot, vehicle: z.infer<typeof vehicleContractSchema>): readonly UpcomingStop[] | null {
  let index = vehicle.nextStop === null ? -1 : journey.calls.findIndex((call) => stopMatches(call, vehicle.nextStop!));
  if (index < 0 && vehicle.monitoredCall !== null) {
    index = journey.calls.findIndex((call) => call.order === vehicle.monitoredCall!.order
      && (vehicle.monitoredCall!.stopPointRef === null || call.quayId === vehicle.monitoredCall!.stopPointRef));
    if (index >= 0 && vehicle.monitoredCall.vehicleAtStop) index += 1;
  }
  if (index < 0) {
    return vehicle.nextStop === null && vehicle.routeProgress !== null && vehicle.routeProgress >= 0.999 ? [] : null;
  }

  return journey.calls.slice(index).map(mapUpcomingCall);
}

export function toVehicleEventState(vehicle: z.infer<typeof vehicleContractSchema>, observation: z.infer<typeof vehicleObservationSchema> | null, current: VehicleState | null): VehicleState {
  const trail = current?.trail ?? [];
  const sameJourney = current !== null
    && vehicle.journeyVersion !== null
    && current.journeyVersion === vehicle.journeyVersion
    && journeyReferenceKey(current.journeyReference) === journeyReferenceKey(vehicle.journeyReference);
  const journey = sameJourney ? current.journey : null;
  const upcomingStops = journey === null ? [] : (upcomingFromCompactEvent(journey, vehicle) ?? current?.upcomingStops ?? []);
  return {
    id: vehicle.id,
    transportMode: vehicle.transportMode,
    passengerServiceState: vehicle.passengerServiceState,
    lineCode: vehicle.lineCode,
    routeName: vehicle.routeName,
    state: vehicle.state,
    latitude: vehicle.latitude,
    longitude: vehicle.longitude,
    bearing: vehicle.bearing,
    delaySeconds: vehicle.delaySeconds,
    lastSeenAt: vehicle.lastSeenAt,
    refreshedAt: vehicle.refreshedAt,
    version: vehicle.version,
    nextStop: vehicle.nextStop,
    journeyReference: vehicle.journeyReference,
    monitoredCall: vehicle.monitoredCall,
    progressBetweenStops: vehicle.progressBetweenStops,
    journeyVersion: vehicle.journeyVersion,
    routeProgress: vehicle.routeProgress,
    trail: observation === null ? trail : [...trail, { latitude: observation.latitude, longitude: observation.longitude, observedAt: observation.observedAt }].slice(-100),
    journey,
    upcomingStops,
  };
}

export function toVehicleState(data: VehicleData): VehicleState {
  return {
    id: data.vehicle.id,
    transportMode: data.vehicle.transportMode,
    passengerServiceState: data.vehicle.passengerServiceState,
    lineCode: data.vehicle.lineCode,
    routeName: data.vehicle.routeName,
    state: data.vehicle.state,
    latitude: data.vehicle.latitude,
    longitude: data.vehicle.longitude,
    bearing: data.vehicle.bearing,
    delaySeconds: data.vehicle.delaySeconds,
    lastSeenAt: data.vehicle.lastSeenAt,
    refreshedAt: data.vehicle.refreshedAt,
    version: data.vehicle.version,
    nextStop: data.vehicle.nextStop,
    journeyReference: data.vehicle.journeyReference,
    monitoredCall: data.vehicle.monitoredCall,
    progressBetweenStops: data.vehicle.progressBetweenStops,
    journeyVersion: data.vehicle.journeyVersion,
    routeProgress: data.vehicle.routeProgress,
    trail: data.trail.map(({ latitude: lat, longitude: lon, observedAt }) => ({ latitude: lat, longitude: lon, observedAt })),
    journey: data.journey,
    upcomingStops: data.upcomingStops.map((stop, index) => ({ ...mapUpcomingCall(stop, index), ...(data.vehicle.monitoredCall?.vehicleAtStop === true && stop.order === data.vehicle.monitoredCall.order ? { current: true } : {}) })),
  };
}

export function parseServerMessage(raw: string): z.infer<typeof serverMessageSchema> | null {
  try {
    const result = serverMessageSchema.safeParse(JSON.parse(raw) as unknown);
    return result.success ? result.data : null;
  } catch {
    return null;
  }
}

export type ContractMapItem = z.infer<typeof mapItemSchema>;
export function toMapItems(items: readonly ContractMapItem[]): readonly MapItem[] { return items; }
