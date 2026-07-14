import { createEffect, createMemo, createSignal, For, onCleanup, onMount, Show, type Component } from "solid-js";
import type { Departure, FocusState, MobileSheetState, StationDepartureBoard, StationSnapshot, StationVehicle, VehicleState } from "../types/domain";
import { Button, DepartureRow, FeedbackBanner, SkeletonRows, StatusChip, VehicleRow } from "./DesignSystem";
import { Icon } from "./Icon";
import { vehicleModeLabel } from "./VehicleMode";
import { useClock } from "../state/clock";
import { languageLocale, localize, useI18n, type Language, type LocalizedText } from "../state/i18n";
import { formatDelay, formatOsloDateTime, formatRelativeTime, formatTransportTime } from "../utils/format";
import { ApiClientError } from "../services/httpClient";

export type VisibleMobileSheetState = Exclude<MobileSheetState, "none">;

const mobileSheetOrder = ["peek", "half", "full"] as const satisfies readonly VisibleMobileSheetState[];
const SHEET_DRAG_THRESHOLD_PX = 40;
const SHEET_LONG_DRAG_PX = 180;
const SHEET_CLICK_SUPPRESSION_PX = 8;

function visibleMobileSheetState(state: MobileSheetState): VisibleMobileSheetState {
  return state === "none" ? "half" : state;
}

export function moveMobileSheet(
  state: MobileSheetState,
  direction: "up" | "down",
  steps = 1,
): VisibleMobileSheetState {
  const currentIndex = mobileSheetOrder.indexOf(visibleMobileSheetState(state));
  const offset = direction === "up" ? Math.max(1, steps) : -Math.max(1, steps);
  const nextIndex = Math.max(0, Math.min(mobileSheetOrder.length - 1, currentIndex + offset));
  return mobileSheetOrder[nextIndex]!;
}

function tappedMobileSheet(state: MobileSheetState): VisibleMobileSheetState {
  const visible = visibleMobileSheetState(state);
  if (visible === "full") return "half";
  if (visible === "peek") return "half";
  return "full";
}

type SheetPointerEvent = PointerEvent & { readonly currentTarget: HTMLButtonElement };
type SheetKeyboardEvent = KeyboardEvent & { readonly currentTarget: HTMLButtonElement };

const SheetGrabber: Component<{
  readonly kind: "station" | "vehicle";
  readonly sheet: MobileSheetState;
  readonly onSheet: (sheet: VisibleMobileSheetState) => void;
}> = (props) => {
  const i18n = useI18n();
  let activePointerId: number | null = null;
  let startY = 0;
  let startHeight = 0;
  let greatestDistance = 0;
  let panel: HTMLElement | null = null;
  let suppressClick = false;

  const state = () => visibleMobileSheetState(props.sheet);
  const resource = () => props.kind === "station"
    ? i18n.text({ nb: "holdeplasspanelet", en: "station sheet" })
    : i18n.text({ nb: "kjøretøypanelet", en: "vehicle sheet" });
  const actionLabel = () => state() === "full"
    ? i18n.text({ nb: "Minimer {resource} og vis mer av kartet", en: "Collapse {resource} and show more of the map" }, { resource: resource() })
    : state() === "peek"
      ? i18n.text({ nb: "Vis {resource}", en: "Show {resource}" }, { resource: resource() })
      : i18n.text({ nb: "Utvid {resource}", en: "Expand {resource}" }, { resource: resource() });
  const instructionsId = () => `${props.kind}-sheet-gesture-instructions`;
  const panelId = () => `${props.kind}-detail-sheet`;

  const clearDragPresentation = (button: HTMLButtonElement) => {
    panel?.classList.remove("is-dragging");
    panel?.style.removeProperty("height");
    if (activePointerId !== null && button.hasPointerCapture?.(activePointerId)) {
      button.releasePointerCapture?.(activePointerId);
    }
    activePointerId = null;
    panel = null;
  };

  const pointerDown = (event: SheetPointerEvent) => {
    if (!event.isPrimary || (event.pointerType === "mouse" && event.button !== 0)) return;
    activePointerId = event.pointerId;
    startY = event.clientY;
    greatestDistance = 0;
    panel = event.currentTarget.closest(".detail-panel") as HTMLElement | null;
    startHeight = panel?.getBoundingClientRect().height ?? 0;
    panel?.classList.add("is-dragging");
    event.currentTarget.setPointerCapture?.(event.pointerId);
  };

  const pointerMove = (event: SheetPointerEvent) => {
    if (activePointerId !== event.pointerId || panel === null) return;
    const deltaY = event.clientY - startY;
    greatestDistance = Math.max(greatestDistance, Math.abs(deltaY));
    if (greatestDistance >= SHEET_CLICK_SUPPRESSION_PX) event.preventDefault();
    const peekHeight = Math.max(112, Math.min(window.innerHeight * 0.15, 132));
    const fullHeight = Math.max(peekHeight, window.innerHeight - 158);
    const nextHeight = Math.max(peekHeight, Math.min(fullHeight, startHeight - deltaY));
    panel.style.height = `${Math.round(nextHeight)}px`;
  };

  const finishPointer = (event: SheetPointerEvent, cancelled = false) => {
    if (activePointerId !== event.pointerId) return;
    const deltaY = event.clientY - startY;
    const dragged = greatestDistance >= SHEET_CLICK_SUPPRESSION_PX;
    clearDragPresentation(event.currentTarget);
    if (cancelled || !dragged) return;

    suppressClick = true;
    if (Math.abs(deltaY) >= SHEET_DRAG_THRESHOLD_PX) {
      const steps = Math.abs(deltaY) >= SHEET_LONG_DRAG_PX ? 2 : 1;
      props.onSheet(moveMobileSheet(props.sheet, deltaY < 0 ? "up" : "down", steps));
    }
    window.setTimeout(() => { suppressClick = false; }, 0);
  };

  const keyDown = (event: SheetKeyboardEvent) => {
    const next = event.key === "ArrowUp"
      ? moveMobileSheet(props.sheet, "up")
      : event.key === "ArrowDown"
        ? moveMobileSheet(props.sheet, "down")
        : event.key === "Home"
          ? "peek"
          : event.key === "End"
            ? "full"
            : null;
    if (next === null) return;
    event.preventDefault();
    props.onSheet(next);
  };

  return (
    <button
      class="sheet-grabber"
      type="button"
      data-sheet-state={state()}
      aria-controls={panelId()}
      aria-describedby={instructionsId()}
      aria-label={actionLabel()}
      title={actionLabel()}
      onPointerDown={pointerDown}
      onPointerMove={pointerMove}
      onPointerUp={(event) => finishPointer(event)}
      onPointerCancel={(event) => finishPointer(event, true)}
      onKeyDown={keyDown}
      onClick={(event) => {
        if (suppressClick) {
          event.preventDefault();
          return;
        }
        props.onSheet(tappedMobileSheet(props.sheet));
      }}
    >
      <span class="sheet-grabber-bar" aria-hidden="true" />
      <span class="sheet-grabber-direction" aria-hidden="true"><Icon name="chevron" size={15} /></span>
      <span id={instructionsId()} class="sr-only">{i18n.text({
        nb: "Dra opp eller ned for å endre høyden på panelet. Bruk pil opp eller pil ned med tastatur.",
        en: "Drag up or down to resize the panel. Use the Up or Down Arrow key with a keyboard.",
      })}</span>
    </button>
  );
};

function localizedSourceMessage(message: string | null | undefined, language: Language, fallback: LocalizedText): string {
  if (message === null || message === undefined || message.trim() === "") return localize(language, fallback);
  if (language === "en") return message;

  const exact: Readonly<Record<string, string>> = {
    "Could not load station details.": "Kunne ikke laste detaljer for holdeplassen.",
    "Live updates are delayed. Showing the last known departures.": "Sanntidsoppdateringene er forsinket. Viser de sist kjente avgangene.",
    "Registering live watch…": "Starter sanntidsoppdateringer …",
    "Showing deterministic stale station data.": "Viser utdaterte deterministiske holdeplassdata.",
    "Realtime unavailable; polling fallback is active.": "Sanntid er utilgjengelig. Regelmessig oppdatering er aktiv.",
    "Journey details are temporarily unavailable.": "Reisedetaljene er midlertidig utilgjengelige.",
  };
  if (exact[message] !== undefined) return exact[message];

  const translated = message
    .replaceAll("Departures could not be refreshed; showing saved departure information.", "Avganger kunne ikke oppdateres. Viser lagret avgangsinformasjon.")
    .replaceAll("Departures are temporarily unavailable.", "Avganger er midlertidig utilgjengelige.")
    .replaceAll("Departures were refreshed.", "Avganger ble oppdatert.")
    .replaceAll("Nearby vehicle positions were refreshed; saved station-serving matches remain until departures reconnect.", "Kjøretøyposisjoner i nærheten ble oppdatert. Lagrede koblinger til ruter som stopper her, beholdes til avgangstjenesten er tilkoblet igjen.")
    .replaceAll("Nearby vehicle positions were refreshed; station-serving matches are unavailable until departures reconnect.", "Kjøretøyposisjoner i nærheten ble oppdatert. Koblinger til ruter som stopper her, er utilgjengelige til avgangstjenesten er tilkoblet igjen.")
    .replaceAll("Nearby vehicle positions could not be refreshed; showing saved positions.", "Kjøretøyposisjoner i nærheten kunne ikke oppdateres. Viser lagrede posisjoner.")
    .replaceAll("Nearby vehicle positions are temporarily unavailable.", "Kjøretøyposisjoner i nærheten er midlertidig utilgjengelige.")
    .replaceAll("Nearby vehicle positions were refreshed.", "Kjøretøyposisjoner i nærheten ble oppdatert.")
    .replaceAll("Station vehicle positions could not be refreshed; showing saved positions.", "Kjøretøyposisjoner for holdeplassen kunne ikke oppdateres. Viser lagrede posisjoner.")
    .replaceAll("Station vehicle positions are temporarily unavailable.", "Kjøretøyposisjoner for holdeplassen er midlertidig utilgjengelige.")
    .replaceAll("Nearby and station-serving vehicle positions were refreshed.", "Kjøretøy i nærheten og kjøretøy som stopper her, ble oppdatert.")
    .replace(/Entur will be retried after ([^.]+)\./, "FjordPulse prøver Entur på nytt etter $1.");
  return translated === message ? fallback.nb : translated;
}

