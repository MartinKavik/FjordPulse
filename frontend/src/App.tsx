import { createEffect, createMemo, createSignal, lazy, onCleanup, onMount, Show, Suspense, type Component } from "solid-js";
import { createStore } from "solid-js/store";
import { fjordPulseHttp } from "./services/httpClient";
import { createRealtimeClient, type RealtimeClient } from "./services/realtimeClient";
import { createBrowserRouter, type AppRoute } from "./state/routing";
import type { FocusState, MapItem, MobileSheetState, PublicScenario, SearchResult, ServerMessage, StationSnapshot, Telemetry, VehicleState } from "./types/domain";
import { mapDeparture, mapNearbyVehicle, nearbyVehiclesEventDataSchema, stationDeparturesEventDataSchema, stationSnapshotPayloadSchema, telemetryPayloadSchema, toStationSnapshot, toVehicleEventState, toVehicleState, vehicleDataSchema, vehicleEventPayloadSchema } from "./types/validators";
import { AdminApp, type AdminPage } from "./components/Admin";
import { NavigationRail, riderUpdateNotice, SearchOverlay, TopBar } from "./components/AppChrome";
import { FeedbackBanner, FocusPill } from "./components/DesignSystem";
import { StationPanel, VehiclePanel, WelcomePanel } from "./components/Panels";
import "@fontsource-variable/inter/wght.css";
import "@fontsource-variable/inter/wght-italic.css";
import "maplibre-gl/dist/maplibre-gl.css";
import "./styles.css";
import { ClockProvider } from "./state/clock";
import { I18nProvider, useI18n } from "./state/i18n";
import { enturStateFromStation, mergeTelemetryTick, newestTimestamp, telemetryFromHealth } from "./state/telemetry";
import { rankFixtureSearch } from "./utils/search";
import { defaultWelcomePanelExpanded, readWelcomePanelPreference, rememberWelcomePanelPreference } from "./state/welcomePanel";

const MapCanvas = lazy(async () => ({ default: (await import("./components/MapCanvas")).MapCanvas }));
const fixturesAllowed = import.meta.env.DEV || import.meta.env.MODE === "test" || import.meta.env.VITE_ENABLE_FIXTURES === "true";
const FixtureRouter = fixturesAllowed ? lazy(async () => ({ default: (await import("./components/FixtureRouter")).FixtureRouter })) : undefined;
const SEARCH_DEBOUNCE_MS = 700;
const MINIMUM_SEARCH_LENGTH = 2;

interface PublicAppProps {
  readonly scenario?: PublicScenario;
  readonly fixtureSearchResults?: readonly SearchResult[];
  readonly fixtureStation?: StationSnapshot;
  readonly fixtureVehicle?: VehicleState;
}

const EMPTY_TELEMETRY: Telemetry = {
  backend: "checking",
  realtime: "idle",
  entur: "idle",
  liveQueryBridge: "offline",
  refreshMode: "realtime",
  lastUpdateAt: null,
};

const LIVE_DEFAULT: PublicScenario = {
  id: "live",
  mapItems: [],
  stationSnapshot: null,
  vehicle: null,
  focus: "none",
  searchQuery: "",
  searchResults: [],
  searchOpen: false,
  telemetry: EMPTY_TELEMETRY,
  mobileSheet: "none",
};

interface PendingResource {
  readonly kind: "station" | "vehicle";
  readonly id: string;
  readonly label: string;
  readonly state: "loading" | "error";
  readonly message: string | null;
}

interface VehicleRefreshFeedback {
  readonly state: "idle" | "refreshing" | "error";
}

function versionTime(value: string): number {
  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? parsed : Number.NEGATIVE_INFINITY;
}

function isStrictlyNewer(incoming: string, current: string): boolean {
  return versionTime(incoming) > versionTime(current);
}

function journeyReferenceKey(reference: VehicleState["journeyReference"]): string | null {
  return reference === null ? null : `${reference.serviceJourneyId}\u0000${reference.operatingDate}\u0000${reference.datedServiceJourneyId ?? ""}`;
}

function fixtureVehicleForStation(vehicleId: string, base: VehicleState | null, stationSnapshot: StationSnapshot | null): VehicleState | null {
  if (base?.id === vehicleId) return base;
  const summary = stationSnapshot === null
    ? undefined
    : [...stationSnapshot.servingVehicles, ...stationSnapshot.nearbyVehicles].find((vehicle) => vehicle.id === vehicleId);
  if (summary === undefined || stationSnapshot === null) return null;

  return {
    id: summary.id,
    transportMode: summary.transportMode,
    passengerServiceState: summary.passengerServiceState,
    lineCode: summary.lineCode,
    routeName: null,
    state: summary.state,
    latitude: summary.latitude,
    longitude: summary.longitude,
    bearing: null,
    delaySeconds: summary.delaySeconds,
    lastSeenAt: summary.lastSeenAt,
    refreshedAt: stationSnapshot.updatedAt,
    version: stationSnapshot.version,
    nextStop: null,
    journeyReference: null,
    monitoredCall: null,
    progressBetweenStops: null,
    journeyVersion: null,
    routeProgress: null,
    trail: [],
    journey: null,
    upcomingStops: [],
  };
}

