import { For, type Component } from "solid-js";
import {
  VISUAL_SCENARIO_IDS,
  freshStationSnapshot,
  line100Vehicle,
  type VisualScenarioId,
} from "../fixtures/scenarios";
import { Button, DepartureRow, FeedbackBanner, FjordPulseLogo, FocusPill, SkeletonRows, StatusChip, VehicleRow } from "./DesignSystem";
import { UpdateNotice } from "./AppChrome";
import { Icon } from "./Icon";
import { LanguageSwitcher } from "./LanguageSwitcher";
import { useI18n, type LocalizedText } from "../state/i18n";

const scenarioNames: Readonly<Record<VisualScenarioId, LocalizedText>> = {
  desktop_default_map: { nb: "Skrivebord · standardkart", en: "Desktop · default map" },
  desktop_station_fresh: { nb: "Skrivebord · holdeplass med ferske data", en: "Desktop · station with fresh data" },
  desktop_station_loading: { nb: "Skrivebord · holdeplass lastes", en: "Desktop · station loading" },
  desktop_station_empty: { nb: "Skrivebord · holdeplass uten avganger", en: "Desktop · station without departures" },
  desktop_station_stale: { nb: "Skrivebord · forsinkede holdeplassdata", en: "Desktop · stale station data" },
  desktop_station_error: { nb: "Skrivebord · feil ved holdeplass", en: "Desktop · station error" },
  desktop_vehicle_selected: { nb: "Skrivebord · valgt kjøretøy", en: "Desktop · selected vehicle" },
  desktop_vehicle_non_passenger: { nb: "Skrivebord · kjøretøy utenfor passasjertrafikk", en: "Desktop · vehicle not in passenger service" },
  desktop_vehicle_focus_following: { nb: "Skrivebord · følger kjøretøy", en: "Desktop · following vehicle" },
  desktop_vehicle_focus_paused: { nb: "Skrivebord · kjøretøyfølging satt på pause", en: "Desktop · vehicle following paused" },
  desktop_vehicle_stale: { nb: "Skrivebord · forsinket kjøretøyposisjon", en: "Desktop · stale vehicle position" },
  desktop_vehicle_lost: { nb: "Skrivebord · kjøretøyposisjon utilgjengelig", en: "Desktop · vehicle position unavailable" },
  desktop_degraded_fallback: { nb: "Skrivebord · periodisk reserveoppdatering", en: "Desktop · periodic fallback updates" },
  desktop_search_results: { nb: "Skrivebord · søkeresultater", en: "Desktop · search results" },
  desktop_search_empty: { nb: "Skrivebord · tomt søk", en: "Desktop · empty search" },
  mobile_default_map: { nb: "Mobil · standardkart", en: "Mobile · default map" },
  mobile_station_sheet: { nb: "Mobil · holdeplasspanel", en: "Mobile · station sheet" },
  mobile_station_full_sheet: { nb: "Mobil · fullt holdeplasspanel", en: "Mobile · full station sheet" },
  mobile_vehicle_focus: { nb: "Mobil · følger kjøretøy", en: "Mobile · following vehicle" },
  mobile_vehicle_non_passenger: { nb: "Mobil · kjøretøy utenfor passasjertrafikk", en: "Mobile · vehicle not in passenger service" },
  mobile_vehicle_lost: { nb: "Mobil · kjøretøyposisjon utilgjengelig", en: "Mobile · vehicle position unavailable" },
  admin_status: { nb: "Administrasjon · systemstatus", en: "Admin · system status" },
  admin_infrastructure: { nb: "Administrasjon · infrastruktur", en: "Admin · infrastructure" },
  admin_watches: { nb: "Administrasjon · overvåkingsposter", en: "Admin · watch records" },
  admin_entur_log: { nb: "Administrasjon · Entur-forespørsler", en: "Admin · Entur requests" },
  admin_database: { nb: "Administrasjon · database", en: "Admin · database" },
  design_system_components: { nb: "Designsystem · komponenter", en: "Design system · components" },
};

function scenarioCategory(id: VisualScenarioId): LocalizedText {
  if (id.startsWith("desktop_")) return { nb: "Offentlig app for skrivebord", en: "Desktop public app" };
  if (id.startsWith("mobile_")) return { nb: "Offentlig mobilapp", en: "Mobile public app" };
  if (id.startsWith("admin_")) return { nb: "Administrasjonskonsoll", en: "Admin console" };
  return { nb: "Designsystem", en: "Design system" };
}

export const ScenarioIndex: Component = () => {
  const i18n = useI18n();
  return (
    <main class="scenario-index">
      <header>
        <div class="scenario-header-controls">
          <FjordPulseLogo />
          <LanguageSwitcher />
        </div>
        <span class="eyebrow">{i18n.text({ nb: "DETERMINISTISKE VISUELLE RUTER", en: "DETERMINISTIC VISUAL ROUTES" })}</span>
        <h1>{i18n.text({ nb: "FjordPulse-scenarier", en: "FjordPulse scenario gallery" })}</h1>
        <p>{i18n.text({ nb: "Hver godkjent visuell tilstand har en stabil nettadresse for Playwright og manuell gjennomgang.", en: "Every approved visual state has a stable URL for Playwright and human review." })}</p>
      </header>
      <div class="scenario-grid"><For each={VISUAL_SCENARIO_IDS}>{(id, index) => <a href={`/__scenario/${id}`}><span>{String(index() + 1).padStart(2, "0")}</span><div><strong>{i18n.text(scenarioNames[id])}</strong><small>{i18n.text(scenarioCategory(id))}</small></div><Icon name="chevron" size={18} /></a>}</For></div>
    </main>
  );
};

