import { createSignal, For, Show, type Component } from "solid-js";
import type { FocusState, StationSnapshot, VehicleState } from "../types/domain";
import { Button, DepartureRow, FeedbackBanner, SkeletonRows, StatusChip, VehicleRow } from "./DesignSystem";
import { Icon } from "./Icon";
import { useClock } from "../state/clock";
import { formatBearing, formatDelay, formatRelativeTime, formatTransportTime } from "../utils/format";

export interface WelcomePanelProps {
  readonly expanded: boolean;
  readonly onExpandedChange: (expanded: boolean) => void;
}

export const WelcomePanel: Component<WelcomePanelProps> = (props) => {
  let collapseButton: HTMLButtonElement | undefined;
  let restoreButton: HTMLButtonElement | undefined;
  const changeExpanded = (expanded: boolean) => {
    props.onExpandedChange(expanded);
    queueMicrotask(() => (expanded ? collapseButton : restoreButton)?.focus());
  };

  return (
    <Show when={props.expanded} fallback={
      <button
        ref={restoreButton}
        class="welcome-restore"
        type="button"
        aria-label="Show FjordPulse introduction"
        aria-controls="fjordpulse-welcome"
        aria-expanded="false"
        title="Show FjordPulse welcome"
        onClick={() => changeExpanded(true)}
      >
        <Icon name="map" size={20} />
        <span>About</span>
      </button>
    }>
      <aside id="fjordpulse-welcome" class="detail-panel welcome-panel" aria-label="Welcome">
        <button
          ref={collapseButton}
          class="icon-button welcome-collapse"
          type="button"
          aria-label="Hide FjordPulse introduction"
          aria-controls="fjordpulse-welcome"
          aria-expanded="true"
          title="Hide welcome panel"
          onClick={() => changeExpanded(false)}
        >
          <Icon name="close" size={19} />
        </button>
        <div class="welcome-eyebrow">Realtime transport · Norway</div>
        <h1>Norway in motion.</h1>
        <p>Find a station, see upcoming departures, and follow a vehicle along its route.</p>
        <div class="welcome-route" aria-hidden="true">
          <span /><span /><span /><span /><span />
        </div>
        <div class="welcome-features">
          <div><Icon name="map" size={21} /><span><strong>Find your station</strong>Search by stop, place, or line</span></div>
          <div><Icon name="activity" size={21} /><span><strong>Live departures</strong>See current times and nearby vehicles</span></div>
          <div><Icon name="focus" size={21} /><span><strong>Follow a vehicle</strong>View its route, stops, and latest position</span></div>
        </div>
      </aside>
    </Show>
  );
};

export interface StationPanelProps {
  readonly snapshot: StationSnapshot;
  readonly sheet: "none" | "half" | "full";
  readonly onClose: () => void;
  readonly onRetry: () => void;
  readonly onVehicle: (vehicleId: string) => void;
  readonly onSheet: (sheet: "half" | "full") => void;
}

interface NearbyVehiclesContentProps {
  readonly vehicles: StationSnapshot["nearbyVehicles"];
  readonly state: StationSnapshot["state"];
  readonly searchRadiusMeters: number | null;
  readonly onVehicle: (vehicleId: string) => void;
}

function nearbySearchArea(radiusMeters: number | null): string {
  if (radiusMeters === null || !Number.isFinite(radiusMeters) || radiusMeters <= 0) return "near this station";
  if (radiusMeters < 1_000) return `within ${Math.round(radiusMeters)} m of this station`;
  const kilometers = Math.round(radiusMeters / 100) / 10;
  return `within ${kilometers} km of this station`;
}