const transportModeLabels: Readonly<Record<string, LocalizedText>> = {
  bus: { nb: "Buss", en: "Bus" },
  coach: { nb: "Ekspressbuss", en: "Coach" },
  tram: { nb: "Trikk", en: "Tram" },
  rail: { nb: "Tog", en: "Rail" },
  metro: { nb: "T-bane", en: "Metro" },
  water: { nb: "Båt", en: "Water" },
  air: { nb: "Fly", en: "Air" },
  taxi: { nb: "Taxi", en: "Taxi" },
  unknown: { nb: "Ukjent", en: "Unknown" },
};

const stationKindLabels: Readonly<Record<StationSnapshot["station"]["kind"], LocalizedText>> = {
  stop_place: { nb: "Holdeplass", en: "Stop place" },
  station: { nb: "Stasjon", en: "Station" },
  bus_station: { nb: "Busstasjon", en: "Bus station" },
  ferry_terminal: { nb: "Ferjeterminal", en: "Ferry terminal" },
  rail_station: { nb: "Togstasjon", en: "Train station" },
  tram_stop: { nb: "Trikkeholdeplass", en: "Tram stop" },
  metro_station: { nb: "T-banestasjon", en: "Metro station" },
  airport: { nb: "Flyplass", en: "Airport" },
  unknown: { nb: "Holdeplass", en: "Station" },
};

function transportModeLabel(mode: string, language: Language): string {
  const label = transportModeLabels[mode];
  return label === undefined ? mode : localize(language, label);
}

function stationKindLabel(kind: StationSnapshot["station"]["kind"], language: Language): string {
  return localize(language, stationKindLabels[kind]);
}

export interface WelcomePanelProps {
  readonly expanded: boolean;
  readonly onExpandedChange: (expanded: boolean) => void;
}

export const WelcomePanel: Component<WelcomePanelProps> = (props) => {
  const i18n = useI18n();
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
        aria-label={i18n.text({ nb: "Vis introduksjonen til FjordPulse", en: "Show FjordPulse introduction" })}
        aria-controls="fjordpulse-welcome"
        aria-expanded="false"
        title={i18n.text({ nb: "Vis velkomstpanelet", en: "Show FjordPulse welcome" })}
        onClick={() => changeExpanded(true)}
      >
        <Icon name="map" size={20} />
        <span>{i18n.text({ nb: "Om", en: "About" })}</span>
      </button>
    }>
      <aside id="fjordpulse-welcome" class="detail-panel welcome-panel" aria-label={i18n.text({ nb: "Velkommen", en: "Welcome" })}>
        <button
          ref={collapseButton}
          class="icon-button welcome-collapse"
          type="button"
          aria-label={i18n.text({ nb: "Skjul introduksjonen til FjordPulse", en: "Hide FjordPulse introduction" })}
          aria-controls="fjordpulse-welcome"
          aria-expanded="true"
          title={i18n.text({ nb: "Skjul velkomstpanelet", en: "Hide welcome panel" })}
          onClick={() => changeExpanded(false)}
        >
          <Icon name="close" size={19} />
        </button>
        <div class="welcome-eyebrow">{i18n.text({ nb: "Kollektivtransport i sanntid · Norge", en: "Realtime transport · Norway" })}</div>
        <h1>{i18n.text({ nb: "Norge i bevegelse.", en: "Norway in motion." })}</h1>
        <p>{i18n.text({ nb: "Finn en holdeplass, se kommende avganger og følg et kjøretøy langs ruten.", en: "Find a station, see upcoming departures, and follow a vehicle along its route." })}</p>
        <div class="welcome-route" aria-hidden="true">
          <span /><span /><span /><span /><span />
        </div>
        <div class="welcome-features">
          <div><Icon name="map" size={21} /><span><strong>{i18n.text({ nb: "Finn holdeplassen din", en: "Find your station" })}</strong>{i18n.text({ nb: "Søk etter holdeplass, sted eller linje", en: "Search by stop, place, or line" })}</span></div>
          <div><Icon name="activity" size={21} /><span><strong>{i18n.text({ nb: "Avganger i sanntid", en: "Live departures" })}</strong>{i18n.text({ nb: "Se oppdaterte tider og kjøretøy i nærheten", en: "See current times and nearby vehicles" })}</span></div>
          <div><Icon name="focus" size={21} /><span><strong>{i18n.text({ nb: "Følg et kjøretøy", en: "Follow a vehicle" })}</strong>{i18n.text({ nb: "Se ruten, holdeplassene og siste posisjon", en: "View its route, stops, and latest position" })}</span></div>
        </div>
      </aside>
    </Show>
  );
};

export interface StationPanelProps {
  readonly snapshot: StationSnapshot;
  readonly sheet: MobileSheetState;
  readonly onClose: () => void;
  readonly onRetry: () => void;
  readonly onVehicle: (vehicleId: string) => void;
  readonly onSheet: (sheet: VisibleMobileSheetState) => void;
  readonly onLoadDayDepartures?: ((stationId: string, date: string, limit: number, cursor: string | null, signal: AbortSignal, refresh?: boolean) => Promise<StationDepartureBoard>) | undefined;
}

interface NearbyVehiclesContentProps {
  readonly vehicles: StationSnapshot["nearbyVehicles"];
  readonly state: StationSnapshot["state"];
  readonly searchRadiusMeters: number | null;
  readonly onVehicle: (vehicleId: string) => void;
}

function nearbySearchArea(radiusMeters: number | null, language: Language): string {
  if (radiusMeters === null || !Number.isFinite(radiusMeters) || radiusMeters <= 0) {
    return localize(language, { nb: "nær denne holdeplassen", en: "near this station" });
  }
  if (radiusMeters < 1_000) {
    return localize(language, { nb: "innen {distance} m fra denne holdeplassen", en: "within {distance} m of this station" }, { distance: Math.round(radiusMeters) });
  }
  const kilometers = Math.round(radiusMeters / 100) / 10;
  const distance = new Intl.NumberFormat(languageLocale(language), { maximumFractionDigits: 1 }).format(kilometers);
  return localize(language, { nb: "innen {distance} km fra denne holdeplassen", en: "within {distance} km of this station" }, { distance });
}

