import { createSignal, For, onMount, Show, type Component } from "solid-js";
import type { FocusState, StationSnapshot, VehicleState } from "../types/domain";
import { Button, DepartureRow, FeedbackBanner, SkeletonRows, StatusChip, VehicleRow } from "./DesignSystem";
import { Icon } from "./Icon";
import { vehicleModeLabel } from "./VehicleMode";
import { useClock } from "../state/clock";
import { languageLocale, localize, useI18n, type Language, type LocalizedText } from "../state/i18n";
import { formatDelay, formatOsloDateTime, formatRelativeTime, formatTransportTime } from "../utils/format";

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

const StationVehiclesContent: Component<StationVehiclesContentProps> = (props) => {
  const i18n = useI18n();
  const active = () => props.snapshot.servingVehicles.filter((vehicle) => vehicle.relation === "starting_here" || vehicle.relation === "approaching" || vehicle.relation === "at_station");
  const progressUnknown = () => props.snapshot.servingVehicles.filter((vehicle) => vehicle.relation === "serves_station");
  const departed = () => props.snapshot.servingVehicles.filter((vehicle) => vehicle.relation === "departed");
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
        <div class="section-heading"><div><span class="eyebrow">{i18n.text({ nb: "Koblet til rutene", en: "Matched to services" })}</span><h2>{i18n.text({ nb: "Kjøretøy som stopper her", en: "Vehicles serving this station" })}</h2></div><span aria-label={i18n.text({ nb: "{count} kjøretøy", en: "{count} vehicles" }, { count: props.snapshot.servingVehicles.length })}>{props.snapshot.servingVehicles.length}</span></div>
        <p class="action-hint">{i18n.text({ nb: "Sanntidsposisjoner koblet til denne holdeplassen. Noen kjøretøy kan fortsatt være langt unna.", en: "Live positions matched to this station. Some vehicles may still be far away." })}</p>
        <details class="station-disclosure coverage-disclosure">
          <summary><span>{i18n.text({ nb: "Slik kobles kjøretøy", en: "How vehicles are matched" })}</span><Icon name="chevron" size={16} /></summary>
          <div class="station-disclosure-content"><p>{coverageMessage()}</p></div>
        </details>
        <Show when={props.snapshot.servingVehicleCoverage.truncated}>
          <FeedbackBanner tone="warning" title={i18n.text({ nb: "Svært travel holdeplass", en: "Very busy station" })}>{i18n.text(
            { nb: "Grensen for søkeomfang ble nådd. {queried} ulike ruter fra svaret ble kontrollert, og flere kan finnes. Kommende avganger ble prioritert.", en: "The search coverage limit was reached. {queried} distinct services from the response were checked, and more may exist. Upcoming departures were prioritized." },
            { queried: props.snapshot.servingVehicleCoverage.queriedJourneyCount },
          )}</FeedbackBanner>
        </Show>
        <Show when={props.snapshot.servingVehicles.length > 0} fallback={<div class="empty-state compact" role="status"><span><Icon name="bus" size={25} /></span><div><strong>{servingEmptyTitle()}</strong><p>{servingEmptyMessage()}</p></div></div>}>
          <Show when={active().length > 0}>
            <div class="vehicle-subgroup"><h3 class="eyebrow">{i18n.text({ nb: "På vei eller ved holdeplassen", en: "On the way or at the station" })}</h3><div class="vehicle-list"><For each={active()}>{(vehicle) => <VehicleRow vehicle={vehicle} onSelect={props.onVehicle} />}</For></div></div>
          </Show>
          <Show when={progressUnknown().length > 0}>
            <div class="vehicle-subgroup"><h3 class="eyebrow">{i18n.text({ nb: "Stopper her · posisjon i reisen er ukjent", en: "Stops here · journey progress unknown" })}</h3><div class="vehicle-list"><For each={progressUnknown()}>{(vehicle) => <VehicleRow vehicle={vehicle} onSelect={props.onVehicle} />}</For></div></div>
          </Show>
          <Show when={departed().length > 0}>
            <div class="vehicle-subgroup"><h3 class="eyebrow">{i18n.text({ nb: "Har passert holdeplassen", en: "Passed this station" })}</h3><div class="vehicle-list"><For each={departed()}>{(vehicle) => <VehicleRow vehicle={vehicle} onSelect={props.onVehicle} />}</For></div></div>
          </Show>
        </Show>
      </section>
      <section class="panel-section nearby-section">
        <div class="section-heading"><div><span class="eyebrow">{i18n.text({ nb: "Andre posisjoner i området", en: "Other positions in the area" })}</span><h2>{i18n.text({ nb: "Andre kjøretøy i nærheten", en: "Other nearby vehicles" })}</h2></div><span aria-label={i18n.text({ nb: "{count} kjøretøy", en: "{count} vehicles" }, { count: otherNearby().length })}>{otherNearby().length}</span></div>
        <p class="action-hint">{i18n.text(
          { nb: "Andre rapporterte sanntidsposisjoner {area}.", en: "Other reported live positions {area}." },
          { area: nearbySearchArea(props.snapshot.nearbyVehicleSearchRadiusMeters, i18n.language()) },
        )}</p>
        <NearbyVehiclesContent vehicles={otherNearby()} state={props.snapshot.state} searchRadiusMeters={props.snapshot.nearbyVehicleSearchRadiusMeters} onVehicle={props.onVehicle} />
      </section>
    </>
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
  const uniqueVehicleCount = () => new Set([
    ...props.snapshot.servingVehicles.map((vehicle) => vehicle.id),
    ...props.snapshot.nearbyVehicles.map((vehicle) => vehicle.id),
  ]).size;
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
    return i18n.text({ nb: "Ingen kommende avganger.", en: "No upcoming departures." });
  };
  const departureEmptyMessage = () => {
    if (props.snapshot.state === "refreshing") return i18n.text({ nb: "Ser etter de nyeste avgangstidene. Resultater kan vises snart.", en: "Checking for the latest departure times. Results may appear shortly." });
    if (props.snapshot.state === "stale" || props.snapshot.state === "backoff" || props.snapshot.state === "rate_limited") return i18n.text({ nb: "Ingen lagrede avgangstider er tilgjengelige. FjordPulse prøver igjen automatisk.", en: "No saved departure times are available. FjordPulse will retry automatically." });
    if (props.snapshot.state === "unavailable") return i18n.text({ nb: "Avgangstidene kunne ikke hentes. Prøv igjen om litt.", en: "Departure times could not be loaded. Try again shortly." });
    return i18n.text({ nb: "Prøv igjen senere eller velg en annen holdeplass.", en: "Try again later or choose another station." });
  };

  return (
    <aside class={`detail-panel station-panel sheet-${props.sheet}`} aria-label={i18n.text({ nb: "Detaljer for holdeplassen {name}", en: "{name} station details" }, { name: props.snapshot.station.name })}>
      <button
        class="sheet-grabber"
        type="button"
        onClick={() => props.onSheet(props.sheet === "full" ? "half" : "full")}
        aria-label={props.sheet === "full"
          ? i18n.text({ nb: "Minimer holdeplasspanelet", en: "Collapse station sheet" })
          : i18n.text({ nb: "Utvid holdeplasspanelet", en: "Expand station sheet" })}
      ><span /></button>
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
        <button ref={(element) => { tabButtons.departures = element; }} id="station-tab-departures" role="tab" aria-controls={tab() === "departures" ? "station-panel-departures" : undefined} aria-describedby="station-tab-departures-count" aria-selected={tab() === "departures"} tabIndex={tab() === "departures" ? 0 : -1} onKeyDown={(event) => moveTabFocus(event, "departures")} onClick={() => activateTab("departures")}><Icon name="clock" size={17} /><span>{i18n.text({ nb: "Avganger", en: "Departures" })}</span><span class="tab-count" aria-hidden="true">{props.snapshot.departures.length}</span></button>
        <button ref={(element) => { tabButtons.vehicles = element; }} id="station-tab-vehicles" role="tab" aria-controls={tab() === "vehicles" ? "station-panel-vehicles" : undefined} aria-describedby="station-tab-vehicles-count" aria-selected={tab() === "vehicles"} tabIndex={tab() === "vehicles" ? 0 : -1} onKeyDown={(event) => moveTabFocus(event, "vehicles")} onClick={() => activateTab("vehicles")}><Icon name="bus" size={17} /><span>{i18n.text({ nb: "Kjøretøy", en: "Vehicles" })}</span><span class="tab-count" aria-hidden="true">{uniqueVehicleCount()}</span></button>
        <button ref={(element) => { tabButtons.details = element; }} id="station-tab-details" role="tab" aria-controls={tab() === "details" ? "station-panel-details" : undefined} aria-selected={tab() === "details"} tabIndex={tab() === "details" ? 0 : -1} onKeyDown={(event) => moveTabFocus(event, "details")} onClick={() => activateTab("details")}><Icon name="pin" size={17} /><span>{i18n.text({ nb: "Detaljer", en: "Details" })}</span></button>
      </div>
      <span id="station-tab-departures-count" class="sr-only">{i18n.text({ nb: "Kommende avganger: {count}", en: "Upcoming departures: {count}" }, { count: props.snapshot.departures.length })}</span>
      <span id="station-tab-vehicles-count" class="sr-only">{i18n.text({ nb: "Viste kjøretøy: {count}", en: "Vehicles shown: {count}" }, { count: uniqueVehicleCount() })}</span>

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
              <section class="panel-section">
                <div class="section-heading"><div><span class="eyebrow">{i18n.text({ nb: "Neste fra denne holdeplassen", en: "Next from this station" })}</span><h2>{i18n.text({ nb: "Avganger", en: "Departures" })}</h2></div><span>{i18n.text({ nb: "{count} kommende", en: "{count} upcoming" }, { count: props.snapshot.departures.length })}</span></div>
                <Show when={props.snapshot.departures.length > 0} fallback={
                  <div class="empty-state" role="status" data-state={props.snapshot.state === "fresh" || props.snapshot.state === "empty" ? "empty" : "unavailable"}><span><Icon name="clock" size={27} /></span><strong>{departureEmptyTitle()}</strong><p>{departureEmptyMessage()}</p></div>
                }>
                  <div class="departure-list"><For each={props.snapshot.departures}>{(departure) => <DepartureRow departure={departure} muted={props.snapshot.state === "stale"} />}</For></div>
                </Show>
              </section>
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
    <aside class={`detail-panel vehicle-panel sheet-${props.sheet} ${nonPassenger() ? "service-non-passenger" : ""}`} aria-label={panelLabel()}>
      <button
        class="sheet-grabber"
        type="button"
        onClick={() => props.onSheet(props.sheet === "full" ? "half" : "full")}
        aria-label={props.sheet === "full"
          ? i18n.text({ nb: "Minimer kjøretøypanelet", en: "Collapse vehicle sheet" })
          : i18n.text({ nb: "Utvid kjøretøypanelet", en: "Expand vehicle sheet" })}
      ><span /></button>
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