const NearbyVehiclesContent: Component<NearbyVehiclesContentProps> = (props) => {
  const emptyTitle = () => {
    if (props.state === "fresh" || props.state === "empty") return "No nearby vehicles reported.";
    if (props.state === "refreshing") return "Refreshing nearby vehicles.";
    if (props.state === "stale") return "No saved nearby vehicles.";
    if (props.state === "backoff" || props.state === "rate_limited") return "Nearby vehicle refresh paused.";
    return "Nearby vehicles unavailable.";
  };
  const emptyMessage = () => {
    const area = nearbySearchArea(props.searchRadiusMeters);
    if (props.state === "fresh" || props.state === "empty") return `No live vehicle positions were found ${area}. The search is complete; check again shortly.`;
    if (props.state === "refreshing") return `Checking for current vehicle positions ${area}. Results may appear shortly.`;
    if (props.state === "stale") return `Live updates are delayed, and no saved vehicle positions are available ${area}.`;
    if (props.state === "backoff" || props.state === "rate_limited") return `No saved live vehicle positions are available ${area}. FjordPulse will retry automatically.`;
    return `No live vehicle positions are available ${area}. Check again shortly.`;
  };

  return (
    <Show when={props.vehicles.length > 0} fallback={
      <div class="empty-state compact" role="status" data-state={props.state === "fresh" || props.state === "empty" ? "empty" : "unavailable"}>
        <span><Icon name="bus" size={25} /></span>
        <div><strong>{emptyTitle()}</strong><p>{emptyMessage()}</p></div>
      </div>
    }>
      <div class="vehicle-list"><For each={props.vehicles}>{(vehicle) => <VehicleRow vehicle={vehicle} onSelect={props.onVehicle} />}</For></div>
    </Show>
  );
};

export const StationPanel: Component<StationPanelProps> = (props) => {
  const now = useClock();
  const [tab, setTab] = createSignal<"departures" | "vehicles" | "info">("departures");
  const stateLabel = () => ({
    loading: "Connecting", fresh: "Live", refreshing: "Refreshing", empty: "Live",
    stale: "Stale", unavailable: "Unavailable", error: "Error", backoff: "Backoff", rate_limited: "Rate limited",
  } as const)[props.snapshot.state];
  const chipState = () => ({
    loading: "connecting", fresh: "connected", refreshing: "connecting", empty: "connected",
    stale: "delayed", unavailable: "offline", error: "offline", backoff: "delayed", rate_limited: "delayed",
  } as const)[props.snapshot.state];
  const locality = () => props.snapshot.station.locality ?? props.snapshot.station.municipality;
  const loadingTitle = () => tab() === "vehicles" ? "Loading nearby vehicles" : tab() === "info" ? "Loading station details" : "Loading departures";
  const loadingMessage = () => tab() === "vehicles"
    ? "Checking for current vehicle positions near this station."
    : tab() === "info" ? "Getting the latest station information." : "Getting the latest times and nearby vehicles.";

  return (
    <aside class={`detail-panel station-panel sheet-${props.sheet}`} aria-label={`${props.snapshot.station.name} station details`}>
      <button class="sheet-grabber" type="button" onClick={() => props.onSheet(props.sheet === "full" ? "half" : "full")} aria-label={props.sheet === "full" ? "Collapse station sheet" : "Expand station sheet"}><span /></button>
      <header class="panel-header">
        <div>
          <span class="panel-eyebrow">Station<Show when={locality()}>{(value) => ` · ${value()}`}</Show></span>
          <h1>{props.snapshot.station.name}</h1>
          <div class="panel-meta"><StatusChip state={chipState()} label={stateLabel()} /><span>Updated {formatRelativeTime(props.snapshot.updatedAt, now())}</span></div>
        </div>
        <button class="icon-button" type="button" onClick={props.onClose} aria-label="Close station panel"><Icon name="close" size={23} /></button>
      </header>

      <div class="panel-tabs" role="tablist" aria-label="Station sections">
        <button role="tab" aria-selected={tab() === "departures"} onClick={() => setTab("departures")}><Icon name="clock" size={17} />Departures</button>
        <button role="tab" aria-selected={tab() === "vehicles"} onClick={() => setTab("vehicles")}><Icon name="bus" size={17} />Vehicles</button>
        <button role="tab" aria-selected={tab() === "info"} onClick={() => setTab("info")}><Icon name="pin" size={17} />Info</button>
      </div>

      <div class="panel-scroll">
        <Show when={props.snapshot.state === "loading"}>
          <div class="watch-registering"><span class="spinner" /><strong>{loadingTitle()}</strong><p>{loadingMessage()}</p></div>
          <SkeletonRows count={5} />
        </Show>

        <Show when={props.snapshot.state === "error"}>
          <FeedbackBanner tone="danger" title="Could not load station details.">{props.snapshot.message ?? "The transport source did not respond. Your map and search remain available."}</FeedbackBanner>
          <div class="panel-actions"><Button tone="primary" icon="refresh" onClick={props.onRetry}>Retry</Button><Button icon="close" onClick={props.onClose}>Close panel</Button></div>
          <div class="disabled-section"><Icon name="clock" size={22} /><span>Departures unavailable</span></div>
          <div class="disabled-section"><Icon name="bus" size={22} /><span>Nearby vehicles unavailable</span></div>
        </Show>

        <Show when={props.snapshot.state !== "loading" && props.snapshot.state !== "error"}>
          <Show when={props.snapshot.state === "stale" || props.snapshot.state === "backoff" || props.snapshot.state === "rate_limited"}>
            <FeedbackBanner tone="warning" title={props.snapshot.state === "stale" ? "Live data delayed" : "Entur refresh paused"}>
              {props.snapshot.message ?? "Showing the last known transport information while FjordPulse reconnects."}
            </FeedbackBanner>
          </Show>

          <Show when={tab() === "departures"}>
            <section class="panel-section">
              <div class="section-heading"><div><span class="eyebrow">Next from this station</span><h2>Departures</h2></div><span>{props.snapshot.departures.length} upcoming</span></div>
              <Show when={props.snapshot.departures.length > 0} fallback={
                <div class="empty-state"><span><Icon name="clock" size={27} /></span><strong>No upcoming departures.</strong><p>Try again later or choose another station.</p></div>
              }>
                <div class="departure-list"><For each={props.snapshot.departures}>{(departure) => <DepartureRow departure={departure} muted={props.snapshot.state === "stale"} />}</For></div>
              </Show>
            </section>
            <section class="panel-section nearby-section">
              <div class="section-heading"><div><span class="eyebrow">Reporting now</span><h2>Nearby vehicles</h2></div></div>
              <NearbyVehiclesContent vehicles={props.snapshot.nearbyVehicles} state={props.snapshot.state} searchRadiusMeters={props.snapshot.nearbyVehicleSearchRadiusMeters} onVehicle={props.onVehicle} />
            </section>
          </Show>

          <Show when={tab() === "vehicles"}>
            <section class="panel-section"><div class="section-heading"><div><span class="eyebrow">Reporting now</span><h2>Nearby vehicles</h2></div><span>{props.snapshot.nearbyVehicles.length} reporting</span></div><NearbyVehiclesContent vehicles={props.snapshot.nearbyVehicles} state={props.snapshot.state} searchRadiusMeters={props.snapshot.nearbyVehicleSearchRadiusMeters} onVehicle={props.onVehicle} /></section>
          </Show>

          <Show when={tab() === "info"}>
            <section class="panel-section station-info"><h2>Station information</h2><dl><div><dt>Stop ID</dt><dd>{props.snapshot.station.id}</dd></div><div><dt>Transport</dt><dd>{props.snapshot.station.transportModes.join(", ")}</dd></div><div><dt>Timezone</dt><dd>Europe/Oslo</dd></div></dl></section>
          </Show>
        </Show>
      </div>
    </aside>
  );
};

