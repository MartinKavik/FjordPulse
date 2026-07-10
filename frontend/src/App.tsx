import { createEffect, createSignal, lazy, onCleanup, onMount, Show, Suspense, type Component } from "solid-js";
import { createStore } from "solid-js/store";
import {
  freshStationSnapshot,
  getPublicScenario,
  isPublicScenarioId,
  line100Vehicle,
  searchResults as fixtureSearchResults,
  type PublicScenario,
} from "./fixtures/scenarios";
import { fjordPulseHttp } from "./services/httpClient";
import { createRealtimeClient, type RealtimeClient } from "./services/realtimeClient";
import { parseRoute } from "./state/routing";
import type { FocusState, MapItem, SearchResult, ServerMessage, StationSnapshot, Telemetry, VehicleState } from "./types/domain";
import { mapDeparture, mapNearbyVehicle, nearbyVehiclesDataSchema, stationDeparturesDataSchema, stationSnapshotPayloadSchema, telemetryPayloadSchema, toStationSnapshot, toVehicleEventState, toVehicleState, vehicleDataSchema, vehicleEventPayloadSchema } from "./types/validators";
import { AdminApp, type AdminPage } from "./components/Admin";
import { NavigationRail, SearchOverlay, TopBar } from "./components/AppChrome";
import { FeedbackBanner, FocusPill, TelemetryStrip } from "./components/DesignSystem";
import { StationPanel, VehiclePanel, WelcomePanel } from "./components/Panels";
import { DesignSystemPage, ScenarioIndex } from "./components/ScenarioPages";
import "maplibre-gl/dist/maplibre-gl.css";
import "./styles.css";

const MapCanvas = lazy(async () => ({ default: (await import("./components/MapCanvas")).MapCanvas }));
const fixturesAllowed = import.meta.env.DEV || import.meta.env.MODE === "test" || import.meta.env.VITE_ENABLE_FIXTURES === "true";

interface PublicAppProps {
  readonly scenario?: PublicScenario;
}

const EMPTY_TELEMETRY: Telemetry = {
  backend: "degraded",
  realtime: "idle",
  entur: "offline",
  liveQueryBridge: "offline",
  refreshMode: "realtime",
  lastUpdateAt: null,
};

function fixtureMode(scenario: PublicScenario | undefined): boolean {
  return scenario !== undefined || (fixturesAllowed && import.meta.env.VITE_DATA_MODE === "fixture");
}

function fallbackScenario(scenario: PublicScenario | undefined): PublicScenario {
  return scenario ?? getPublicScenario("desktop_default_map");
}

const RouteContext: Component<{ readonly result: SearchResult; readonly onClose: () => void; readonly onStation: (stationId: string) => void }> = (props) => (
  <aside class="detail-panel route-panel" aria-label={`${props.result.label} route context`}>
    <header class="panel-header"><div><span class="panel-eyebrow">Line search result</span><h1>{props.result.label}</h1><p class="route-name">{props.result.secondaryText ?? "Route details"}</p></div><button type="button" class="icon-button" onClick={props.onClose} aria-label="Close route context">×</button></header>
    <div class="panel-scroll"><FeedbackBanner tone="info" title="Route overview">Detailed route geometry is not available in this preview. Select a related station to inspect live departures.</FeedbackBanner><div class="route-stations"><button type="button" onClick={() => props.onStation("NSR:StopPlace:36025")}>Førde rutebilstasjon <span>Open station →</span></button><button type="button" onClick={() => props.onStation("NSR:StopPlace:34562")}>Sandane rutebilstasjon <span>Open station →</span></button><button type="button" onClick={() => props.onStation("NSR:StopPlace:35453")}>Nordfjordeid rutebilstasjon <span>Open station →</span></button></div></div>
  </aside>
);