export const DesignSystemPage: Component = () => {
  const i18n = useI18n();
  return (
    <main class="design-board">
      <header><div><LanguageSwitcher /><span class="eyebrow">{i18n.text({ nb: "FJORDPULSE-GRENSESNITT", en: "FJORDPULSE UI" })}</span><h1>{i18n.text({ nb: "Designsystem", en: "Design system" })}</h1></div><p>{i18n.text({ nb: "Typesikre, gjenbrukbare komponenter for en pålitelig utforsker av kollektivtransport i sanntid.", en: "Typed, reusable components for a trustworthy realtime transport explorer." })}</p></header>
      <section class="design-section topbar-sample"><h2><span>01</span>{i18n.text({ nb: "Merkevare og toppfelt", en: "Brand & top bar" })}</h2><div class="sample-topbar"><FjordPulseLogo /><div class="sample-search"><Icon name="search" size={20} />{i18n.text({ nb: "Søk etter holdeplass, sted eller linje…", en: "Search for station, place, or line…" })}<kbd>⌘ K</kbd></div></div></section>
      <section class="design-section"><h2><span>02</span>{i18n.text({ nb: "Ressursstatus", en: "Resource status" })}</h2><div class="sample-row"><StatusChip state="stale" label={i18n.text({ nb: "Forsinket", en: "Stale" })} /><StatusChip state="lost" label={i18n.text({ nb: "Ikke rapportert", en: "Lost" })} /><StatusChip state="delayed" label={i18n.text({ nb: "Oppdateringer forsinket", en: "Updates delayed" })} /><StatusChip state="offline" label={i18n.text({ nb: "Utilgjengelig", en: "Unavailable" })} /></div></section>
      <section class="design-section markers-sample"><h2><span>03</span>{i18n.text({ nb: "Kartmarkører", en: "Map markers" })}</h2><div class="sample-row"><div class="sample-marker"><Icon name="bus" size={20} /><small>{i18n.text({ nb: "Holdeplass", en: "Station" })}</small></div><div class="sample-marker selected"><Icon name="bus" size={20} /><small>{i18n.text({ nb: "Valgt", en: "Selected" })}</small></div><div class="sample-cluster">18<small>{i18n.text({ nb: "Klynge", en: "Cluster" })}</small></div><div class="sample-marker vehicle"><Icon name="bus" size={20} /><small>{i18n.text({ nb: "Kjøretøy", en: "Vehicle" })}</small></div><div class="sample-marker stale"><Icon name="bus" size={20} /><small>{i18n.text({ nb: "Forsinket", en: "Stale" })}</small></div></div></section>
      <section class="design-section list-sample"><h2><span>04</span>{i18n.text({ nb: "Transportoppføringer", en: "Transport rows" })}</h2><div class="component-grid"><DepartureRow departure={freshStationSnapshot.departures[1]!} /><DepartureRow departure={freshStationSnapshot.departures[0]!} /><DepartureRow departure={{ ...freshStationSnapshot.departures[2]!, status: "cancelled" }} /><VehicleRow vehicle={freshStationSnapshot.nearbyVehicles[0]!} /></div></section>
      <section class="design-section"><h2><span>05</span>{i18n.text({ nb: "Fokus og handlinger", en: "Focus & actions" })}</h2><div class="sample-row action-sample"><Button tone="primary" icon="focus">{i18n.text({ nb: "Følg kjøretøy", en: "Focus vehicle" })}</Button><Button icon="pause">{i18n.text({ nb: "Sett følging på pause", en: "Pause follow" })}</Button><Button tone="danger" icon="close">{i18n.text({ nb: "Stopp følging", en: "Stop following" })}</Button><FocusPill line={line100Vehicle.lineCode} passengerServiceState={line100Vehicle.passengerServiceState} lastSeenAt={line100Vehicle.lastSeenAt} paused={false} onPause={() => undefined} onResume={() => undefined} onUnfocus={() => undefined} /></div></section>
      <section class="design-section feedback-sample"><h2><span>06</span>{i18n.text({ nb: "Tilbakemelding og lasting", en: "Feedback & loading" })}</h2><div class="component-grid"><FeedbackBanner tone="warning" title={i18n.text({ nb: "Dataene er forsinket", en: "Data is stale" })}>{i18n.text({ nb: "Viser sist kjente transportdata fra 2 minutter siden.", en: "Showing last known transport data from 2 min ago." })}</FeedbackBanner><FeedbackBanner tone="danger" title={i18n.text({ nb: "Tilkoblingsfeil", en: "Connection error" })}>{i18n.text({ nb: "Sanntidsoppdateringer er utilgjengelige. Periodisk oppdatering fortsetter.", en: "Realtime is unavailable. Periodic refresh continues." })}</FeedbackBanner><SkeletonRows count={2} /></div></section>
      <section class="design-section update-notice-sample"><h2><span>07</span>{i18n.text({ nb: "Levering av oppdateringer", en: "Update delivery" })}</h2><div class="sample-row"><UpdateNotice notice="reconnecting" /><UpdateNotice notice="polling" /><UpdateNotice notice="unavailable" /></div></section>
    </main>
  );
};
