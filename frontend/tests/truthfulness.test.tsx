import { cleanup, render, screen } from "@solidjs/testing-library";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ClockProvider } from "../src/state/clock";
import { TelemetryStrip } from "../src/components/DesignSystem";
import { compassPoint, formatDelay, formatRelativeTime } from "../src/utils/format";
import { normalizeSearchText, rankFixtureSearch } from "../src/utils/search";
import type { SearchResult } from "../src/types/domain";
import { line100Vehicle } from "../src/fixtures/scenarios";
import { mapNearbyVehicle, toVehicleEventState } from "../src/types/validators";

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe("truthful live formatting", () => {
  it("advances relative ages from one shared reactive clock", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-07-10T12:00:06Z"));
    render(() => <ClockProvider><TelemetryStrip telemetry={{ backend: "ok", realtime: "connected", entur: "ok", liveQueryBridge: "connected", refreshMode: "realtime", lastUpdateAt: "2026-07-10T12:00:00Z" }} /></ClockProvider>);
    expect(screen.getByText("6s ago")).toBeInTheDocument();
    await vi.advanceTimersByTimeAsync(2_000);
    expect(screen.getByText("8s ago")).toBeInTheDocument();
  });

  it("clamps future reports and formats compass and delay semantics", () => {
    expect(formatRelativeTime("2026-07-10T12:01:00Z", Date.parse("2026-07-10T12:00:00Z"))).toBe("now");
    expect(compassPoint(0)).toBe("N");
    expect(compassPoint(32)).toBe("NNE");
    expect(compassPoint(225)).toBe("SW");
    expect(formatDelay(-90)).toBe("2 min early");
    expect(formatDelay(0)).toBe("On time");
    expect(formatDelay(120)).toBe("+2 min");
  });

  it("does not present an unrelated vehicle metric as distance from the selected station", () => {
    const vehicle = mapNearbyVehicle({
      id: "SKY:Vehicle:test",
      lineCode: null,
      destination: null,
      state: "live",
      latitude: 61.45,
      longitude: 5.85,
      bearing: null,
      delaySeconds: null,
      distanceMeters: 350,
      lastSeenAt: "2026-07-10T12:00:00Z",
      version: "2026-07-10T12:00:00Z",
    });

    expect(vehicle.relation).toBe("within the station search area");
  });
});

describe("Norwegian-friendly fixture search", () => {
  const results: readonly SearchResult[] = [
    { type: "station", id: "NSR:StopPlace:1", label: "Førde rutebilstasjon", secondaryText: "Sunnfjord", stationId: "NSR:StopPlace:1", lineCode: null, latitude: 61.45, longitude: 5.85 },
    { type: "place", id: "place-oslo", label: "Oslo", secondaryText: "Oslo", stationId: null, lineCode: null, latitude: 59.91, longitude: 10.75 },
  ];

  it("folds Norwegian letters and supports prefix and mild typo matches", () => {
    expect(normalizeSearchText("FØRDE")).toBe("forde");
    expect(rankFixtureSearch(results, "Forde")[0]?.label).toBe("Førde rutebilstasjon");
    expect(rankFixtureSearch(results, "Fo")[0]?.label).toBe("Førde rutebilstasjon");
    expect(rankFixtureSearch(results, "Frode")[0]?.label).toBe("Førde rutebilstasjon");
  });
});

describe("realtime journey truth", () => {
  it("advances upcoming calls from compact movement events", () => {
    const sandane = line100Vehicle.journey?.calls[2];
    expect(sandane).toBeDefined();
    const next = toVehicleEventState({
      ...line100Vehicle,
      destination: "Nordfjordeid",
      distanceMeters: null,
      version: "2026-07-10T18:43:24.000Z",
      monitoredCall: { stopPointRef: sandane!.quayId, order: sandane!.order, vehicleAtStop: false },
      nextStop: sandane!,
    }, null, line100Vehicle);

    expect(next.upcomingStops.map(({ name }) => name)).toEqual(["Sandane rutebilstasjon", "Nordfjordeid"]);
  });

  it("clears the prior journey when the source removes its reference", () => {
    const next = toVehicleEventState({
      ...line100Vehicle,
      destination: "Nordfjordeid",
      distanceMeters: null,
      version: "2026-07-10T18:43:24.000Z",
      journeyReference: null,
      monitoredCall: null,
      nextStop: null,
      journeyVersion: null,
      routeProgress: null,
    }, null, line100Vehicle);

    expect(next.journey).toBeNull();
    expect(next.upcomingStops).toEqual([]);
  });
});
