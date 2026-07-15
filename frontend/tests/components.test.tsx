import { cleanup, fireEvent, render, screen } from "@solidjs/testing-library";
import { createSignal, type Component, type JSX } from "solid-js";
import { afterEach, describe, expect, it, vi } from "vitest";
import { freshStationSnapshot, line100Vehicle, nonPassengerVehicle } from "../src/fixtures/scenarios";
import { DepartureRow, FjordPulseLogo, FocusPill, StatusChip, VehicleRow } from "../src/components/DesignSystem";
import { NavigationRail, riderUpdateNotice, SearchOverlay, TopBar, UpdateNotice, type RiderUpdateNotice } from "../src/components/AppChrome";
import { moveMobileSheet, StationPanel, VehiclePanel, WelcomePanel } from "../src/components/Panels";
import { BasemapLayerPicker, buildTransportData, compactClusterCount, installTransportOverlays, MapStatusOverlay, SELECTED_RESOURCE_MIN_ZOOM, selectionCameraTransition, vehicleMarkerLabelSide } from "../src/components/MapCanvas";
import { defaultWelcomePanelExpanded, readWelcomePanelPreference, rememberWelcomePanelPreference, WELCOME_PANEL_STORAGE_KEY } from "../src/state/welcomePanel";
import { ClockProvider } from "../src/state/clock";
import { I18nProvider } from "../src/state/i18n";
import { ApiClientError } from "../src/services/httpClient";
import type { MobileSheetState, StationDepartureBoard, Telemetry } from "../src/types/domain";

const basemaps = [
  { id: "satellite" as const, label: "Satellite", styleUrl: "https://api.maptiler.com/maps/hybrid-v4/style.json?key=test-key" },
  { id: "streets" as const, label: "Map", styleUrl: "https://api.maptiler.com/maps/streets-v4/style.json?key=test-key" },
];

const EnglishWrapper: Component<{ readonly children: JSX.Element }> = (props) => (
  <I18nProvider initialLanguage="en">{props.children}</I18nProvider>
);

const NorwegianWrapper: Component<{ readonly children: JSX.Element }> = (props) => (
  <I18nProvider initialLanguage="nb">{props.children}</I18nProvider>
);

function renderEnglish(view: () => JSX.Element) {
  return render(view, { wrapper: EnglishWrapper });
}

function renderNorwegian(view: () => JSX.Element) {
  return render(view, { wrapper: NorwegianWrapper });
}

afterEach(() => cleanup());

describe("design-system components", () => {
  it("uses the shared FjordPulse mountain mark in the header logo", () => {
    renderEnglish(() => <FjordPulseLogo />);
    const logo = screen.getByRole("link", { name: "FjordPulse home" });
    expect(logo.querySelector("img.brand-mark")).toHaveAttribute("src", "/fjordpulse-mark.svg");
  });

  it("communicates status with text in addition to color", () => {
    renderEnglish(() => <StatusChip state="delayed" label="Live delayed" />);
    expect(screen.getByRole("status")).toHaveTextContent("Live delayed");
    expect(screen.getByRole("status")).toHaveAttribute("data-state", "delayed");
  });

  it("formats transport times in Europe/Oslo", () => {
    renderEnglish(() => <DepartureRow departure={freshStationSnapshot.departures[0]!} />);
    expect(screen.getByText("20:45")).toBeInTheDocument();
    expect(screen.getByText("+2 min")).toBeInTheDocument();
    expect(screen.getByText("Platform 1")).toBeInTheDocument();
  });

  it("derives one rider-facing exception with explicit priority", () => {
    const healthy: Telemetry = { backend: "ok", realtime: "connected", entur: "ok", liveQueryBridge: "connected", refreshMode: "realtime", lastUpdateAt: null };
    expect(riderUpdateNotice(healthy, false)).toBeNull();
    expect(riderUpdateNotice(healthy, true)).toBeNull();
    expect(riderUpdateNotice({ ...healthy, realtime: "connecting" }, true)).toBeNull();
    expect(riderUpdateNotice({ ...healthy, realtime: "reconnecting" }, true)).toBe("reconnecting");
    expect(riderUpdateNotice({ ...healthy, realtime: "offline", refreshMode: "polling" }, true)).toBe("polling");
    expect(riderUpdateNotice({ ...healthy, backend: "degraded", realtime: "offline", refreshMode: "polling" }, true)).toBe("unavailable");
  });

  it("renders exact, accessible update language", () => {
    const { unmount } = renderEnglish(() => <UpdateNotice notice="reconnecting" />);
    expect(screen.getByRole("status", { name: "Update status" })).toHaveTextContent("Reconnecting to live updates…");
    unmount();
    const polling = renderEnglish(() => <UpdateNotice notice="polling" />);
    expect(screen.getByRole("status", { name: "Update status" })).toHaveTextContent("Live connection interrupted · Updating periodically");
    polling.unmount();
    renderEnglish(() => <UpdateNotice notice="unavailable" />);
    expect(screen.getByRole("status", { name: "Update status" })).toHaveTextContent("Updates temporarily unavailable · Showing saved information");
  });

  it("ignores a stale unsupported notice value instead of crashing station rendering", () => {
    expect(() => renderEnglish(() => <UpdateNotice notice={"connecting" as RiderUpdateNotice} />)).not.toThrow();
    expect(screen.queryByRole("status", { name: "Update status" })).not.toBeInTheDocument();
  });

  it("safely removes an update notice while station state is changing", async () => {
    const [notice, setNotice] = createSignal<RiderUpdateNotice | null>(null);
    renderEnglish(() => (
      <TopBar
        query=""
        searchOpen={false}
        updateNotice={notice()}
        onQuery={() => undefined}
        onSearchFocus={() => undefined}
        onSearchKeyDown={() => undefined}
      />
    ));

    setNotice("polling");
    await Promise.resolve();
    expect(screen.getByRole("status", { name: "Update status" })).toHaveTextContent("Updating periodically");

    expect(() => setNotice(null)).not.toThrow();
    await Promise.resolve();
    expect(screen.queryByRole("status", { name: "Update status" })).not.toBeInTheDocument();
  });

  it("keeps the administration destination in the localized public navigation", () => {
    const onSearch = vi.fn();
    const { unmount } = renderEnglish(() => <NavigationRail onSearch={onSearch} />);
    const englishNavigation = screen.getByRole("navigation", { name: "Main navigation" });
    expect(englishNavigation.getElementsByClassName("mobile-navigation-only")).toHaveLength(1);
    expect(screen.getByRole("link", { name: "Admin" })).toHaveAttribute("href", "/admin/status");
    fireEvent.click(screen.getByRole("link", { name: "Search" }));
    expect(onSearch).toHaveBeenCalledOnce();

    unmount();
    renderNorwegian(() => <NavigationRail onSearch={() => undefined} />);
    expect(screen.getByRole("navigation", { name: "Hovedmeny" })).toContainElement(screen.getByRole("link", { name: "Admin" }));
  });
});