export interface VehiclePanelProps {
  readonly vehicle: VehicleState;
  readonly focus: FocusState;
  readonly sheet: "none" | "half" | "full";
  readonly onClose: () => void;
  readonly onFocus: () => void;
  readonly onPause: () => void;
  readonly onResume: () => void;
  readonly onUnfocus: () => void;
  readonly onStop: () => void;
  readonly onRetry: () => void;
  readonly onSheet: (sheet: "half" | "full") => void;
}

export const VehiclePanel: Component<VehiclePanelProps> = (props) => {
  const now = useClock();
  const [showAllStops, setShowAllStops] = createSignal(false);
  const visibleStops = () => showAllStops() ? props.vehicle.upcomingStops : props.vehicle.upcomingStops.slice(0, 6);
  return (
    <aside class={`detail-panel vehicle-panel sheet-${props.sheet}`} aria-label={`Line ${props.vehicle.lineCode ?? "unknown"} vehicle details`}>
      <button class="sheet-grabber" type="button" onClick={() => props.onSheet(props.sheet === "full" ? "half" : "full")} aria-label={props.sheet === "full" ? "Collapse vehicle sheet" : "Expand vehicle sheet"}><span /></button>
      <header class="panel-header">
        <div>
          <span class="panel-eyebrow">Vehicle · {props.vehicle.id}</span>
          <h1>Line {props.vehicle.lineCode ?? "Unknown"}</h1>
          <p class="route-name">{props.vehicle.routeName ?? "Route not reported"}</p>
        </div>
        <div class="panel-header-actions"><StatusChip state={props.vehicle.state} label={props.vehicle.state[0]?.toUpperCase() + props.vehicle.state.slice(1)} /><button class="icon-button" type="button" onClick={props.onClose} aria-label="Close vehicle panel"><Icon name="close" size={23} /></button></div>
      </header>

      <div class="panel-scroll">
        <Show when={props.vehicle.state === "stale"}>
          <FeedbackBanner tone="warning" title="Vehicle position is stale">Last seen {formatRelativeTime(props.vehicle.lastSeenAt, now())}. The map will update when a new position becomes available.</FeedbackBanner>
        </Show>
        <Show when={props.vehicle.state === "lost"}>
          <FeedbackBanner tone="danger" title="Vehicle no longer reported">The vehicle left the watched area or stopped reporting. Its last known location remains on the map.</FeedbackBanner>
        </Show>

        <div class="vehicle-summary">
          <div><span>Delay</span><strong class={props.vehicle.delaySeconds !== null && props.vehicle.delaySeconds > 0 ? "warning-text" : ""}>{formatDelay(props.vehicle.delaySeconds)}</strong></div>
          <div><span>Next stop</span><strong>{props.vehicle.nextStop?.name ?? "Not reported"}</strong></div>
          <div><span>Last seen</span><strong>{formatRelativeTime(props.vehicle.lastSeenAt, now())}</strong></div>
          <div><span>Direction</span><strong>{formatBearing(props.vehicle.bearing)}</strong></div>
        </div>

        <Show when={props.vehicle.state === "live" && props.focus === "none"}>
          <Button tone="primary" icon="focus" class="full-button" onClick={props.onFocus}>Focus this vehicle</Button>
          <p class="action-hint">Follow this vehicle on the map as its position updates.</p>
        </Show>
        <Show when={props.vehicle.state === "live" && props.focus !== "none"}>
          <div class="panel-actions"><Button tone="primary" icon={props.focus === "paused" ? "focus" : "pause"} onClick={() => props.focus === "paused" ? props.onResume() : props.onPause()}>{props.focus === "paused" ? "Resume follow" : "Pause follow"}</Button><Button icon="close" onClick={props.onUnfocus}>Unfocus</Button></div>
        </Show>
        <Show when={props.vehicle.state === "stale"}>
          <div class="panel-actions"><Button tone="primary" icon="refresh" onClick={props.onRetry}>Keep watching</Button><Button icon="close" onClick={props.onStop}>Stop watching</Button></div>
        </Show>
        <Show when={props.vehicle.state === "lost"}>
          <div class="panel-actions"><Button tone="primary" icon="refresh" onClick={props.onRetry}>Try again</Button><Button tone="danger" icon="close" onClick={props.onStop}>Stop following</Button></div>
        </Show>

        <section class="panel-section upcoming-stops">
          <div class="section-heading"><div><span class="eyebrow">Journey progress</span><h2>{props.vehicle.state === "lost" ? "Last known journey" : "Upcoming stops"}</h2></div></div>
          <Show when={props.vehicle.journey !== null && props.vehicle.journey.state !== "fresh" && props.vehicle.journey.warning !== null}>
            <FeedbackBanner tone="warning" title="Journey schedule may be stale">{props.vehicle.journey?.warning}</FeedbackBanner>
          </Show>
          <Show when={props.vehicle.upcomingStops.length > 0} fallback={<div class="empty-state compact"><Icon name="pin" size={24} /><p>{props.vehicle.journeyReference === null ? "This vehicle did not report a service journey." : props.vehicle.journey === null || props.vehicle.journey.state !== "fresh" ? "Journey details are temporarily unavailable." : "No further stops remain on this journey."}</p></div>}>
            <ol><For each={visibleStops()}>{(stop) => <li class={stop.current ? "is-current" : ""}><span /><strong>{stop.name}</strong><time datetime={stop.expectedAt ?? undefined}>{stop.expectedAt === null ? "—" : formatTransportTime(stop.expectedAt)}</time></li>}</For></ol>
            <Show when={props.vehicle.upcomingStops.length > 6}>
              <Button class="full-button" onClick={() => setShowAllStops((value) => !value)}>{showAllStops() ? "Show next 6" : `Show all ${props.vehicle.upcomingStops.length} stops`}</Button>
            </Show>
          </Show>
        </section>
      </div>
    </aside>
  );
};
