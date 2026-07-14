import { For, Show, type Component, type JSX } from "solid-js";
import type { Departure, NearbyVehicle, PassengerServiceState, ServiceState, StationVehicle, VehicleStatus } from "../types/domain";
import { useClock } from "../state/clock";
import { localize, useI18n, type Language, type LocalizedText } from "../state/i18n";
import { formatRelativeTime, formatTransportTime } from "../utils/format";
import { Icon, type IconName } from "./Icon";
import { vehicleModeIcon, vehicleModeLabel } from "./VehicleMode";

export type Tone = "positive" | "info" | "warning" | "danger" | "neutral";

export const FjordPulseLogo: Component<{ readonly compact?: boolean }> = (props) => {
  const i18n = useI18n();
  return (
    <a class="brand" href="/" aria-label={i18n.text({ nb: "FjordPulse-forside", en: "FjordPulse home" })}>
      <img class="brand-mark" src="/fjordpulse-mark.svg" width="44" height="36" alt="" />
      <span class={props.compact ? "sr-only" : undefined}>Fjord<span>Pulse</span></span>
    </a>
  );
};

function stateTone(state: ServiceState | VehicleStatus): Tone {
  if (state === "ok" || state === "connected" || state === "live") return "positive";
  if (state === "offline" || state === "lost") return "danger";
  if (state === "delayed" || state === "reconnecting" || state === "degraded" || state === "stale") return "warning";
  if (state === "connecting") return "info";
  return "neutral";
}

const stateLabels = {
  ok: { nb: "OK", en: "OK" },
  idle: { nb: "Inaktiv", en: "Idle" },
  connecting: { nb: "Kobler til", en: "Connecting" },
  connected: { nb: "Tilkoblet", en: "Connected" },
  reconnecting: { nb: "Kobler til på nytt", en: "Reconnecting" },
  delayed: { nb: "Forsinket", en: "Delayed" },
  offline: { nb: "Frakoblet", en: "Offline" },
  degraded: { nb: "Begrenset", en: "Degraded" },
  live: { nb: "Sanntid", en: "Live" },
  stale: { nb: "Utdatert", en: "Stale" },
  lost: { nb: "Mistet", en: "Lost" },
} as const satisfies Record<ServiceState | VehicleStatus, LocalizedText>;

export const StatusChip: Component<{
  readonly state: ServiceState | VehicleStatus;
  readonly label?: string | undefined;
  readonly icon?: IconName | undefined;
}> = (props) => {
  const i18n = useI18n();
  return (
    <span class={`status-chip tone-${stateTone(props.state)}`} role="status" data-state={props.state}>
      <span class="status-dot" aria-hidden="true" />
      <Show when={props.icon}>{(icon) => <Icon name={icon()} size={16} />}</Show>
      {props.label ?? i18n.text(stateLabels[props.state])}
    </span>
  );
};

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

export const SkeletonRows: Component<{ readonly count?: number }> = (props) => {
  const i18n = useI18n();
  return (
    <div class="skeleton-list" aria-label={i18n.text({ nb: "Laster transportdata", en: "Loading transport data" })} aria-busy="true">
      <For each={Array.from({ length: props.count ?? 4 })}>
        {(_, index) => (
          <div class="skeleton-row" style={{ "--skeleton-index": index() }}>
            <span /><div><span /><span /></div><span />
          </div>
        )}
      </For>
    </div>
  );
};

function departureLabel(departure: Departure, language: Language): string {
  if (departure.status === "cancelled") return localize(language, { nb: "Kansellert", en: "Cancelled" });
  if (departure.delaySeconds !== null && departure.delaySeconds > 0) return `+${Math.round(departure.delaySeconds / 60)} min`;
  if (departure.status === "realtime") return localize(language, { nb: "I rute", en: "On time" });
  return localize(language, { nb: "Rutetid", en: "Scheduled" });
}

