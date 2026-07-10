import { z } from "zod";
import type { Departure, MapItem, NearbyVehicle, Station, StationSnapshot, VehicleState } from "./domain";
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

export const stationSnapshotPayloadSchema = z.object({
  stationId,
  state: sourceStateSchema,
  version: rfc3339,
  updatedAt: rfc3339,
  lastSuccessfulAt: nullableRfc3339.default(null),
  warning: z.string().max(500).nullable().default(null),
  departures: z.array(departureSchema),
  nearbyVehicles: z.array(vehicleSummarySchema),
}).strict();
export const stationDataSchema = z.object({ station: stationSchema, snapshot: stationSnapshotPayloadSchema }).strict();
export const stationDeparturesDataSchema = z.object({
  stationId, state: sourceStateSchema, version: rfc3339, updatedAt: rfc3339, lastSuccessfulAt: nullableRfc3339.default(null), warning: z.string().max(500).nullable().default(null), departures: z.array(departureSchema),
}).strict();
export const nearbyVehiclesDataSchema = z.object({
  stationId, state: sourceStateSchema, version: rfc3339, updatedAt: rfc3339, lastSuccessfulAt: nullableRfc3339.default(null), warning: z.string().max(500).nullable().default(null), vehicles: z.array(vehicleSummarySchema),
}).strict();

export const stopCallSchema = z.object({
  stopPlaceId: z.string().max(200).nullable(), name: z.string().min(1).max(300), aimedArrivalAt: nullableRfc3339, expectedArrivalAt: nullableRfc3339,
}).strict();
export const vehicleContractSchema = z.object({
  id: vehicleId,
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
  version: rfc3339,
  nextStop: stopCallSchema.nullable(),
}).strict();
export const vehicleObservationSchema = z.object({ latitude, longitude, bearing: z.number().min(0).max(360).nullable(), observedAt: rfc3339 }).strict();
export const vehicleDataSchema = z.object({ vehicle: vehicleContractSchema, trail: z.array(vehicleObservationSchema).max(500), upcomingStops: z.array(stopCallSchema).max(100) }).strict();
export const vehicleEventPayloadSchema = z.object({ vehicle: vehicleContractSchema, observation: vehicleObservationSchema.nullable() }).strict();

export const telemetryPayloadSchema = z.object({
  backend: z.enum(["ok", "degraded", "offline"]),
  realtime: z.enum(["idle", "connecting", "connected", "reconnecting", "offline"]),
  entur: z.enum(["ok", "delayed", "backoff", "rate_limited", "offline"]),
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

export function mapNearbyVehicle(vehicle: VehicleSummary): NearbyVehicle {
  const relation = vehicle.distanceMeters !== null ? `${Math.round(vehicle.distanceMeters)} m away` : vehicle.destination !== null ? `towards ${vehicle.destination}` : "near selected station";
  return { id: vehicle.id, lineCode: vehicle.lineCode, relation, lastSeenAt: vehicle.lastSeenAt, delaySeconds: vehicle.delaySeconds, state: vehicle.state, latitude: vehicle.latitude, longitude: vehicle.longitude };
}

export function mapDeparture(departure: z.infer<typeof departureSchema>): Departure {
  const { id, lineCode, destination, aimedDepartureAt, expectedDepartureAt, status, delaySeconds } = departure;
  return { id, lineCode, destination, aimedDepartureAt, expectedDepartureAt, status, delaySeconds };
}

export function toStationSnapshot(station: Station, snapshot: StationSnapshotPayload): StationSnapshot {
  return {
    station,
    stationId: snapshot.stationId,
    state: snapshot.state,
    version: snapshot.version,
    updatedAt: snapshot.updatedAt,
    departures: snapshot.departures.map(mapDeparture),
    nearbyVehicles: snapshot.nearbyVehicles.map(mapNearbyVehicle),
    ...(snapshot.warning === null ? {} : { message: snapshot.warning }),
  };
}

export function toVehicleEventState(vehicle: z.infer<typeof vehicleContractSchema>, observation: z.infer<typeof vehicleObservationSchema> | null, current: VehicleState | null): VehicleState {
  const trail = current?.trail ?? [];
  return {
    id: vehicle.id,
    lineCode: vehicle.lineCode,
    routeName: vehicle.routeName,
    state: vehicle.state,
    latitude: vehicle.latitude,
    longitude: vehicle.longitude,
    bearing: vehicle.bearing,
    delaySeconds: vehicle.delaySeconds,
    lastSeenAt: vehicle.lastSeenAt,
    version: vehicle.version,
    nextStop: vehicle.nextStop,
    trail: observation === null ? trail : [...trail, { latitude: observation.latitude, longitude: observation.longitude, observedAt: observation.observedAt }].slice(-100),
    upcomingStops: current?.upcomingStops ?? [],
  };
}

export function toVehicleState(data: VehicleData): VehicleState {
  return {
    id: data.vehicle.id,
    lineCode: data.vehicle.lineCode,
    routeName: data.vehicle.routeName,
    state: data.vehicle.state,
    latitude: data.vehicle.latitude,
    longitude: data.vehicle.longitude,
    bearing: data.vehicle.bearing,
    delaySeconds: data.vehicle.delaySeconds,
    lastSeenAt: data.vehicle.lastSeenAt,
    version: data.vehicle.version,
    nextStop: data.vehicle.nextStop,
    trail: data.trail.map(({ latitude: lat, longitude: lon, observedAt }) => ({ latitude: lat, longitude: lon, observedAt })),
    upcomingStops: data.upcomingStops.map((stop, index) => ({ id: stop.stopPlaceId ?? `upcoming-${index}`, name: stop.name, expectedAt: stop.expectedArrivalAt ?? stop.aimedArrivalAt, ...(index === 0 ? { current: true } : {}) })),
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