const PublicApp: Component<PublicAppProps> = (props) => {
  const fixed = fallbackScenario(props.scenario);
  const fixture = fixtureMode(props.scenario);
  const [mapItems, setMapItems] = createSignal<readonly MapItem[]>(fixture ? fixed.mapItems : []);
  const [station, setStation] = createSignal<StationSnapshot | null>(fixed.stationSnapshot);
  const [vehicle, setVehicle] = createSignal<VehicleState | null>(fixed.vehicle);
  const [focus, setFocus] = createSignal<FocusState>(fixed.focus);
  const [mobileSheet, setMobileSheet] = createSignal<"none" | "half" | "full">(fixed.mobileSheet);
  const [routeContext, setRouteContext] = createSignal<SearchResult | null>(null);
  const [search, setSearch] = createStore({
    open: fixed.searchOpen,
    query: fixed.searchQuery,
    results: fixed.searchResults,
    activeIndex: 0,
    loading: false,
  });
  const [telemetry, setTelemetry] = createSignal<Telemetry>(fixture ? fixed.telemetry : EMPTY_TELEMETRY);
  const [scenarioControls, setScenarioControls] = createSignal(false);
  const [backendScenario, setBackendScenario] = createSignal("normal");
  let searchInput: HTMLInputElement | undefined;
  let searchTimer: ReturnType<typeof setTimeout> | null = null;
  let pollingTimer: ReturnType<typeof setInterval> | null = null;
  let mapTimer: ReturnType<typeof setTimeout> | null = null;
  let abortController: AbortController | null = null;
  let mapAbortController: AbortController | null = null;
  let realtime: RealtimeClient | null = null;

  const isMobileScenario = () => props.scenario?.id.startsWith("mobile_") ?? false;
  const isMobileViewport = () => isMobileScenario() || window.matchMedia("(max-width: 760px)").matches;

  const patchTelemetry = (patch: Partial<Telemetry>) => setTelemetry((current) => ({ ...current, ...patch }));

  const applyRealtimeMessage = (message: ServerMessage) => {
    if (message.type === "station_snapshot" || message.type === "station_snapshot_changed") {
      const parsed = stationSnapshotPayloadSchema.safeParse(message.payload);
      const current = station();
      if (parsed.success && current !== null) setStation(toStationSnapshot(current.station, parsed.data));
    }
    if (message.type === "station_departures_changed") {
      const parsed = stationDeparturesDataSchema.safeParse(message.payload);
      if (parsed.success) setStation((current) => current === null ? null : { ...current, state: parsed.data.state, version: parsed.data.version, updatedAt: parsed.data.updatedAt, departures: parsed.data.departures.map(mapDeparture) });
    }
    if (message.type === "nearby_vehicles_changed") {
      const parsed = nearbyVehiclesDataSchema.safeParse(message.payload);
      if (parsed.success) setStation((current) => current === null ? null : { ...current, state: parsed.data.state, version: parsed.data.version, updatedAt: parsed.data.updatedAt, nearbyVehicles: parsed.data.vehicles.map(mapNearbyVehicle) });
    }
    if (message.type === "vehicle_snapshot") {
      const parsed = vehicleDataSchema.safeParse(message.payload);
      if (parsed.success) setVehicle(toVehicleState(parsed.data));
    }
    if (message.type === "vehicle_moved" || message.type === "vehicle_stale" || message.type === "vehicle_lost") {
      const parsed = vehicleEventPayloadSchema.safeParse(message.payload);
      if (parsed.success) setVehicle((current) => toVehicleEventState(parsed.data.vehicle, parsed.data.observation, current));
      else if (message.type === "vehicle_stale") setVehicle((current) => current === null ? null : { ...current, state: "stale" });
      else if (message.type === "vehicle_lost") setVehicle((current) => current === null ? null : { ...current, state: "lost" });
    }
    if (message.type === "telemetry_tick") { const parsed = telemetryPayloadSchema.safeParse(message.payload); if (parsed.success) setTelemetry(parsed.data); }
    if (message.type === "source_backoff" || message.type === "rate_limited") patchTelemetry({ entur: "delayed" });
  };

  const refreshSnapshots = async () => {
    if (fixture) return;
    try {
      const currentStation = station();
      const currentVehicle = vehicle();
      const [stationResult, vehicleResult] = await Promise.allSettled([
        currentStation === null ? Promise.resolve(null) : fjordPulseHttp.getStation(currentStation.stationId, undefined, true),
        currentVehicle === null ? Promise.resolve(null) : fjordPulseHttp.getVehicle(currentVehicle.id, undefined, true),
      ]);
      if (stationResult.status === "fulfilled" && stationResult.value !== null) setStation(stationResult.value);
      if (vehicleResult.status === "fulfilled" && vehicleResult.value !== null) setVehicle(vehicleResult.value);
      patchTelemetry({ backend: "ok", refreshMode: "polling", lastUpdateAt: new Date().toISOString() });
    } catch {
      patchTelemetry({ backend: "degraded" });
    }
  };

  const startPolling = () => {
    patchTelemetry({ realtime: "offline", refreshMode: "polling", message: "Live updates unavailable. Refreshing periodically." });
    if (pollingTimer !== null || fixture) return;
    const configured = Number(import.meta.env.VITE_FALLBACK_POLL_MS ?? "15000");
    const interval = Number.isFinite(configured) && configured >= 1000 ? configured : 15_000;
    pollingTimer = setInterval(() => void refreshSnapshots(), interval);
    void refreshSnapshots();
  };

  const ensureRealtime = async () => {
    if (fixture) return;
    if (realtime === null) {
      realtime = createRealtimeClient(fjordPulseHttp, {
        onState: (state) => {
          if (state === "connected" || state === "connecting" || state === "reconnecting" || state === "offline" || state === "idle") {
            patchTelemetry({ realtime: state, liveQueryBridge: state === "connected" ? "connected" : state === "offline" ? "offline" : "reconnecting", refreshMode: state === "connected" ? "realtime" : telemetry().refreshMode });
          }
          if (state === "connected" && pollingTimer !== null) { clearInterval(pollingTimer); pollingTimer = null; }
        },
        onMessage: applyRealtimeMessage,
        onFallback: startPolling,
      });
    }
    await realtime.connect();
  };

  const leaveStationWatch = (stationId: string) => {
    realtime?.forget("watch_station", { stationId });
    realtime?.send("unwatch_station", { stationId });
  };

  const leaveVehicleWatch = (vehicleId: string, focused: boolean) => {
    if (focused) {
      realtime?.forget("focus_vehicle", { vehicleId });
      realtime?.forget("pause_focus", { vehicleId });
      realtime?.send("unfocus_vehicle", { vehicleId });
    }
    realtime?.forget("watch_vehicle", { vehicleId });
    realtime?.send("unwatch_vehicle", { vehicleId });
  };

  const loadStation = async (stationId: string, forceRefresh = false) => {
    const priorStation = station();
    if (priorStation !== null && priorStation.stationId !== stationId) leaveStationWatch(priorStation.stationId);
    const priorVehicle = vehicle();
    if (priorVehicle !== null) leaveVehicleWatch(priorVehicle.id, focus() !== "none");
    setVehicle(null);
    setFocus("none");
    setRouteContext(null);
    setSearch({ open: false });
    setMobileSheet(isMobileViewport() ? "half" : "none");
    const known = station()?.station ?? freshStationSnapshot.station;
    setStation({ ...freshStationSnapshot, station: { ...known, id: stationId }, stationId, state: "loading", departures: [], nearbyVehicles: [], message: "Registering live watch…" });
    if (fixture) {
      setStation({ ...freshStationSnapshot, station: { ...freshStationSnapshot.station, id: stationId }, stationId });
      setTelemetry(fixed.telemetry.realtime === "idle" ? { ...fixed.telemetry, realtime: "connected", refreshMode: "realtime" } : fixed.telemetry);
      return;
    }
    abortController?.abort();
    abortController = new AbortController();
    try {
      await ensureRealtime();
      realtime?.send("watch_station", { stationId }, true);
      const snapshot = await fjordPulseHttp.getStation(stationId, abortController.signal, forceRefresh);
      setStation(snapshot);
      patchTelemetry({ backend: "ok", entur: snapshot.state === "stale" ? "delayed" : "ok", lastUpdateAt: snapshot.updatedAt });
    } catch (error) {
      if (abortController.signal.aborted) return;
      setStation((current) => current === null ? null : { ...current, state: "error", message: error instanceof Error ? error.message : "Could not load station details." });
      patchTelemetry({ backend: "degraded" });
    }
  };

  const closeStation = () => {
    const current = station();
    if (current !== null) leaveStationWatch(current.stationId);
    setStation(null); setMobileSheet("none");
  };

  const loadVehicle = async (vehicleId: string, forceRefresh = false) => {
    const priorStation = station();
    if (priorStation !== null) leaveStationWatch(priorStation.stationId);
    const priorVehicle = vehicle();
    if (priorVehicle !== null && priorVehicle.id !== vehicleId) leaveVehicleWatch(priorVehicle.id, focus() !== "none");
    if (priorVehicle !== null && priorVehicle.id !== vehicleId) setFocus("none");
    setStation(null); setRouteContext(null); setMobileSheet(isMobileViewport() ? "half" : "none");
    if (fixture) { setVehicle({ ...line100Vehicle, id: vehicleId }); return; }
    try {
      await ensureRealtime();
      realtime?.send("watch_vehicle", { vehicleId }, true);
      setVehicle(await fjordPulseHttp.getVehicle(vehicleId, undefined, forceRefresh));
    } catch {
      setVehicle({ ...line100Vehicle, id: vehicleId, state: "lost" });
    }
  };

  const closeVehicle = () => {
    const current = vehicle();
    if (current !== null) leaveVehicleWatch(current.id, focus() !== "none");
    setVehicle(null); setFocus("none"); setMobileSheet("none");
  };

  const updateFocus = (next: FocusState) => {
    const current = vehicle();
    if (current === null) return;
    if (next === "following" && focus() === "none") realtime?.send("focus_vehicle", { vehicleId: current.id }, true);
    else if (next === "following") { realtime?.forget("pause_focus", { vehicleId: current.id }); realtime?.send("resume_focus", { vehicleId: current.id }); }
    else if (next === "paused") realtime?.send("pause_focus", { vehicleId: current.id }, true);
    else {
      realtime?.forget("focus_vehicle", { vehicleId: current.id });
      realtime?.forget("pause_focus", { vehicleId: current.id });
      realtime?.send("unfocus_vehicle", { vehicleId: current.id });
    }
    setFocus(next);
  };

  const performSearch = (query: string) => {
    if (searchTimer !== null) clearTimeout(searchTimer);
    setSearch({ query, open: true, activeIndex: 0 });
    if (query.trim().length === 0) { setSearch({ results: [], loading: false }); return; }
    setSearch({ loading: true });
    searchTimer = setTimeout(async () => {
      if (fixture) {
        const normalized = query.trim().toLocaleLowerCase("nb-NO");
        setSearch({ results: normalized.includes("xyz") ? [] : fixtureSearchResults.filter((result) => result.label.toLocaleLowerCase("nb-NO").includes(normalized) || normalized.length < 3), loading: false });
        return;
      }
      try { setSearch({ results: await fjordPulseHttp.search(query), loading: false }); }
      catch { setSearch({ results: [], loading: false }); }
    }, fixture ? 0 : 250);
  };

  const loadMapViewport = (bounds: readonly [number, number, number, number], zoom: number) => {
    if (fixture) return;
    if (mapTimer !== null) clearTimeout(mapTimer);
    mapTimer = setTimeout(async () => {
      mapAbortController?.abort();
      mapAbortController = new AbortController();
      try {
        const items = await fjordPulseHttp.getStations(bounds, zoom, mapAbortController.signal);
        setMapItems(items);
        patchTelemetry({ backend: "ok", entur: "ok", lastUpdateAt: new Date().toISOString() });
      } catch {
        if (!mapAbortController.signal.aborted) patchTelemetry({ backend: "degraded", message: "Station map is temporarily unavailable." });
      }
    }, 180);
  };

  const selectSearchResult = (result: SearchResult) => {
    if (result.type === "station" || result.type === "place") {
      const stationId = result.stationId ?? (result.type === "station" ? result.id : null);
      if (stationId !== null) void loadStation(stationId);
    }
    else if (result.type === "vehicle") void loadVehicle(result.id);
    else { setSearch({ open: false }); setRouteContext(result); }
  };

  const selectDevelopmentScenario = async (scenario: string) => {
    if (props.scenario !== undefined) {
      window.location.assign(`/__scenario/${scenario}?controls=1`);
      return;
    }
    try {
      const selected = await fjordPulseHttp.setScenario(scenario);
      setBackendScenario(selected.scenario);
      window.location.reload();
    } catch {
      patchTelemetry({ backend: "degraded", message: "Could not change the development scenario." });
    }
  };

  const searchKeyDown = (event: KeyboardEvent) => {
    if (event.key === "Escape") { setSearch({ open: false }); searchInput?.blur(); return; }
    if (event.key === "ArrowDown") { event.preventDefault(); setSearch("activeIndex", (current) => Math.min(current + 1, Math.max(0, search.results.length - 1))); }
    if (event.key === "ArrowUp") { event.preventDefault(); setSearch("activeIndex", (current) => Math.max(current - 1, 0)); }
    if (event.key === "Enter") { const result = search.results[search.activeIndex]; if (result !== undefined) selectSearchResult(result); }
  };

  onMount(() => {
    const controls = fixturesAllowed && new URLSearchParams(window.location.search).get("controls") === "1";
    setScenarioControls(controls);
    if (controls && props.scenario === undefined) {
      void fjordPulseHttp.getScenario().then((value) => setBackendScenario(value.scenario)).catch(() => undefined);
    }
    const keyboard = (event: KeyboardEvent) => {
      if (event.key === "/" && document.activeElement?.tagName !== "INPUT") { event.preventDefault(); setSearch({ open: true }); searchInput?.focus(); }
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") { event.preventDefault(); setSearch({ open: true }); searchInput?.focus(); }
      if (event.key === "Escape" && routeContext() !== null) setRouteContext(null);
    };
    window.addEventListener("keydown", keyboard);
    onCleanup(() => window.removeEventListener("keydown", keyboard));

    if (!fixture) loadMapViewport([4, 57, 32, 72], 4);
  });

  createEffect(() => {
    if (props.scenario === undefined) return;
    setMapItems(props.scenario.mapItems);
  });

  onCleanup(() => {
    if (searchTimer !== null) clearTimeout(searchTimer);
    if (mapTimer !== null) clearTimeout(mapTimer);
    if (pollingTimer !== null) clearInterval(pollingTimer);
    abortController?.abort();
    mapAbortController?.abort();
    realtime?.close();
  });

  const panel = () => vehicle() !== null ? "vehicle" : station() !== null ? "station" : routeContext() !== null ? "route" : "welcome";
  return (
    <div class={`app-shell ${isMobileScenario() ? "force-mobile" : ""}`} data-scenario={props.scenario?.id ?? "live"}>
      <TopBar
        query={search.query}
        searchOpen={search.open}
        realtimeState={telemetry().realtime}
        onQuery={performSearch}
        onSearchFocus={() => setSearch({ open: true })}
        onSearchKeyDown={searchKeyDown}
        setSearchRef={(element) => { searchInput = element; }}
      />
      <NavigationRail onSearch={() => { setSearch({ open: true }); searchInput?.focus(); }} />
      <Suspense fallback={<section class="map-region map-loading" aria-label="Loading interactive map"><span class="spinner" /></section>}>
        <MapCanvas
          items={mapItems()}
          station={station()}
          vehicle={vehicle()}
          focus={focus()}
          deterministic={fixture}
          onSelectStation={(id) => void loadStation(id)}
          onSelectVehicle={(id) => void loadVehicle(id)}
          onManualMove={() => { if (focus() === "following") updateFocus("paused"); }}
          onViewportChange={loadMapViewport}
        />
      </Suspense>
      <Show when={focus() !== "none" && vehicle() !== null}><FocusPill line={vehicle()?.lineCode ?? "Unknown"} paused={focus() === "paused"} onPause={() => updateFocus("paused")} onResume={() => updateFocus("following")} onUnfocus={() => updateFocus("none")} /></Show>
      <Show when={panel() === "welcome"}><WelcomePanel /></Show>
      <Show when={panel() === "station" && station() !== null}><StationPanel snapshot={station()!} sheet={mobileSheet()} onClose={closeStation} onRetry={() => void loadStation(station()!.stationId, true)} onVehicle={(id) => void loadVehicle(id)} onSheet={setMobileSheet} /></Show>
      <Show when={panel() === "vehicle" && vehicle() !== null}><VehiclePanel vehicle={vehicle()!} focus={focus()} sheet={mobileSheet()} onClose={closeVehicle} onFocus={() => updateFocus("following")} onPause={() => updateFocus("paused")} onResume={() => updateFocus("following")} onUnfocus={() => updateFocus("none")} onStop={closeVehicle} onRetry={() => void loadVehicle(vehicle()!.id, true)} onSheet={setMobileSheet} /></Show>
      <Show when={panel() === "route" && routeContext() !== null}><RouteContext result={routeContext()!} onClose={() => setRouteContext(null)} onStation={(id) => void loadStation(id)} /></Show>
      <SearchOverlay open={search.open} query={search.query} results={search.results} activeIndex={search.activeIndex} loading={search.loading} onSelect={selectSearchResult} onClose={() => setSearch({ open: false })} />
      <TelemetryStrip telemetry={telemetry()} />
      <Show when={scenarioControls()}><label class="scenario-control">Scenario<select value={props.scenario?.id ?? backendScenario()} onChange={(event) => void selectDevelopmentScenario(event.currentTarget.value)}>{props.scenario !== undefined ? <><option value="desktop_default_map">default</option><option value="desktop_station_fresh">station fresh</option><option value="desktop_vehicle_focus_following">vehicle focus</option><option value="desktop_degraded_fallback">fallback</option></> : <><option value="normal">normal</option><option value="station_empty">station empty</option><option value="station_stale">station stale</option><option value="station_error">station error</option><option value="vehicle_live">vehicle live</option><option value="vehicle_stale">vehicle stale</option><option value="vehicle_lost">vehicle lost</option><option value="fallback">fallback</option><option value="entur_backoff">Entur backoff</option><option value="realtime_reconnect">realtime reconnect</option></>}</select></label></Show>
    </div>
  );
};

export const App: Component = () => {
  const route = parseRoute(window.location);
  if (route.kind === "scenario-index" && fixturesAllowed) return <ScenarioIndex />;
  if (route.kind === "admin") return <AdminApp page={route.page as AdminPage} fixture={false} http={fjordPulseHttp} />;
  if (route.kind === "scenario" && fixturesAllowed) {
    if (route.scenario === "design_system_components") return <DesignSystemPage />;
    if (route.scenario.startsWith("admin_")) {
      const page: AdminPage = route.scenario === "admin_watches" ? "watches" : route.scenario === "admin_entur_log" ? "entur-log" : "status";
      return <AdminApp page={page} fixture={true} http={fjordPulseHttp} />;
    }
    if (isPublicScenarioId(route.scenario)) return <PublicApp scenario={getPublicScenario(route.scenario)} />;
  }
  return <PublicApp />;
};