function departureTone(departure: Departure): Tone {
  if (departure.status === "cancelled") return "danger";
  if (departure.delaySeconds !== null && departure.delaySeconds > 0) return "warning";
  if (departure.status === "realtime") return "positive";
  return "neutral";
}

export const DepartureRow: Component<{ readonly departure: Departure; readonly muted?: boolean }> = (props) => {
  const i18n = useI18n();
  return (
    <div class={`departure-row ${props.muted ? "is-muted" : ""}`} data-status={props.departure.status}>
      <time datetime={props.departure.expectedDepartureAt ?? props.departure.aimedDepartureAt}>{formatTransportTime(props.departure.expectedDepartureAt ?? props.departure.aimedDepartureAt, i18n.language())}</time>
      <span class={`line-badge line-${props.departure.lineCode?.toLowerCase() ?? "unknown"}`}>{props.departure.lineCode ?? "—"}</span>
      <span class="departure-destination">
        <strong>{props.departure.destination ?? i18n.text({ nb: "Reisemål utilgjengelig", en: "Destination unavailable" })}</strong>
        <Show when={props.departure.platform}>{(platform) => <small class="departure-platform">{i18n.text({ nb: "Plattform {platform}", en: "Platform {platform}" }, { platform: platform() })}</small>}</Show>
      </span>
      <span class={`row-status tone-${departureTone(props.departure)}`}>{departureLabel(props.departure, i18n.language())}</span>
    </div>
  );
};

function localizedVehicleRelation(relation: string, language: Language): string {
  if (language === "en") return relation;
  if (relation === "within the station search area") return "i søkeområdet rundt holdeplassen";
  if (relation.startsWith("towards ")) return `mot ${relation.slice("towards ".length)}`;
  if (relation.startsWith("near ")) return `nær ${relation.slice("near ".length)}`;
  return relation;
}

function stationVehicleCallLabel(vehicle: StationVehicle, language: Language): string {
  if (vehicle.progress === "at_station") return localize(language, { nb: "Ved holdeplassen nå", en: "At this station now" });
  const time = vehicle.stationCallAt === null ? null : formatTransportTime(vehicle.stationCallAt, language);
  if (vehicle.progress === "after_station") {
    return time === null
      ? localize(language, { nb: "Har allerede passert holdeplassen", en: "Already passed this station" })
      : localize(language, { nb: "Passerte kl. {time}", en: "Passed at {time}" }, { time });
  }
  if (vehicle.callRole === "starts_here") {
    return time === null
      ? localize(language, { nb: "Starter her · tidspunkt utilgjengelig", en: "Starts here · call time unavailable" })
      : localize(language, { nb: "Starter her kl. {time}", en: "Starts here at {time}" }, { time });
  }
  if (time !== null) return localize(language, { nb: "Ventet her kl. {time}", en: "Expected here at {time}" }, { time });
  return localize(language, { nb: "Tidspunkt for stoppet er utilgjengelig", en: "Call time unavailable" });
}

