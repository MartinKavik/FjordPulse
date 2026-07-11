import { For, Show, type Component, type JSX } from "solid-js";
import type { Departure, NearbyVehicle, ServiceState, Telemetry, VehicleStatus } from "../types/domain";
import { useClock } from "../state/clock";
import { formatRelativeTime, formatTransportTime } from "../utils/format";
import { Icon, type IconName } from "./Icon";

export type Tone = "positive" | "info" | "warning" | "danger" | "neutral";

export const FjordPulseLogo: Component<{ readonly compact?: boolean }> = (props) => (
  <a class="brand" href="/" aria-label="FjordPulse home">
    <svg class="brand-mark" viewBox="0 0 44 36" aria-hidden="true">
      <path d="M2 31 14 7l8 13L30 2l12 29-12-8-8 8-8-8-12 8Z" fill="currentColor" opacity=".95" />
      <path d="m5 33 9-5 8 5 8-5 9 5" fill="none" stroke="currentColor" stroke-width="2" />
    </svg>
    <span class={props.compact ? "sr-only" : undefined}>Fjord<span>Pulse</span></span>
  </a>
);

function stateTone(state: ServiceState | VehicleStatus): Tone {
  if (state === "ok" || state === "connected" || state === "live") return "positive";
  if (state === "offline" || state === "lost") return "danger";
  if (state === "delayed" || state === "reconnecting" || state === "degraded" || state === "stale") return "warning";
  if (state === "connecting") return "info";
  return "neutral";
}

export const StatusChip: Component<{
  readonly state: ServiceState | VehicleStatus;
  readonly label?: string | undefined;
  readonly icon?: IconName | undefined;
}> = (props) => (
  <span class={`status-chip tone-${stateTone(props.state)}`} role="status" data-state={props.state}>
    <span class="status-dot" aria-hidden="true" />
    <Show when={props.icon}>{(icon) => <Icon name={icon()} size={16} />}</Show>
    {props.label ?? props.state}
  </span>
);

export const Button: Component<{
  readonly children: JSX.Element;
  readonly tone?: "primary" | "secondary" | "danger" | "ghost";
  readonly icon?: IconName;
  readonly type?: "button" | "submit";
  readonly disabled?: boolean;
  readonly onClick?: JSX.EventHandlerUnion<HTMLButtonElement, MouseEvent>;
  readonly ariaLabel?: string;
  readonly class?: string;
}> = (props) => (
  <button
    type={props.type ?? "button"}
    class={`button button-${props.tone ?? "secondary"} ${props.class ?? ""}`}
    disabled={props.disabled}
    onClick={props.onClick}
    aria-label={props.ariaLabel}
  >
    <Show when={props.icon}>{(icon) => <Icon name={icon()} size={18} />}</Show>
    {props.children}
  </button>
);

export const FeedbackBanner: Component<{
  readonly tone: "warning" | "danger" | "info";
  readonly title: string;
  readonly children: JSX.Element;
}> = (props) => (
  <div class={`feedback-banner tone-${props.tone}`} role={props.tone === "danger" ? "alert" : "status"}>
    <Icon name={props.tone === "danger" ? "close" : "alert"} size={22} />
    <div><strong>{props.title}</strong><p>{props.children}</p></div>
  </div>
);

export const SkeletonRows: Component<{ readonly count?: number }> = (props) => (
  <div class="skeleton-list" aria-label="Loading transport data" aria-busy="true">
    <For each={Array.from({ length: props.count ?? 4 })}>
      {(_, index) => (
        <div class="skeleton-row" style={{ "--skeleton-index": index() }}>
          <span /><div><span /><span /></div><span />
        </div>
      )}
    </For>
  </div>
);

function departureLabel(departure: Departure): string {
  if (departure.status === "cancelled") return "Cancelled";
  if (departure.delaySeconds !== null && departure.delaySeconds > 0) return `+${Math.round(departure.delaySeconds / 60)} min`;
  if (departure.status === "realtime") return "On time";
  return "Scheduled";
}

function departureTone(departure: Departure): Tone {
  if (departure.status === "cancelled") return "danger";
  if (departure.delaySeconds !== null && departure.delaySeconds > 0) return "warning";
  if (departure.status === "realtime") return "positive";
  return "neutral";
}