describe("mobile detail sheet controls", () => {
  it("moves between adjacent and distant snap points without crossing boundaries", () => {
    expect(moveMobileSheet("peek", "down")).toBe("peek");
    expect(moveMobileSheet("peek", "up")).toBe("half");
    expect(moveMobileSheet("half", "down")).toBe("peek");
    expect(moveMobileSheet("half", "up")).toBe("full");
    expect(moveMobileSheet("full", "down")).toBe("half");
    expect(moveMobileSheet("full", "up")).toBe("full");
    expect(moveMobileSheet("peek", "up", 2)).toBe("full");
    expect(moveMobileSheet("full", "down", 2)).toBe("peek");
    expect(moveMobileSheet("none", "down")).toBe("peek");
    expect(moveMobileSheet("none", "up")).toBe("full");
  });

  it("taps a station handle through peek, half, and full labels", async () => {
    const noop = () => undefined;
    const Harness: Component = () => {
      const [sheet, setSheet] = createSignal<MobileSheetState>("peek");
      return (
        <StationPanel
          snapshot={freshStationSnapshot}
          sheet={sheet()}
          onClose={noop}
          onRetry={noop}
          onVehicle={noop}
          onSheet={setSheet}
        />
      );
    };

    renderEnglish(() => <Harness />);
    const panel = screen.getByRole("complementary", { name: /station details/ });
    expect(panel).toHaveAttribute("data-sheet-state", "peek");

    await fireEvent.click(screen.getByRole("button", { name: "Show station sheet" }));
    expect(panel).toHaveAttribute("data-sheet-state", "half");
    await fireEvent.click(screen.getByRole("button", { name: "Expand station sheet" }));
    expect(panel).toHaveAttribute("data-sheet-state", "full");
    await fireEvent.click(screen.getByRole("button", { name: "Collapse station sheet and show more of the map" }));
    expect(panel).toHaveAttribute("data-sheet-state", "half");
  });

  it("taps a vehicle handle through peek, half, and full without closing the selection", async () => {
    const close = vi.fn();
    const noop = () => undefined;
    const Harness: Component = () => {
      const [sheet, setSheet] = createSignal<MobileSheetState>("peek");
      return (
        <VehiclePanel
          vehicle={line100Vehicle}
          focus="following"
          sheet={sheet()}
          onClose={close}
          onFocus={noop}
          onPause={noop}
          onResume={noop}
          onUnfocus={noop}
          onStop={noop}
          onRetry={noop}
          onSheet={setSheet}
        />
      );
    };

    renderEnglish(() => <Harness />);
    const panel = screen.getByRole("complementary", { name: /details on Line 100/ });
    expect(panel).toHaveAttribute("data-sheet-state", "peek");

    await fireEvent.click(screen.getByRole("button", { name: "Show vehicle sheet" }));
    expect(panel).toHaveAttribute("data-sheet-state", "half");
    await fireEvent.click(screen.getByRole("button", { name: "Expand vehicle sheet" }));
    expect(panel).toHaveAttribute("data-sheet-state", "full");
    await fireEvent.click(screen.getByRole("button", { name: "Collapse vehicle sheet and show more of the map" }));
    expect(panel).toHaveAttribute("data-sheet-state", "half");
    expect(close).not.toHaveBeenCalled();
  });

  it("supports Arrow, Home, and End keyboard snap controls", async () => {
    const noop = () => undefined;
    const Harness: Component = () => {
      const [sheet, setSheet] = createSignal<MobileSheetState>("half");
      return <StationPanel snapshot={freshStationSnapshot} sheet={sheet()} onClose={noop} onRetry={noop} onVehicle={noop} onSheet={setSheet} />;
    };

    renderEnglish(() => <Harness />);
    const panel = screen.getByRole("complementary", { name: /station details/ });
    const grabber = () => panel.querySelector<HTMLButtonElement>(".sheet-grabber")!;

    await fireEvent.keyDown(grabber(), { key: "ArrowDown" });
    expect(panel).toHaveAttribute("data-sheet-state", "peek");
    await fireEvent.keyDown(grabber(), { key: "ArrowUp" });
    expect(panel).toHaveAttribute("data-sheet-state", "half");
    await fireEvent.keyDown(grabber(), { key: "End" });
    expect(panel).toHaveAttribute("data-sheet-state", "full");
    await fireEvent.keyDown(grabber(), { key: "ArrowUp" });
    expect(panel).toHaveAttribute("data-sheet-state", "full");
    await fireEvent.keyDown(grabber(), { key: "Home" });
    expect(panel).toHaveAttribute("data-sheet-state", "peek");
    await fireEvent.keyDown(grabber(), { key: "ArrowDown" });
    expect(panel).toHaveAttribute("data-sheet-state", "peek");
  });

  it("suppresses the synthetic click after a pointer drag", async () => {
    const transitions: MobileSheetState[] = [];
    const noop = () => undefined;
    const Harness: Component = () => {
      const [sheet, setSheet] = createSignal<MobileSheetState>("half");
      const changeSheet = (next: Exclude<MobileSheetState, "none">) => {
        transitions.push(next);
        setSheet(next);
      };
      return <VehiclePanel vehicle={line100Vehicle} focus="following" sheet={sheet()} onClose={noop} onFocus={noop} onPause={noop} onResume={noop} onUnfocus={noop} onStop={noop} onRetry={noop} onSheet={changeSheet} />;
    };

    renderEnglish(() => <Harness />);
    const panel = screen.getByRole("complementary", { name: /details on Line 100/ });
    const grabber = panel.querySelector<HTMLButtonElement>(".sheet-grabber")!;

    fireEvent.pointerDown(grabber, { pointerId: 1, pointerType: "touch", isPrimary: true, clientY: 300 });
    fireEvent.pointerMove(grabber, { pointerId: 1, pointerType: "touch", isPrimary: true, clientY: 210 });
    fireEvent.pointerUp(grabber, { pointerId: 1, pointerType: "touch", isPrimary: true, clientY: 210 });
    fireEvent.click(grabber);

    expect(panel).toHaveAttribute("data-sheet-state", "full");
    expect(transitions).toEqual(["full"]);

    await new Promise<void>((resolve) => window.setTimeout(resolve, 0));
    await fireEvent.click(grabber);
    expect(panel).toHaveAttribute("data-sheet-state", "half");
    expect(transitions).toEqual(["full", "half"]);
  });
});

