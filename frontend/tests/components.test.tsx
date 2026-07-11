import { cleanup, fireEvent, render, screen } from "@solidjs/testing-library";
import { createSignal, type Component } from "solid-js";
import { afterEach, describe, expect, it, vi } from "vitest";
import { freshStationSnapshot, line100Vehicle } from "../src/fixtures/scenarios";
import { DepartureRow, StatusChip, TelemetryStrip } from "../src/components/DesignSystem";
import { SearchOverlay } from "../src/components/AppChrome";
import { StationPanel, VehiclePanel, WelcomePanel } from "../src/components/Panels";
import { BasemapLayerPicker, buildTransportData, compactClusterCount, installTransportOverlays, MapStatusOverlay, SELECTED_RESOURCE_MIN_ZOOM, selectionCameraTransition } from "../src/components/MapCanvas";
import { defaultWelcomePanelExpanded, readWelcomePanelPreference, rememberWelcomePanelPreference, WELCOME_PANEL_STORAGE_KEY } from "../src/state/welcomePanel";

const basemaps = [
  { id: "satellite" as const, label: "Satellite", styleUrl: "https://api.maptiler.com/maps/hybrid-v4/style.json?key=test-key" },
  { id: "streets" as const, label: "Map", styleUrl: "https://api.maptiler.com/maps/streets-v4/style.json?key=test-key" },
];

afterEach(() => cleanup());

describe("design-system components", () => {
  it("communicates status with text in addition to color", () => {
    render(() => <StatusChip state="delayed" label="Live delayed" />);
    expect(screen.getByRole("status")).toHaveTextContent("Live delayed");
    expect(screen.getByRole("status")).toHaveAttribute("data-state", "delayed");
  });

  it("formats transport times in Europe/Oslo", () => {
    render(() => <DepartureRow departure={freshStationSnapshot.departures[0]!} />);
    expect(screen.getByText("20:45")).toBeInTheDocument();
    expect(screen.getByText("+2 min")).toBeInTheDocument();
  });

  it("renders canonical telemetry including polling fallback", () => {
    render(() => <TelemetryStrip telemetry={{ backend: "ok", realtime: "offline", entur: "ok", liveQueryBridge: "offline", refreshMode: "polling", lastUpdateAt: null }} />);
    expect(screen.getByLabelText("System telemetry")).toHaveTextContent("polling");
    expect(screen.getByLabelText("System telemetry")).toHaveTextContent("offline");
    expect(screen.getByLabelText("System telemetry")).toHaveTextContent("Awaiting data");
  });

  it("presents healthy lazy startup as ready, standby, and on demand", () => {
    render(() => <TelemetryStrip telemetry={{ backend: "ok", realtime: "idle", entur: "idle", liveQueryBridge: "connected", refreshMode: "realtime", lastUpdateAt: null }} />);
    const strip = screen.getByLabelText("System telemetry");
    expect(strip).toHaveTextContent("Backendok");
    expect(strip).toHaveTextContent("Realtimeready");
    expect(strip).toHaveTextContent("Enturstandby");
    expect(strip).toHaveTextContent("Refreshon demand");
    expect(strip).toHaveTextContent("Last updateAwaiting data");
  });

  it("presents the pre-health backend state neutrally", () => {
    render(() => <TelemetryStrip telemetry={{ backend: "checking", realtime: "idle", entur: "idle", liveQueryBridge: "offline", refreshMode: "realtime", lastUpdateAt: null }} />);
    const strip = screen.getByLabelText("System telemetry");
    expect(strip).toHaveTextContent("Backendchecking…");
    expect(strip).not.toHaveTextContent("degraded");
  });
});

