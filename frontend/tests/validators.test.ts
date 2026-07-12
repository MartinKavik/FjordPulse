import { describe, expect, it } from "vitest";
import { clientMessageSchema, parseServerMessage, searchResultSchema, serverMessageSchema, servingVehicleCoverageSchema, stationVehicleSchema, vehicleDataSchema, vehicleSummarySchema } from "../src/types/validators";

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

  it("requires an explicit canonical mode on vehicle summaries", () => {
    const summary = {
      id: "SKY:Vehicle:123",
      transportMode: "ferry",
      passengerServiceState: "passenger",
      lineCode: "2",
      destination: "Nesoddtangen",
      state: "live",
      latitude: 59.9,
      longitude: 10.7,
      bearing: 90,
      delaySeconds: 0,
      distanceMeters: null,
      lastSeenAt: "2026-07-10T10:00:00Z",
      version: "2026-07-10T10:00:00Z",
    };
    expect(vehicleSummarySchema.safeParse(summary).success).toBe(true);
    expect(vehicleSummarySchema.safeParse({ ...summary, transportMode: "submarine" }).success).toBe(false);
    expect(vehicleSummarySchema.safeParse({ ...summary, passengerServiceState: "probably_passenger" }).success).toBe(false);
    const { transportMode: _transportMode, ...missingMode } = summary;
    expect(vehicleSummarySchema.safeParse(missingMode).success).toBe(false);
    const { passengerServiceState: _passengerServiceState, ...missingPassengerServiceState } = summary;
    expect(vehicleSummarySchema.safeParse(missingPassengerServiceState).success).toBe(false);
  });

  it("validates station-serving relationships and bounded coverage", () => {
    const vehicle = {
      id: "VYG:Vehicle:outside-radius",
      transportMode: "rail",
      passengerServiceState: "passenger",
      lineCode: "R13",
      destination: "Dal",
      state: "live",
      latitude: 60.2,
      longitude: 11.1,
      bearing: null,
      delaySeconds: 0,
      distanceMeters: null,
      lastSeenAt: "2026-07-10T10:00:00Z",
      version: "2026-07-10T10:00:00Z",
      relation: "approaching",
      stationCallAt: "2026-07-10T10:15:00Z",
    };
    expect(stationVehicleSchema.safeParse(vehicle).success).toBe(true);
    expect(stationVehicleSchema.safeParse({ ...vehicle, passengerServiceState: "unknown" }).success).toBe(true);
    expect(stationVehicleSchema.safeParse({ ...vehicle, passengerServiceState: "non_passenger" }).success).toBe(false);
    const { relation: _relation, stationCallAt: _stationCallAt, ...nearbyVehicle } = vehicle;
    expect(vehicleSummarySchema.safeParse({ ...nearbyVehicle, passengerServiceState: "non_passenger" }).success).toBe(true);
    expect(stationVehicleSchema.safeParse({ ...vehicle, relation: "probably_nearby" }).success).toBe(false);
    expect(servingVehicleCoverageSchema.safeParse({ windowStart: null, windowEnd: null, candidateJourneyCount: 320, queriedJourneyCount: 200, truncated: true }).success).toBe(true);
    expect(servingVehicleCoverageSchema.safeParse({ windowStart: null, windowEnd: null, candidateJourneyCount: 320, queriedJourneyCount: 201, truncated: true }).success).toBe(false);
  });

  it("forbids journey data on non-passenger vehicle snapshots", () => {
    const stop = {
      stopPlaceId: "NSR:StopPlace:548",
      quayId: "NSR:Quay:548",
      name: "Førde rutebilstasjon",
      order: 1,
      latitude: 61.452,
      longitude: 5.857,
      aimedArrivalAt: "2026-07-10T10:05:00Z",
      expectedArrivalAt: "2026-07-10T10:05:00Z",
      aimedDepartureAt: "2026-07-10T10:06:00Z",
      expectedDepartureAt: "2026-07-10T10:06:00Z",
      realtime: true,
      cancellation: false,
    };
    const journey = {
      serviceJourneyId: "SKY:ServiceJourney:100-1",
      operatingDate: "2026-07-10",
      datedServiceJourneyId: null,
      version: "2026-07-10T10:00:00Z",
      state: "fresh",
      route: null,
      calls: [stop],
      refreshedAt: "2026-07-10T10:00:00Z",
      lastSuccessfulAt: "2026-07-10T10:00:00Z",
      warning: null,
    };
    const nonPassengerSnapshot = {
      vehicle: {
        id: "SKY:Vehicle:123",
        transportMode: "bus",
        passengerServiceState: "non_passenger",
        lineCode: "4",
        routeName: "Flaktveit - Hesjaholtet",
        state: "live",
        latitude: 60.4,
        longitude: 5.3,
        lastSeenAt: "2026-07-10T10:00:00Z",
        refreshedAt: "2026-07-10T10:00:00Z",
        version: "2026-07-10T10:00:00Z",
        nextStop: null,
        journeyReference: null,
        monitoredCall: null,
        progressBetweenStops: null,
        journeyVersion: null,
        routeProgress: null,
      },
      trail: [],
      journey: null,
      upcomingStops: [],
    };

    expect(vehicleDataSchema.safeParse(nonPassengerSnapshot).success).toBe(true);
    expect(vehicleDataSchema.safeParse({ ...nonPassengerSnapshot, journey }).success).toBe(false);
    expect(vehicleDataSchema.safeParse({ ...nonPassengerSnapshot, upcomingStops: [stop] }).success).toBe(false);
    expect(vehicleDataSchema.safeParse({ ...nonPassengerSnapshot, vehicle: { ...nonPassengerSnapshot.vehicle, passengerServiceState: "passenger" }, journey, upcomingStops: [stop] }).success).toBe(true);
  });
});