const NearbyVehiclesContent: Component<NearbyVehiclesContentProps> = (props) => {
  const i18n = useI18n();
  const emptyTitle = () => {
    if (props.state === "fresh" || props.state === "empty") return i18n.text({ nb: "Ingen kjøretøy i nærheten.", en: "No nearby vehicles reported." });
    if (props.state === "refreshing") return i18n.text({ nb: "Oppdaterer kjøretøy i nærheten.", en: "Refreshing nearby vehicles." });
    if (props.state === "stale") return i18n.text({ nb: "Ingen lagrede kjøretøy i nærheten.", en: "No saved nearby vehicles." });
    if (props.state === "backoff" || props.state === "rate_limited") return i18n.text({ nb: "Oppdatering av kjøretøy er satt på pause.", en: "Nearby vehicle refresh paused." });
    return i18n.text({ nb: "Kjøretøy i nærheten er utilgjengelige.", en: "Nearby vehicles unavailable." });
  };
  const emptyMessage = () => {
    const area = nearbySearchArea(props.searchRadiusMeters, i18n.language());
    if (props.state === "fresh" || props.state === "empty") return i18n.text(
      { nb: "Fant ingen oppdaterte kjøretøyposisjoner {area}. Søket er fullført. Prøv igjen om litt.", en: "No live vehicle positions were found {area}. The search is complete; check again shortly." },
      { area },
    );
    if (props.state === "refreshing") return i18n.text(
      { nb: "Ser etter oppdaterte kjøretøyposisjoner {area}. Resultater kan vises snart.", en: "Checking for current vehicle positions {area}. Results may appear shortly." },
      { area },
    );
    if (props.state === "stale") return i18n.text(
      { nb: "Sanntidsoppdateringene er forsinket, og ingen lagrede kjøretøyposisjoner er tilgjengelige {area}.", en: "Live updates are delayed, and no saved vehicle positions are available {area}." },
      { area },
    );
    if (props.state === "backoff" || props.state === "rate_limited") return i18n.text(
      { nb: "Ingen lagrede kjøretøyposisjoner er tilgjengelige {area}. FjordPulse prøver igjen automatisk.", en: "No saved live vehicle positions are available {area}. FjordPulse will retry automatically." },
      { area },
    );
    return i18n.text(
      { nb: "Ingen oppdaterte kjøretøyposisjoner er tilgjengelige {area}. Prøv igjen om litt.", en: "No live vehicle positions are available {area}. Check again shortly." },
      { area },
    );
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

interface StationVehiclesContentProps {
  readonly snapshot: StationSnapshot;
  readonly onVehicle: (vehicleId: string) => void;
}

function stationCallTimestamp(vehicle: StationVehicle): number | null {
  if (vehicle.stationCallAt === null) return null;
  const timestamp = Date.parse(vehicle.stationCallAt);
  return Number.isFinite(timestamp) ? timestamp : null;
}

function dueAtStationWithinHour(vehicle: StationVehicle, now: number): boolean {
  if (vehicle.progress === "at_station") return true;
  if (vehicle.progress === "after_station") return false;
  const callAt = stationCallTimestamp(vehicle);
  if (callAt === null) return false;
  const untilCall = callAt - now;
  if (vehicle.progress === "before_station") return untilCall >= 0 && untilCall <= 60 * 60_000;
  return untilCall >= -5 * 60_000 && untilCall <= 60 * 60_000;
}

const StationVehiclesContent: Component<StationVehiclesContentProps> = (props) => {
  const now = useClock();
  const i18n = useI18n();
  const active = createMemo(() => props.snapshot.servingVehicles.filter((vehicle) => dueAtStationWithinHour(vehicle, now())));
  const departed = createMemo(() => props.snapshot.servingVehicles.filter((vehicle) => vehicle.progress === "after_station"));
  const laterOrUncertain = createMemo(() => props.snapshot.servingVehicles.filter((vehicle) => !dueAtStationWithinHour(vehicle, now()) && vehicle.progress !== "after_station"));
  const servingIds = () => new Set(props.snapshot.servingVehicles.map((vehicle) => vehicle.id));
  const otherNearby = () => props.snapshot.nearbyVehicles.filter((vehicle) => !servingIds().has(vehicle.id));
  const coverageMessage = () => {
    const coverage = props.snapshot.servingVehicleCoverage;
    if (coverage.windowStart === null || coverage.windowEnd === null) return i18n.text({
      nb: "FjordPulse kobler rapporterende kjøretøy til planlagte avganger som stopper her. Et koblet kjøretøy kan være utenfor området rundt holdeplassen.",
      en: "FjordPulse matches reporting vehicles to scheduled services that call here. A matched vehicle may be outside the nearby area.",
    });
    return i18n.text(
      { nb: "FjordPulse kobler rapporterende kjøretøy til planlagte avganger som stopper her mellom {start} og {end}. Et koblet kjøretøy kan være utenfor området rundt holdeplassen.", en: "FjordPulse matches reporting vehicles to scheduled services that call here between {start} and {end}. A matched vehicle may be outside the nearby area." },
      { start: formatOsloDateTime(coverage.windowStart, i18n.language()), end: formatOsloDateTime(coverage.windowEnd, i18n.language()) },
    );
  };
  const servingEmptyTitle = () => {
    if (props.snapshot.state === "refreshing" || props.snapshot.state === "loading") return i18n.text({ nb: "Oppdaterer kjøretøy for holdeplassen.", en: "Updating station-serving vehicles." });
    if (props.snapshot.state === "unavailable" || props.snapshot.state === "error") return i18n.text({ nb: "Kjøretøy for holdeplassen er utilgjengelige.", en: "Station-serving vehicles unavailable." });
    return i18n.text({ nb: "Ingen kjøretøy koblet til holdeplassen nå.", en: "No station-serving vehicle reported now." });
  };
  const servingEmptyMessage = () => {
    if (props.snapshot.state === "refreshing" || props.snapshot.state === "loading") return i18n.text({ nb: "Ser etter kjøretøy som stopper her. Resultater kan vises snart.", en: "Checking for vehicles that stop here. Results may appear shortly." });
    if (props.snapshot.state === "stale" || props.snapshot.state === "backoff" || props.snapshot.state === "rate_limited") return i18n.text({ nb: "Ingen lagrede kjøretøy kunne kobles til rutene som stopper her. FjordPulse prøver igjen automatisk.", en: "No saved vehicles could be matched to services that stop here. FjordPulse will retry automatically." });
    if (props.snapshot.state === "unavailable" || props.snapshot.state === "error") return i18n.text({ nb: "Søket etter kjøretøy som stopper her, kunne ikke fullføres. Prøv igjen om litt.", en: "The search for vehicles serving this station could not be completed. Try again shortly." });
    return i18n.text({ nb: "Ingen kjøretøy som rapporterer posisjon nå, kunne kobles til rutene som stopper her. Planlagte avganger kan fortsatt gå som normalt.", en: "No currently reporting vehicle could be matched to services that stop here. Scheduled departures may still operate normally." });
  };

  return (
    <>
      <section class="panel-section serving-vehicles-section">
        <div class="section-heading"><div><span class="eyebrow">{i18n.text({ nb: "Sanntidsposisjoner", en: "Live positions" })}</span><h2>{i18n.text({ nb: "Kjøretøy koblet til holdeplassen", en: "Vehicles connected to this station" })}</h2></div></div>
        <p class="action-hint">{i18n.text({ nb: "Gruppert etter når kjøretøyet skal være ved denne holdeplassen — ikke bare etter hvilken rute det kjører.", en: "Grouped by when each vehicle is due at this station—not merely by the route it serves." })}</p>
        <Show when={props.snapshot.servingVehicleCoverage.truncated}>
          <FeedbackBanner tone="warning" title={i18n.text({ nb: "Svært travel holdeplass", en: "Very busy station" })}>{i18n.text(
            { nb: "Grensen for søkeomfang ble nådd. {queried} ulike ruter fra svaret ble kontrollert, og flere kan finnes. Kommende avganger ble prioritert.", en: "The search coverage limit was reached. {queried} distinct services from the response were checked, and more may exist. Upcoming departures were prioritized." },
            { queried: props.snapshot.servingVehicleCoverage.queriedJourneyCount },
          )}</FeedbackBanner>
        </Show>
        <Show when={props.snapshot.servingVehicles.length > 0} fallback={<div class="empty-state compact" role="status"><span><Icon name="bus" size={25} /></span><div><strong>{servingEmptyTitle()}</strong><p>{servingEmptyMessage()}</p></div></div>}>
          <div class="vehicle-subgroup">
            <div class="subgroup-heading"><h3>{i18n.text({ nb: "Ved holdeplassen eller ventet innen 60 min", en: "At station or due within 60 minutes" })}</h3><span aria-label={i18n.text({ nb: "{count} kjøretøy", en: "{count} vehicles" }, { count: active().length })}>{active().length}</span></div>
            <Show when={active().length > 0} fallback={<p class="subgroup-empty">{i18n.text({ nb: "Ingen rapporterende kjøretøy er ventet her den neste timen.", en: "No reporting vehicle is due here in the next hour." })}</p>}>
              <div class="vehicle-list"><For each={active()}>{(vehicle) => <VehicleRow vehicle={vehicle} onSelect={props.onVehicle} />}</For></div>
            </Show>
          </div>
          <div class="vehicle-subgroup">
            <div class="subgroup-heading"><h3>{i18n.text({ nb: "Senere eller usikkert tidspunkt", en: "Later or timing uncertain" })}</h3><span aria-label={i18n.text({ nb: "{count} kjøretøy", en: "{count} vehicles" }, { count: laterOrUncertain().length })}>{laterOrUncertain().length}</span></div>
            <p class="subgroup-hint">{i18n.text({ nb: "Kjøretøy med et stopp mer enn 60 minutter frem i tid, et passert rutetidspunkt eller ukjent reiseforløp.", en: "Vehicles with a call more than 60 minutes away, an overdue scheduled call, or unknown journey progress." })}</p>
            <Show when={laterOrUncertain().length > 0} fallback={<p class="subgroup-empty">{i18n.text({ nb: "Ingen andre rapporterende kjøretøy har et senere eller usikkert stopp.", en: "No other reporting vehicles have a later or uncertain call." })}</p>}>
              <div class="vehicle-list"><For each={laterOrUncertain()}>{(vehicle) => <VehicleRow vehicle={vehicle} onSelect={props.onVehicle} />}</For></div>
            </Show>
          </div>
          <Show when={departed().length > 0}>
            <details class="station-disclosure passed-vehicles-disclosure">
              <summary><span>{i18n.text({ nb: "Har allerede passert denne holdeplassen", en: "Already passed this station" })}</span><span class="disclosure-count">{departed().length}</span><Icon name="chevron" size={16} /></summary>
              <div class="station-disclosure-content"><div class="vehicle-list"><For each={departed()}>{(vehicle) => <VehicleRow vehicle={vehicle} onSelect={props.onVehicle} />}</For></div></div>
            </details>
          </Show>
        </Show>
        <details class="station-disclosure coverage-disclosure">
          <summary><span>{i18n.text({ nb: "Slik kobles kjøretøy", en: "How vehicles are matched" })}</span><Icon name="chevron" size={16} /></summary>
          <div class="station-disclosure-content"><p>{coverageMessage()}</p></div>
        </details>
      </section>
      <section class="panel-section nearby-section">
        <div class="section-heading"><div><span class="eyebrow">{i18n.text({ nb: "Ikke koblet til et stopp her", en: "Not matched to a call here" })}</span><h2>{i18n.text({ nb: "Andre kjøretøy i nærheten", en: "Other nearby live vehicles" })}</h2></div><span aria-label={i18n.text({ nb: "{count} kjøretøy", en: "{count} vehicles" }, { count: otherNearby().length })}>{otherNearby().length}</span></div>
        <p class="action-hint">{i18n.text(
          { nb: "Andre rapporterte sanntidsposisjoner {area}.", en: "Other reported live positions {area}." },
          { area: nearbySearchArea(props.snapshot.nearbyVehicleSearchRadiusMeters, i18n.language()) },
        )}</p>
        <NearbyVehiclesContent vehicles={otherNearby()} state={props.snapshot.state} searchRadiusMeters={props.snapshot.nearbyVehicleSearchRadiusMeters} onVehicle={props.onVehicle} />
      </section>
    </>
  );
};

type DayDepartureLoader = NonNullable<StationPanelProps["onLoadDayDepartures"]>;

interface StationDeparturesContentProps {
  readonly snapshot: StationSnapshot;
  readonly now: number;
  readonly emptyTitle: string;
  readonly emptyMessage: string;
  readonly loadDayDepartures?: DayDepartureLoader | undefined;
}

function departureTimestamp(departure: Departure): number {
  const timestamp = Date.parse(departure.expectedDepartureAt ?? departure.aimedDepartureAt);
  return Number.isFinite(timestamp) ? timestamp : Number.MAX_SAFE_INTEGER;
}

function departureSemanticKey(departure: Departure): string {
  return [
    departure.id,
    departure.aimedDepartureAt,
    departure.platform ?? "",
    departure.lineCode ?? "",
    departure.destination ?? "",
  ].join("\u0000");
}

function osloLocalDate(timestamp: number): string {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Europe/Oslo",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(timestamp);
  const value = (type: Intl.DateTimeFormatPartTypes) => parts.find((part) => part.type === type)?.value ?? "";
  return `${value("year")}-${value("month")}-${value("day")}`;
}

function localizedDay(date: string, language: Language): string {
  const timestamp = Date.parse(`${date}T12:00:00Z`);
  if (!Number.isFinite(timestamp)) return date;
  return new Intl.DateTimeFormat(languageLocale(language), {
    timeZone: "Europe/Oslo",
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(timestamp);
}

function groupDeparturesByHour(departures: readonly Departure[], language: Language): readonly { readonly hour: string; readonly rows: readonly Departure[] }[] {
  const formatter = new Intl.DateTimeFormat(languageLocale(language), { timeZone: "Europe/Oslo", hour: "2-digit", minute: "2-digit" });
  const groups = new Map<string, Departure[]>();
  for (const departure of departures) {
    const hourStart = Math.floor(departureTimestamp(departure) / 3_600_000) * 3_600_000;
    const key = formatter.format(hourStart);
    groups.set(key, [...(groups.get(key) ?? []), departure]);
  }
  return [...groups.entries()].map(([hour, rows]) => ({ hour, rows }));
}

const StationDeparturesContent: Component<StationDeparturesContentProps> = (props) => {
  const i18n = useI18n();
  const [dayOpen, setDayOpen] = createSignal(false);
  const [dayLoaded, setDayLoaded] = createSignal(false);
  const [dayDepartures, setDayDepartures] = createSignal<readonly Departure[]>([]);
  const [dayPage, setDayPage] = createSignal<StationDepartureBoard["page"]>({ limit: 50, hasMore: false, nextCursor: null });
  const [dayComplete, setDayComplete] = createSignal(false);
  const [dayTotalCount, setDayTotalCount] = createSignal<number | null>(null);
  const [dayLoading, setDayLoading] = createSignal(false);
  const [dayError, setDayError] = createSignal<string | null>(null);
  const [dayErrorCode, setDayErrorCode] = createSignal<string | null>(null);
  let requestController: AbortController | null = null;
  let activeKey = "";

  const date = () => osloLocalDate(props.now);
  const resetDay = () => {
    requestController?.abort();
    requestController = null;
    setDayOpen(false);
    setDayLoaded(false);
    setDayDepartures([]);
    setDayPage({ limit: 50, hasMore: false, nextCursor: null });
    setDayComplete(false);
    setDayTotalCount(null);
    setDayLoading(false);
    setDayError(null);
    setDayErrorCode(null);
  };

  createEffect(() => {
    const nextKey = `${props.snapshot.stationId}\u0000${date()}`;
    if (activeKey === "") {
      activeKey = nextKey;
      return;
    }
    if (activeKey !== nextKey) {
      activeKey = nextKey;
      resetDay();
    }
  });
  onCleanup(() => requestController?.abort());

  const loadPage = async (cursor: string | null, replace: boolean, refresh = false) => {
    if (dayLoading()) return;
    if (props.loadDayDepartures === undefined) {
      setDayDepartures(props.snapshot.departures);
      setDayPage({ limit: Math.max(1, props.snapshot.departureBoard.limit), hasMore: false, nextCursor: null });
      setDayComplete(!props.snapshot.departureBoard.hasMore);
      setDayTotalCount(props.snapshot.departureBoard.hasMore ? null : props.snapshot.departures.length);
      setDayLoaded(true);
      return;
    }

    requestController?.abort();
    const controller = new AbortController();
    requestController = controller;
    setDayLoading(true);
    setDayError(null);
    setDayErrorCode(null);
    try {
      const board = await props.loadDayDepartures(props.snapshot.stationId, date(), 50, cursor, controller.signal, refresh);
      if (controller.signal.aborted) return;
      if (board.stationId !== props.snapshot.stationId || board.date !== date()) {
        throw new Error("The timetable response did not match the selected station and date.");
      }
      setDayDepartures((current) => {
        const source = replace ? board.departures : [...current, ...board.departures];
        const unique = new Map(source.map((departure) => [departureSemanticKey(departure), departure]));
        return [...unique.values()].sort((left, right) => departureTimestamp(left) - departureTimestamp(right));
      });
      setDayPage(board.page);
      setDayComplete(board.complete);
      setDayTotalCount(board.totalCount);
      setDayLoaded(true);
    } catch (error) {
      if (controller.signal.aborted) return;
      setDayError(error instanceof Error ? error.message : i18n.text({ nb: "Dagens rutetabell kunne ikke lastes.", en: "Today's timetable could not be loaded." }));
      setDayErrorCode(error instanceof ApiClientError ? error.code : null);
    } finally {
      if (requestController === controller) requestController = null;
      if (!controller.signal.aborted) setDayLoading(false);
    }
  };

  const openDay = () => {
    setDayOpen(true);
    if (!dayLoaded() && !dayLoading()) void loadPage(null, true);
  };
  const closeDay = () => {
    requestController?.abort();
    requestController = null;
    setDayLoading(false);
    setDayOpen(false);
  };
  const retryDay = () => {
    const incompleteSource = dayLoaded() && !dayComplete() && !dayPage().hasMore;
    const expiredCursor = dayErrorCode() === "invalid_cursor";
    void loadPage(
      incompleteSource || expiredCursor ? null : dayPage().nextCursor,
      incompleteSource || expiredCursor || !dayLoaded(),
      incompleteSource,
    );
  };
  const rowsForDay = dayDepartures;
  const earlier = createMemo(() => rowsForDay().filter((departure) => departureTimestamp(departure) < props.now));
  const upcoming = createMemo(() => rowsForDay().filter((departure) => departureTimestamp(departure) >= props.now));
  const nextRows = createMemo(() => {
    const rows = upcoming();
    if (rows.length === 0) return [];
    const firstTimestamp = departureTimestamp(rows[0]!);
    return rows.filter((departure) => departureTimestamp(departure) === firstTimestamp);
  });
  const later = createMemo(() => upcoming().slice(nextRows().length));
  const laterGroups = createMemo(() => groupDeparturesByHour(later(), i18n.language()));
  const previewSummary = () => props.snapshot.departureBoard.hasMore
    ? i18n.text({ nb: "{count} vist · flere i dag", en: "{count} shown · more today" }, { count: props.snapshot.departures.length })
    : i18n.text({ nb: "{count} kommende", en: "{count} upcoming" }, { count: props.snapshot.departures.length });
  const daySummary = () => {
    if (dayPage().hasMore && dayTotalCount() !== null) return i18n.text(
      { nb: "{loaded} av {total} lastet", en: "{loaded} of {total} loaded" },
      { loaded: dayDepartures().length, total: dayTotalCount()! },
    );
    if (dayPage().hasMore) return i18n.text(
      { nb: "{count} lastet · flere tilgjengelig", en: "{count} loaded · more available" },
      { count: dayDepartures().length },
    );
    if (dayComplete() && dayTotalCount() !== null) return i18n.text(
      { nb: "{count} avganger i dag", en: "{count} departures today" },
      { count: dayTotalCount()! },
    );
    return i18n.text({ nb: "Minst {count} lastet", en: "At least {count} loaded" }, { count: dayDepartures().length });
  };

  return (
    <section class="panel-section departures-section">
      <Show when={!dayOpen()} fallback={
        <>
          <div class="section-heading timetable-heading">
            <div><span class="eyebrow">{localizedDay(date(), i18n.language())}</span><h2>{i18n.text({ nb: "Dagens rutetabell", en: "Today's timetable" })}</h2></div>
            <span>{daySummary()}</span>
          </div>
          <div class="timetable-toolbar"><Button icon="chevron" onClick={closeDay}>{i18n.text({ nb: "Tilbake til neste avganger", en: "Back to next departures" })}</Button></div>
          <Show when={dayError() !== null}>
            <FeedbackBanner tone="warning" title={dayErrorCode() === "invalid_cursor"
              ? i18n.text({ nb: "Rutetabellsiden er utløpt", en: "This timetable page expired" })
              : dayLoaded()
                ? i18n.text({ nb: "Kunne ikke oppdatere dagens rutetabell", en: "Could not update today's timetable" })
                : i18n.text({ nb: "Kunne ikke laste dagens rutetabell", en: "Could not load today's timetable" })}>{dayErrorCode() === "invalid_cursor"
                ? dayDepartures().length > 0
                  ? i18n.text({ nb: "Lastede avganger beholdes. Start fra første side for å fortsette med en stabil, oppdatert rutetabell.", en: "Loaded departures are retained. Restart from the first page to continue with a stable, current timetable." })
                  : i18n.text({ nb: "Start fra første side for å fortsette med en stabil, oppdatert rutetabell.", en: "Restart from the first page to continue with a stable, current timetable." })
                : dayDepartures().length > 0
                  ? i18n.text({ nb: "Avganger som allerede er lastet, beholdes. Prøv igjen for å hente resten.", en: "Departures already loaded are retained. Retry to get the rest." })
                  : dayLoaded()
                    ? i18n.text({ nb: "Neste side kunne ikke lastes. Prøv igjen for å fortsette.", en: "The next page could not be loaded. Retry to continue." })
                    : i18n.text({ nb: "Ingen rutetabelldata ble lastet. Prøv igjen.", en: "No timetable data was loaded. Try again." })}</FeedbackBanner>
            <div class="panel-actions"><Button icon="refresh" onClick={retryDay}>{dayErrorCode() === "invalid_cursor" ? i18n.text({ nb: "Start rutetabellen på nytt", en: "Restart timetable" }) : i18n.text({ nb: "Prøv igjen", en: "Retry" })}</Button></div>
          </Show>
          <Show when={dayLoaded() && !dayComplete() && !dayPage().hasMore && dayError() === null}>
            <FeedbackBanner tone="warning" title={i18n.text({ nb: "Rutetabellen kan være ufullstendig", en: "Timetable may be incomplete" })}>{dayDepartures().length === 0
              ? i18n.text({ nb: "Datakilden kunne ikke bekrefte hele dagen. Ingen avganger ble returnert, men flere kan finnes.", en: "The data source could not confirm the whole day. No departures were returned, but some may still exist." })
              : i18n.text({ nb: "Datakilden kunne ikke bekrefte hele dagen. Viste avganger beholdes, men flere kan finnes.", en: "The data source could not confirm the whole day. Shown departures are retained, but more may exist." })}</FeedbackBanner>
            <div class="panel-actions"><Button icon="refresh" disabled={dayLoading()} onClick={() => void loadPage(null, true, true)}>{dayLoading() ? i18n.text({ nb: "Kontrollerer på nytt …", en: "Checking again…" }) : i18n.text({ nb: "Kontroller hele dagen på nytt", en: "Retry full timetable" })}</Button></div>
          </Show>
          <Show when={dayLoading() && !dayLoaded()}><div class="watch-registering" role="status"><span class="spinner" /><strong>{i18n.text({ nb: "Laster dagens avganger", en: "Loading today's departures" })}</strong><p>{i18n.text({ nb: "Henter rutetabellen i mindre deler.", en: "Fetching the timetable in manageable pages." })}</p></div><SkeletonRows count={6} /></Show>
          <Show when={!dayLoading() || dayLoaded()}>
            <Show when={earlier().length > 0}>
              <details class="station-disclosure timetable-earlier">
                <summary><span>{i18n.text({ nb: "Tidligere i dag", en: "Earlier today" })}</span><span class="disclosure-count">{earlier().length}</span><Icon name="chevron" size={16} /></summary>
                <div class="station-disclosure-content"><div class="departure-list"><For each={earlier()}>{(departure) => <DepartureRow departure={departure} muted />}</For></div></div>
              </details>
            </Show>
            <Show when={nextRows().length > 0}>
              <div class="timetable-group next-departure-group"><h3>{i18n.text({ nb: "Neste", en: "Next" })}</h3><div class="departure-list"><For each={nextRows()}>{(departure) => <DepartureRow departure={departure} muted={props.snapshot.state === "stale"} />}</For></div></div>
            </Show>
            <Show when={laterGroups().length > 0}>
              <div class="timetable-later"><h3>{i18n.text({ nb: "Senere i dag", en: "Later today" })}</h3><For each={laterGroups()}>{(group) => <div class="timetable-group"><h4>{group.hour}</h4><div class="departure-list"><For each={group.rows}>{(departure) => <DepartureRow departure={departure} muted={props.snapshot.state === "stale"} />}</For></div></div>}</For></div>
            </Show>
            <Show when={dayLoaded() && dayComplete() && rowsForDay().length === 0}><div class="empty-state" role="status"><span><Icon name="clock" size={27} /></span><strong>{i18n.text({ nb: "Ingen avganger denne dagen.", en: "No departures on this day." })}</strong><p>{i18n.text({ nb: "Velg en annen holdeplass eller prøv igjen senere.", en: "Choose another station or try again later." })}</p></div></Show>
            <Show when={dayLoaded() && dayComplete() && upcoming().length === 0 && earlier().length > 0}><p class="timetable-finished" role="status">{i18n.text({ nb: "Ingen flere avganger i dag.", en: "No more departures today." })}</p></Show>
            <Show when={dayLoaded() && dayPage().hasMore && dayPage().nextCursor !== null}>
              <div class="timetable-more"><Button tone="primary" icon="chevron" disabled={dayLoading()} onClick={() => void loadPage(dayPage().nextCursor, false)}>{dayLoading() ? i18n.text({ nb: "Laster flere …", en: "Loading more…" }) : i18n.text({ nb: "Vis 50 flere", en: "Show 50 more" })}</Button></div>
            </Show>
          </Show>
        </>
      }>
        <div class="section-heading"><div><span class="eyebrow">{i18n.text({ nb: "Fra nå", en: "From now" })}</span><h2>{i18n.text({ nb: "Neste avganger", en: "Next departures" })}</h2></div><span>{previewSummary()}</span></div>
        <p class="action-hint">{props.snapshot.departureBoard.hasMore
          ? i18n.text(
            { nb: "Viser {count} avganger frem til midnatt. Åpne dagens rutetabell for resten og tidligere avganger.", en: "Showing {count} departures through midnight. Open today's timetable for the rest and for earlier departures." },
            { count: props.snapshot.departures.length },
          )
          : i18n.text({ nb: "Alle kjente avganger som gjenstår frem til midnatt, vises. Åpne dagens rutetabell for tidligere avganger.", en: "All known departures remaining through midnight are shown. Open today's timetable for earlier departures." })}</p>
        <Show when={props.snapshot.departures.length > 0} fallback={<div class="empty-state" role="status" data-state={props.snapshot.state === "fresh" || props.snapshot.state === "empty" ? "empty" : "unavailable"}><span><Icon name="clock" size={27} /></span><strong>{props.emptyTitle}</strong><p>{props.emptyMessage}</p></div>}>
          <div class="departure-list"><For each={props.snapshot.departures}>{(departure) => <DepartureRow departure={departure} muted={props.snapshot.state === "stale"} />}</For></div>
        </Show>
        <div class="timetable-open"><Button tone="primary" icon="clock" onClick={openDay}>{i18n.text({ nb: "Vis dagens rutetabell", en: "View today's timetable" })}</Button></div>
      </Show>
    </section>
  );
};

const StationDetailsContent: Component<{ readonly snapshot: StationSnapshot }> = (props) => {
  const i18n = useI18n();
  const unavailable = () => i18n.text({ nb: "Ikke tilgjengelig", en: "Not available" });
  const modes = () => props.snapshot.station.transportModes.length === 0
    ? unavailable()
    : props.snapshot.station.transportModes.map((mode) => transportModeLabel(mode, i18n.language())).join(", ");
  const coordinate = () => {
    const formatter = new Intl.NumberFormat(languageLocale(i18n.language()), {
      minimumFractionDigits: 5,
      maximumFractionDigits: 5,
    });
    const separator = i18n.language() === "nb" ? "; " : ", ";
    return `${formatter.format(props.snapshot.station.latitude)}${separator}${formatter.format(props.snapshot.station.longitude)}`;
  };
  const nearbyArea = () => nearbySearchArea(props.snapshot.nearbyVehicleSearchRadiusMeters, i18n.language());

  return (
    <>
      <section class="panel-section station-info">
        <h2>{i18n.text({ nb: "Om holdeplassen", en: "About this station" })}</h2>
        <dl class="station-facts">
          <div><dt>{i18n.text({ nb: "Holdeplasstype", en: "Station type" })}</dt><dd>{stationKindLabel(props.snapshot.station.kind, i18n.language())}</dd></div>
          <Show when={props.snapshot.station.locality}>{(locality) => <div><dt>{i18n.text({ nb: "Sted", en: "Area" })}</dt><dd>{locality()}</dd></div>}</Show>
          <Show when={props.snapshot.station.municipality}>{(municipality) => <div><dt>{i18n.text({ nb: "Kommune", en: "Municipality" })}</dt><dd>{municipality()}</dd></div>}</Show>
          <div><dt>{i18n.text({ nb: "Transport", en: "Transport" })}</dt><dd>{modes()}</dd></div>
        </dl>
      </section>

      <section class="panel-section station-scope">
        <h2>{i18n.text({ nb: "Dette finner du her", en: "What you can see here" })}</h2>
        <div class="station-scope-grid">
          <div><span><Icon name="clock" size={18} /></span><p><strong>{i18n.text({ nb: "Avganger", en: "Departures" })}</strong>{i18n.text({ nb: "Kommende avganger med planlagte og oppdaterte tider.", en: "Upcoming departures with scheduled and updated times." })}</p></div>
          <div><span><Icon name="bus" size={18} /></span><p><strong>{i18n.text({ nb: "Kjøretøy", en: "Vehicles" })}</strong>{i18n.text(
            { nb: "Rapporterende kjøretøy koblet til avganger som stopper her, pluss andre sanntidsposisjoner {area}. Koblede kjøretøy kan være lenger unna.", en: "Reporting vehicles matched to services that call here, plus other live positions {area}. Matched vehicles may be farther away." },
            { area: nearbyArea() },
          )}</p></div>
        </div>
      </section>

      <details class="station-disclosure technical-disclosure">
        <summary><span>{i18n.text({ nb: "Tekniske detaljer", en: "Technical details" })}</span><Icon name="chevron" size={16} /></summary>
        <div class="station-disclosure-content">
          <dl class="technical-facts">
            <div><dt>{i18n.text({ nb: "Stopp-ID", en: "Stop ID" })}</dt><dd>{props.snapshot.station.id}</dd></div>
            <div><dt>{i18n.text({ nb: "Koordinater", en: "Coordinates" })}</dt><dd>{coordinate()}</dd></div>
            <div><dt>{i18n.text({ nb: "Tidssone", en: "Timezone" })}</dt><dd>Europe/Oslo</dd></div>
          </dl>
        </div>
      </details>
    </>
  );
};

const stationStateLabels = {
  loading: { nb: "Kobler til", en: "Connecting" },
  fresh: { nb: "Sanntid", en: "Live" },
  refreshing: { nb: "Oppdaterer", en: "Refreshing" },
  empty: { nb: "Sanntid", en: "Live" },
  stale: { nb: "Utdatert", en: "Stale" },
  unavailable: { nb: "Utilgjengelig", en: "Unavailable" },
  error: { nb: "Feil", en: "Error" },
  backoff: { nb: "Venter", en: "Backoff" },
  rate_limited: { nb: "Forespørsler begrenset", en: "Rate limited" },
} as const satisfies Record<StationSnapshot["state"], LocalizedText>;

export const StationPanel: Component<StationPanelProps> = (props) => {
  const now = useClock();
  const i18n = useI18n();
  let heading: HTMLHeadingElement | undefined;
  onMount(() => queueMicrotask(() => heading?.focus({ preventScroll: true })));
  type StationTab = "departures" | "vehicles" | "details";
  const tabOrder: readonly StationTab[] = ["departures", "vehicles", "details"];
  const tabButtons: Partial<Record<StationTab, HTMLButtonElement>> = {};
  const [tab, setTab] = createSignal<StationTab>("departures");
  let panelScroll: HTMLDivElement | undefined;
  const activateTab = (next: StationTab, moveFocus = false) => {
    setTab(next);
    if (panelScroll !== undefined) panelScroll.scrollTop = 0;
    if (moveFocus) queueMicrotask(() => tabButtons[next]?.focus());
  };
  const moveTabFocus = (event: KeyboardEvent, current: StationTab) => {
    const currentIndex = tabOrder.indexOf(current);
    const nextIndex = event.key === "ArrowRight"
      ? (currentIndex + 1) % tabOrder.length
      : event.key === "ArrowLeft"
        ? (currentIndex - 1 + tabOrder.length) % tabOrder.length
        : event.key === "Home"
          ? 0
          : event.key === "End"
            ? tabOrder.length - 1
            : null;
    if (nextIndex === null) return;
    event.preventDefault();
    const next = tabOrder[nextIndex]!;
    activateTab(next, true);
  };
  const stateLabel = () => i18n.text(stationStateLabels[props.snapshot.state]);
  const chipState = () => ({
    loading: "connecting", fresh: "connected", refreshing: "connecting", empty: "connected",
    stale: "delayed", unavailable: "offline", error: "offline", backoff: "delayed", rate_limited: "delayed",
  } as const)[props.snapshot.state];
  const locality = () => props.snapshot.station.locality ?? props.snapshot.station.municipality;
  const loadingTitle = () => tab() === "vehicles"
    ? i18n.text({ nb: "Laster kjøretøy for holdeplassen", en: "Loading station vehicles" })
    : i18n.text({ nb: "Laster avganger", en: "Loading departures" });
  const loadingMessage = () => tab() === "vehicles"
    ? i18n.text({ nb: "Ser etter kjøretøy som stopper her, og andre posisjoner i nærheten.", en: "Checking for vehicles that stop here and other nearby positions." })
    : i18n.text({ nb: "Henter de nyeste avgangstidene.", en: "Getting the latest departure times." });
  const errorTitle = () => tab() === "vehicles"
    ? i18n.text({ nb: "Kunne ikke laste kjøretøyposisjoner.", en: "Could not load vehicle positions." })
    : i18n.text({ nb: "Kunne ikke laste avganger.", en: "Could not load departures." });
  const departureEmptyTitle = () => {
    if (props.snapshot.state === "refreshing") return i18n.text({ nb: "Oppdaterer avganger.", en: "Refreshing departures." });
    if (props.snapshot.state === "stale") return i18n.text({ nb: "Ingen lagrede kommende avganger.", en: "No saved upcoming departures." });
    if (props.snapshot.state === "backoff" || props.snapshot.state === "rate_limited") return i18n.text({ nb: "Oppdatering av avganger er satt på pause.", en: "Departure refresh paused." });
    if (props.snapshot.state === "unavailable") return i18n.text({ nb: "Avganger er utilgjengelige.", en: "Departures unavailable." });
    return i18n.text({ nb: "Ingen flere avganger i dag.", en: "No more departures today." });
  };
  const departureEmptyMessage = () => {
    if (props.snapshot.state === "refreshing") return i18n.text({ nb: "Ser etter de nyeste avgangstidene. Resultater kan vises snart.", en: "Checking for the latest departure times. Results may appear shortly." });
    if (props.snapshot.state === "stale" || props.snapshot.state === "backoff" || props.snapshot.state === "rate_limited") return i18n.text({ nb: "Ingen lagrede avgangstider er tilgjengelige. FjordPulse prøver igjen automatisk.", en: "No saved departure times are available. FjordPulse will retry automatically." });
    if (props.snapshot.state === "unavailable") return i18n.text({ nb: "Avgangstidene kunne ikke hentes. Prøv igjen om litt.", en: "Departure times could not be loaded. Try again shortly." });
    return i18n.text({ nb: "Rutetabellen er kontrollert frem til midnatt i tidssonen Europe/Oslo.", en: "The timetable was checked through midnight in the Europe/Oslo time zone." });
  };

  return (
    <aside id="station-detail-sheet" class={`detail-panel station-panel sheet-${props.sheet}`} data-sheet-state={visibleMobileSheetState(props.sheet)} aria-label={i18n.text({ nb: "Detaljer for holdeplassen {name}", en: "{name} station details" }, { name: props.snapshot.station.name })}>
      <SheetGrabber kind="station" sheet={props.sheet} onSheet={props.onSheet} />
      <header class="panel-header">
        <div>
          <span class="panel-eyebrow">{i18n.text({ nb: "Holdeplass", en: "Station" })}<Show when={locality()}>{(value) => ` · ${value()}`}</Show></span>
          <h1 ref={heading} tabIndex={-1}>{props.snapshot.station.name}</h1>
          <div class="panel-meta">
            <Show when={props.snapshot.state !== "fresh" && props.snapshot.state !== "empty"}>
              <StatusChip state={chipState()} label={stateLabel()} />
            </Show>
            <span>{i18n.text(
              { nb: "Data oppdatert {time}", en: "Data updated {time}" },
              { time: formatRelativeTime(props.snapshot.updatedAt, now(), i18n.language()) },
            )}</span>
          </div>
        </div>
        <button class="icon-button" type="button" onClick={props.onClose} aria-label={i18n.text({ nb: "Lukk holdeplasspanelet", en: "Close station panel" })}><Icon name="close" size={23} /></button>
      </header>

      <div class="panel-tabs" role="tablist" aria-label={i18n.text({ nb: "Deler av holdeplasspanelet", en: "Station sections" })}>
        <button ref={(element) => { tabButtons.departures = element; }} id="station-tab-departures" role="tab" aria-controls={tab() === "departures" ? "station-panel-departures" : undefined} aria-selected={tab() === "departures"} tabIndex={tab() === "departures" ? 0 : -1} onKeyDown={(event) => moveTabFocus(event, "departures")} onClick={() => activateTab("departures")}><Icon name="clock" size={17} /><span>{i18n.text({ nb: "Avganger", en: "Departures" })}</span></button>
        <button ref={(element) => { tabButtons.vehicles = element; }} id="station-tab-vehicles" role="tab" aria-controls={tab() === "vehicles" ? "station-panel-vehicles" : undefined} aria-selected={tab() === "vehicles"} tabIndex={tab() === "vehicles" ? 0 : -1} onKeyDown={(event) => moveTabFocus(event, "vehicles")} onClick={() => activateTab("vehicles")}><Icon name="bus" size={17} /><span>{i18n.text({ nb: "Kjøretøy", en: "Vehicles" })}</span></button>
        <button ref={(element) => { tabButtons.details = element; }} id="station-tab-details" role="tab" aria-controls={tab() === "details" ? "station-panel-details" : undefined} aria-selected={tab() === "details"} tabIndex={tab() === "details" ? 0 : -1} onKeyDown={(event) => moveTabFocus(event, "details")} onClick={() => activateTab("details")}><Icon name="pin" size={17} /><span>{i18n.text({ nb: "Detaljer", en: "Details" })}</span></button>
      </div>

      <div ref={panelScroll} class="panel-scroll" id={`station-panel-${tab()}`} role="tabpanel" aria-labelledby={`station-tab-${tab()}`} tabIndex={0}>
        <Show when={tab() === "details"}>
          <Show when={props.snapshot.state === "error"}>
            <FeedbackBanner tone="danger" title={i18n.text({ nb: "Sanntidsinnhold er utilgjengelig", en: "Live content unavailable" })}>{i18n.text({ nb: "Holdeplassinformasjonen nedenfor er fortsatt tilgjengelig. Prøv på nytt for avganger og kjøretøy.", en: "The station information below is still available. Retry to load departures and vehicles." })}</FeedbackBanner>
            <div class="panel-actions"><Button tone="primary" icon="refresh" onClick={props.onRetry}>{i18n.text({ nb: "Prøv igjen", en: "Retry" })}</Button></div>
          </Show>
          <StationDetailsContent snapshot={props.snapshot} />
        </Show>

        <Show when={tab() !== "details"}>
          <Show when={props.snapshot.state === "loading"}>
            <div class="watch-registering"><span class="spinner" /><strong>{loadingTitle()}</strong><p>{loadingMessage()}</p></div>
            <SkeletonRows count={5} />
          </Show>

          <Show when={props.snapshot.state === "error"}>
            <FeedbackBanner tone="danger" title={errorTitle()}>
              {localizedSourceMessage(props.snapshot.message, i18n.language(), { nb: "Transportkilden svarte ikke. Kartet og søket er fortsatt tilgjengelige.", en: "The transport source did not respond. Your map and search remain available." })}
            </FeedbackBanner>
            <div class="panel-actions"><Button tone="primary" icon="refresh" onClick={props.onRetry}>{i18n.text({ nb: "Prøv igjen", en: "Retry" })}</Button><Button icon="close" onClick={props.onClose}>{i18n.text({ nb: "Lukk panelet", en: "Close panel" })}</Button></div>
            <div class="disabled-section"><Icon name={tab() === "vehicles" ? "bus" : "clock"} size={22} /><span>{tab() === "vehicles"
              ? i18n.text({ nb: "Kjøretøyposisjoner er utilgjengelige", en: "Vehicle positions unavailable" })
              : i18n.text({ nb: "Avganger er utilgjengelige", en: "Departures unavailable" })}</span></div>
          </Show>

          <Show when={props.snapshot.state !== "loading" && props.snapshot.state !== "error"}>
            <Show when={props.snapshot.state === "stale" || props.snapshot.state === "backoff" || props.snapshot.state === "rate_limited"}>
              <FeedbackBanner tone="warning" title={props.snapshot.state === "stale"
                ? i18n.text({ nb: "Sanntidsdata er forsinket", en: "Live data delayed" })
                : i18n.text({ nb: "Entur-oppdateringen er satt på pause", en: "Entur refresh paused" })}>
                {localizedSourceMessage(props.snapshot.message, i18n.language(), { nb: "Viser den sist kjente transportinformasjonen mens FjordPulse kobler til på nytt.", en: "Showing the last known transport information while FjordPulse reconnects." })}
              </FeedbackBanner>
            </Show>

            <Show when={tab() === "departures"}>
              <StationDeparturesContent snapshot={props.snapshot} now={now()} emptyTitle={departureEmptyTitle()} emptyMessage={departureEmptyMessage()} loadDayDepartures={props.onLoadDayDepartures} />
            </Show>

            <Show when={tab() === "vehicles"}>
              <StationVehiclesContent snapshot={props.snapshot} onVehicle={props.onVehicle} />
            </Show>
          </Show>
        </Show>
      </div>
    </aside>
  );
};

export interface VehiclePanelProps {
  readonly vehicle: VehicleState;
  readonly focus: FocusState;
  readonly refreshState?: "idle" | "refreshing" | "error";
  readonly sheet: MobileSheetState;
  readonly onClose: () => void;
  readonly onFocus: () => void;
  readonly onPause: () => void;
  readonly onResume: () => void;
  readonly onUnfocus: () => void;
  readonly onStop: () => void;
  readonly onRetry: () => void;
  readonly onSheet: (sheet: VisibleMobileSheetState) => void;
}

const vehicleStateLabels = {
  live: { nb: "Sanntid", en: "Live" },
  stale: { nb: "Utdatert", en: "Stale" },
  lost: { nb: "Posisjon utilgjengelig", en: "Position unavailable" },
} as const satisfies Record<VehicleState["state"], LocalizedText>;

function journeyCallIndex(vehicle: VehicleState): number {
  const calls = vehicle.journey?.calls ?? [];
  const monitored = vehicle.monitoredCall;
  if (monitored !== null) {
    const exact = calls.findIndex((call) => call.order === monitored.order
      && (monitored.stopPointRef === null || call.quayId === monitored.stopPointRef));
    if (exact >= 0) return exact;

    if (monitored.stopPointRef !== null) {
      const byQuay = calls.findIndex((call) => call.quayId === monitored.stopPointRef);
      if (byQuay >= 0) return byQuay;
    }

    const byOrder = calls.findIndex((call) => call.order === monitored.order);
    if (byOrder >= 0) return byOrder;
  }

  const nextStop = vehicle.nextStop;
  if (nextStop === null) return -1;
  return calls.findIndex((call) => call.order === nextStop.order
    && (nextStop.quayId === null || call.quayId === nextStop.quayId)
    && (nextStop.stopPlaceId === null || call.stopPlaceId === nextStop.stopPlaceId));
}

function previousStopName(vehicle: VehicleState): string | null {
  const calls = vehicle.journey?.calls ?? [];
  for (let index = journeyCallIndex(vehicle) - 1; index >= 0; index -= 1) {
    const call = calls[index];
    if (call !== undefined && !call.cancellation) return call.name;
  }
  return null;
}

export const VehiclePanel: Component<VehiclePanelProps> = (props) => {
  const now = useClock();
  const i18n = useI18n();
  let heading: HTMLHeadingElement | undefined;
  onMount(() => queueMicrotask(() => heading?.focus({ preventScroll: true })));
  const [showAllStops, setShowAllStops] = createSignal(false);
  const visibleStops = () => showAllStops() ? props.vehicle.upcomingStops : props.vehicle.upcomingStops.slice(0, 6);
  const previousStop = () => previousStopName(props.vehicle);
  const modeLabel = () => vehicleModeLabel(props.vehicle.transportMode, i18n.language());
  const modeLabelInSentence = () => i18n.language() === "nb" ? modeLabel().toLocaleLowerCase("nb-NO") : modeLabel();
  const nonPassenger = () => props.vehicle.passengerServiceState === "non_passenger";
  const hasCachedJourney = () => props.vehicle.journey?.lastSuccessfulAt != null;
  const nonPassengerPositionSubtitle = () => props.vehicle.state === "live"
    ? i18n.text({ nb: "Posisjonen oppdateres fortsatt", en: "Position is still updating" })
    : i18n.text({ nb: "Siste kjente posisjon vises", en: "Last known position is shown" });
  const panelLabel = () => nonPassenger()
    ? i18n.text(
      { nb: "Detaljer for {mode} utenfor passasjertrafikk", en: "{mode} details, not in passenger service" },
      { mode: modeLabelInSentence() },
    )
    : i18n.text(
      { nb: "Detaljer for {mode} på linje {line}", en: "{mode} details on Line {line}" },
      { mode: modeLabelInSentence(), line: props.vehicle.lineCode ?? i18n.text({ nb: "ukjent", en: "unknown" }) },
    );
  return (
    <aside id="vehicle-detail-sheet" class={`detail-panel vehicle-panel sheet-${props.sheet} ${nonPassenger() ? "service-non-passenger" : ""}`} data-sheet-state={visibleMobileSheetState(props.sheet)} aria-label={panelLabel()}>
      <SheetGrabber kind="vehicle" sheet={props.sheet} onSheet={props.onSheet} />
      <header class="panel-header">
        <div>
          <span class="panel-eyebrow">{modeLabel()} · {props.vehicle.id}</span>
          <h1 ref={heading} tabIndex={-1}>{nonPassenger()
            ? i18n.text({ nb: "Ikke i passasjertrafikk", en: "Not in passenger service" })
            : i18n.text({ nb: "Linje {line}", en: "Line {line}" }, { line: props.vehicle.lineCode ?? i18n.text({ nb: "Ukjent", en: "Unknown" }) })}</h1>
          <p class="route-name">{nonPassenger()
            ? nonPassengerPositionSubtitle()
            : props.vehicle.routeName ?? i18n.text({ nb: "Rute ikke oppgitt", en: "Route not reported" })}</p>
        </div>
        <div class="panel-header-actions"><Show when={props.vehicle.state !== "live"}><StatusChip state={props.vehicle.state} label={i18n.text(vehicleStateLabels[props.vehicle.state])} /></Show><button class="icon-button" type="button" onClick={props.onClose} aria-label={i18n.text({ nb: "Lukk kjøretøypanelet", en: "Close vehicle panel" })}><Icon name="close" size={23} /></button></div>
      </header>

      <div class="panel-scroll">
        <Show when={props.refreshState === "error"}>
          <FeedbackBanner tone="danger" title={i18n.text({ nb: "Kunne ikke oppdatere posisjonen", en: "Position could not be refreshed" })}>{i18n.text({ nb: "Den sist kjente posisjonen vises fortsatt. Kontroller tilkoblingen og prøv igjen.", en: "The last known position is still shown. Check the connection and try again." })}</FeedbackBanner>
        </Show>
        <Show when={props.vehicle.state === "stale"}>
          <FeedbackBanner tone="warning" title={i18n.text({ nb: "Kjøretøyets posisjon er utdatert", en: "Vehicle position is stale" })}>{i18n.text(
            { nb: "Sist sett {time}. Kartet oppdateres når en ny posisjon er tilgjengelig.", en: "Last seen {time}. The map will update when a new position becomes available." },
            { time: formatRelativeTime(props.vehicle.lastSeenAt, now(), i18n.language()) },
          )}</FeedbackBanner>
        </Show>
        <Show when={props.vehicle.state === "lost"}>
          <FeedbackBanner tone="danger" title={i18n.text({ nb: "Sanntidsposisjonen er midlertidig utilgjengelig", en: "Live position temporarily unavailable" })}>{i18n.text(
            { nb: "Siste posisjon var {time}. Dette kan være et midlertidig opphold i transportdataene, eller reisen kan være avsluttet. FjordPulse fortsetter å sjekke og følger kjøretøyet automatisk igjen når en ny posisjon kommer.", en: "The last position was {time}. This may be a temporary gap in the transport feed, or the service may have ended. FjordPulse keeps checking and resumes following automatically when a new position arrives." },
            { time: formatRelativeTime(props.vehicle.lastSeenAt, now(), i18n.language()) },
          )}</FeedbackBanner>
        </Show>

        <Show when={nonPassenger()} fallback={
          <div class="vehicle-summary">
            <div><span>{i18n.text({ nb: "Forsinkelse", en: "Delay" })}</span><strong class={props.vehicle.delaySeconds !== null && props.vehicle.delaySeconds > 0 ? "warning-text" : ""}>{formatDelay(props.vehicle.delaySeconds, i18n.language())}</strong></div>
            <div><span>{i18n.text({ nb: "Neste holdeplass", en: "Next stop" })}</span><strong>{props.vehicle.nextStop?.name ?? i18n.text({ nb: "Ikke oppgitt", en: "Not reported" })}</strong></div>
            <div><span>{i18n.text({ nb: "Sist sett", en: "Last seen" })}</span><strong>{formatRelativeTime(props.vehicle.lastSeenAt, now(), i18n.language())}</strong></div>
            <div><span>{i18n.text({ nb: "Forrige holdeplass", en: "Previous stop" })}</span><strong>{previousStop() ?? i18n.text({ nb: "Ikke tilgjengelig", en: "Not available" })}</strong></div>
          </div>
        }>
          <div class="vehicle-summary is-compact">
            <div><span>{i18n.text({ nb: "Posisjonsstatus", en: "Position status" })}</span><strong class={`state-${props.vehicle.state}`}>{i18n.text(vehicleStateLabels[props.vehicle.state])}</strong></div>
            <div><span>{i18n.text({ nb: "Sist sett", en: "Last seen" })}</span><strong>{formatRelativeTime(props.vehicle.lastSeenAt, now(), i18n.language())}</strong></div>
          </div>
        </Show>

        <Show when={props.vehicle.state === "live" && props.focus === "none"}>
          <Button tone="primary" icon="focus" class="full-button" onClick={props.onFocus}>{i18n.text({ nb: "Følg dette kjøretøyet", en: "Focus this vehicle" })}</Button>
          <p class="action-hint">{i18n.text({ nb: "Følg kjøretøyet på kartet når posisjonen oppdateres.", en: "Follow this vehicle on the map as its position updates." })}</p>
        </Show>
        <Show when={props.vehicle.state === "live" && props.focus !== "none"}>
          <div class="panel-actions"><Button tone="primary" icon={props.focus === "paused" ? "focus" : "pause"} onClick={() => props.focus === "paused" ? props.onResume() : props.onPause()}>{props.focus === "paused" ? i18n.text({ nb: "Fortsett å følge", en: "Resume follow" }) : i18n.text({ nb: "Sett følging på pause", en: "Pause follow" })}</Button><Button icon="close" onClick={props.onUnfocus}>{i18n.text({ nb: "Slutt å følge", en: "Unfocus" })}</Button></div>
        </Show>
        <Show when={props.vehicle.state === "stale"}>
          <div class="panel-actions"><Button tone="primary" icon="refresh" disabled={props.refreshState === "refreshing"} onClick={props.onRetry}>{props.refreshState === "refreshing" ? i18n.text({ nb: "Oppdaterer …", en: "Refreshing…" }) : i18n.text({ nb: "Oppdater posisjonen", en: "Refresh position" })}</Button><Button icon="close" onClick={props.onStop}>{i18n.text({ nb: "Slutt å overvåke", en: "Stop watching" })}</Button></div>
        </Show>
        <Show when={props.vehicle.state === "lost"}>
          <div class="panel-actions"><Button tone="primary" icon="refresh" disabled={props.refreshState === "refreshing"} onClick={props.onRetry}>{props.refreshState === "refreshing" ? i18n.text({ nb: "Prøver igjen …", en: "Retrying…" }) : i18n.text({ nb: "Prøv igjen", en: "Try again" })}</Button><Button tone="danger" icon="close" onClick={props.onStop}>{i18n.text({ nb: "Slutt å følge", en: "Stop following" })}</Button></div>
        </Show>

        <Show when={nonPassenger()} fallback={
          <section class="panel-section upcoming-stops">
            <div class="section-heading"><div><span class="eyebrow">{i18n.text({ nb: "Reiseforløp", en: "Journey progress" })}</span><h2>{props.vehicle.state === "lost" ? i18n.text({ nb: "Sist kjente reise", en: "Last known journey" }) : i18n.text({ nb: "Kommende holdeplasser", en: "Upcoming stops" })}</h2></div></div>
            <Show when={props.vehicle.journey !== null && props.vehicle.journey.state !== "fresh"}>
              <FeedbackBanner
                tone="warning"
                title={hasCachedJourney()
                  ? i18n.text({ nb: "Viser lagret reiseplan", en: "Showing saved journey schedule" })
                  : i18n.text({ nb: "Reisedetaljer er utilgjengelige", en: "Journey details unavailable" })}
              >{hasCachedJourney()
                ? i18n.text({ nb: "Reiseplanen kunne ikke oppdateres og kan være utdatert.", en: "The journey schedule could not be refreshed and may be out of date." })
                : i18n.text({ nb: "FjordPulse kan ikke hente holdeplassene for denne reisen akkurat nå. Kjøretøyets posisjon kan fortsatt være oppdatert.", en: "FjordPulse cannot load the stops for this journey right now. The vehicle position may still be current." })}</FeedbackBanner>
            </Show>
            <Show when={props.vehicle.upcomingStops.length > 0} fallback={<div class="empty-state compact"><Icon name="pin" size={24} /><p>{props.vehicle.journeyReference === null
              ? i18n.text({ nb: "Kjøretøyet rapporterte ingen reise.", en: "This vehicle did not report a service journey." })
              : props.vehicle.journey === null || !hasCachedJourney() && props.vehicle.journey.state !== "fresh"
                ? i18n.text({ nb: "Reisedetaljene er midlertidig utilgjengelige.", en: "Journey details are temporarily unavailable." })
                : props.vehicle.journey.state !== "fresh"
                  ? i18n.text({ nb: "Ingen flere holdeplasser er tilgjengelige i den lagrede reiseplanen.", en: "No further stops are available in the saved journey schedule." })
                  : i18n.text({ nb: "Det er ingen flere holdeplasser på denne reisen.", en: "No further stops remain on this journey." })}</p></div>}>
              <ol><For each={visibleStops()}>{(stop) => <li class={stop.current ? "is-current" : ""}><span class="stop-marker" aria-hidden="true" /><strong>{stop.name}</strong><time datetime={stop.expectedAt ?? undefined}>{stop.expectedAt === null ? "—" : formatTransportTime(stop.expectedAt, i18n.language())}</time></li>}</For></ol>
              <Show when={props.vehicle.upcomingStops.length > 6}>
                <Button class="full-button" onClick={() => setShowAllStops((value) => !value)}>{showAllStops()
                  ? i18n.text({ nb: "Vis neste 6", en: "Show next 6" })
                  : i18n.text(
                    props.vehicle.upcomingStops.length === 1
                      ? { nb: "Vis 1 holdeplass", en: "Show 1 stop" }
                      : { nb: "Vis alle {count} holdeplasser", en: "Show all {count} stops" },
                    { count: props.vehicle.upcomingStops.length },
                  )}</Button>
              </Show>
            </Show>
          </section>
        }>
          <section class="panel-section non-passenger-section">
            <div class="section-heading"><div><span class="eyebrow">{i18n.text({ nb: "Driftsstatus", en: "Service status" })}</span><h2>{i18n.text({ nb: "Ingen aktiv passasjerreise", en: "No active passenger journey" })}</h2></div></div>
            <div class="non-passenger-notice" role="status" aria-live="polite" aria-label={i18n.text({ nb: "Status for passasjertrafikk", en: "Passenger service status" })}>
              <span aria-hidden="true"><Icon name="activity" size={24} /></span>
              <p>{i18n.text({
                nb: "Kjøretøyet rapporterer fortsatt posisjon, men er ikke i passasjertrafikk nå. Det kan være på vei til eller fra en garasje, eller mellom avganger.",
                en: "The vehicle is still reporting its position but is not operating a public passenger service right now. It may be travelling to or from a depot, or between services.",
              })}</p>
            </div>
          </section>
        </Show>
      </div>
    </aside>
  );
};