const ResourcePanel: Component<{
  readonly resource: PendingResource;
  readonly onRetry: () => void;
  readonly onClose: () => void;
}> = (props) => {
  const i18n = useI18n();
  let heading: HTMLHeadingElement | undefined;
  onMount(() => queueMicrotask(() => heading?.focus({ preventScroll: true })));
  const kind = () => props.resource.kind === "station"
    ? i18n.text({ nb: "holdeplass", en: "station" })
    : i18n.text({ nb: "kjøretøy", en: "vehicle" });
  const displayLabel = () => {
    if (props.resource.label === "") {
      return props.resource.kind === "station"
        ? i18n.text({ nb: "Holdeplass", en: "Station" })
        : i18n.text({ nb: "Kjøretøy", en: "Vehicle" });
    }
    if (props.resource.kind === "vehicle" && i18n.language() === "nb") {
      return props.resource.label
        .replace(/^Line\s+/i, "Linje ")
        .replace(/^Vehicle\s+/i, "Kjøretøy ");
    }
    return props.resource.label;
  };
  const errorMessage = () => {
    if (props.resource.message === "No active vehicle is currently available for this line.") {
      return i18n.text({ nb: "Ingen aktive kjøretøy er tilgjengelige på denne linjen akkurat nå.", en: props.resource.message });
    }
    return i18n.language() === "en" && props.resource.message !== null
      ? props.resource.message
      : i18n.text({ nb: "Sanntidsinformasjonen er midlertidig utilgjengelig. Prøv igjen.", en: "Live details are temporarily unavailable. Please try again." });
  };
  return <aside class="detail-panel resource-panel" aria-label={i18n.text(
    { nb: "{label} · {state}", en: "{label} · {state}" },
    { label: displayLabel(), state: props.resource.state === "loading" ? i18n.text({ nb: "laster", en: "loading" }) : i18n.text({ nb: "feil", en: "error" }) },
  )}>
    <header class="panel-header">
      <div><span class="panel-eyebrow">{props.resource.kind === "station" ? i18n.text({ nb: "Holdeplass", en: "Station" }) : i18n.text({ nb: "Kjøretøy", en: "Vehicle" })}</span><h1 ref={heading} tabIndex={-1}>{displayLabel()}</h1></div>
      <button type="button" class="icon-button" onClick={props.onClose} aria-label={i18n.text({ nb: "Lukk panelet", en: "Close panel" })}>×</button>
    </header>
    <div class="panel-scroll">
      <Show when={props.resource.state === "loading"}>
        <div class="watch-registering"><span class="spinner" /><strong>{i18n.text({ nb: "Laster sanntidsinformasjon", en: "Loading live details" })}</strong><p>{i18n.text({ nb: "Henter den nyeste tilgjengelige transportinformasjonen.", en: "Getting the latest available transport information." })}</p></div>
      </Show>
      <Show when={props.resource.state === "error"}>
        <FeedbackBanner tone="danger" title={i18n.text({ nb: "Kunne ikke laste {kind}.", en: "Could not load {kind}." }, { kind: kind() })}>{errorMessage()}</FeedbackBanner>
        <div class="panel-actions"><button type="button" class="button button-primary" onClick={props.onRetry}>{i18n.text({ nb: "Prøv igjen", en: "Retry" })}</button><button type="button" class="button button-secondary" onClick={props.onClose}>{i18n.text({ nb: "Lukk", en: "Close" })}</button></div>
      </Show>
    </div>
  </aside>;
};

