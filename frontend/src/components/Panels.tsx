import { createSignal, For, Show, type Component } from "solid-js";
import type { FocusState, StationSnapshot, VehicleState } from "../types/domain";
import { Button, DepartureRow, FeedbackBanner, SkeletonRows, StatusChip, VehicleRow } from "./DesignSystem";
import { Icon } from "./Icon";

export const WelcomePanel: Component = () => (
  <aside class="detail-panel welcome-panel" aria-label="Welcome">
    <div class="welcome-eyebrow">Realtime transport · Norway</div>
    <h1>Norway in motion.</h1>
    <p>Select a station to see live departures and the vehicles that matter—without loading every bus in the country.</p>
    <div class="welcome-route" aria-hidden="true">
      <span /><span /><span /><span /><span />
    </div>
    <div class="welcome-features">
      <div><Icon name="map" size={21} /><span><strong>Station first</strong>Explore calm, useful clusters</span></div>
      <div><Icon name="activity" size={21} /><span><strong>Realtime on demand</strong>Watch only what you choose</span></div>
      <div><Icon name="focus" size={21} /><span><strong>Focus mode</strong>Follow a vehicle as it reports</span></div>
    </div>
  </aside>
);

export interface StationPanelProps {
  readonly snapshot: StationSnapshot;
  readonly sheet: "none" | "half" | "full";
  readonly onClose: () => void;
  readonly onRetry: () => void;
  readonly onVehicle: (vehicleId: string) => void;
  readonly onSheet: (sheet: "half" | "full") => void;
}

export const StationPanel: Component<StationPanelProps> = (props) => {
  const [tab, setTab] = createSignal<"departures" | "vehicles" | "info">("departures");
  const stateLabel = () => {
    if (props.snapshot.state === "fresh") return "Live";
    if (props.snapshot.state === "loading") return "Connecting";
    if (props.snapshot.state === "empty") return "Live";
    if (props.snapshot.state === "error") return "Error";
    return "Stale";
  };
  const chipState = () => props.snapshot.state === "error" ? "offline" as const : props.snapshot.state === "stale" ? "delayed" as const : props.snapshot.state === "loading" ? "connecting" as const : "connected" as const;

  return (
    <aside class={`detail-panel station-panel sheet-${props.sheet}`} aria-label={`${props.snapshot.station.name} station details`}>
      <button class="sheet-grabber" type="button" onClick={() => props.onSheet(props.sheet === "full" ? "half" : "full")} aria-label={props.sheet === "full" ? "Collapse station sheet" : "Expand station sheet"}><span /></button>
      <header class="panel-header">
        <div>
          <span class="panel-eyebrow">Station · Vestland</span>
          <h1>{props.snapshot.station.name}</h1>
          <div class="panel-meta"><StatusChip state={chipState()} label={stateLabel()} /><span>Updated {props.snapshot.state === "stale" ? "2 min" : "8s"} ago</span></div>
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
          <div class="watch-registering"><span class="spinner" /><strong>Registering live station watch</strong><p>The map stays available while FjordPulse fetches departures.</p></div>
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
                <div class="empty-state"><span><Icon name="clock" size={27} /></span><strong>No upcoming departures.</strong><p>This is a valid live result. Try again later or explore another station.</p></div>
              }>
                <div class="departure-list"><For each={props.snapshot.departures}>{(departure) => <DepartureRow departure={departure} muted={props.snapshot.state === "stale"} />}</For></div>
              </Show>
            </section>
            <section class="panel-section nearby-section">
              <div class="section-heading"><div><span class="eyebrow">Reporting now</span><h2>Nearby vehicles</h2></div></div>
              <Show when={props.snapshot.nearbyVehicles.length > 0} fallback={
                <div class="empty-state compact"><span><Icon name="bus" size={25} /></span><div><strong>No live vehicles currently reported nearby.</strong><p>A quiet result is not an error.</p></div></div>
              }>
                <div class="vehicle-list"><For each={props.snapshot.nearbyVehicles}>{(vehicle) => <VehicleRow vehicle={vehicle} onSelect={props.onVehicle} />}</For></div>
              </Show>
            </section>
          </Show>

          <Show when={tab() === "vehicles"}>
            <section class="panel-section"><div class="section-heading"><div><span class="eyebrow">Reporting now</span><h2>Nearby vehicles</h2></div></div><div class="vehicle-list"><For each={props.snapshot.nearbyVehicles}>{(vehicle) => <VehicleRow vehicle={vehicle} onSelect={props.onVehicle} />}</For></div></section>
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
  const delay = () => props.vehicle.delaySeconds === null ? "Not reported" : props.vehicle.delaySeconds === 0 ? "On time" : `+${Math.round(props.vehicle.delaySeconds / 60)} min`;
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
          <FeedbackBanner tone="warning" title="Vehicle position is stale">Last seen 2 min ago. FjordPulse can keep watching for the next real report.</FeedbackBanner>
        </Show>
        <Show when={props.vehicle.state === "lost"}>
          <FeedbackBanner tone="danger" title="Vehicle no longer reported">The vehicle left the watched area or stopped reporting. Its last known location remains on the map.</FeedbackBanner>
        </Show>

        <div class="vehicle-summary">
          <div><span>Delay</span><strong class={props.vehicle.delaySeconds !== null && props.vehicle.delaySeconds > 0 ? "warning-text" : ""}>{delay()}</strong></div>
          <div><span>Next stop</span><strong>{props.vehicle.nextStop?.name ?? "Not reported"}</strong></div>
          <div><span>Last seen</span><strong>{props.vehicle.state === "live" ? "6s ago" : "2 min ago"}</strong></div>
          <div><span>Direction</span><strong>{props.vehicle.bearing === null ? "Not reported" : `${Math.round(props.vehicle.bearing)}° NE`}</strong></div>
        </div>

        <Show when={props.vehicle.state === "live" && props.focus === "none"}>
          <Button tone="primary" icon="focus" class="full-button" onClick={props.onFocus}>Focus this vehicle</Button>
          <p class="action-hint">Focus starts a high-priority watch and follows new reported positions.</p>
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
          <Show when={props.vehicle.upcomingStops.length > 0} fallback={<div class="empty-state compact"><Icon name="pin" size={24} /><p>Upcoming stops are not reported for this journey.</p></div>}>
            <ol><For each={props.vehicle.upcomingStops}>{(stop) => <li class={stop.current ? "is-current" : ""}><span /><strong>{stop.name}</strong><time datetime={stop.expectedAt ?? undefined}>{stop.expectedAt === null ? "—" : new Intl.DateTimeFormat("en-GB", { timeZone: "Europe/Oslo", hour: "2-digit", minute: "2-digit" }).format(new Date(stop.expectedAt))}</time></li>}</For></ol>
          </Show>
        </section>
      </div>
    </aside>
  );
};