export const VehicleRow: Component<{ readonly vehicle: NearbyVehicle | StationVehicle; readonly onSelect?: (id: string) => void }> = (props) => {
  const now = useClock();
  const i18n = useI18n();
  const modeLabel = () => vehicleModeLabel(props.vehicle.transportMode, i18n.language());
  const nonPassenger = () => props.vehicle.passengerServiceState === "non_passenger";
  const relationLabel = () => {
    if ("callRole" in props.vehicle) return stationVehicleCallLabel(props.vehicle, i18n.language());
    const relation = localizedVehicleRelation(props.vehicle.relation, i18n.language());
    return relation;
  };
  const accessibleLabel = () => nonPassenger()
    ? i18n.text(
      {
        nb: "Åpne {mode}, ikke i passasjertrafikk. Posisjonsstatus: {state}. Kjøretøy-ID: {id}",
        en: "Open {mode}, not in passenger service. Position status: {state}. Vehicle ID: {id}",
      },
      { mode: modeLabel(), state: i18n.text(stateLabels[props.vehicle.state]), id: props.vehicle.id },
    )
    : i18n.text(
      {
        nb: "Åpne {mode} på linje {line}. {relation}. Status: {state}. Kjøretøy-ID: {id}",
        en: "Open {mode} on Line {line}. {relation}. Status: {state}. Vehicle ID: {id}",
      },
      {
        mode: modeLabel(),
        line: props.vehicle.lineCode ?? i18n.text({ nb: "ukjent", en: "unknown" }),
        relation: relationLabel(),
        state: i18n.text(stateLabels[props.vehicle.state]),
        id: props.vehicle.id,
      },
    );
  return (
    <button
      type="button"
      class={`vehicle-row ${nonPassenger() ? "service-non-passenger" : ""}`}
      onClick={() => props.onSelect?.(props.vehicle.id)}
      aria-label={accessibleLabel()}
    >
      <span class="vehicle-icon"><Icon name={vehicleModeIcon(props.vehicle.transportMode)} size={20} /></span>
      <Show when={!nonPassenger()}><span class="line-badge">{props.vehicle.lineCode ?? "—"}</span></Show>
      <span class="vehicle-copy">
        <strong><span class="vehicle-mode-label">{modeLabel()}</span> · {nonPassenger()
          ? i18n.text({ nb: "Ikke i passasjertrafikk", en: "Not in passenger service" })
          : relationLabel()}</strong>
        <span class="row-meta">{formatRelativeTime(props.vehicle.lastSeenAt, now(), i18n.language())}</span>
      </span>
      <span class={`status-dot tone-${stateTone(props.vehicle.state)}`} aria-label={i18n.text(stateLabels[props.vehicle.state])} />
      <Icon name="chevron" size={16} />
    </button>
  );
};

export const FocusPill: Component<{
  readonly line: string | null;
  readonly passengerServiceState: PassengerServiceState;
  readonly lastSeenAt: string;
  readonly paused: boolean;
  readonly onPause: () => void;
  readonly onResume: () => void;
  readonly onUnfocus: () => void;
}> = (props) => {
  const now = useClock();
  const i18n = useI18n();
  const nonPassenger = () => props.passengerServiceState === "non_passenger";
  return (
    <div class={`focus-pill ${props.paused ? "is-paused" : ""} ${nonPassenger() ? "service-non-passenger" : ""}`} role="status" aria-live="polite">
      <div>
        <span class="status-dot" />
        <strong>{props.paused
          ? i18n.text({ nb: "Følging satt på pause", en: "Follow paused" })
          : nonPassenger()
            ? i18n.text({ nb: "Følger kjøretøyet", en: "Following vehicle" })
            : i18n.text(
              { nb: "Følger linje {line}", en: "Following Line {line}" },
              { line: props.line ?? i18n.text({ nb: "ukjent", en: "unknown" }) },
            )}</strong>
        <small>{nonPassenger()
          ? i18n.text(
            { nb: "Ikke i passasjertrafikk · Sist sett {time}", en: "Not in passenger service · Last seen {time}" },
            { time: formatRelativeTime(props.lastSeenAt, now(), i18n.language()) },
          )
          : i18n.text(
            { nb: "Sist sett {time}", en: "Last seen {time}" },
            { time: formatRelativeTime(props.lastSeenAt, now(), i18n.language()) },
          )}</small>
      </div>
      <Button icon={props.paused ? "focus" : "pause"} onClick={() => props.paused ? props.onResume() : props.onPause()}>{props.paused ? i18n.text({ nb: "Fortsett", en: "Resume" }) : i18n.text({ nb: "Pause", en: "Pause" })}</Button>
      <Button icon="close" onClick={props.onUnfocus}>{i18n.text({ nb: "Slutt å følge", en: "Unfocus" })}</Button>
    </div>
  );
};