describe("public interaction components", () => {
  it("describes rider outcomes instead of internal loading strategy", () => {
    renderEnglish(() => <WelcomePanel expanded onExpandedChange={() => undefined} />);
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
    renderEnglish(() => <Harness />);

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
    renderEnglish(() => <BasemapLayerPicker basemaps={basemaps} selected="satellite" loading={false} onSelect={select} />);
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
    const { unmount } = renderEnglish(() => <MapStatusOverlay state="loading" basemap="satellite" errorCode={null} onRetry={retry} />);
    expect(screen.getByText("Loading satellite map…").closest("[role=status]")).toBeInTheDocument();
    unmount();
    renderEnglish(() => <MapStatusOverlay state="error" basemap="satellite" errorCode="map_provider_misconfigured" onRetry={retry} />);
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
    expect(host.addLayer).toHaveBeenCalledTimes(15);
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
    expect(callsById.has("fjordpulse-vehicle-label")).toBe(false);
    const clusterLayer = callsById.get("fjordpulse-station-clusters")?.[0] as { readonly paint?: Record<string, unknown> };
    expect(clusterLayer.paint?.["circle-opacity"]).toBe(0.62);
    const clusterCountLayer = callsById.get("fjordpulse-station-cluster-counts")?.[0] as { readonly layout?: Record<string, unknown> };
    expect(clusterCountLayer.layout?.["text-ignore-placement"]).toBe(true);

    sources.clear();
    layers.clear();
    installTransportOverlays(host, emptyData);
    expect(host.addSource).toHaveBeenCalledTimes(2);
    expect(host.addLayer).toHaveBeenCalledTimes(30);
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

  it("places the selected vehicle label on the side with usable screen space", () => {
    expect(vehicleMarkerLabelSide(195, 390)).toBe("right");
    expect(vehicleMarkerLabelSide(250, 390)).toBe("left");
    expect(vehicleMarkerLabelSide(720, 1_440)).toBe("right");
    expect(vehicleMarkerLabelSide(Number.NaN, 1_440)).toBe("right");
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
    renderEnglish(() => <SearchOverlay open query="førde" results={[result]} activeIndex={0} loading={false} onSelect={select} onClose={() => undefined} />);
    await fireEvent.click(screen.getByRole("option"));
    expect(select).toHaveBeenCalledWith(result);
  });

  it("distinguishes the search pause, minimum query, and query-specific empty states", () => {
    const noop = () => undefined;
    const staleResult = { type: "station" as const, id: "NSR:StopPlace:548", label: "Førde rutebilstasjon", secondaryText: "Station", stationId: "NSR:StopPlace:548", lineCode: null, latitude: 61.45, longitude: 5.85 };
    const waiting = renderEnglish(() => <SearchOverlay open query="Forde" results={[staleResult]} activeIndex={0} waiting loading={false} onSelect={noop} onClose={noop} />);

    expect(screen.getByRole("status")).toHaveTextContent("Search starts after a short pause…");
    expect(screen.queryByRole("option")).not.toBeInTheDocument();
    expect(screen.queryByText(/No results for/)).not.toBeInTheDocument();
    waiting.unmount();

    const tooShort = renderEnglish(() => <SearchOverlay open query=" F " results={[]} activeIndex={0} loading={false} onSelect={noop} onClose={noop} />);
    expect(screen.getByText("Type at least two characters to search.")).toBeInTheDocument();
    expect(screen.queryByText(/No results for/)).not.toBeInTheDocument();
    tooShort.unmount();

    renderEnglish(() => <SearchOverlay open query=" Forde " results={[]} activeIndex={0} loading={false} onSelect={noop} onClose={noop} />);
    expect(screen.getByText("No results for “Forde”.")).toBeInTheDocument();
  });

  it("identifies authoritative vehicle modes in lists, search, and details", () => {
    const noop = () => undefined;
    const ferry = { ...freshStationSnapshot.nearbyVehicles[0]!, transportMode: "ferry" as const };
    const row = renderEnglish(() => <VehicleRow vehicle={ferry} onSelect={noop} />);
    expect(screen.getByRole("button", { name: /Open Ferry on Line FB59/i })).toHaveTextContent("Ferry");
    row.unmount();

    const trainResult = {
      type: "vehicle" as const,
      id: "VYG:Vehicle:42",
      label: "Vehicle VYG:Vehicle:42",
      secondaryText: "Line F4 · Bergen",
      stationId: null,
      lineCode: "F4",
      latitude: 60.39,
      longitude: 5.32,
      transportMode: "rail" as const,
    };
    const search = renderEnglish(() => <SearchOverlay open query="F4" results={[trainResult]} activeIndex={0} loading={false} onSelect={noop} onClose={noop} />);
    expect(screen.getByRole("option")).toHaveTextContent("Train VYG:Vehicle:42");
    expect(screen.getByRole("option")).toHaveTextContent("Train");
    search.unmount();

    const props = { sheet: "none" as const, onClose: noop, onFocus: noop, onPause: noop, onResume: noop, onUnfocus: noop, onStop: noop, onRetry: noop, onSheet: noop };
    renderEnglish(() => <VehiclePanel {...props} vehicle={{ ...line100Vehicle, transportMode: "tram" }} focus="none" />);
    expect(screen.getByLabelText("Tram details on Line 100")).toHaveTextContent(`Tram · ${line100Vehicle.id}`);
  });

  it("presents a reporting non-passenger vehicle without passenger-service claims", () => {
    const noop = () => undefined;
    const props = { sheet: "none" as const, onClose: noop, onFocus: noop, onPause: noop, onResume: noop, onUnfocus: noop, onStop: noop, onRetry: noop, onSheet: noop };
    const english = renderEnglish(() => <VehiclePanel {...props} vehicle={nonPassengerVehicle} focus="none" />);

    const panel = screen.getByLabelText("Bus details, not in passenger service");
    expect(screen.getByRole("heading", { name: "Not in passenger service" })).toBeInTheDocument();
    expect(panel).toHaveTextContent("Position is still updating");
    expect(panel).toHaveTextContent("Position statusLive");
    expect(panel).toHaveTextContent("Last seen");
    expect(panel).not.toHaveTextContent("Line 4");
    expect(panel).not.toHaveTextContent("Flaktveit - Hesjaholtet");
    expect(panel).not.toHaveTextContent("+18 min");
    expect(panel).not.toHaveTextContent("Delay");
    expect(panel).not.toHaveTextContent("Next stop");
    expect(panel).not.toHaveTextContent("Previous stop");
    expect(panel).not.toHaveTextContent("Journey progress");
    expect(panel).not.toHaveTextContent("Entur did not return");
    expect(screen.getByRole("heading", { name: "No active passenger journey" })).toBeInTheDocument();
    expect(screen.getByRole("status", { name: "Passenger service status" })).toHaveTextContent("It may be travelling to or from a depot, or between services.");
    expect(screen.getByRole("button", { name: "Focus this vehicle" })).toBeInTheDocument();
    english.unmount();

    const row = renderEnglish(() => <VehicleRow vehicle={{ ...freshStationSnapshot.nearbyVehicles[0]!, passengerServiceState: "non_passenger", lineCode: "4", relation: "towards skyss.no" }} onSelect={noop} />);
    const nonPassengerRow = screen.getByRole("button", { name: /Open Bus, not in passenger service/ });
    expect(nonPassengerRow).toHaveTextContent("Bus · Not in passenger service");
    expect(nonPassengerRow.querySelector(".line-badge")).not.toBeInTheDocument();
    expect(nonPassengerRow).not.toHaveAccessibleName(/Line 4/);
    expect(nonPassengerRow).not.toHaveAccessibleName(/skyss\.no/);
    row.unmount();

    const norwegianSearch = renderNorwegian(() => <SearchOverlay
      open
      query="3350447622"
      results={[{
        type: "vehicle",
        id: "3350447622",
        label: "Vehicle 3350447622",
        secondaryText: "Not in passenger service",
        stationId: null,
        lineCode: null,
        latitude: 60.48,
        longitude: 5.38,
        transportMode: "bus",
      }]}
      activeIndex={0}
      loading={false}
      onSelect={noop}
      onClose={noop}
    />);
    expect(screen.getByRole("option")).toHaveTextContent("Ikke i passasjertrafikk");
    expect(screen.getByRole("option")).not.toHaveTextContent("Not in passenger service");
    norwegianSearch.unmount();

    const stale = renderEnglish(() => <VehiclePanel {...props} vehicle={{ ...nonPassengerVehicle, state: "stale" }} focus="paused" />);
    expect(screen.getByLabelText("Bus details, not in passenger service")).toHaveTextContent("Last known position is shown");
    expect(screen.getByLabelText("Bus details, not in passenger service")).not.toHaveTextContent("Position is still updating");
    stale.unmount();

    renderNorwegian(() => <VehiclePanel {...props} vehicle={nonPassengerVehicle} focus="none" />);
    const norwegianPanel = screen.getByLabelText("Detaljer for buss utenfor passasjertrafikk");
    expect(screen.getByRole("heading", { name: "Ikke i passasjertrafikk" })).toBeInTheDocument();
    expect(norwegianPanel).toHaveTextContent("Posisjonen oppdateres fortsatt");
    expect(norwegianPanel).toHaveTextContent("PosisjonsstatusSanntid");
    expect(screen.getByRole("heading", { name: "Ingen aktiv passasjerreise" })).toBeInTheDocument();
    expect(screen.getByRole("status", { name: "Status for passasjertrafikk" })).toHaveTextContent("Det kan være på vei til eller fra en garasje, eller mellom avganger.");
  });

  it("keeps the same vehicle focused while passenger details disappear and return", async () => {
    const vehicleId = line100Vehicle.id;
    const [vehicle, setVehicle] = createSignal(line100Vehicle);
    const pause = vi.fn();
    const unfocus = vi.fn();
    const noop = () => undefined;
    const Harness: Component = () => (
      <ClockProvider now={() => Date.parse("2026-07-10T18:42:30Z")}>
        <FocusPill
          line={vehicle().lineCode}
          passengerServiceState={vehicle().passengerServiceState}
          lastSeenAt={vehicle().lastSeenAt}
          paused={false}
          onPause={pause}
          onResume={noop}
          onUnfocus={unfocus}
        />
        <VehiclePanel
          vehicle={vehicle()}
          focus="following"
          sheet="none"
          onClose={noop}
          onFocus={noop}
          onPause={pause}
          onResume={noop}
          onUnfocus={unfocus}
          onStop={noop}
          onRetry={noop}
          onSheet={noop}
        />
      </ClockProvider>
    );

    const view = renderEnglish(() => <Harness />);
    const mountedFocusPill = view.container.querySelector(".focus-pill");
    const mountedPanel = view.container.querySelector(".vehicle-panel");
    expect(mountedFocusPill).toHaveTextContent("Following Line 100");
    expect(mountedPanel).toHaveTextContent(`Bus · ${vehicleId}`);
    expect(mountedPanel).toHaveTextContent("Upcoming stops");
    expect(mountedPanel).toHaveTextContent("Skei");

    setVehicle({
      ...nonPassengerVehicle,
      id: vehicleId,
      version: "2026-07-10T18:43:00.000Z",
      refreshedAt: "2026-07-10T18:43:00Z",
      lastSeenAt: "2026-07-10T18:43:00Z",
    });
    await Promise.resolve();

    expect(view.container.querySelector(".focus-pill")).toBe(mountedFocusPill);
    expect(view.container.querySelector(".vehicle-panel")).toBe(mountedPanel);
    expect(mountedFocusPill).toHaveTextContent("Following vehicle");
    expect(mountedFocusPill).toHaveTextContent("Not in passenger service");
    expect(mountedPanel).toHaveTextContent(`Bus · ${vehicleId}`);
    expect(mountedPanel).toHaveTextContent("No active passenger journey");
    expect(mountedPanel).not.toHaveTextContent("Line 4");
    expect(mountedPanel).not.toHaveTextContent("Delay");
    expect(mountedPanel).not.toHaveTextContent("Upcoming stops");
    expect(screen.getByRole("button", { name: /^Pause follow$/ })).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /^Unfocus$/ })).toHaveLength(2);
    await fireEvent.click(screen.getByRole("button", { name: /^Pause follow$/ }));
    await fireEvent.click(screen.getAllByRole("button", { name: /^Unfocus$/ })[0]!);
    expect(pause).toHaveBeenCalledOnce();
    expect(unfocus).toHaveBeenCalledOnce();

    setVehicle({
      ...line100Vehicle,
      id: vehicleId,
      version: "2026-07-10T18:44:00.000Z",
      refreshedAt: "2026-07-10T18:44:00Z",
      lastSeenAt: "2026-07-10T18:44:00Z",
    });
    await Promise.resolve();

    expect(view.container.querySelector(".focus-pill")).toBe(mountedFocusPill);
    expect(view.container.querySelector(".vehicle-panel")).toBe(mountedPanel);
    expect(mountedFocusPill).toHaveTextContent("Following Line 100");
    expect(mountedFocusPill).not.toHaveTextContent("Not in passenger service");
    expect(mountedPanel).toHaveTextContent(`Bus · ${vehicleId}`);
    expect(mountedPanel).toHaveTextContent("Upcoming stops");
    expect(mountedPanel).toHaveTextContent("Skei");
    expect(mountedPanel).not.toHaveTextContent("No active passenger journey");
    expect(screen.getByRole("button", { name: /^Pause follow$/ })).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /^Unfocus$/ })).toHaveLength(2);
  });

  it("distinguishes a saved journey schedule from one that never resolved", () => {
    const noop = () => undefined;
    const props = { sheet: "none" as const, onClose: noop, onFocus: noop, onPause: noop, onResume: noop, onUnfocus: noop, onStop: noop, onRetry: noop, onSheet: noop };
    const unavailableJourney = {
      ...line100Vehicle.journey!,
      state: "unavailable" as const,
      route: null,
      calls: [],
      lastSuccessfulAt: null,
      warning: "Entur did not return the referenced service journey.",
    };
    const unavailable = renderEnglish(() => <VehiclePanel {...props} vehicle={{ ...line100Vehicle, journey: unavailableJourney, upcomingStops: [] }} focus="none" />);
    expect(screen.getByText("Journey details unavailable")).toBeInTheDocument();
    expect(screen.getByText("FjordPulse cannot load the stops for this journey right now. The vehicle position may still be current.")).toBeInTheDocument();
    expect(screen.queryByText("Journey schedule may be stale")).not.toBeInTheDocument();
    expect(screen.queryByText(/Entur did not return/)).not.toBeInTheDocument();
    unavailable.unmount();

    renderEnglish(() => <VehiclePanel {...props} vehicle={{
      ...line100Vehicle,
      journey: { ...line100Vehicle.journey!, state: "unavailable", warning: "Entur journey request failed." },
    }} focus="none" />);
    expect(screen.getByText("Showing saved journey schedule")).toBeInTheDocument();
    expect(screen.getByText("The journey schedule could not be refreshed and may be out of date.")).toBeInTheDocument();
    expect(screen.queryByText("Journey details unavailable")).not.toBeInTheDocument();
    expect(screen.queryByText(/Entur journey request failed/)).not.toBeInTheDocument();
  });

  it("keeps station empty and error states distinct", () => {
    const noop = () => undefined;
    const { unmount } = renderEnglish(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "empty", departures: [], nearbyVehicles: [], servingVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    expect(screen.getByText("No more departures today.")).toBeInTheDocument();
    expect(screen.getByText("The timetable was checked through midnight in the Europe/Oslo time zone.")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    unmount();
    renderEnglish(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "error", message: "Could not load station details.", departures: [], nearbyVehicles: [], servingVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    expect(screen.getByRole("alert")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("fully localizes partial station-source recovery warnings", () => {
    const noop = () => undefined;
    const message = "Departures could not be refreshed; showing saved departure information. Nearby vehicle positions were refreshed; saved station-serving matches remain until departures reconnect.";
    renderNorwegian(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "stale", message }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);

    expect(screen.getByText("Avganger kunne ikke oppdateres. Viser lagret avgangsinformasjon. Kjøretøyposisjoner i nærheten ble oppdatert. Lagrede koblinger til ruter som stopper her, beholdes til avgangstjenesten er tilkoblet igjen.")).toBeInTheDocument();
    expect(screen.queryByText(/saved station-serving matches/)).not.toBeInTheDocument();
  });

  it("uses resource ages instead of healthy Live badges", () => {
    const noop = () => undefined;
    const station = renderEnglish(() => <StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    expect(screen.getByText(/Data updated /)).toBeInTheDocument();
    expect(screen.queryByText("Live", { exact: true })).not.toBeInTheDocument();
    station.unmount();

    const props = { sheet: "none" as const, onClose: noop, onFocus: noop, onPause: noop, onResume: noop, onUnfocus: noop, onStop: noop, onRetry: noop, onSheet: noop };
    renderEnglish(() => <VehiclePanel {...props} vehicle={line100Vehicle} focus="none" />);
    expect(screen.getByText("Last seen")).toBeInTheDocument();
    expect(screen.queryByText("Live", { exact: true })).not.toBeInTheDocument();
  });

  it("shows the previous journey stop instead of a compass bearing", () => {
    const noop = () => undefined;
    const props = { sheet: "none" as const, onClose: noop, onFocus: noop, onPause: noop, onResume: noop, onUnfocus: noop, onStop: noop, onRetry: noop, onSheet: noop };
    const { unmount } = renderEnglish(() => <VehiclePanel {...props} vehicle={line100Vehicle} focus="none" />);
    expect(screen.getByText("Previous stop").parentElement).toHaveTextContent("Førde rutebilstasjon");
    expect(screen.queryByText("Direction")).not.toBeInTheDocument();
    expect(screen.queryByText("32° NNE")).not.toBeInTheDocument();
    unmount();

    const currentCall = line100Vehicle.journey!.calls[2]!;
    const nextCall = line100Vehicle.journey!.calls[3]!;
    const atStop = renderEnglish(() => <VehiclePanel {...props} vehicle={{
      ...line100Vehicle,
      monitoredCall: { stopPointRef: currentCall.quayId, order: currentCall.order, vehicleAtStop: true },
      nextStop: nextCall,
    }} focus="none" />);
    expect(screen.getByText("Previous stop").parentElement).toHaveTextContent("Skei");
    expect(screen.getByText("Previous stop").parentElement).not.toHaveTextContent("Sandane rutebilstasjon");
    atStop.unmount();

    const cancelledPreviousCalls = line100Vehicle.journey!.calls.map((call, index) => index === 1
      ? { ...call, cancellation: true }
      : call);
    const cancelledPrevious = renderEnglish(() => <VehiclePanel {...props} vehicle={{
      ...line100Vehicle,
      journey: { ...line100Vehicle.journey!, calls: cancelledPreviousCalls },
      monitoredCall: { stopPointRef: currentCall.quayId, order: currentCall.order, vehicleAtStop: true },
      nextStop: nextCall,
    }} focus="none" />);
    expect(screen.getByText("Previous stop").parentElement).toHaveTextContent("Førde rutebilstasjon");
    expect(screen.getByText("Previous stop").parentElement).not.toHaveTextContent("Skei");
    cancelledPrevious.unmount();

    renderEnglish(() => <VehiclePanel {...props} vehicle={{ ...line100Vehicle, journey: null, monitoredCall: null, nextStop: null }} focus="none" />);
    expect(screen.getByText("Previous stop").parentElement).toHaveTextContent("Not available");
  });

  it("keeps Reed's completed vehicle results in the dedicated Vehicles tab", async () => {
    const noop = () => undefined;
    renderEnglish(() => <StationPanel
      snapshot={{
        ...freshStationSnapshot,
        station: { ...freshStationSnapshot.station, id: "NSR:StopPlace:34503", name: "Reed", latitude: 61.737591, longitude: 6.40968 },
        stationId: "NSR:StopPlace:34503",
        nearbyVehicles: [],
        servingVehicles: [],
      }}
      sheet="none"
      onClose={noop}
      onRetry={noop}
      onVehicle={noop}
      onSheet={noop}
    />);

    expect(screen.getByRole("heading", { name: "Next departures" })).toBeInTheDocument();
    expect(screen.getByText("Sandane")).toBeInTheDocument();
    expect(screen.queryByText("Vehicles serving this station")).not.toBeInTheDocument();
    expect(screen.queryByText("No station-serving vehicle reported now.")).not.toBeInTheDocument();
    expect(screen.queryByText("No nearby vehicles reported.")).not.toBeInTheDocument();
    const emptyVehiclesTab = screen.getByRole("tab", { name: "Vehicles" });
    expect(emptyVehiclesTab.querySelector(".tab-count")).not.toBeInTheDocument();
    await fireEvent.click(emptyVehiclesTab);
    expect(screen.getByText("Vehicles connected to this station")).toBeInTheDocument();
    expect(screen.getByText("No station-serving vehicle reported now.")).toBeInTheDocument();
    expect(screen.getByText("No nearby vehicles reported.").closest("[role=status]")).toHaveAttribute("data-state", "empty");
    expect(screen.getByText("No nearby vehicles reported.").closest("[role=status]")).toHaveTextContent("No live vehicle positions were found within 5 km of this station. The search is complete; check again shortly.");
  });

  it("loads and progressively renders today's Oslo timetable with an opaque cursor", async () => {
    const noop = () => undefined;
    const firstDeparture = { ...freshStationSnapshot.departures[0]!, id: "day-earlier", destination: "Earlier bus", aimedDepartureAt: "2026-01-01T23:10:00Z", expectedDepartureAt: "2026-01-01T23:10:00Z" };
    const nextDeparture = { ...freshStationSnapshot.departures[1]!, id: "day-next", destination: "Next bus", aimedDepartureAt: "2026-01-01T23:40:00Z", expectedDepartureAt: "2026-01-01T23:40:00Z" };
    const laterDeparture = { ...freshStationSnapshot.departures[2]!, id: "day-later", destination: "Later bus", aimedDepartureAt: "2026-01-02T00:05:00Z", expectedDepartureAt: "2026-01-02T00:05:00Z" };
    const finalDeparture = { ...freshStationSnapshot.departures[3]!, id: "day-next", destination: "Final bus", aimedDepartureAt: "2026-01-02T02:15:00Z", expectedDepartureAt: "2026-01-02T02:15:00Z" };
    const firstPage: StationDepartureBoard = {
      stationId: freshStationSnapshot.stationId,
      mode: "day",
      date: "2026-01-02",
      timeZone: "Europe/Oslo",
      windowStart: "2026-01-01T23:00:00Z",
      windowEnd: "2026-01-02T23:00:00Z",
      departures: [firstDeparture, nextDeparture, laterDeparture],
      page: { limit: 50, hasMore: true, nextCursor: "opaque-page-2" },
      complete: true,
      totalCount: 4,
    };
    const finalPage: StationDepartureBoard = {
      ...firstPage,
      departures: [finalDeparture],
      page: { limit: 50, hasMore: false, nextCursor: null },
      complete: true,
      totalCount: 4,
    };
    const loadDay = vi.fn(async (_stationId: string, _date: string, _limit: number, cursor: string | null, _signal: AbortSignal): Promise<StationDepartureBoard> => cursor === null ? firstPage : finalPage);

    renderEnglish(() => <ClockProvider now={() => Date.parse("2026-01-01T23:30:00Z")}><StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} onLoadDayDepartures={loadDay} /></ClockProvider>);
    await fireEvent.click(screen.getByRole("button", { name: "View today's timetable" }));

    expect(await screen.findByRole("heading", { name: "Today's timetable" })).toBeInTheDocument();
    expect(loadDay).toHaveBeenNthCalledWith(1, freshStationSnapshot.stationId, "2026-01-02", 50, null, expect.any(AbortSignal), false);
    expect(screen.getByText("Earlier today")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Next" })).toBeInTheDocument();
    expect(screen.getByText("Next bus")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Later today" })).toBeInTheDocument();
    expect(screen.getByText("3 of 4 loaded")).toBeInTheDocument();

    await fireEvent.click(screen.getByRole("button", { name: "Show 50 more" }));
    expect(await screen.findByText("Final bus")).toBeInTheDocument();
    expect(loadDay).toHaveBeenNthCalledWith(2, freshStationSnapshot.stationId, "2026-01-02", 50, "opaque-page-2", expect.any(AbortSignal), false);
    expect(screen.getByText("4 departures today")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Show 50 more" })).not.toBeInTheDocument();
  });

  it("retains loaded timetable rows when a later page fails", async () => {
    const noop = () => undefined;
    const retainedDeparture = { ...freshStationSnapshot.departures[0]!, id: "retained", destination: "Retained departure" };
    const firstPage: StationDepartureBoard = {
      stationId: freshStationSnapshot.stationId,
      mode: "day",
      date: "2026-07-10",
      timeZone: "Europe/Oslo",
      windowStart: "2026-07-09T22:00:00Z",
      windowEnd: "2026-07-10T22:00:00Z",
      departures: [retainedDeparture],
      page: { limit: 50, hasMore: true, nextCursor: "next-page" },
      complete: false,
      totalCount: null,
    };
    let request = 0;
    const loadDay = vi.fn(async (): Promise<StationDepartureBoard> => {
      request += 1;
      if (request === 1) return firstPage;
      throw new Error("temporary failure");
    });

    renderEnglish(() => <ClockProvider now={() => Date.parse("2026-07-10T18:42:30Z")}><StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} onLoadDayDepartures={loadDay} /></ClockProvider>);
    await fireEvent.click(screen.getByRole("button", { name: "View today's timetable" }));
    expect(await screen.findByText("Retained departure")).toBeInTheDocument();
    await fireEvent.click(screen.getByRole("button", { name: "Show 50 more" }));

    expect(await screen.findByText("Could not update today's timetable")).toBeInTheDocument();
    expect(screen.getByText("Retained departure")).toBeInTheDocument();
    expect(screen.getByText("Departures already loaded are retained. Retry to get the rest.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("does not claim departures were retained when the initial daily timetable request fails", async () => {
    const noop = () => undefined;
    const loadDay = vi.fn(async (): Promise<StationDepartureBoard> => {
      throw new Error("temporary failure");
    });

    renderEnglish(() => <ClockProvider now={() => Date.parse("2026-07-10T18:42:30Z")}><StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} onLoadDayDepartures={loadDay} /></ClockProvider>);
    await fireEvent.click(screen.getByRole("button", { name: "View today's timetable" }));

    expect(await screen.findByText("Could not load today's timetable")).toBeInTheDocument();
    expect(screen.getByText("No timetable data was loaded. Try again.")).toBeInTheDocument();
    expect(screen.queryByText(/retained/i)).not.toBeInTheDocument();
    expect(screen.queryByText("Sandane")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("marks an exhausted but incomplete source day as a retained lower bound", async () => {
    const noop = () => undefined;
    const retainedDeparture = { ...freshStationSnapshot.departures[0]!, id: "incomplete-day", destination: "Confirmed departure" };
    const incompleteBoard: StationDepartureBoard = {
      stationId: freshStationSnapshot.stationId,
      mode: "day",
      date: "2026-07-10",
      timeZone: "Europe/Oslo",
      windowStart: "2026-07-09T22:00:00Z",
      windowEnd: "2026-07-10T22:00:00Z",
      departures: [retainedDeparture],
      page: { limit: 50, hasMore: false, nextCursor: null },
      complete: false,
      totalCount: null,
    };
    const loadDay = vi.fn(async (): Promise<StationDepartureBoard> => incompleteBoard);

    renderEnglish(() => <ClockProvider now={() => Date.parse("2026-07-10T18:42:30Z")}><StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} onLoadDayDepartures={loadDay} /></ClockProvider>);
    await fireEvent.click(screen.getByRole("button", { name: "View today's timetable" }));

    expect(await screen.findByText("Timetable may be incomplete")).toBeInTheDocument();
    expect(screen.getByText("At least 1 loaded")).toBeInTheDocument();
    expect(screen.getByText("Confirmed departure")).toBeInTheDocument();
    expect(screen.getByText("The data source could not confirm the whole day. Shown departures are retained, but more may exist.")).toBeInTheDocument();
    await fireEvent.click(screen.getByRole("button", { name: "Retry full timetable" }));
    expect(loadDay).toHaveBeenCalledTimes(2);
    expect(loadDay).toHaveBeenNthCalledWith(2, freshStationSnapshot.stationId, "2026-07-10", 50, null, expect.any(AbortSignal), true);
    expect(screen.getByText("Confirmed departure")).toBeInTheDocument();
  });

  it("does not claim an incomplete empty source day has no departures", async () => {
    const noop = () => undefined;
    const incompleteBoard: StationDepartureBoard = {
      stationId: freshStationSnapshot.stationId,
      mode: "day",
      date: "2026-07-10",
      timeZone: "Europe/Oslo",
      windowStart: "2026-07-09T22:00:00Z",
      windowEnd: "2026-07-10T22:00:00Z",
      departures: [],
      page: { limit: 50, hasMore: false, nextCursor: null },
      complete: false,
      totalCount: null,
    };
    const loadDay = vi.fn(async (): Promise<StationDepartureBoard> => incompleteBoard);

    renderEnglish(() => <ClockProvider now={() => Date.parse("2026-07-10T18:42:30Z")}><StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} onLoadDayDepartures={loadDay} /></ClockProvider>);
    await fireEvent.click(screen.getByRole("button", { name: "View today's timetable" }));

    expect(await screen.findByText("Timetable may be incomplete")).toBeInTheDocument();
    expect(screen.getByText("The data source could not confirm the whole day. No departures were returned, but some may still exist.")).toBeInTheDocument();
    expect(screen.queryByText("No departures on this day.")).not.toBeInTheDocument();
    expect(screen.queryByText("No more departures today.")).not.toBeInTheDocument();
  });

  it("restarts an expired daily cursor without discarding retained rows first", async () => {
    const noop = () => undefined;
    const retainedDeparture = { ...freshStationSnapshot.departures[0]!, id: "cursor-retained", destination: "Retained before restart" };
    const replacementDeparture = { ...freshStationSnapshot.departures[1]!, id: "cursor-replacement", destination: "Fresh first page" };
    const firstPage: StationDepartureBoard = {
      stationId: freshStationSnapshot.stationId,
      mode: "day",
      date: "2026-07-10",
      timeZone: "Europe/Oslo",
      windowStart: "2026-07-09T22:00:00Z",
      windowEnd: "2026-07-10T22:00:00Z",
      departures: [retainedDeparture],
      page: { limit: 50, hasMore: true, nextCursor: "expired-page" },
      complete: true,
      totalCount: 2,
    };
    const replacement: StationDepartureBoard = {
      ...firstPage,
      departures: [replacementDeparture],
      page: { limit: 50, hasMore: false, nextCursor: null },
      totalCount: 1,
    };
    let request = 0;
    const loadDay = vi.fn(async (): Promise<StationDepartureBoard> => {
      request += 1;
      if (request === 1) return firstPage;
      if (request === 2) throw new ApiClientError("Timetable cursor has expired.", 400, "invalid_cursor");
      return replacement;
    });

    renderEnglish(() => <ClockProvider now={() => Date.parse("2026-07-10T18:42:30Z")}><StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} onLoadDayDepartures={loadDay} /></ClockProvider>);
    await fireEvent.click(screen.getByRole("button", { name: "View today's timetable" }));
    expect(await screen.findByText("Retained before restart")).toBeInTheDocument();
    await fireEvent.click(screen.getByRole("button", { name: "Show 50 more" }));

    expect(await screen.findByText("This timetable page expired")).toBeInTheDocument();
    expect(screen.getByText("Retained before restart")).toBeInTheDocument();
    await fireEvent.click(screen.getByRole("button", { name: "Restart timetable" }));
    expect(await screen.findByText("Fresh first page")).toBeInTheDocument();
    expect(loadDay).toHaveBeenNthCalledWith(3, freshStationSnapshot.stationId, "2026-07-10", 50, null, expect.any(AbortSignal), false);
  });

  it("provides keyboard navigation and linked panels for station tabs", async () => {
    const noop = () => undefined;
    renderEnglish(() => <StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    const departures = screen.getByRole("tab", { name: "Departures" });
    departures.focus();

    await fireEvent.keyDown(departures, { key: "ArrowRight" });
    await Promise.resolve();

    const vehicles = screen.getByRole("tab", { name: "Vehicles" });
    expect(departures).not.toHaveAttribute("aria-describedby");
    expect(vehicles).not.toHaveAttribute("aria-describedby");
    expect(vehicles).toHaveFocus();
    expect(vehicles).toHaveAttribute("aria-selected", "true");
    expect(vehicles).toHaveAttribute("aria-controls", "station-panel-vehicles");
    expect(screen.getByRole("tabpanel")).toHaveAttribute("aria-labelledby", "station-tab-vehicles");
    expect(departures).toHaveAttribute("tabindex", "-1");

    await fireEvent.keyDown(vehicles, { key: "End" });
    await Promise.resolve();
    const details = screen.getByRole("tab", { name: "Details" });
    expect(details).toHaveFocus();
    expect(details).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("tabpanel")).toHaveAttribute("aria-labelledby", "station-tab-details");

    await fireEvent.keyDown(details, { key: "Home" });
    await Promise.resolve();
    expect(departures).toHaveFocus();
    expect(departures).toHaveAttribute("aria-selected", "true");

    await fireEvent.keyDown(departures, { key: "ArrowLeft" });
    await Promise.resolve();
    expect(details).toHaveFocus();
    expect(details).toHaveAttribute("aria-selected", "true");
  });

  it("keeps the active station tab linked to a panel while data is loading", () => {
    const noop = () => undefined;
    renderEnglish(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "loading" }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);

    const selected = screen.getByRole("tab", { name: /^Departures(?:,?\s+\d+)?$/ });
    expect(selected).toHaveAttribute("aria-controls", "station-panel-departures");
    expect(screen.getByRole("tabpanel")).toHaveAttribute("id", "station-panel-departures");
    expect(screen.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ })).not.toHaveAttribute("aria-controls");
    expect(screen.getByRole("tab", { name: "Details" })).not.toHaveAttribute("aria-controls");
  });

  it("makes station Details useful in both languages without repeating live lists", async () => {
    const noop = () => undefined;
    const english = renderEnglish(() => <StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);

    await fireEvent.click(screen.getByRole("tab", { name: "Details" }));
    expect(screen.getByRole("heading", { name: "About this station" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "What you can see here" })).toBeInTheDocument();
    expect(screen.getByText("Førde")).toBeInTheDocument();
    expect(screen.getByText("Sunnfjord")).toBeInTheDocument();
    expect(screen.queryByText("Vehicles serving this station")).not.toBeInTheDocument();
    expect(screen.queryByText("Other nearby vehicles")).not.toBeInTheDocument();

    const technicalSummary = screen.getByText("Technical details").closest("summary");
    expect(technicalSummary).not.toBeNull();
    await fireEvent.click(technicalSummary!);
    expect(screen.getByText(freshStationSnapshot.station.id)).toBeInTheDocument();
    expect(screen.getByText(/61\.4522.*5\.8572/)).toBeInTheDocument();
    expect(screen.getByText("Europe/Oslo")).toBeInTheDocument();
    english.unmount();

    renderNorwegian(() => <StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    await fireEvent.click(screen.getByRole("tab", { name: "Detaljer" }));
    expect(screen.getByRole("heading", { name: "Om holdeplassen" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Dette finner du her" })).toBeInTheDocument();
    expect(screen.getByText("Tekniske detaljer").closest("summary")).not.toBeNull();
  });

  it("keeps stable station Details available while live data loads or fails", async () => {
    const noop = () => undefined;
    const loading = renderEnglish(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "loading" }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);

    await fireEvent.click(screen.getByRole("tab", { name: "Details" }));
    expect(screen.getByRole("heading", { name: "About this station" })).toBeInTheDocument();
    expect(screen.getByText(freshStationSnapshot.station.id)).toBeInTheDocument();
    expect(screen.queryByText("Loading station details")).not.toBeInTheDocument();
    loading.unmount();

    renderEnglish(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "error", message: "Could not load station details." }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    await fireEvent.click(screen.getByRole("tab", { name: "Details" }));
    expect(screen.getByRole("heading", { name: "About this station" })).toBeInTheDocument();
    expect(screen.getByText(freshStationSnapshot.station.id)).toBeInTheDocument();
    expect(screen.queryByText("Departures unavailable")).not.toBeInTheDocument();
    expect(screen.queryByText("Vehicle positions unavailable")).not.toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("The station information below is still available.");
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("omits unavailable locality facts instead of repeating placeholder cards", async () => {
    const noop = () => undefined;
    renderEnglish(() => <StationPanel
      snapshot={{
        ...freshStationSnapshot,
        station: { ...freshStationSnapshot.station, locality: null, municipality: null },
      }}
      sheet="none"
      onClose={noop}
      onRetry={noop}
      onVehicle={noop}
      onSheet={noop}
    />);

    await fireEvent.click(screen.getByRole("tab", { name: "Details" }));
    expect(screen.queryByText("Area", { exact: true })).not.toBeInTheDocument();
    expect(screen.queryByText("Municipality", { exact: true })).not.toBeInTheDocument();
    expect(screen.queryByText("Not available", { exact: true })).not.toBeInTheDocument();
    expect(screen.getByText("Station type").parentElement).toHaveTextContent("Bus station");
    expect(screen.getByText("Transport").parentElement).toHaveTextContent("Bus");
  });

  it("opens station-serving vehicles outside the nearby list without duplicating overlaps", async () => {
    const selected = vi.fn();
    const noop = () => undefined;
    renderEnglish(() => <ClockProvider now={() => Date.parse("2026-07-10T18:42:30Z")}><StationPanel snapshot={freshStationSnapshot} sheet="none" onClose={noop} onRetry={noop} onVehicle={selected} onSheet={noop} /></ClockProvider>);

    expect(screen.getByRole("heading", { name: "Next departures" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Departures" }).querySelector(".tab-count")).not.toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Vehicles" }).querySelector(".tab-count")).not.toBeInTheDocument();
    expect(screen.queryByText("Vehicles serving this station")).not.toBeInTheDocument();
    expect(screen.queryByText("Other nearby vehicles")).not.toBeInTheDocument();
    await fireEvent.click(screen.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }));
    expect(screen.getAllByRole("button", { name: /Open Bus on Line FB59\./ })).toHaveLength(1);
    expect(screen.getByText("Other reported live positions within 5 km of this station.")).toBeInTheDocument();
    const approaching = screen.getByRole("button", { name: /Open Bus on Line 90\./ });
    expect(screen.getByRole("heading", { name: "At station or due within 60 minutes", level: 3 }).parentElement).toHaveTextContent("2");
    expect(screen.getByRole("heading", { name: "Later or timing uncertain", level: 3 }).parentElement).toHaveTextContent("0");
    expect(approaching).toHaveTextContent("Expected here at 21:35");
    expect(approaching).toHaveAccessibleName(/Expected here at 21:35\. Status: Live\. Vehicle ID: SKY:Vehicle:90-901/);
    expect(screen.getByText("Already passed this station")).toBeInTheDocument();
    await fireEvent.click(approaching);
    expect(selected).toHaveBeenCalledWith("SKY:Vehicle:90-901");
  });

  it("keeps a vehicle that starts here hours later out of the due-within-an-hour group", async () => {
    const noop = () => undefined;
    const farFutureStart = {
      ...freshStationSnapshot.servingVehicles[1]!,
      id: "SKY:Vehicle:late-origin",
      lineCode: "LATE",
      callRole: "starts_here" as const,
      progress: "before_station" as const,
      stationCallAt: "2026-07-10T23:42:30Z",
    };
    renderEnglish(() => <ClockProvider now={() => Date.parse("2026-07-10T18:42:30Z")}><StationPanel snapshot={{ ...freshStationSnapshot, servingVehicles: [farFutureStart] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} /></ClockProvider>);

    await fireEvent.click(screen.getByRole("tab", { name: "Vehicles" }));
    expect(screen.getByRole("heading", { name: "At station or due within 60 minutes" }).parentElement).toHaveTextContent("0");
    expect(screen.getByRole("heading", { name: "Later or timing uncertain" }).parentElement).toHaveTextContent("1");
    expect(screen.getByRole("button", { name: /Starts here at 01:42/ })).toBeInTheDocument();
  });

  it("includes only current through exactly 60-minute before-station calls in the due group", async () => {
    const noop = () => undefined;
    const vehicle = freshStationSnapshot.servingVehicles[1]!;
    const atBoundaryVehicles = [
      { ...vehicle, id: "SKY:Vehicle:due-now", lineCode: "NOW", stationCallAt: "2026-07-10T18:42:30.000Z" },
      { ...vehicle, id: "SKY:Vehicle:due-60", lineCode: "SIXTY", stationCallAt: "2026-07-10T19:42:30.000Z" },
      { ...vehicle, id: "SKY:Vehicle:overdue", lineCode: "OVERDUE", stationCallAt: "2026-07-10T18:42:29.999Z" },
      { ...vehicle, id: "SKY:Vehicle:after-60", lineCode: "LATER", stationCallAt: "2026-07-10T19:42:30.001Z" },
    ];
    renderEnglish(() => <ClockProvider now={() => Date.parse("2026-07-10T18:42:30.000Z")}><StationPanel snapshot={{ ...freshStationSnapshot, servingVehicles: atBoundaryVehicles }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} /></ClockProvider>);

    await fireEvent.click(screen.getByRole("tab", { name: "Vehicles" }));
    const dueHeading = screen.getByRole("heading", { name: "At station or due within 60 minutes" });
    const uncertainHeading = screen.getByRole("heading", { name: "Later or timing uncertain" });
    expect(dueHeading.parentElement).toHaveTextContent("2");
    expect(uncertainHeading.parentElement).toHaveTextContent("2");
    expect(screen.getByRole("button", { name: /Line OVERDUE/ }).closest(".vehicle-subgroup")).toContainElement(uncertainHeading);
    expect(screen.getByRole("button", { name: /Line SIXTY/ }).closest(".vehicle-subgroup")).toContainElement(dueHeading);
  });

  it("does not present an unknown-progress station service as approaching", async () => {
    const noop = () => undefined;
    const unknownProgress = { ...freshStationSnapshot.servingVehicles[0]!, callRole: "calls_here" as const, progress: "unknown" as const, stationCallAt: null };
    renderEnglish(() => <StationPanel snapshot={{ ...freshStationSnapshot, servingVehicles: [unknownProgress] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);

    await fireEvent.click(screen.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }));
    expect(screen.getByRole("heading", { name: "Later or timing uncertain" })).toBeInTheDocument();
    expect(screen.getByText("No reporting vehicle is due here in the next hour.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Call time unavailable/ })).toBeInTheDocument();
  });

  it("describes truncated station coverage as a lower bound instead of a known total", async () => {
    const noop = () => undefined;
    renderEnglish(() => <StationPanel snapshot={{
      ...freshStationSnapshot,
      servingVehicleCoverage: { ...freshStationSnapshot.servingVehicleCoverage, candidateJourneyCount: 200, queriedJourneyCount: 200, truncated: true },
    }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);

    await fireEvent.click(screen.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }));
    expect(screen.getByText(/200 distinct services from the response were checked, and more may exist/)).toBeInTheDocument();
    expect(screen.queryByText(/200 of 200 candidate services/)).not.toBeInTheDocument();
  });

  it("labels the nearby-vehicle request as loading instead of showing a completed empty state", async () => {
    const noop = () => undefined;
    renderEnglish(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "loading", departures: [], nearbyVehicles: [], servingVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);

    await fireEvent.click(screen.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }));
    expect(screen.getByText("Loading station vehicles")).toBeInTheDocument();
    expect(screen.getByText("Checking for vehicles that stop here and other nearby positions.")).toBeInTheDocument();
    expect(screen.queryByText("No nearby vehicles reported.")).not.toBeInTheDocument();
  });

  it("does not claim a failed or in-progress zero-result refresh is complete", async () => {
    const noop = () => undefined;
    const paused = renderEnglish(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "rate_limited", departures: [], nearbyVehicles: [], servingVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    await fireEvent.click(screen.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }));
    expect(screen.getByText("Nearby vehicle refresh paused.").closest("[role=status]")).toHaveAttribute("data-state", "unavailable");
    expect(screen.getAllByText("FjordPulse will retry automatically.", { exact: false })).toHaveLength(2);
    expect(screen.queryByText("The search is complete", { exact: false })).not.toBeInTheDocument();
    paused.unmount();

    renderEnglish(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "refreshing", departures: [], nearbyVehicles: [], servingVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    await fireEvent.click(screen.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }));
    expect(screen.getByText("Refreshing nearby vehicles.")).toBeInTheDocument();
    expect(screen.getAllByText("Results may appear shortly.", { exact: false })).toHaveLength(2);
    expect(screen.getByText("Updating station-serving vehicles.")).toBeInTheDocument();
    expect(screen.getByText("Checking for vehicles that stop here. Results may appear shortly.")).toBeInTheDocument();
    expect(screen.queryByText("The search is complete", { exact: false })).not.toBeInTheDocument();
  });

  it("exposes precise focus, stale-refresh, and lost recovery actions", async () => {
    const noop = () => undefined;
    const retry = vi.fn();
    const props = { sheet: "none" as const, onClose: noop, onFocus: noop, onPause: noop, onResume: noop, onUnfocus: noop, onStop: noop, onRetry: retry, onSheet: noop };
    const { unmount } = renderEnglish(() => <VehiclePanel {...props} vehicle={line100Vehicle} focus="none" />);
    expect(screen.getByRole("button", { name: /Focus this vehicle/i })).toBeInTheDocument();
    expect(screen.getByText("Follow this vehicle on the map as its position updates.")).toBeInTheDocument();
    unmount();
    const staleRender = renderEnglish(() => <VehiclePanel {...props} vehicle={{ ...line100Vehicle, state: "stale" }} focus="paused" />);
    const refreshPosition = screen.getByRole("button", { name: /Refresh position/i });
    expect(screen.queryByRole("button", { name: /Keep watching/i })).not.toBeInTheDocument();
    await fireEvent.click(refreshPosition);
    expect(retry).toHaveBeenCalledOnce();
    staleRender.unmount();
    const refreshing = renderEnglish(() => <VehiclePanel {...props} refreshState="refreshing" vehicle={{ ...line100Vehicle, state: "stale" }} focus="paused" />);
    expect(screen.getByRole("button", { name: /Refreshing/i })).toBeDisabled();
    refreshing.unmount();
    const refreshFailed = renderEnglish(() => <VehiclePanel {...props} refreshState="error" vehicle={{ ...line100Vehicle, state: "stale" }} focus="paused" />);
    expect(screen.getByRole("alert")).toHaveTextContent("Position could not be refreshed");
    expect(screen.getByText("The last known position is still shown. Check the connection and try again.")).toBeInTheDocument();
    refreshFailed.unmount();
    renderEnglish(() => <VehiclePanel {...props} vehicle={{ ...line100Vehicle, state: "lost" }} focus="none" />);
    expect(screen.getByRole("alert")).toHaveTextContent("Live position temporarily unavailable");
    expect(screen.getByRole("alert")).toHaveTextContent("FjordPulse keeps checking and resumes following automatically");
    expect(screen.getByRole("alert")).not.toHaveTextContent("watched area");
    expect(screen.getByRole("button", { name: /Try again/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Stop following/i })).toBeInTheDocument();
  });
});