describe("public interaction components", () => {
  it("describes rider outcomes instead of internal loading strategy", () => {
    render(() => <WelcomePanel expanded onExpandedChange={() => undefined} />);
    const welcome = screen.getByLabelText("Welcome");
    expect(welcome).toHaveTextContent("Find a station, see upcoming departures, and follow a vehicle along its route.");
    expect(welcome).toHaveTextContent("Find your station");
    expect(welcome).toHaveTextContent("Live departures");
    expect(welcome).toHaveTextContent("Follow a vehicle");
    expect(welcome).not.toHaveTextContent(/loading every bus|clusters|on demand|high-priority watch/i);
  });

  it("collapses and restores the welcome panel with keyboard focus preserved", async () => {
    const Harness: Component = () => {
      const [expanded, setExpanded] = createSignal(true);
      return <WelcomePanel expanded={expanded()} onExpandedChange={setExpanded} />;
    };
    render(() => <Harness />);

    const collapse = screen.getByRole("button", { name: "Hide FjordPulse introduction" });
    expect(collapse).toHaveAttribute("aria-expanded", "true");
    await fireEvent.click(collapse);
    await Promise.resolve();

    expect(screen.queryByLabelText("Welcome")).not.toBeInTheDocument();
    const restore = screen.getByRole("button", { name: "Show FjordPulse introduction" });
    expect(restore).toHaveTextContent("About");
    expect(restore).toHaveAttribute("aria-expanded", "false");
    expect(restore).toHaveFocus();

    await fireEvent.click(restore);
    await Promise.resolve();
    expect(screen.getByLabelText("Welcome")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Hide FjordPulse introduction" })).toHaveFocus();
  });

  it("defaults by viewport and safely persists only explicit welcome choices", () => {
    const values = new Map<string, string>();
    const storage = {
      getItem: (key: string) => values.get(key) ?? null,
      setItem: (key: string, value: string) => { values.set(key, value); },
    };

    expect(defaultWelcomePanelExpanded(null, false)).toBe(true);
    expect(defaultWelcomePanelExpanded(null, true)).toBe(false);
    rememberWelcomePanelPreference(false, storage);
    expect(values.get(WELCOME_PANEL_STORAGE_KEY)).toBe("collapsed");
    expect(readWelcomePanelPreference(storage)).toBe(false);
    expect(defaultWelcomePanelExpanded(false, false)).toBe(false);
    rememberWelcomePanelPreference(true, storage);
    expect(readWelcomePanelPreference(storage)).toBe(true);
    expect(defaultWelcomePanelExpanded(true, true)).toBe(true);

    const blockedStorage = {
      getItem: () => { throw new DOMException("blocked"); },
      setItem: () => { throw new DOMException("blocked"); },
    };
    expect(readWelcomePanelPreference(blockedStorage)).toBeNull();
    expect(() => rememberWelcomePanelPreference(false, blockedStorage)).not.toThrow();
  });

  it("opens an accessible basemap picker, selects a layer, and restores focus", async () => {
    const select = vi.fn();
    render(() => <BasemapLayerPicker basemaps={basemaps} selected="satellite" loading={false} onSelect={select} />);
    const trigger = screen.getByRole("button", { name: "Map layers" });
    await fireEvent.click(trigger);
    expect(trigger).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("radio", { name: /Satellite/ })).toHaveAttribute("aria-checked", "true");
    await fireEvent.click(screen.getByRole("radio", { name: /^Map/ }));
    expect(select).toHaveBeenCalledWith("streets");
    await Promise.resolve();
    expect(trigger).toHaveFocus();

    await fireEvent.click(trigger);
    await fireEvent.keyDown(document, { key: "Escape" });
    expect(trigger).toHaveAttribute("aria-expanded", "false");
    await Promise.resolve();
    expect(trigger).toHaveFocus();

    await fireEvent.click(trigger);
    await fireEvent.pointerDown(document.body);
    expect(trigger).toHaveAttribute("aria-expanded", "false");
  });

  it("renders explicit map loading, misconfiguration, and retry states", async () => {
    const retry = vi.fn();
    const { unmount } = render(() => <MapStatusOverlay state="loading" basemap="satellite" errorCode={null} onRetry={retry} />);
    expect(screen.getByText("Loading satellite map…").closest("[role=status]")).toBeInTheDocument();
    unmount();
    render(() => <MapStatusOverlay state="error" basemap="satellite" errorCode="map_provider_misconfigured" onRetry={retry} />);
    expect(screen.getByRole("alert")).toHaveTextContent("You do not need an API key");
    await fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    expect(retry).toHaveBeenCalledOnce();
  });

  it("installs transport overlays exactly once per loaded style", () => {
    const sources = new Set<string>();
    const layers = new Set<string>();
    const host = {
      getSource: (id: string) => sources.has(id) ? {} : undefined,
      addSource: vi.fn((id: string) => { sources.add(id); }),
      getLayer: (id: string) => layers.has(id) ? {} : undefined,
      addLayer: vi.fn((layer: { readonly id: string }, beforeId?: string) => { void beforeId; layers.add(layer.id); }),
      getStyle: () => ({
        version: 8 as const,
        sources: {},
        layers: [
          { id: "provider-road", type: "line" as const, source: "provider" },
          { id: "provider-label", type: "symbol" as const, source: "provider" },
        ],
      }),
    };
    const emptyData = { type: "FeatureCollection" as const, features: [] };
    installTransportOverlays(host, emptyData);
    installTransportOverlays(host, emptyData);
    expect(host.addSource).toHaveBeenCalledTimes(1);
    expect(host.addLayer).toHaveBeenCalledTimes(16);
    const callsById = new Map(host.addLayer.mock.calls.map((call) => [call[0].id, call]));
    for (const layerId of [
      "fjordpulse-journey-route-casing",
      "fjordpulse-journey-route-passed",
      "fjordpulse-journey-route-remaining",
      "fjordpulse-vehicle-trail",
      "fjordpulse-station-clusters",
      "fjordpulse-station-cluster-counts",
      "fjordpulse-station-cluster-hit-targets",
      "fjordpulse-station-points",
    ]) {
      expect(callsById.get(layerId)?.[1]).toBe("provider-label");
    }
    expect(callsById.get("fjordpulse-selected-station")?.[1]).toBeUndefined();
    expect(callsById.get("fjordpulse-selected-station-halo")?.[1]).toBeUndefined();
    expect(callsById.get("fjordpulse-selected-station-label")?.[1]).toBeUndefined();
    const clusterLayer = callsById.get("fjordpulse-station-clusters")?.[0] as { readonly paint?: Record<string, unknown> };
    expect(clusterLayer.paint?.["circle-opacity"]).toBe(0.62);
    const clusterCountLayer = callsById.get("fjordpulse-station-cluster-counts")?.[0] as { readonly layout?: Record<string, unknown> };
    expect(clusterCountLayer.layout?.["text-ignore-placement"]).toBe(true);

    sources.clear();
    layers.clear();
    installTransportOverlays(host, emptyData);
    expect(host.addSource).toHaveBeenCalledTimes(2);
    expect(host.addLayer).toHaveBeenCalledTimes(32);
  });

  it("keeps the authoritative selected station outside the clustered viewport catalog", () => {
    const data = buildTransportData([
      {
        kind: "cluster",
        id: "cluster-forde",
        count: 143,
        latitude: 61.45,
        longitude: 5.86,
        bounds: { minLongitude: 5.5, minLatitude: 61.2, maxLongitude: 6.2, maxLatitude: 61.8 },
      },
    ], freshStationSnapshot.stationId, null, null, true, freshStationSnapshot) as {
      readonly features: readonly { readonly geometry: { readonly coordinates: readonly number[] }; readonly properties: Readonly<Record<string, unknown>> }[];
    };
    const selected = data.features.find(({ properties }) => properties.kind === "selected-station");

    expect(selected?.properties).toMatchObject({
      id: freshStationSnapshot.stationId,
      name: freshStationSnapshot.station.name,
    });
    expect(selected?.geometry.coordinates).toEqual([
      freshStationSnapshot.station.longitude,
      freshStationSnapshot.station.latitude,
    ]);
  });

  it("keeps dense cluster counts compact without hiding small exact counts", () => {
    expect(compactClusterCount(35)).toBe("35");
    expect(compactClusterCount(1_463)).toBe("1.5k");
    expect(compactClusterCount(17_345)).toBe("17k");
  });

  it("preserves useful visible and refreshed selections while revealing overview and off-screen selections", () => {
    expect(selectionCameraTransition(15, true, true)).toBeNull();
    expect(selectionCameraTransition(15, false, false)).toBeNull();
    expect(selectionCameraTransition(3.6, true, false)).toBeNull();
    expect(selectionCameraTransition(3.6, true, true)).toEqual({ zoom: SELECTED_RESOURCE_MIN_ZOOM });
    expect(selectionCameraTransition(8, false, true)).toEqual({ zoom: SELECTED_RESOURCE_MIN_ZOOM });
    expect(selectionCameraTransition(15, false, true)).toEqual({ zoom: 15 });
  });

  it("selects a keyboard-highlighted search result", async () => {
    const select = vi.fn();
    const result = { type: "station" as const, id: "NSR:StopPlace:548", label: "Førde rutebilstasjon", secondaryText: "Station", stationId: "NSR:StopPlace:548", lineCode: null, latitude: 61.45, longitude: 5.85 };
    render(() => <SearchOverlay open query="førde" results={[result]} activeIndex={0} loading={false} onSelect={select} onClose={() => undefined} />);
    await fireEvent.click(screen.getByRole("option"));
    expect(select).toHaveBeenCalledWith(result);
  });

  it("keeps station empty and error states distinct", () => {
    const noop = () => undefined;
    const { unmount } = render(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "empty", departures: [], nearbyVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    expect(screen.getByText("No upcoming departures.")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    unmount();
    render(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "error", message: "Could not load station details.", departures: [], nearbyVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    expect(screen.getByRole("alert")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("explains Reed's completed zero-vehicle result on both station views", async () => {
    const noop = () => undefined;
    render(() => <StationPanel
      snapshot={{
        ...freshStationSnapshot,
        station: { ...freshStationSnapshot.station, id: "NSR:StopPlace:34503", name: "Reed", latitude: 61.737591, longitude: 6.40968 },
        stationId: "NSR:StopPlace:34503",
        nearbyVehicles: [],
      }}
      sheet="none"
      onClose={noop}
      onRetry={noop}
      onVehicle={noop}
      onSheet={noop}
    />);

    expect(screen.getByText("No nearby vehicles reported.").closest("[role=status]")).toHaveAttribute("data-state", "empty");
    await fireEvent.click(screen.getByRole("tab", { name: "Vehicles" }));
    expect(screen.getByText("0 reporting")).toBeInTheDocument();
    expect(screen.getByText("No nearby vehicles reported.").closest("[role=status]")).toHaveTextContent("No live vehicle positions were found within 5 km of this station. The search is complete; check again shortly.");
  });

  it("labels the nearby-vehicle request as loading instead of showing a completed empty state", async () => {
    const noop = () => undefined;
    render(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "loading", departures: [], nearbyVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);

    await fireEvent.click(screen.getByRole("tab", { name: "Vehicles" }));
    expect(screen.getByText("Loading nearby vehicles")).toBeInTheDocument();
    expect(screen.getByText("Checking for current vehicle positions near this station.")).toBeInTheDocument();
    expect(screen.queryByText("No nearby vehicles reported.")).not.toBeInTheDocument();
  });

  it("does not claim a failed or in-progress zero-result refresh is complete", () => {
    const noop = () => undefined;
    const paused = render(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "rate_limited", departures: [], nearbyVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    expect(screen.getByText("Nearby vehicle refresh paused.").closest("[role=status]")).toHaveAttribute("data-state", "unavailable");
    expect(screen.getByText("FjordPulse will retry automatically.", { exact: false })).toBeInTheDocument();
    expect(screen.queryByText("The search is complete", { exact: false })).not.toBeInTheDocument();
    paused.unmount();

    render(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "refreshing", departures: [], nearbyVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    expect(screen.getByText("Refreshing nearby vehicles.")).toBeInTheDocument();
    expect(screen.getByText("Results may appear shortly.", { exact: false })).toBeInTheDocument();
    expect(screen.queryByText("The search is complete", { exact: false })).not.toBeInTheDocument();
  });

  it("exposes Focus, stale, and lost recovery actions", () => {
    const noop = () => undefined;
    const props = { sheet: "none" as const, onClose: noop, onFocus: noop, onPause: noop, onResume: noop, onUnfocus: noop, onStop: noop, onRetry: noop, onSheet: noop };
    const { unmount } = render(() => <VehiclePanel {...props} vehicle={line100Vehicle} focus="none" />);
    expect(screen.getByRole("button", { name: /Focus this vehicle/i })).toBeInTheDocument();
    expect(screen.getByText("Follow this vehicle on the map as its position updates.")).toBeInTheDocument();
    unmount();
    const staleRender = render(() => <VehiclePanel {...props} vehicle={{ ...line100Vehicle, state: "stale" }} focus="paused" />);
    expect(screen.getByRole("button", { name: /Keep watching/i })).toBeInTheDocument();
    staleRender.unmount();
    render(() => <VehiclePanel {...props} vehicle={{ ...line100Vehicle, state: "lost" }} focus="none" />);
    expect(screen.getByRole("button", { name: /Try again/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Stop following/i })).toBeInTheDocument();
  });
});