export const DepartureRow: Component<{ readonly departure: Departure; readonly muted?: boolean }> = (props) => (
  <div class={`departure-row ${props.muted ? "is-muted" : ""}`} data-status={props.departure.status}>
    <time datetime={props.departure.expectedDepartureAt ?? props.departure.aimedDepartureAt}>{formatTransportTime(props.departure.expectedDepartureAt ?? props.departure.aimedDepartureAt)}</time>
    <span class={`line-badge line-${props.departure.lineCode?.toLowerCase() ?? "unknown"}`}>{props.departure.lineCode ?? "—"}</span>
    <strong>{props.departure.destination ?? "Destination unavailable"}</strong>
    <span class={`row-status tone-${departureTone(props.departure)}`}>{departureLabel(props.departure)}</span>
  </div>
);

export const VehicleRow: Component<{ readonly vehicle: NearbyVehicle; readonly onSelect?: (id: string) => void }> = (props) => {
  const now = useClock();
  return (
    <button type="button" class="vehicle-row" onClick={() => props.onSelect?.(props.vehicle.id)} aria-label={`Open Line ${props.vehicle.lineCode ?? "unknown"} vehicle`}>
      <span class="vehicle-icon"><Icon name="bus" size={20} /></span>
      <span class="line-badge">{props.vehicle.lineCode ?? "—"}</span>
      <strong>{props.vehicle.relation}</strong>
      <span class="row-meta">{formatRelativeTime(props.vehicle.lastSeenAt, now())}</span>
      <span class={`status-dot tone-${stateTone(props.vehicle.state)}`} aria-label={props.vehicle.state} />
      <Icon name="chevron" size={16} />
    </button>
  );
};

export const TelemetryStrip: Component<{ readonly telemetry: Telemetry }> = (props) => {
  const now = useClock();
  const entries = () => [
    { icon: "server" as const, label: "Backend", state: props.telemetry.backend, value: props.telemetry.backend === "checking" ? "checking…" : props.telemetry.backend },
    { icon: "wifi" as const, label: "Realtime", state: props.telemetry.realtime, value: props.telemetry.realtime === "idle" ? "ready" : props.telemetry.realtime },
    { icon: "refresh" as const, label: props.telemetry.entur === "not_used" ? "Transport" : "Entur", state: props.telemetry.entur, value: props.telemetry.entur === "not_used" ? "demo data" : props.telemetry.entur === "idle" ? "standby" : props.telemetry.entur },
    { icon: "clock" as const, label: "Refresh", state: props.telemetry.refreshMode, value: props.telemetry.realtime === "idle" ? "on demand" : props.telemetry.refreshMode },
  ];
  return (
    <footer class="telemetry-strip" aria-label="System telemetry">
      <For each={entries()}>{(item) => (
        <div class="telemetry-item">
          <Icon name={item.icon} size={20} />
          <span>{item.label}</span>
          <strong class={`state-${item.state}`}>{item.value}</strong>
        </div>
      )}</For>
      <div class="telemetry-item telemetry-update"><Icon name="clock" size={20} /><span>Last update</span><strong>{props.telemetry.lastUpdateAt === null ? "Awaiting data" : formatRelativeTime(props.telemetry.lastUpdateAt, now())}</strong></div>
    </footer>
  );
};

export const FocusPill: Component<{
  readonly line: string;
  readonly lastSeenAt: string;
  readonly paused: boolean;
  readonly onPause: () => void;
  readonly onResume: () => void;
  readonly onUnfocus: () => void;
}> = (props) => {
  const now = useClock();
  return (
    <div class={`focus-pill ${props.paused ? "is-paused" : ""}`} role="status">
      <div><span class="status-dot" /><strong>{props.paused ? "Follow paused" : `Following Line ${props.line}`}</strong><small>Last seen {formatRelativeTime(props.lastSeenAt, now())}</small></div>
      <Button icon={props.paused ? "focus" : "pause"} onClick={() => props.paused ? props.onResume() : props.onPause()}>{props.paused ? "Resume" : "Pause"}</Button>
      <Button icon="close" onClick={props.onUnfocus}>Unfocus</Button>
    </div>
  );
};