const PublicApp: Component<PublicAppProps> = (props) => {
  const i18n = useI18n();
  const fixed = props.scenario ?? LIVE_DEFAULT;
  const fixture = props.scenario !== undefined;
  const [mapItems, setMapItems] = createSignal<readonly MapItem[]>(fixture ? fixed.mapItems : []);
  const [station, setStation] = createSignal<StationSnapshot | null>(fixed.stationSnapshot);
  const [vehicle, setVehicle] = createSignal<VehicleState | null>(fixed.vehicle);
  const [focus, setFocus] = createSignal<FocusState>(fixed.focus);
  const [mobileSheet, setMobileSheet] = createSignal<MobileSheetState>(fixed.mobileSheet);
  const [pendingResource, setPendingResource] = createSignal<PendingResource | null>(null);
  const [vehicleRefreshFeedback, setVehicleRefreshFeedback] = createSignal<VehicleRefreshFeedback>({ state: "idle" });
  const [searchTarget, setSearchTarget] = createSignal<{ readonly longitude: number; readonly latitude: number; readonly requestId: number } | null>(null);
  const [search, setSearch] = createStore({
    open: fixed.searchOpen,
    query: fixed.searchQuery,
    results: fixed.searchResults,
    activeIndex: 0,
    waiting: false,
    loading: false,
    error: null as string | null,
  });
  const [telemetry, setTelemetry] = createSignal<Telemetry>(fixture ? fixed.telemetry : EMPTY_TELEMETRY);
  const [serverEnturState, setServerEnturState] = createSignal<Telemetry["entur"] | null>(fixture ? fixed.telemetry.entur : null);
  const [scenarioControls, setScenarioControls] = createSignal(false);
  const [backendScenario, setBackendScenario] = createSignal("normal");
  const [dataMode, setDataMode] = createSignal<"real" | "fake" | "unknown">(fixture ? "fake" : "unknown");
  const isMobileScenario = () => props.scenario?.id.startsWith("mobile_") ?? false;
  const initiallyMobile = isMobileScenario() || window.matchMedia("(max-width: 900px)").matches;
  const [mobileViewport, setMobileViewport] = createSignal(initiallyMobile);
  const [welcomePreference, setWelcomePreference] = createSignal<boolean | null>(readWelcomePanelPreference());
  let searchInput: HTMLInputElement | undefined;
  let searchTimer: ReturnType<typeof setTimeout> | null = null;
  let pollingTimer: ReturnType<typeof setInterval> | null = null;
  let healthTimer: ReturnType<typeof setInterval> | null = null;
  let mapTimer: ReturnType<typeof setTimeout> | null = null;
  let abortController: AbortController | null = null;
  let searchAbortController: AbortController | null = null;
  let mapAbortController: AbortController | null = null;
  let realtime: RealtimeClient | null = null;
  let mapDataFailed = false;
  let searchTargetRequestId = 0;

  const isMobileViewport = () => mobileViewport();
  const welcomeExpanded = () => defaultWelcomePanelExpanded(welcomePreference(), isMobileViewport());
  const setWelcomeExpanded = (expanded: boolean) => {
    setWelcomePreference(expanded);
    rememberWelcomePanelPreference(expanded);
  };

  const cancelPendingSearch = () => {
    if (searchTimer !== null) clearTimeout(searchTimer);
    searchTimer = null;
    searchAbortController?.abort();
    searchAbortController = null;
  };

  const openSearch = () => {
    setSearch({ open: true });
    searchInput?.focus();
  };

  const closeSearch = () => {
    cancelPendingSearch();
    setSearch({ open: false, waiting: false, loading: false });
    searchInput?.blur();
  };

  const patchTelemetry = (patch: Partial<Telemetry>) => setTelemetry((current) => ({ ...current, ...patch }));
  const noteAuthoritativeUpdate = (updatedAt: string) => setTelemetry((current) => ({
    ...current,
    lastUpdateAt: newestTimestamp(current.lastUpdateAt, updatedAt),
  }));

  const acceptStationSnapshot = (incoming: StationSnapshot): boolean => {
    const selected = station();
    const pending = pendingResource();
    if (selected?.stationId !== incoming.stationId && !(pending?.kind === "station" && pending.id === incoming.stationId)) return false;
    let accepted = true;
    setStation((current) => {
      if (current !== null && current.stationId === incoming.stationId && versionTime(current.version) > versionTime(incoming.version)) {
        accepted = false;
        return current;
      }
      return incoming;
    });
    if (!accepted) return false;
    noteAuthoritativeUpdate(incoming.updatedAt);
    patchTelemetry({ entur: enturStateFromStation(incoming.state, dataMode(), serverEnturState()) });
    return true;
  };

  const acceptVehicleSnapshot = (incoming: VehicleState): boolean => {
    const selected = vehicle();
    const pending = pendingResource();
    if (selected?.id !== incoming.id && !(pending?.kind === "vehicle" && pending.id === incoming.id)) return false;
    setVehicle((current) => current !== null
      && current.id === incoming.id
      && versionTime(current.version) > versionTime(incoming.version)
      ? current
      : incoming);
    setVehicleRefreshFeedback({ state: "idle" });
    noteAuthoritativeUpdate(incoming.refreshedAt);
    return true;
  };

  const applyRealtimeMessage = (message: ServerMessage) => {
    if (message.type === "station_snapshot" || message.type === "station_snapshot_changed") {
      const parsed = stationSnapshotPayloadSchema.safeParse(message.payload);
      const current = station();
      if (parsed.success && current !== null && parsed.data.stationId === current.stationId && isStrictlyNewer(parsed.data.version, current.version)) {
        acceptStationSnapshot(toStationSnapshot(current.station, parsed.data, current.nearbyVehicleSearchRadiusMeters));
      }
    }
    if (message.type === "station_departures_changed") {
      const parsed = stationDeparturesEventDataSchema.safeParse(message.payload);
      if (parsed.success) {
        const current = station();
        if (current !== null && parsed.data.stationId === current.stationId && isStrictlyNewer(parsed.data.version, current.version)) {
          setStation({
            ...current,
            state: parsed.data.state,
            version: parsed.data.version,
            updatedAt: parsed.data.updatedAt,
            departures: parsed.data.departures.map(mapDeparture),
          });
          noteAuthoritativeUpdate(parsed.data.updatedAt);
          patchTelemetry({ entur: enturStateFromStation(parsed.data.state, dataMode(), serverEnturState()) });
        }
      }
    }
    if (message.type === "nearby_vehicles_changed") {
      const parsed = nearbyVehiclesEventDataSchema.safeParse(message.payload);
      if (parsed.success) {
        const current = station();
        if (current !== null && parsed.data.stationId === current.stationId && isStrictlyNewer(parsed.data.version, current.version)) {
          setStation({ ...current, state: parsed.data.state, version: parsed.data.version, updatedAt: parsed.data.updatedAt, nearbyVehicles: parsed.data.vehicles.map(mapNearbyVehicle) });
          noteAuthoritativeUpdate(parsed.data.updatedAt);
          patchTelemetry({ entur: enturStateFromStation(parsed.data.state, dataMode(), serverEnturState()) });
        }
      }
    }
    if (message.type === "vehicle_snapshot") {
      const parsed = vehicleDataSchema.safeParse(message.payload);
      if (parsed.success) acceptVehicleSnapshot(toVehicleState(parsed.data));
    }
    if (message.type === "vehicle_moved" || message.type === "vehicle_stale" || message.type === "vehicle_lost") {
      const parsed = vehicleEventPayloadSchema.safeParse(message.payload);
      if (parsed.success) {
        const current = vehicle();
        if (current === null || current.id !== parsed.data.vehicle.id || !isStrictlyNewer(parsed.data.vehicle.version, current.version)) return;
        const next = toVehicleEventState(parsed.data.vehicle, parsed.data.observation, current);
        setVehicle(next);
        setVehicleRefreshFeedback({ state: "idle" });
        noteAuthoritativeUpdate(next.refreshedAt);
        const journeyChanged = next.journeyVersion !== current.journeyVersion
          || journeyReferenceKey(next.journeyReference) !== journeyReferenceKey(current.journeyReference);
        if (journeyChanged && next.journeyReference !== null) {
          void fjordPulseHttp.getVehicle(next.id).then(acceptVehicleSnapshot).catch(() => undefined);
        }
      }
    }
    if (message.type === "telemetry_tick") {
      const parsed = telemetryPayloadSchema.safeParse(message.payload);
      if (parsed.success) {
        setServerEnturState(parsed.data.entur);
        setTelemetry((current) => {
          const merged = mergeTelemetryTick(current, parsed.data);
          const selectedStation = station();
          return selectedStation === null
            ? merged
            : { ...merged, entur: enturStateFromStation(selectedStation.state, dataMode(), parsed.data.entur) };
        });
      }
    }
    if (message.type === "source_backoff") patchTelemetry({ entur: enturStateFromStation("backoff", dataMode(), serverEnturState()) });
    if (message.type === "rate_limited") patchTelemetry({ entur: enturStateFromStation("rate_limited", dataMode(), serverEnturState()) });
  };

  const refreshSnapshots = async () => {
    if (fixture) return;
    const currentStation = station();
    const currentVehicle = vehicle();
    if (currentStation === null && currentVehicle === null) return;
    const [stationResult, vehicleResult] = await Promise.allSettled([
      currentStation === null ? Promise.resolve(null) : fjordPulseHttp.getStation(currentStation.stationId, undefined, true),
      currentVehicle === null ? Promise.resolve(null) : fjordPulseHttp.getVehicle(currentVehicle.id, undefined, true),
    ]);
    const successfulUpdates: string[] = [];
    if (stationResult.status === "fulfilled" && stationResult.value !== null && acceptStationSnapshot(stationResult.value)) {
      successfulUpdates.push(stationResult.value.updatedAt);
    }
    if (vehicleResult.status === "fulfilled" && vehicleResult.value !== null && acceptVehicleSnapshot(vehicleResult.value)) {
      successfulUpdates.push(vehicleResult.value.refreshedAt);
    }
    if (successfulUpdates.length > 0) {
      setTelemetry((current) => ({
        ...current,
        backend: "ok",
        lastUpdateAt: newestTimestamp(current.lastUpdateAt, ...successfulUpdates),
      }));
      return;
    }
    const selectedRequestFailed = (currentStation !== null && stationResult.status === "rejected" && station()?.stationId === currentStation.stationId)
      || (currentVehicle !== null && vehicleResult.status === "rejected" && vehicle()?.id === currentVehicle.id);
    if (selectedRequestFailed) patchTelemetry({ backend: "degraded" });
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

  const loadStation = async (stationId: string, forceRefresh = false, label = "") => {
    const priorStation = station();
    if (priorStation !== null && priorStation.stationId !== stationId) leaveStationWatch(priorStation.stationId);
    const priorVehicle = vehicle();
    if (priorVehicle !== null) leaveVehicleWatch(priorVehicle.id, focus() !== "none");
    setVehicle(null);
    setVehicleRefreshFeedback({ state: "idle" });
    setFocus("none");
    setPendingResource(null);
    setSearch({ open: false });
    if (!forceRefresh || priorStation?.stationId !== stationId) setMobileSheet(isMobileViewport() ? "half" : "none");
    if (fixture) {
      const snapshot = fixed.stationSnapshot ?? props.fixtureStation ?? null;
      if (snapshot !== null) setStation({ ...snapshot, station: { ...snapshot.station, id: stationId }, stationId });
      setTelemetry(fixed.telemetry.realtime === "idle" ? { ...fixed.telemetry, realtime: "connected", refreshMode: "realtime" } : fixed.telemetry);
      return;
    }
    setStation(null);
    setPendingResource({ kind: "station", id: stationId, label, state: "loading", message: null });
    abortController?.abort();
    const requestController = new AbortController();
    abortController = requestController;
    try {
      await ensureRealtime();
      realtime?.send("watch_station", { stationId }, true);
      const snapshot = await fjordPulseHttp.getStation(stationId, requestController.signal, forceRefresh);
      if (!acceptStationSnapshot(snapshot)) return;
      setPendingResource(null);
      patchTelemetry({ backend: "ok", lastUpdateAt: newestTimestamp(telemetry().lastUpdateAt, snapshot.updatedAt) });
    } catch (error) {
      if (requestController.signal.aborted) return;
      setPendingResource({ kind: "station", id: stationId, label, state: "error", message: error instanceof Error ? error.message : null });
      patchTelemetry({ backend: "degraded" });
    }
  };

  const closeStation = () => {
    abortController?.abort();
    const current = station();
    if (current !== null) leaveStationWatch(current.stationId);
    setStation(null); setPendingResource(null); setMobileSheet("none");
  };

  const loadVehicle = async (vehicleId: string, forceRefresh = false, label = "") => {
    const priorStation = station();
    if (priorStation !== null) leaveStationWatch(priorStation.stationId);
    const priorVehicle = vehicle();
    const refreshingSelectedVehicle = forceRefresh && priorVehicle?.id === vehicleId;
    if (priorVehicle !== null && priorVehicle.id !== vehicleId) leaveVehicleWatch(priorVehicle.id, focus() !== "none");
    if (priorVehicle !== null && priorVehicle.id !== vehicleId) setFocus("none");
    setVehicleRefreshFeedback({ state: refreshingSelectedVehicle ? "refreshing" : "idle" });
    setStation(null); setPendingResource(null);
    if (!refreshingSelectedVehicle) setMobileSheet(isMobileViewport() ? "half" : "none");
    if (fixture) {
      const snapshot = fixtureVehicleForStation(vehicleId, fixed.vehicle ?? props.fixtureVehicle ?? null, priorStation ?? fixed.stationSnapshot ?? props.fixtureStation ?? null);
      if (snapshot !== null) setVehicle(snapshot);
      else setPendingResource({ kind: "vehicle", id: vehicleId, label, state: "error", message: "No active vehicle is currently available for this line." });
      setVehicleRefreshFeedback({ state: "idle" });
      return;
    }
    abortController?.abort();
    const requestController = new AbortController();
    abortController = requestController;
    if (!forceRefresh || priorVehicle?.id !== vehicleId) setVehicle(null);
    if (!refreshingSelectedVehicle) setPendingResource({ kind: "vehicle", id: vehicleId, label, state: "loading", message: null });
    try {
      await ensureRealtime();
      realtime?.send("watch_vehicle", { vehicleId }, true);
      const snapshot = await fjordPulseHttp.getVehicle(vehicleId, requestController.signal, forceRefresh);
      if (!acceptVehicleSnapshot(snapshot)) return;
      setPendingResource(null);
      patchTelemetry({
        backend: "ok",
        entur: dataMode() === "fake" ? "not_used" : dataMode() === "real" ? (snapshot.state === "live" ? "ok" : "delayed") : "idle",
        lastUpdateAt: newestTimestamp(telemetry().lastUpdateAt, snapshot.refreshedAt),
      });
    } catch (error) {
      if (requestController.signal.aborted) return;
      if (refreshingSelectedVehicle) {
        setPendingResource(null);
        setVehicleRefreshFeedback({ state: "error" });
      } else {
        setPendingResource({ kind: "vehicle", id: vehicleId, label, state: "error", message: error instanceof Error ? error.message : null });
      }
      patchTelemetry({ backend: "degraded" });
    }
  };

  const closeVehicle = () => {
    abortController?.abort();
    const current = vehicle();
    if (current !== null) leaveVehicleWatch(current.id, focus() !== "none");
    setVehicle(null); setPendingResource(null); setVehicleRefreshFeedback({ state: "idle" }); setFocus("none"); setMobileSheet("none");
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
    cancelPendingSearch();
    const searchValue = query.trim();
    setSearch({ query, open: true, activeIndex: 0, results: [], waiting: false, loading: false, error: null });
    if (searchValue.length < MINIMUM_SEARCH_LENGTH) return;
    setSearch({ waiting: true });
    searchTimer = setTimeout(async () => {
      searchTimer = null;
      if (search.query !== query) return;
      if (fixture) {
        setSearch({ results: rankFixtureSearch(props.fixtureSearchResults ?? fixed.searchResults, searchValue), waiting: false, loading: false, error: null });
        return;
      }
      const controller = new AbortController();
      searchAbortController = controller;
      const requestedQuery = query;
      setSearch({ waiting: false, loading: true });
      try {
        const results = await fjordPulseHttp.search(searchValue, controller.signal);
        if (search.query === requestedQuery) setSearch({ results, loading: false, error: null });
      } catch (error) {
        if (controller.signal.aborted) return;
        if (search.query === requestedQuery) setSearch({ results: [], loading: false, error: error instanceof Error ? error.message : i18n.text({ nb: "Søket mislyktes.", en: "Search request failed." }) });
      } finally {
        if (searchAbortController === controller) searchAbortController = null;
      }
    }, SEARCH_DEBOUNCE_MS);
  };

  const loadMapViewport = (bounds: readonly [number, number, number, number], zoom: number) => {
    if (fixture) return;
    if (mapTimer !== null) clearTimeout(mapTimer);
    mapTimer = setTimeout(async () => {
      mapAbortController?.abort();
      mapAbortController = new AbortController();
      try {
        const result = await fjordPulseHttp.getStationMap(bounds, zoom, mapAbortController.signal);
        setMapItems(result.items);
        mapDataFailed = false;
        setTelemetry((current) => ({
          ...current,
          backend: "ok",
          lastUpdateAt: newestTimestamp(current.lastUpdateAt, result.updatedAt),
        }));
      } catch {
        if (!mapAbortController.signal.aborted) {
          mapDataFailed = true;
          patchTelemetry({ backend: "degraded", message: "Station map is temporarily unavailable." });
        }
      }
    }, 180);
  };

  const selectSearchResult = (result: SearchResult) => {
    closeSearch();
    if (result.longitude !== null && result.latitude !== null && (result.type === "station" || result.type === "place")) {
      searchTargetRequestId += 1;
      setSearchTarget({ longitude: result.longitude, latitude: result.latitude, requestId: searchTargetRequestId });
    }
    if (result.type === "station") {
      const stationId = result.stationId ?? result.id;
      void loadStation(stationId, false, result.label);
    }
    else if (result.type === "place") {
      setSearch({ open: false });
      const currentStation = station();
      if (currentStation !== null) leaveStationWatch(currentStation.stationId);
      const currentVehicle = vehicle();
      if (currentVehicle !== null) leaveVehicleWatch(currentVehicle.id, focus() !== "none");
      setStation(null); setVehicle(null); setPendingResource(null); setFocus("none");
    }
    else if (result.type === "vehicle") void loadVehicle(result.id, false, result.label);
    else {
      const matchingVehicle = search.results.find((candidate) => candidate.type === "vehicle" && candidate.lineCode === result.lineCode);
      setSearch({ open: false });
      if (matchingVehicle !== undefined) void loadVehicle(matchingVehicle.id, false, result.label);
      else if (fixture && props.fixtureVehicle?.lineCode === result.lineCode) void loadVehicle(props.fixtureVehicle.id, false, result.label);
      else setPendingResource({ kind: "vehicle", id: result.id, label: result.label, state: "error", message: "No active vehicle is currently available for this line." });
    }
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

  const refreshPublicHealth = async () => {
    if (fixture) return;
    try {
      const health = await fjordPulseHttp.getHealth();
      setDataMode(health.dataMode);
      setTelemetry((current) => {
        const mapped = telemetryFromHealth(current, health);
        setServerEnturState(mapped.entur);
        const selectedStation = station();
        const entur = selectedStation === null
          ? mapped.entur
          : enturStateFromStation(selectedStation.state, health.dataMode, mapped.entur);
        return mapDataFailed ? { ...mapped, backend: "degraded", entur } : { ...mapped, entur };
      });
    } catch {
      patchTelemetry({ backend: "degraded", entur: dataMode() === "fake" ? "not_used" : "idle" });
    }
  };

  const searchKeyDown = (event: KeyboardEvent) => {
    if (event.key === "Escape") { closeSearch(); return; }
    if (event.key === "ArrowDown") { event.preventDefault(); setSearch("activeIndex", (current) => Math.min(current + 1, Math.max(0, search.results.length - 1))); }
    if (event.key === "ArrowUp") { event.preventDefault(); setSearch("activeIndex", (current) => Math.max(current - 1, 0)); }
    if (event.key === "Enter") { const result = search.results[search.activeIndex]; if (result !== undefined) selectSearchResult(result); }
  };

  onMount(() => {
    const mobileMedia = window.matchMedia("(max-width: 900px)");
    const updateMobileViewport = (event: MediaQueryListEvent) => setMobileViewport(isMobileScenario() || event.matches);
    setMobileViewport(isMobileScenario() || mobileMedia.matches);
    mobileMedia.addEventListener("change", updateMobileViewport);
    onCleanup(() => mobileMedia.removeEventListener("change", updateMobileViewport));

    const controls = fixturesAllowed && new URLSearchParams(window.location.search).get("controls") === "1";
    setScenarioControls(controls);
    if (controls && props.scenario === undefined) {
      void fjordPulseHttp.getScenario().then((value) => setBackendScenario(value.scenario)).catch(() => undefined);
    }
    const keyboard = (event: KeyboardEvent) => {
      if (event.key === "/" && document.activeElement?.tagName !== "INPUT") { event.preventDefault(); openSearch(); }
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") { event.preventDefault(); openSearch(); }
      if (event.key === "Escape" && pendingResource() !== null) setPendingResource(null);
    };
    window.addEventListener("keydown", keyboard);
    onCleanup(() => window.removeEventListener("keydown", keyboard));

    if (!fixture) {
      void refreshPublicHealth();
      healthTimer = setInterval(() => void refreshPublicHealth(), 15_000);
    }
  });

  createEffect(() => {
    if (props.scenario === undefined) return;
    setMapItems(props.scenario.mapItems);
  });

  onCleanup(() => {
    if (searchTimer !== null) clearTimeout(searchTimer);
    if (mapTimer !== null) clearTimeout(mapTimer);
    if (pollingTimer !== null) clearInterval(pollingTimer);
    if (healthTimer !== null) clearInterval(healthTimer);
    abortController?.abort();
    searchAbortController?.abort();
    mapAbortController?.abort();
    realtime?.close();
  });

  const panel = () => vehicle() !== null ? "vehicle" : station() !== null ? "station" : pendingResource() !== null ? "pending" : "welcome";
  const welcomeCollapsed = () => panel() === "welcome" && !welcomeExpanded();
  const updateNotice = () => riderUpdateNotice(telemetry(), panel() !== "welcome");
  return (
    <div class={`app-shell ${isMobileScenario() ? "force-mobile" : ""} ${welcomeCollapsed() ? "welcome-collapsed" : ""}`} data-scenario={props.scenario?.id ?? "live"}>
      <TopBar
        query={search.query}
        searchOpen={search.open}
        updateNotice={updateNotice()}
        onQuery={performSearch}
        onSearchFocus={() => setSearch({ open: true })}
        onSearchKeyDown={searchKeyDown}
        setSearchRef={(element) => { searchInput = element; }}
      />
      <NavigationRail onSearch={openSearch} />
      <Suspense fallback={<section class="map-region map-loading" aria-label={i18n.text({ nb: "Laster interaktivt kart", en: "Loading interactive map" })}><span class="spinner" /></section>}>
        <MapCanvas
          items={mapItems()}
          station={station()}
          vehicle={vehicle()}
          journey={vehicle()?.journey ?? null}
          searchTarget={searchTarget()}
          focus={focus()}
          deterministic={fixture}
          onSelectStation={(id) => void loadStation(id)}
          onSelectVehicle={(id) => void loadVehicle(id)}
          onManualMove={() => { if (focus() === "following") updateFocus("paused"); }}
          onViewportChange={loadMapViewport}
        />
      </Suspense>
      <Show when={focus() !== "none" && vehicle() !== null}><FocusPill line={vehicle()!.lineCode} passengerServiceState={vehicle()!.passengerServiceState} lastSeenAt={vehicle()!.lastSeenAt} paused={focus() === "paused"} onPause={() => updateFocus("paused")} onResume={() => updateFocus("following")} onUnfocus={() => updateFocus("none")} /></Show>
      <Show when={panel() === "welcome"}><WelcomePanel expanded={welcomeExpanded()} onExpandedChange={setWelcomeExpanded} /></Show>
      <Show when={panel() === "station" && station() !== null}><StationPanel snapshot={station()!} sheet={mobileSheet()} onClose={closeStation} onRetry={() => void loadStation(station()!.stationId, true)} onVehicle={(id) => void loadVehicle(id)} onSheet={setMobileSheet} onLoadDayDepartures={(stationId, date, limit, cursor, signal, refresh) => fjordPulseHttp.getStationDepartureBoard(stationId, date, limit, cursor, signal, refresh)} /></Show>
      <Show when={panel() === "vehicle" && vehicle() !== null}><VehiclePanel vehicle={vehicle()!} focus={focus()} refreshState={vehicleRefreshFeedback().state} sheet={mobileSheet()} onClose={closeVehicle} onFocus={() => updateFocus("following")} onPause={() => updateFocus("paused")} onResume={() => updateFocus("following")} onUnfocus={() => updateFocus("none")} onStop={closeVehicle} onRetry={() => void loadVehicle(vehicle()!.id, true)} onSheet={setMobileSheet} /></Show>
      <Show when={panel() === "pending" && pendingResource() !== null}><ResourcePanel resource={pendingResource()!} onClose={() => setPendingResource(null)} onRetry={() => { const resource = pendingResource(); if (resource?.kind === "station") void loadStation(resource.id, true, resource.label); else if (resource !== null) void loadVehicle(resource.id, true, resource.label); }} /></Show>
      <SearchOverlay open={search.open} query={search.query} results={search.results} activeIndex={search.activeIndex} waiting={search.waiting} loading={search.loading} error={search.error} onSelect={selectSearchResult} onClose={closeSearch} />
      <Show when={dataMode() !== "unknown"}>
        <div class={`transport-attribution mode-${dataMode()}`} role="note" aria-label={i18n.text({ nb: "Kilde for transportdata", en: "Transport data source" })}>
          <Show when={dataMode() === "fake"} fallback={<a href="https://developer.entur.org/" target="_blank" rel="noreferrer">{i18n.text({ nb: "Transportdata: Entur", en: "Transport data: Entur" })}</a>}><strong>{i18n.text({ nb: "Demodata", en: "Demo data" })}</strong><span>{i18n.text({ nb: "Deterministiske transportdata", en: "Deterministic transport fixtures" })}</span></Show>
        </div>
      </Show>
      <Show when={scenarioControls()}><label class="scenario-control">{i18n.text({ nb: "Scenario", en: "Scenario" })}<select value={props.scenario?.id ?? backendScenario()} onChange={(event) => void selectDevelopmentScenario(event.currentTarget.value)}>{props.scenario !== undefined ? <><option value="desktop_default_map">{i18n.text({ nb: "standard", en: "default" })}</option><option value="desktop_station_fresh">{i18n.text({ nb: "holdeplass i sanntid", en: "station fresh" })}</option><option value="desktop_vehicle_focus_following">{i18n.text({ nb: "følg kjøretøy", en: "vehicle focus" })}</option><option value="desktop_degraded_fallback">{i18n.text({ nb: "reserveløsning", en: "fallback" })}</option></> : <><option value="normal">normal</option><option value="station_empty">{i18n.text({ nb: "tom holdeplass", en: "station empty" })}</option><option value="station_stale">{i18n.text({ nb: "utdatert holdeplass", en: "station stale" })}</option><option value="station_error">{i18n.text({ nb: "holdeplassfeil", en: "station error" })}</option><option value="vehicle_live">{i18n.text({ nb: "kjøretøy i sanntid", en: "vehicle live" })}</option><option value="vehicle_stale">{i18n.text({ nb: "utdatert kjøretøy", en: "vehicle stale" })}</option><option value="vehicle_lost">{i18n.text({ nb: "mistet kjøretøy", en: "vehicle lost" })}</option><option value="fallback">{i18n.text({ nb: "reserveløsning", en: "fallback" })}</option><option value="entur_backoff">{i18n.text({ nb: "Entur-venting", en: "Entur backoff" })}</option><option value="realtime_reconnect">{i18n.text({ nb: "sanntid kobler til igjen", en: "realtime reconnect" })}</option></>}</select></label></Show>
    </div>
  );
};

type AdminRoute = Extract<AppRoute, { readonly kind: "admin" }>;

interface FixtureRoute {
  readonly scenario: string | null;
  readonly index: boolean;
}

const databaseViewForRoute = (route: AdminRoute) => route.page === "database" ? route.databaseView : undefined;

const FixtureContent: Component<{ readonly route: FixtureRoute }> = (props) => {
  const i18n = useI18n();
  if (FixtureRouter === undefined) return <PublicApp />;
  return <Suspense fallback={<main class="admin-loading"><span class="spinner" /><p>{i18n.text({ nb: "Laster deterministisk scenario …", en: "Loading deterministic scenario…" })}</p></main>}><FixtureRouter scenario={props.route.scenario} index={props.route.index} http={fjordPulseHttp} renderPublic={(value, interactions) => <PublicApp scenario={value} fixtureSearchResults={interactions.searchResults} fixtureStation={interactions.station} fixtureVehicle={interactions.vehicle} />} /></Suspense>;
};

const AppContent: Component = () => {
  const router = createBrowserRouter();
  const adminRoute = createMemo<AdminRoute | undefined>(() => {
    const route = router.route();
    return route.kind === "admin" ? route : undefined;
  });
  const fixtureRoute = createMemo<FixtureRoute | undefined>(() => {
    const route = router.route();
    if (!fixturesAllowed || FixtureRouter === undefined || route.kind === "admin") return undefined;
    if (route.kind === "scenario") return { scenario: route.scenario, index: false };
    if (route.kind === "scenario-index") return { scenario: null, index: true };
    return import.meta.env.VITE_DATA_MODE === "fixture"
      ? { scenario: "desktop_default_map", index: false }
      : undefined;
  });

  return <Show when={adminRoute()} fallback={
    <Show when={fixtureRoute()} fallback={<PublicApp />}>
      {(route) => <FixtureContent route={route()} />}
    </Show>
  }>
    {(route) => <AdminApp page={route().page as AdminPage} databaseView={databaseViewForRoute(route())} fixture={false} http={fjordPulseHttp} onNavigate={router.navigate} />}
  </Show>;
};

export const App: Component = () => <I18nProvider><ClockProvider><AppContent /></ClockProvider></I18nProvider>;
