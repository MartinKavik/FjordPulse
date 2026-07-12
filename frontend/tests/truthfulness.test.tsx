import { cleanup, render, screen } from "@solidjs/testing-library";
import type { Component, JSX } from "solid-js";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ClockProvider } from "../src/state/clock";
import { I18nProvider } from "../src/state/i18n";
import { FocusPill } from "../src/components/DesignSystem";
import { compassPoint, formatDelay, formatRelativeTime } from "../src/utils/format";
import { normalizeSearchText, rankFixtureSearch } from "../src/utils/search";
import type { SearchResult } from "../src/types/domain";
import { line100Vehicle } from "../src/fixtures/scenarios";
import { mapNearbyVehicle, toVehicleEventState } from "../src/types/validators";

const EnglishWrapper: Component<{ readonly children: JSX.Element }> = (props) => (
  <I18nProvider initialLanguage="en">{props.children}</I18nProvider>
);

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe("truthful live formatting", () => {
  it("advances relative ages from one shared reactive clock", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-07-10T12:00:06Z"));
    render(
      () => <ClockProvider><FocusPill line="100" passengerServiceState="passenger" lastSeenAt="2026-07-10T12:00:00Z" paused={false} onPause={() => undefined} onResume={() => undefined} onUnfocus={() => undefined} /></ClockProvider>,
      { wrapper: EnglishWrapper },
    );
    expect(screen.getByRole("status")).toHaveTextContent("Last seen 6s ago");
    await vi.advanceTimersByTimeAsync(2_000);
    expect(screen.getByRole("status")).toHaveTextContent("Last seen 8s ago");
  });

  it("follows a non-passenger vehicle without presenting an operational line as public service", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-07-10T12:00:06Z"));
    render(
      () => <ClockProvider><FocusPill line="4" passengerServiceState="non_passenger" lastSeenAt="2026-07-10T12:00:00Z" paused={false} onPause={() => undefined} onResume={() => undefined} onUnfocus={() => undefined} /></ClockProvider>,
      { wrapper: EnglishWrapper },
    );

    const status = screen.getByRole("status");
    expect(status).toHaveTextContent("Following vehicle");
    expect(status).toHaveTextContent("Not in passenger service · Last seen 6s ago");
    expect(status).not.toHaveTextContent("Following Line 4");
    await vi.advanceTimersByTimeAsync(2_000);
    expect(status).toHaveTextContent("Last seen 8s ago");
  });

  it("clamps future reports and formats compass and delay semantics", () => {
    const now = Date.parse("2026-07-10T12:00:00Z");
    expect(formatRelativeTime("2026-07-10T12:01:00Z", now)).toBe("nå");
    expect(formatRelativeTime("2026-07-10T12:01:00Z", now, "en")).toBe("now");
    expect(compassPoint(0)).toBe("N");
    expect(compassPoint(32)).toBe("NNØ");
    expect(compassPoint(32, "en")).toBe("NNE");
    expect(compassPoint(225)).toBe("SV");
    expect(compassPoint(225, "en")).toBe("SW");
    expect(formatDelay(-90)).toBe("2 min før tiden");
    expect(formatDelay(-90, "en")).toBe("2 min early");
    expect(formatDelay(0)).toBe("I rute");
    expect(formatDelay(0, "en")).toBe("On time");
    expect(formatDelay(120)).toBe("+2 min");
  });

  it("does not present an unrelated vehicle metric as distance from the selected station", () => {
    const vehicle = mapNearbyVehicle({
      id: "SKY:Vehicle:test",
      transportMode: "unknown",
      passengerServiceState: "unknown",
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

  it("does not expose an operational destination as a nearby passenger relation", () => {
    const vehicle = mapNearbyVehicle({
      id: "3350447622",
      transportMode: "bus",
      passengerServiceState: "non_passenger",
      lineCode: "4",
      destination: "skyss.no",
      state: "live",
      latitude: 60.48,
      longitude: 5.38,
      bearing: null,
      delaySeconds: 1_029,
      distanceMeters: 120,
      lastSeenAt: "2026-07-12T01:55:09Z",
      version: "2026-07-12T01:55:09Z",
    });

    expect(vehicle.relation).toBe("within the station search area");
    expect(vehicle.relation).not.toContain("skyss.no");
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
