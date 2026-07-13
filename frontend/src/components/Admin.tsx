import { createMemo, createResource, createSignal, For, Match, Show, Switch, type Component, type JSX } from "solid-js";
import { ApiClientError, type HttpClient } from "../services/httpClient";
import type { AdminEnturBudget, AdminEnturLog, AdminEvent, AdminMetric, AdminRealtime, AdminResourceSnapshot, AdminStatus, HealthDependency, MigrationRow, RealtimeEventRow, ServiceState, WatchRow } from "../types/domain";
import { Button, FjordPulseLogo, StatusChip } from "./DesignSystem";
import { Icon, type IconName } from "./Icon";
import { LanguageSwitcher } from "./LanguageSwitcher";
import { useClock } from "../state/clock";
import { useI18n, type Language } from "../state/i18n";
import { formatOsloDateTime, formatOsloTime } from "../utils/format";

export type AdminPage = "status" | "watches" | "entur-log" | "realtime" | "events" | "migrations";

export interface AdminFixtureData {
  readonly status: AdminStatus;
  readonly watches: readonly WatchRow[];
  readonly enturLog: AdminEnturLog;
}

const adminNav: readonly { readonly page: AdminPage; readonly icon: IconName }[] = [
  { page: "status", icon: "activity" },
  { page: "watches", icon: "focus" },
  { page: "entur-log", icon: "server" },
  { page: "realtime", icon: "wifi" },
  { page: "events", icon: "activity" },
  { page: "migrations", icon: "database" },
];

type I18n = ReturnType<typeof useI18n>;

const tx = (i18n: I18n, nb: string, en: string, values: Readonly<Record<string, string | number>> = {}): string =>
  i18n.text({ nb, en }, values);

function adminPageLabel(page: AdminPage, language: Language): string {
  const labels: Readonly<Record<AdminPage, readonly [nb: string, en: string]>> = {
    status: ["Systemstatus", "System status"],
    watches: ["Aktive overvåkinger", "Active watches"],
    "entur-log": ["Logg over Entur-forespørsler", "Entur request log"],
    realtime: ["Sanntidsdiagnostikk", "Realtime diagnostics"],
    events: ["Lagrede hendelser", "Persisted events"],
    migrations: ["Migreringer", "Migrations"],
  };
  return labels[page][language === "nb" ? 0 : 1];
}

const AdminLayout: Component<{ readonly page: AdminPage; readonly children: JSX.Element; readonly username: string; readonly connectionState: ServiceState; readonly connectionLabel: string; readonly onLogout?: () => void }> = (props) => {
  const i18n = useI18n();
  const initials = () => props.username.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? "").join("") || "OP";
  return <div class="admin-shell">
    <aside class="admin-sidebar">
      <FjordPulseLogo />
      <span class="admin-label">{tx(i18n, "ADMINPANEL", "ADMIN CONSOLE")}</span>
      <nav aria-label={tx(i18n, "Administrasjonsnavigasjon", "Admin navigation")}>
        <For each={adminNav}>{(item) => <a href={`/admin/${item.page}`} class={props.page === item.page ? "is-active" : ""} aria-current={props.page === item.page ? "page" : undefined}><Icon name={item.icon} size={19} />{adminPageLabel(item.page, i18n.language())}</a>}</For>
      </nav>
      <div class="admin-sidebar-bottom">
        <StatusChip state={props.connectionState} label={props.connectionLabel} />
        <div class="admin-account">
          <span class="avatar">{initials()}</span>
          <span><small>{tx(i18n, "Logget inn som", "Signed in as")}</small><strong>{props.username}</strong></span>
        </div>
        <button class="admin-logout-button" type="button" onClick={props.onLogout} aria-label={tx(i18n, "Logg ut {username}", "Log out {username}", { username: props.username })}>
          <Icon name="logout" size={18} /><span>{tx(i18n, "Logg ut", "Log out")}</span>
        </button>
      </div>
    </aside>
    <main class="admin-main">{props.children}</main>
  </div>;
};

const AdminHeader: Component<{ readonly title: string; readonly subtitle: string; readonly onRefresh: () => void }> = (props) => {
  const now = useClock();
  const i18n = useI18n();
  return <header class="admin-header"><div><span class="eyebrow">{tx(i18n, "FjordPulse-drift", "FjordPulse operations")}</span><h1>{props.title}</h1><p>{props.subtitle}</p></div><div><LanguageSwitcher class="admin-language-switcher" /><time datetime={new Date(now()).toISOString()}>{formatOsloTime(now(), i18n.language())} Oslo</time><button class="icon-button" type="button" onClick={props.onRefresh} aria-label={tx(i18n, "Oppdater administrasjonsdata", "Refresh admin data")}><Icon name="refresh" size={20} /></button></div></header>;
};

type EventEvidence = AdminEvent | RealtimeEventRow;

function recordValue(value: unknown): Readonly<Record<string, unknown>> | null {
  return typeof value === "object" && value !== null && !Array.isArray(value)
    ? value as Readonly<Record<string, unknown>>
    : null;
}

function stringValue(value: unknown): string | null {
  return typeof value === "string" && value.length > 0 ? value : null;
}

const localized = (language: Language, nb: string, en: string): string => language === "nb" ? nb : en;

function elapsedText(from: string, to: string, language: Language): string | null {
  const elapsedSeconds = Math.max(0, Math.round((Date.parse(to) - Date.parse(from)) / 1_000));
  if (!Number.isFinite(elapsedSeconds)) return null;
  if (elapsedSeconds < 60) return `${elapsedSeconds} ${localized(language, "sek", "sec")}`;
  const minutes = Math.floor(elapsedSeconds / 60);
  const seconds = elapsedSeconds % 60;
  if (minutes < 60) return seconds === 0 ? `${minutes} min` : `${minutes} min ${seconds} ${localized(language, "sek", "sec")}`;
  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;
  return remainingMinutes === 0 ? `${hours} ${localized(language, "t", "hr")}` : `${hours} ${localized(language, "t", "hr")} ${remainingMinutes} min`;
}

export function explainRealtimeEvent(event: EventEvidence, language: Language = "en"): { readonly label: string; readonly summary: string } {
  const vehicle = recordValue(event.payload.vehicle);
  const observation = recordValue(event.payload.observation);
  const observedAt = stringValue(observation?.observedAt) ?? stringValue(vehicle?.lastSeenAt);
  const refreshedAt = stringValue(vehicle?.refreshedAt) ?? event.version;
  const age = observedAt === null ? null : elapsedText(observedAt, refreshedAt, language);

  if (event.type === "vehicle_lost") {
    return {
      label: localized(language, "MISTET", "LOST"),
      summary: age === null
        ? localized(language, "Ingen nylig posisjon var tilgjengelig da kjøretøyet ble markert som mistet.", "No recent position was available when this vehicle was marked lost.")
        : localized(language, `Det var gått ${age} siden siste observasjon da kjøretøyet ble markert som mistet.`, `No recent position. The last observation was ${age} old when this vehicle was marked lost.`),
    };
  }
  if (event.type === "vehicle_stale") {
    return {
      label: localized(language, "UTDATERT", "STALE"),
      summary: age === null
        ? localized(language, "Kjøretøyets nyeste posisjon var eldre enn grensen for sanntidsdata.", "The latest vehicle position was older than the live threshold.")
        : localized(language, `Kjøretøyets nyeste posisjon var ${age} gammel da denne tilstanden ble registrert.`, `The latest vehicle position was ${age} old when this stale state was recorded.`),
    };
  }
  if (event.type === "vehicle_moved") return { label: localized(language, "SANNTID", "LIVE"), summary: localized(language, "En nyere kjøretøyposisjon ble lagret.", "A newer vehicle position was persisted.") };
  if (event.type === "station_snapshot_changed") return { label: localized(language, "OPPDATERT", "UPDATED"), summary: localized(language, "Et nyere øyeblikksbilde for holdeplassen ble lagret.", "A newer station snapshot was persisted.") };
  return { label: localized(language, "REGISTRERT", "RECORDED"), summary: localized(language, "Et varig sanntidsvarsel ble lagret.", "A durable realtime notification was persisted.") };
}

const EventDetails: Component<{ readonly event: EventEvidence }> = (props) => {
  const i18n = useI18n();
  const explanation = () => explainRealtimeEvent(props.event, i18n.language());
  return <details class="event-details">
    <summary role="button" aria-label={tx(i18n, "Detaljer for {type} {scope}", "Details for {type} {scope}", { type: props.event.type, scope: props.event.scope })}>{tx(i18n, "Detaljer", "Details")}</summary>
    <div class="event-detail-content">
      <p>{explanation().summary}</p>
      <dl>
        <div><dt>{tx(i18n, "Kilde", "Source")}</dt><dd><code>{props.event.source}</code></dd></div>
        <div><dt>{tx(i18n, "Enhet", "Entity")}</dt><dd><code>{props.event.entityId}</code></dd></div>
        <div><dt>{tx(i18n, "Versjon", "Version")}</dt><dd>{formatOsloDateTime(props.event.version, i18n.language())}</dd></div>
        <div><dt>{tx(i18n, "Registrert", "Recorded")}</dt><dd>{formatOsloDateTime(props.event.createdAt, i18n.language())}</dd></div>
      </dl>
      <pre aria-label={tx(i18n, "Rådata for hendelsen", "Raw event payload")}><code>{JSON.stringify(props.event.payload, null, 2)}</code></pre>
    </div>
  </details>;
};

const EventDetailRow: Component<{ readonly event: EventEvidence; readonly columns: number }> = (props) => (
  <tr class="event-detail-row"><td colSpan={props.columns}><EventDetails event={props.event} /></td></tr>
);

const formatCount = (value: number, language: Language): string => new Intl.NumberFormat(language === "nb" ? "nb-NO" : "en-GB").format(value);

const formatDecimal = (value: number, language: Language, digits: number): string => new Intl.NumberFormat(language === "nb" ? "nb-NO" : "en-GB", {
  minimumFractionDigits: digits,
  maximumFractionDigits: digits,
}).format(value);

function formatBytes(value: number, language: Language): string {
  const units = ["B", "KiB", "MiB", "GiB", "TiB"] as const;
  let size = Math.max(0, value);
  let unit = 0;
  while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit += 1; }
  return `${formatDecimal(size, language, size >= 100 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function utilizationTone(percent: number): "positive" | "warning" | "danger" {
  if (percent >= 90) return "danger";
  if (percent >= 75) return "warning";
  return "positive";
}

interface ResourceCard {
  readonly label: string;
  readonly value: string;
  readonly detail: string;
  readonly percent: number | null;
  readonly meterLabel: string;
}

const ResourceMetricCard: Component<{ readonly card: ResourceCard }> = (props) => {
  const i18n = useI18n();
  const percent = () => props.card.percent === null ? null : Math.max(0, Math.min(100, props.card.percent));
  return <article class={`metric-card resource-card tone-${percent() === null ? "info" : utilizationTone(percent()!)}`}>
    <span>{props.card.label}</span>
    <strong>{props.card.value}</strong>
    <small>{props.card.detail}</small>
    <Show when={percent() !== null}><div class="resource-meter" role="progressbar" aria-label={props.card.meterLabel} aria-valuemin="0" aria-valuemax="100" aria-valuenow={Math.round(percent()!)} aria-valuetext={tx(i18n, "{percent} % brukt", "{percent}% used", { percent: Math.round(percent()!) })}><span style={{ width: `${percent()!}%` }} /></div></Show>
  </article>;
};

const HostResources: Component<{ readonly resources: AdminResourceSnapshot }> = (props) => {
  const i18n = useI18n();
  const cards = (): readonly ResourceCard[] => {
    const result: ResourceCard[] = [];
    const cpu = props.resources.cpu;
    if (cpu.usagePercent !== null || cpu.load1 !== null) {
      const normalizedLoad = cpu.load1 !== null && cpu.logicalCores !== null ? Math.min(100, cpu.load1 / cpu.logicalCores * 100) : null;
      const loads = [cpu.load1, cpu.load5, cpu.load15].map((value) => value === null ? "—" : formatDecimal(value, i18n.language(), 2)).join(" / ");
      result.push({
        label: cpu.usagePercent === null ? tx(i18n, "CPU-belastning", "CPU load") : tx(i18n, "CPU-bruk", "CPU usage"),
        value: cpu.usagePercent === null
          ? tx(i18n, "{load} belastning", "{load} load", { load: cpu.load1 === null ? "—" : formatDecimal(cpu.load1, i18n.language(), 2) })
          : tx(i18n, "{percent} % brukt", "{percent}% used", { percent: formatDecimal(cpu.usagePercent, i18n.language(), 1) }),
        detail: tx(i18n, "Belastning 1/5/15: {loads}{cores}", "Load 1/5/15: {loads}{cores}", { loads, cores: cpu.logicalCores === null ? "" : tx(i18n, " · {count} logiske CPU-er", " · {count} logical CPUs", { count: cpu.logicalCores }) }),
        percent: cpu.usagePercent ?? normalizedLoad,
        meterLabel: cpu.usagePercent === null ? tx(i18n, "CPU-belastning i forhold til tilgjengelige CPU-er", "CPU load relative to available CPUs") : tx(i18n, "CPU-bruk", "CPU usage"),
      });
    }
    const memory = props.resources.memory;
    if (memory.totalBytes !== null && memory.availableBytes !== null) {
      const used = memory.usedBytes ?? Math.max(0, memory.totalBytes - memory.availableBytes);
      const percent = memory.usedPercent ?? (memory.totalBytes === 0 ? 0 : used / memory.totalBytes * 100);
      result.push({
        label: tx(i18n, "Minne", "Memory"),
        value: tx(i18n, "{amount} ledig", "{amount} free", { amount: formatBytes(memory.availableBytes, i18n.language()) }),
        detail: tx(i18n, "{used} brukt av {total} · {scope}", "{used} used of {total} · {scope}", { used: formatBytes(used, i18n.language()), total: formatBytes(memory.totalBytes, i18n.language()), scope: memory.scope === "cgroup" ? tx(i18n, "Containergrense", "Container limit") : tx(i18n, "Vertsmaskinens RAM", "Host RAM") }),
        percent,
        meterLabel: tx(i18n, "Brukt minne", "Memory used"),
      });
    }
    const disk = props.resources.disk;
    if (disk.totalBytes !== null && disk.freeBytes !== null) {
      const used = disk.usedBytes ?? Math.max(0, disk.totalBytes - disk.freeBytes);
      const percent = disk.usedPercent ?? (disk.totalBytes === 0 ? 0 : used / disk.totalBytes * 100);
      result.push({
        label: tx(i18n, "Applikasjonsdisk", "Application disk"),
        value: tx(i18n, "{amount} ledig", "{amount} free", { amount: formatBytes(disk.freeBytes, i18n.language()) }),
        detail: tx(i18n, "{used} brukt av {total} · {path}", "{used} used of {total} · {path}", { used: formatBytes(used, i18n.language()), total: formatBytes(disk.totalBytes, i18n.language()), path: disk.path }),
        percent,
        meterLabel: tx(i18n, "Brukt diskplass på {path}", "Disk used on {path}", { path: disk.path }),
      });
    }
    return result;
  };
  return <Show when={cards().length > 0}><section class="admin-resource-section" aria-labelledby="host-resources-heading">
    <header><div><span class="eyebrow">{tx(i18n, "NÅVÆRENDE SERVERSTATUS", "CURRENT SERVER SNAPSHOT")}</span><h2 id="host-resources-heading">{tx(i18n, "Serverressurser", "Host resources")}</h2></div><time datetime={props.resources.checkedAt}>{tx(i18n, "Målt {time}", "Measured {time}", { time: formatOsloDateTime(props.resources.checkedAt, i18n.language()) })}</time></header>
    <div class="metric-grid resource-metrics"><For each={cards()}>{(card) => <ResourceMetricCard card={card} />}</For></div>
  </section></Show>;
};

function dependencyLabel(name: string, language: Language): string {
  const labels: Readonly<Record<string, readonly [nb: string, en: string]>> = {
    Backend: ["Backend", "Backend"],
    "Realtime server": ["Sanntidsserver", "Realtime server"],
    SurrealDB: ["SurrealDB", "SurrealDB"],
    "Entur API": ["Entur-API", "Entur API"],
    "Live-query bridge": ["Live Query-bro", "Live-query bridge"],
    "Map tiles": ["Kartfliser", "Map tiles"],
  };
  const label = labels[name];
  return label === undefined ? name : label[language === "nb" ? 0 : 1];
}

function serviceStateLabel(state: ServiceState, language: Language): string {
  const labels: Readonly<Record<ServiceState, readonly [nb: string, en: string]>> = {
    ok: ["OK", "OK"],
    idle: ["AVVENTER", "IDLE"],
    connecting: ["KOBLER TIL", "CONNECTING"],
    connected: ["TILKOBLET", "CONNECTED"],
    reconnecting: ["KOBLER TIL IGJEN", "RECONNECTING"],
    delayed: ["FORSINKET", "DELAYED"],
    offline: ["FRAKOBLET", "OFFLINE"],
    degraded: ["REDUSERT", "DEGRADED"],
  };
  return labels[state][language === "nb" ? 0 : 1];
}

function realtimeDeliveryState(server: HealthDependency, bridge: HealthDependency): ServiceState {
  const states = [server.state, bridge.state] as const;
  for (const state of ["offline", "degraded", "reconnecting", "delayed", "connecting", "idle"] as const) {
    if (states.includes(state)) return state;
  }
  return "ok";
}

function isHealthyServiceState(state: ServiceState): boolean {
  return state === "ok" || state === "connected";
}

const DependencyCard: Component<{ readonly dependency: HealthDependency }> = (props) => {
  const i18n = useI18n();
  const icon = (): IconName => props.dependency.name === "SurrealDB"
    ? "database"
    : props.dependency.name === "Realtime server"
      ? "wifi"
      : props.dependency.name === "Backend"
        ? "server"
        : "refresh";
  return <article class="service-card">
    <span class="service-icon"><Icon name={icon()} size={25} /></span>
    <div>
      <span>{dependencyLabel(props.dependency.name, i18n.language())}</span>
      <strong class={`state-${props.dependency.state}`}>{serviceStateLabel(props.dependency.state, i18n.language())}</strong>
      <small>{operationalDetail(props.dependency.detail, i18n.language())}</small>
    </div>
    <Show when={props.dependency.latencyMs !== undefined}><span class="latency">{props.dependency.latencyMs} ms</span></Show>
  </article>;
};

const RealtimeDeliveryCard: Component<{ readonly server: HealthDependency; readonly bridge: HealthDependency }> = (props) => {
  const i18n = useI18n();
  const state = () => realtimeDeliveryState(props.server, props.bridge);
  const checks = () => [
    { label: tx(i18n, "Server", "Server"), service: props.server },
    { label: tx(i18n, "Databasehendelser", "Database events"), service: props.bridge },
  ] as const;
  return <article class="service-card realtime-delivery-card">
    <span class="service-icon"><Icon name="wifi" size={25} /></span>
    <div>
      <span>{tx(i18n, "Sanntidslevering", "Realtime delivery")}</span>
      <strong class={`state-${state()}`}>{serviceStateLabel(state(), i18n.language())}</strong>
      <ul class="realtime-delivery-checks" aria-label={tx(i18n, "Kontroller for sanntidslevering", "Realtime delivery checks")}>
        <For each={checks()}>{(check) => <li>
          <span>{check.label}</span>
          <strong class={`state-${check.service.state}`}>{serviceStateLabel(check.service.state, i18n.language())}</strong>
          <Show when={check.service.latencyMs !== undefined}><span class="realtime-delivery-latency">{check.service.latencyMs} ms</span></Show>
          <Show when={!isHealthyServiceState(check.service.state)}><small>{operationalDetail(check.service.detail, i18n.language())}</small></Show>
        </li>}</For>
      </ul>
      <a class="realtime-diagnostics-link" href="/admin/realtime">{tx(i18n, "Åpne sanntidsdiagnostikk", "Open realtime diagnostics")} <Icon name="chevron" size={14} /></a>
    </div>
  </article>;
};

function operationalDetail(detail: string, language: Language): string {
  if (language === "en") return detail;
  const exact: Readonly<Record<string, string>> = {
    "All HTTP services healthy": "Alle HTTP-tjenester fungerer.",
    "CakePHP HTTP/control plane is serving.": "CakePHP HTTP-/kontrollplanet er i drift.",
    "Live-query bridge connected": "Live Query-broen er tilkoblet.",
    "Command and LIVE connections healthy": "Kommando- og LIVE-tilkoblingene fungerer.",
    "SurrealDB live-query bridge is subscribed and receiving database events.": "Live Query-broen mot SurrealDB abonnerer på og mottar databasehendelser.",
    "No Entur request recorded in five minutes. Availability will be checked on the next demand-driven request.": "Ingen Entur-forespørsler er registrert de siste fem minuttene. Tilgjengeligheten kontrolleres ved neste behovsstyrte forespørsel.",
    "Demo fake adapters active; Entur is not being queried.": "Demoadaptere er aktive; Entur blir ikke forespurt.",
    "Realtime service and live-query bridge are healthy.": "Sanntidstjenesten og Live Query-broen fungerer.",
    "Realtime service is degraded.": "Sanntidstjenesten har redusert funksjon.",
    "Realtime status is missing, degraded, or stale.": "Sanntidsstatus mangler, har redusert funksjon eller er utdatert.",
    "Realtime status has not reported yet.": "Sanntidstjenesten har ikke rapportert status ennå.",
    "Live-query bridge status is missing, degraded, or stale.": "Status for Live Query-broen mangler, har redusert funksjon eller er utdatert.",
    "Live-query bridge status has not reported yet.": "Live Query-broen har ikke rapportert status ennå.",
    "SurrealDB live-query bridge is not healthy; HTTP polling fallback is active.": "Live Query-broen mot SurrealDB fungerer ikke; reserveoppdatering via HTTP er aktiv.",
    "Authoritative state database is reachable, but the configured station catalog is missing, partial, failed, or has different source provenance.": "Databasen for autoritativ tilstand er tilgjengelig, men den konfigurerte holdeplasskatalogen mangler, er ufullstendig, har feilet eller kommer fra en annen kilde.",
    "MapTiler browser configuration is present; provider availability is verified by the browser at load time, not by this endpoint.": "MapTiler er konfigurert for nettleseren. Tilgjengeligheten kontrolleres av nettleseren ved innlasting, ikke av dette endepunktet.",
    "MAPTILER_API_KEY is not configured; browser maps are unavailable.": "MAPTILER_API_KEY er ikke konfigurert; kart er ikke tilgjengelig i nettleseren.",
  };
  const translated = exact[detail];
  if (translated !== undefined) return translated;
  const database = /^Authoritative state database is reachable; the (\S+) station catalog contains (\d+) records\.$/.exec(detail);
  if (database !== null) return `Databasen for autoritativ tilstand er tilgjengelig. Holdeplasskatalogen (${database[1] ?? ""}) inneholder ${database[2] ?? "0"} poster.`;
  const outcome = /^Latest Entur outcome: ([^.]+)\.$/.exec(detail);
  if (outcome !== null) {
    const outcomeLabels: Readonly<Record<string, string>> = {
      success: "vellykket",
      cache_hit: "hurtigbuffertreff",
      skipped_budget: "hoppet over på grunn av forespørselskvoten",
      rate_limited: "begrenset av Entur",
      backoff: "venter før nytt forsøk",
      timeout: "tidsavbrudd",
      error: "feil",
    };
    const rawOutcome = outcome[1] ?? "";
    return `Siste Entur-resultat: ${outcomeLabels[rawOutcome] ?? rawOutcome}.`;
  }
  return detail;
}

function metricLabel(label: string, language: Language): string {
  if (language === "en") return label;
  const labels: Readonly<Record<string, string>> = {
    "Active WebSocket clients": "Aktive WebSocket-klienter",
    "Active station watches": "Aktive holdeplassovervåkinger",
    "Active vehicle watches": "Aktive kjøretøyovervåkinger",
    "Active Focus sessions": "Aktive fokusøkter",
  };
  return labels[label] ?? label;
}

function metricDetail(detail: string, language: Language): string {
  if (language === "en") return detail;
  if (detail === "Shared station scopes" || detail === "Shared monitored scopes") return "Delte overvåkingsområder";
  if (detail === "Shared selected-vehicle scopes") return "Delte områder for valgte kjøretøy";
  if (detail === "One high-priority watch per focused browser session") return "Én høyprioritert overvåking per fokusert nettleserøkt";
  const messages = /^(\S+)\/min messages · connections, not unique visitors$/.exec(detail);
  if (messages !== null) return `${messages[1] ?? "0"} meldinger/min · tilkoblinger, ikke unike besøkende`;
  return detail;
}

function databaseWarning(warning: string, language: Language): string {
  if (language === "en") return warning;
  const loopback = /^Loopback database target configured for ([^;]+); localhost resolves inside the running service\.$/.exec(warning);
  if (loopback !== null) return `Databasemålet for ${loopback[1] ?? "miljøet"} bruker loopback; localhost slås opp inne i tjenesten som kjører.`;
  return warning;
}

function environmentLabel(environment: AdminStatus["build"]["environment"], language: Language): string {
  const labels: Readonly<Record<AdminStatus["build"]["environment"], readonly [nb: string, en: string]>> = {
    local: ["LOKAL", "LOCAL"],
    development: ["UTVIKLING", "DEVELOPMENT"],
    test: ["TEST", "TEST"],
    staging: ["STAGING", "STAGING"],
    production: ["PRODUKSJON", "PRODUCTION"],
  };
  return labels[environment][language === "nb" ? 0 : 1];
}

const ENTUR_RATE_LIMIT_DOCS_URL = "https://developer.entur.no/docs/open-services/journey-planner/rate-limiting";

function enturBudgetServiceLabel(service: AdminEnturBudget["service"], language: Language): string {
  const labels: Readonly<Record<AdminEnturBudget["service"], readonly [nb: string, en: string]>> = {
    global: ["Alle Entur-tjenester (delt)", "All Entur services (shared)"],
    stop_place_register: ["Stoppestedsregisteret", "Stop Place Register"],
    geocoder: ["Geocoder", "Geocoder"],
    journey_planner: ["Journey Planner", "Journey Planner"],
    vehicle_positions: ["Kjøretøyposisjoner", "Vehicle Positions"],
  };
  return labels[service][language === "nb" ? 0 : 1];
}

function enturBudgetConfigName(service: AdminEnturBudget["service"]): string {
  const names: Readonly<Record<AdminEnturBudget["service"], string>> = {
    global: "ENTUR_GLOBAL_REQUESTS_PER_MINUTE",
    stop_place_register: "ENTUR_STOP_PLACE_REQUESTS_PER_MINUTE",
    geocoder: "ENTUR_GEOCODER_REQUESTS_PER_MINUTE",
    journey_planner: "ENTUR_JOURNEY_REQUESTS_PER_MINUTE",
    vehicle_positions: "ENTUR_VEHICLE_REQUESTS_PER_MINUTE",
  };
  return names[service];
}

function budgetTone(status: AdminStatus): AdminMetric["tone"] {
  if (status.build.dataMode === "fake") return "info";
  const global = status.enturBudgets.find((entry) => entry.service === "global");
  if (global === undefined) return "info";
  if (global.remaining === 0) return "danger";
  if (status.enturBudgets.some((entry) => entry.backoffUntil !== null) || global.remaining / global.limit <= 0.2) return "warning";
  return "positive";
}

function latestBudgetBackoff(budgets: readonly AdminEnturBudget[]): string | null {
  return budgets.reduce<string | null>((latest, entry) => {
    if (entry.backoffUntil === null) return latest;
    if (latest === null || Date.parse(entry.backoffUntil) > Date.parse(latest)) return entry.backoffUntil;
    return latest;
  }, null);
}

const EnturAllowanceCard: Component<{ readonly status: AdminStatus }> = (props) => {
  const i18n = useI18n();
  const globalBudget = () => props.status.enturBudgets.find((entry) => entry.service === "global");
  const backoffUntil = () => latestBudgetBackoff(props.status.enturBudgets);
  const headline = () => {
    if (props.status.build.dataMode === "fake") return tx(i18n, "Ikke i bruk", "Not used");
    const budget = globalBudget();
    if (budget === undefined) return tx(i18n, "Utilgjengelig", "Unavailable");
    return tx(i18n, "{remaining} av {limit} tilgjengelig", "{remaining} of {limit} available", {
      remaining: formatCount(budget.remaining, i18n.language()),
      limit: formatCount(budget.limit, i18n.language()),
    });
  };
  const summary = () => {
    if (props.status.build.dataMode === "fake") return tx(i18n, "Demoadapterne sender ingen forespørsler til Entur.", "Demo adapters do not send requests to Entur.");
    const budget = globalBudget();
    if (budget === undefined) return tx(i18n, "Serveren rapporterte ingen delt forespørselsramme.", "The server did not report a shared request allowance.");
    return tx(i18n, "Delt mellom Entur-tjenestene · rullerende vindu på {seconds} sekunder", "Shared across Entur services · rolling {seconds}-second window", {
      seconds: formatCount(budget.windowSeconds, i18n.language()),
    });
  };
  return <section class="admin-diagnostics-section entur-allowance-section" aria-labelledby="entur-allowance-heading">
    <header><span class="eyebrow">{tx(i18n, "ANSVARLIG API-BRUK", "RESPONSIBLE API USE")}</span><h2 id="entur-allowance-heading">{tx(i18n, "FjordPulse → Entur-forespørselsramme", "FjordPulse → Entur request allowance")}</h2></header>
    <article class={`entur-allowance-card tone-${budgetTone(props.status)}`}>
      <div class="entur-allowance-summary">
        <span class="entur-allowance-icon"><Icon name="server" size={25} /></span>
        <div><span>{tx(i18n, "Tilgjengelig nå", "Available now")}</span><strong>{headline()}</strong><small>{summary()}</small></div>
      </div>
      <div class="entur-allowance-explanation">
        <p>{props.status.build.dataMode === "fake"
          ? tx(i18n, "Grensene nedenfor er konfigurerte, men inaktive så lenge FjordPulse bruker demodata.", "The limits below are configured but inactive while FjordPulse uses demo data.")
          : tx(i18n, "Dette er FjordPulse sin appkonfigurerte beskyttelse for utgående kall til Entur – ikke en kvote rapportert av Entur. Hvert kildekall bruker både den delte rammen og rammen for den aktuelle API-en.", "This is FjordPulse's app-configured protection for outbound Entur calls—not a quota reported by Entur. Each source call uses both the shared allowance and its API-specific allowance.")}</p>
        <Show when={props.status.build.dataMode === "real" && backoffUntil() !== null}>
          <p class="entur-allowance-backoff">{tx(i18n, "Minst én Entur-tjeneste er satt på pause til {time}.", "At least one Entur service is paused until {time}.", { time: formatOsloDateTime(backoffUntil()!, i18n.language()) })}</p>
        </Show>
        <div class="entur-allowance-links">
          <a href="/admin/entur-log">{tx(i18n, "Åpne loggen over Entur-forespørsler", "Open Entur request log")}</a>
          <a href={ENTUR_RATE_LIMIT_DOCS_URL} target="_blank" rel="noreferrer" aria-label={tx(i18n, "Enturs dokumentasjon om grensene for Journey Planner (åpnes i ny fane)", "Entur Journey Planner rate-limit documentation (opens in a new tab)")}>{tx(i18n, "Offisielle grenser for Journey Planner ↗", "Official Journey Planner limits ↗")}</a>
        </div>
      </div>
      <Show when={props.status.enturBudgets.length > 0}>
        <details class="entur-allowance-details">
          <summary><span>{tx(i18n, "Vis konfigurerte grenser for alle Entur-API-er", "Show configured limits for all Entur APIs")}</span><Icon name="chevron" size={16} /></summary>
          <div class="entur-allowance-table-wrap">
            <table>
              <caption class="sr-only">{tx(i18n, "Interne forespørselsgrenser fra FjordPulse til Entur", "Internal FjordPulse-to-Entur request limits")}</caption>
              <thead><tr><th>API</th><th>{tx(i18n, "Tilgjengelig nå", "Available now")}</th><th>{tx(i18n, "Intern grense", "Internal cap")}</th><th>{tx(i18n, "Rullerende vindu", "Rolling window")}</th><th>{tx(i18n, "Satt på pause til", "Paused until")}</th></tr></thead>
              <tbody><For each={props.status.enturBudgets}>{(budget) => <tr><td><strong>{enturBudgetServiceLabel(budget.service, i18n.language())}</strong><code class="entur-budget-config">{enturBudgetConfigName(budget.service)}</code></td><td>{formatCount(budget.remaining, i18n.language())}</td><td>{formatCount(budget.limit, i18n.language())}</td><td>{formatCount(budget.windowSeconds, i18n.language())} s</td><td>{budget.backoffUntil === null ? "—" : formatOsloDateTime(budget.backoffUntil, i18n.language())}</td></tr>}</For></tbody>
            </table>
          </div>
        </details>
      </Show>
    </article>
  </section>;
};

export const AdminStatusPage: Component<{ readonly status: AdminStatus; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  const recentEvents = () => props.status.events.slice(0, 5);
  const realtimeServer = () => props.status.dependencies.find((dependency) => dependency.name === "Realtime server");
  const liveQueryBridge = () => props.status.dependencies.find((dependency) => dependency.name === "Live-query bridge");
  const groupedRealtimeDelivery = () => realtimeServer() !== undefined && liveQueryBridge() !== undefined;
  const standaloneDependencies = () => props.status.dependencies.filter((dependency) => !groupedRealtimeDelivery() || (dependency.name !== "Realtime server" && dependency.name !== "Live-query bridge"));
  const leadingDependencies = () => standaloneDependencies().filter((dependency) => dependency.name === "Backend");
  const remainingDependencies = () => standaloneDependencies().filter((dependency) => dependency.name !== "Backend");
  return <>
    <AdminHeader title={tx(i18n, "Systemstatus", "System status")} subtitle={tx(i18n, "Driftsoversikt over HTTP, sanntid, database og kildetjenester.", "Operational overview of the HTTP, realtime, database, and source services.")} onRefresh={props.onRefresh} />
    <section class="service-grid service-overview-grid" aria-label={tx(i18n, "Tjenesteavhengigheter", "Service dependencies")}>
      <For each={leadingDependencies()}>{(dependency) => <DependencyCard dependency={dependency} />}</For>
      <Show when={groupedRealtimeDelivery()}>
        <RealtimeDeliveryCard server={realtimeServer()!} bridge={liveQueryBridge()!} />
      </Show>
      <For each={remainingDependencies()}>{(dependency) => <DependencyCard dependency={dependency} />}</For>
    </section>
    <section class="metric-grid" aria-label={tx(i18n, "Systemmålinger", "System metrics")}><For each={props.status.metrics}>{(metric) => <article class={`metric-card tone-${metric.tone}`}><span>{metricLabel(metric.label, i18n.language())}</span><strong>{metric.value}</strong><small>{metricDetail(metric.detail, i18n.language())}</small></article>}</For></section>
    <EnturAllowanceCard status={props.status} />
    <HostResources resources={props.status.resources} />
    <section class="admin-diagnostics-section" aria-labelledby="deployment-data-heading">
      <header><span class="eyebrow">{tx(i18n, "DRIFTSMILJØ OG DATABASE", "DEPLOYMENT & DATABASE")}</span><h2 id="deployment-data-heading">{tx(i18n, "Kjøremiljø og lagrede data", "Runtime and stored data")}</h2></header>
      <div class="metric-grid admin-data-metrics">
        <article class={`metric-card tone-${props.status.build.dataMode === "fake" ? "warning" : "info"}`}><span>{tx(i18n, "Miljø", "Environment")}</span><strong>{environmentLabel(props.status.build.environment, i18n.language())}</strong><small>{props.status.build.dataMode === "real" ? tx(i18n, "Ekte Entur-data", "Real Entur data") : tx(i18n, "Demodata", "Demo fixture data")} · {tx(i18n, "bygg", "build")} {props.status.build.version}</small></article>
        <article class={`metric-card tone-${props.status.database.warning === null ? "positive" : "warning"}`}><span>{tx(i18n, "Databasemål", "Database target")}</span><strong>SurrealDB</strong><code class="database-endpoint">{props.status.database.endpointOrigin}</code><small>{props.status.database.namespace} / {props.status.database.name} · {tx(i18n, "{snapshots} holdeplassøyeblikksbilder · {watches} overvåkinger", "{snapshots} station snapshots · {watches} watches", { snapshots: formatCount(props.status.dataCounts.stationSnapshots, i18n.language()), watches: formatCount(props.status.dataCounts.watches, i18n.language()) })}</small><Show when={props.status.database.warning}>{(warning) => <small class="database-warning">{databaseWarning(warning(), i18n.language())}</small>}</Show></article>
        <article class="metric-card tone-info"><span>{tx(i18n, "Holdeplasskatalog", "Station catalog")}</span><strong>{formatCount(props.status.stationImport.count, i18n.language())}</strong><small>{props.status.stationImport.lastImportedAt === null ? tx(i18n, "Ingen fullført import registrert", "No completed import recorded") : tx(i18n, "Importert {time}", "Imported {time}", { time: formatOsloDateTime(props.status.stationImport.lastImportedAt, i18n.language()) })}{props.status.stationImport.sourceVersion === null ? "" : ` · ${props.status.stationImport.sourceVersion}`}</small></article>
        <article class="metric-card tone-info"><span>{tx(i18n, "Gjeldende kjøretøy", "Current vehicles")}</span><strong>{formatCount(props.status.dataCounts.currentVehicles, i18n.language())}</strong><small>{tx(i18n, "{count} beholdte observasjoner", "{count} retained observations", { count: formatCount(props.status.dataCounts.vehicleObservations, i18n.language()) })}</small></article>
        <article class="metric-card tone-info"><span>{tx(i18n, "Varige hendelser", "Durable events")}</span><strong>{formatCount(props.status.dataCounts.realtimeEvents, i18n.language())}</strong><small>{tx(i18n, "Varsler fra databasen", "Database-originated notifications")}</small></article>
        <article class="metric-card tone-info"><span>{tx(i18n, "Registrerte Entur-forespørsler", "Entur request records")}</span><strong>{formatCount(props.status.dataCounts.enturRequestLogs, i18n.language())}</strong><small>{tx(i18n, "Historikk over kildeforespørsler fra serveren", "Backend source-request history")}</small></article>
      </div>
    </section>
    <section class="admin-table-card admin-event-preview" aria-labelledby="recent-events-heading">
      <header>
        <div><span class="eyebrow">{tx(i18n, "DATABASEVARSLER", "DATABASE NOTIFICATIONS")}</span><h2 id="recent-events-heading">{tx(i18n, "Siste lagrede hendelser", "Latest persisted events")}</h2></div>
        <a href="/admin/events">{tx(i18n, "Åpne full hendelseshistorikk", "Open full event history")} <Icon name="chevron" size={15} /></a>
      </header>
      <div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Hendelse", "Event")}</th><th>{tx(i18n, "Omfang", "Scope")}</th><th>{tx(i18n, "Tid", "Time")}</th><th>{tx(i18n, "Tilstand", "State")}</th></tr></thead><tbody>
        <Show when={recentEvents().length > 0} fallback={<tr class="admin-empty-row"><td colSpan={4}>{tx(i18n, "Ingen lagrede hendelser er registrert ennå.", "No persisted events have been recorded yet.")}</td></tr>}>
          <For each={recentEvents()}>{(event) => <tr class={event.status === "warning" ? "is-warning" : ""}><td><span class={`event-dot tone-${event.status === "ok" ? "positive" : event.status === "warning" ? "warning" : "danger"}`}><Icon name="activity" size={14} /></span><strong>{event.type}</strong></td><td><code>{event.scope}</code></td><td>{formatOsloDateTime(event.createdAt, i18n.language())}</td><td><StatusChip state={event.status === "ok" ? "ok" : event.status === "warning" ? "delayed" : "offline"} label={explainRealtimeEvent(event, i18n.language()).label} /></td></tr>}</For>
        </Show>
      </tbody></table></div>
    </section>
  </>;
};

function watchTypeLabel(type: WatchRow["type"], language: Language): string {
  const labels: Readonly<Record<WatchRow["type"], readonly [nb: string, en: string]>> = {
    station: ["holdeplass", "station"],
    vehicle: ["kjøretøy", "vehicle"],
    focus: ["fokus", "focus"],
  };
  return labels[type][language === "nb" ? 0 : 1];
}

function watchPriorityLabel(priority: WatchRow["priority"], language: Language): string {
  const labels: Readonly<Record<WatchRow["priority"], readonly [nb: string, en: string]>> = {
    normal: ["normal", "normal"],
    high: ["høy", "high"],
    critical: ["kritisk", "critical"],
  };
  return labels[priority][language === "nb" ? 0 : 1];
}

function watchStateLabel(state: WatchRow["state"], language: Language): string {
  const labels: Readonly<Record<WatchRow["state"], readonly [nb: string, en: string]>> = {
    active: ["aktiv", "active"],
    stale: ["utdatert", "stale"],
    expiring: ["utløper", "expiring"],
    failed: ["mislykket", "failed"],
  };
  return labels[state][language === "nb" ? 0 : 1];
}

const WatchPage: Component<{ readonly rows: readonly WatchRow[]; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  const count = (state: WatchRow["state"]) => props.rows.filter((row) => row.state === state).length;
  return <>
    <AdminHeader title={tx(i18n, "Aktive overvåkinger", "Active watches")} subtitle={tx(i18n, "Behovsstyrte oppdateringsområder for holdeplasser, kjøretøy og fokus.", "Demand-driven station, vehicle, and Focus refresh scopes.")} onRefresh={props.onRefresh} />
    <section class="metric-grid watch-metrics"><article class="metric-card tone-info"><span>{tx(i18n, "Overvåkinger totalt", "Total watches")}</span><strong>{formatCount(props.rows.length, i18n.language())}</strong><small>{tx(i18n, "På tvers av aktive klienter", "Across active clients")}</small></article><article class="metric-card tone-positive"><span>{tx(i18n, "Fokusovervåkinger", "Focus watches")}</span><strong>{formatCount(props.rows.filter((row) => row.type === "focus").length, i18n.language())}</strong><small>{tx(i18n, "Kritisk prioritet", "Critical priority")}</small></article><article class="metric-card tone-warning"><span>{tx(i18n, "Utløper snart", "Expiring soon")}</span><strong>{formatCount(count("expiring"), i18n.language())}</strong><small>{tx(i18n, "Ingen aktive klienter", "No active clients")}</small></article><article class="metric-card tone-danger"><span>{tx(i18n, "Mislykkede overvåkinger", "Failed watches")}</span><strong>{formatCount(count("failed"), i18n.language())}</strong><small>{tx(i18n, "Krever oppfølging", "Needs attention")}</small></article></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">{tx(i18n, "PLANLEGGER", "SCHEDULER")}</span><h2>{tx(i18n, "Delte oppdateringsområder", "Shared refresh scopes")}</h2></div><span class="table-count">{tx(i18n, "{count} rader", "{count} rows", { count: formatCount(props.rows.length, i18n.language()) })}</span></header><div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Type", "Type")}</th><th>{tx(i18n, "Omfang", "Scope")}</th><th>{tx(i18n, "Klienter", "Clients")}</th><th>{tx(i18n, "Prioritet", "Priority")}</th><th>{tx(i18n, "Sist oppdatert", "Last refresh")}</th><th>{tx(i18n, "Neste oppdatering", "Next refresh")}</th><th>{tx(i18n, "Tilstand", "State")}</th></tr></thead><tbody><For each={props.rows}>{(row) => <tr class={row.state === "stale" ? "is-warning" : ""}><td><span class="type-cell"><Icon name={row.type === "station" ? "map" : row.type === "focus" ? "focus" : "bus"} size={17} />{watchTypeLabel(row.type, i18n.language())}</span></td><td><code>{row.scope}</code></td><td>{formatCount(row.clients, i18n.language())}</td><td><span class={`priority priority-${row.priority}`}>{watchPriorityLabel(row.priority, i18n.language())}</span></td><td>{row.lastRefreshAt === null ? tx(i18n, "Aldri", "Never") : formatOsloDateTime(row.lastRefreshAt, i18n.language())}</td><td>{row.nextRefreshAt === null ? "—" : formatOsloDateTime(row.nextRefreshAt, i18n.language())}</td><td><span class={`watch-state state-${row.state}`}>{watchStateLabel(row.state, i18n.language())}</span></td></tr>}</For></tbody></table></div></section>
  </>;
};

const EnturLogPage: Component<{ readonly data: AdminEnturLog; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  const [api, setApi] = createSignal("all");
  const [status, setStatus] = createSignal("all");
  const [scope, setScope] = createSignal("");
  const filtered = createMemo(() => props.data.entries.filter((row) => (api() === "all" || row.api === api()) && (status() === "all" || row.status === status()) && row.scope.toLowerCase().includes(scope().toLowerCase())));
  const statusLabel = (value: AdminEnturLog["entries"][number]["status"]): string => ({
    ok: tx(i18n, "ok", "ok"),
    error: tx(i18n, "feil", "error"),
    backoff: tx(i18n, "venter", "backoff"),
    rate_limited: tx(i18n, "begrenset", "rate limited"),
  })[value];
  const cacheLabel = (value: AdminEnturLog["entries"][number]["cache"]): string => ({
    hit: tx(i18n, "treff", "hit"),
    miss: tx(i18n, "ikke treff", "miss"),
    stale: tx(i18n, "utdatert", "stale"),
  })[value];
  return <>
    <AdminHeader title={tx(i18n, "Logg over Entur-forespørsler", "Entur request log")} subtitle={tx(i18n, "Kildeforespørsler kun fra serveren, med hurtigbufferbruk, svartid, kvoter og ventetid.", "Backend-only source requests, cache behavior, latency, budgets, and backoff.")} onRefresh={props.onRefresh} />
    <section class="metric-grid entur-metrics"><article class="metric-card tone-info"><span>{tx(i18n, "Forespørsler/min", "Requests / min")}</span><strong>{formatCount(props.data.metrics.requestsPerMinute, i18n.language())}</strong><small>{tx(i18n, "Observerte forespørsler", "Observed requests")}</small></article><article class="metric-card tone-positive"><span>{tx(i18n, "Treffrate i hurtigbuffer", "Cache hit rate")}</span><strong>{formatCount(Math.round(props.data.metrics.cacheHitRate * 100), i18n.language())}%</strong><small>{tx(i18n, "Gjeldende resultatvindu", "Current result window")}</small></article><article class="metric-card tone-info"><span>{tx(i18n, "p95-svartid", "p95 latency")}</span><strong>{props.data.metrics.p95LatencyMs === null ? "—" : `${formatCount(Math.round(props.data.metrics.p95LatencyMs), i18n.language())} ms`}</strong><small>{props.data.metrics.p95LatencyMs === null ? tx(i18n, "Ingen målte forespørsler", "No measured requests") : tx(i18n, "Målte kildekall", "Measured source calls")}</small></article><article class={`metric-card tone-${props.data.metrics.inBackoff ? "warning" : "positive"}`}><span>{tx(i18n, "Ventestatus", "Backoff state")}</span><strong>{props.data.metrics.inBackoff ? tx(i18n, "Aktiv", "Active") : tx(i18n, "Ingen venting", "Clear")}</strong><small>{tx(i18n, "Gjeldende kildevindu", "Current source window")}</small></article></section>
    <section class="filter-bar" aria-label={tx(i18n, "Filtre for Entur-logg", "Entur log filters")}><label>API<select value={api()} onChange={(event) => setApi(event.currentTarget.value)}><option value="all">{tx(i18n, "Alle API-er", "All APIs")}</option><option>Journey Planner</option><option>Vehicle Positions</option><option>Geocoder</option><option>Stop Place Register</option></select></label><label>{tx(i18n, "Status", "Status")}<select value={status()} onChange={(event) => setStatus(event.currentTarget.value)}><option value="all">{tx(i18n, "Alle statuser", "All statuses")}</option><option value="ok">OK</option><option value="backoff">{tx(i18n, "Venter", "Backoff")}</option><option value="error">{tx(i18n, "Feil", "Error")}</option></select></label><label class="scope-filter">{tx(i18n, "Omfang", "Scope")}<input value={scope()} onInput={(event) => setScope(event.currentTarget.value)} placeholder={tx(i18n, "Filtrer omfang …", "Filter scope…")} /></label></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">{tx(i18n, "ANSVARLIG API-BRUK", "RESPONSIBLE API USE")}</span><h2>{tx(i18n, "Forespørselshistorikk", "Request history")}</h2></div><span class="table-count">{tx(i18n, "{count} rader", "{count} rows", { count: formatCount(filtered().length, i18n.language()) })}</span></header><div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Tid", "Time")}</th><th>API</th><th>{tx(i18n, "Omfang", "Scope")}</th><th>{tx(i18n, "Status", "Status")}</th><th>{tx(i18n, "Svartid", "Latency")}</th><th>{tx(i18n, "Antall", "Count")}</th><th>{tx(i18n, "Hurtigbuffer", "Cache")}</th><th>{tx(i18n, "Nytt forsøk", "Retry")}</th></tr></thead><tbody><For each={filtered()}>{(row) => <tr class={row.status === "backoff" ? "is-warning" : ""}><td>{formatOsloDateTime(row.createdAt, i18n.language())}</td><td><strong>{row.api}</strong></td><td><code>{row.scope}</code></td><td><span class={`log-status state-${row.status}`}>{statusLabel(row.status)}</span></td><td>{row.latencyMs === null ? "—" : `${formatCount(row.latencyMs, i18n.language())} ms`}</td><td>{row.requestCount === null ? "—" : formatCount(row.requestCount, i18n.language())}</td><td><span class={`cache cache-${row.cache}`}>{cacheLabel(row.cache)}</span></td><td>{row.retryAt === null ? "—" : formatOsloDateTime(row.retryAt, i18n.language())}</td></tr>}</For></tbody></table></div></section>
  </>;
};

const RealtimePage: Component<{ readonly data: AdminRealtime; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  return <>
    <AdminHeader title={tx(i18n, "Sanntidsdiagnostikk", "Realtime diagnostics")} subtitle={tx(i18n, "Telemetri for tilkobling, rom, Live Query-bro, gjenoppkobling og utsending.", "Connection, room, live-query bridge, reconnect, and broadcast telemetry.")} onRefresh={props.onRefresh} />
    <section class="service-grid realtime-services" aria-label={tx(i18n, "Sanntidstjenester", "Realtime services")}><For each={[props.data.server, props.data.liveQueryBridge]}>{(service) => <article class="service-card"><span class="service-icon"><Icon name="wifi" size={25} /></span><div><span>{dependencyLabel(service.name, i18n.language())}</span><strong class={`state-${service.state}`}>{serviceStateLabel(service.state, i18n.language())}</strong><small>{operationalDetail(service.detail, i18n.language())}</small></div></article>}</For></section>
    <section class="metric-grid watch-metrics"><article class="metric-card tone-info"><span>{tx(i18n, "Aktive klienter", "Active clients")}</span><strong>{formatCount(props.data.activeClients, i18n.language())}</strong><small>{tx(i18n, "WebSocket-tilkoblinger i nettlesere", "Browser WebSockets")}</small></article><article class="metric-card tone-info"><span>{tx(i18n, "Meldinger/min", "Messages / min")}</span><strong>{formatCount(Math.round(props.data.messagesPerMinute), i18n.language())}</strong><small>{tx(i18n, "Validerte rammer", "Validated frames")}</small></article><article class="metric-card tone-warning"><span>{tx(i18n, "Gjenoppkoblinger", "Reconnects")}</span><strong>{formatCount(props.data.reconnectCount, i18n.language())}</strong><small>{tx(i18n, "Siden prosessen startet", "Since process start")}</small></article><article class="metric-card tone-danger"><span>{tx(i18n, "Feil", "Failures")}</span><strong>{formatCount(props.data.failureCount, i18n.language())}</strong><small>{tx(i18n, "Overvåkede feil", "Supervised failures")}</small></article></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">{tx(i18n, "ROMREGISTER", "ROOM REGISTRY")}</span><h2>{tx(i18n, "Aktive rom", "Active rooms")}</h2></div><span class="table-count">{tx(i18n, "Siste utsending {time}", "Last broadcast {time}", { time: props.data.lastBroadcastAt === null ? "—" : formatOsloDateTime(props.data.lastBroadcastAt, i18n.language()) })}</span></header><div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Omfang", "Scope")}</th><th>{tx(i18n, "Klienter", "Clients")}</th><th>{tx(i18n, "Isolasjon", "Isolation")}</th></tr></thead><tbody><For each={props.data.rooms}>{(room) => <tr><td><code>{room.scope}</code></td><td>{formatCount(room.clientCount, i18n.language())}</td><td><StatusChip state="ok" label={tx(i18n, "Avgrenset", "Scoped")} /></td></tr>}</For></tbody></table></div></section>
  </>;
};

export const EventsPage: Component<{ readonly rows: readonly RealtimeEventRow[]; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  return <>
    <AdminHeader title={tx(i18n, "Lagrede sanntidshendelser", "Persisted realtime events")} subtitle={tx(i18n, "Varsler fra databasen via den kanoniske SurrealDB-hendelsesflyten.", "Database-originated notifications from the canonical SurrealDB event path.")} onRefresh={props.onRefresh} />
    <section class="admin-table-card"><header><div><span class="eyebrow">REALTIME_EVENT</span><h2>{tx(i18n, "Nylige varige varsler", "Recent durable notifications")}</h2></div><span class="table-count">{tx(i18n, "{count} rader", "{count} rows", { count: formatCount(props.rows.length, i18n.language()) })}</span></header><div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Hendelses-ID", "Event ID")}</th><th>{tx(i18n, "Type", "Type")}</th><th>{tx(i18n, "Tilstand", "State")}</th><th>{tx(i18n, "Omfang", "Scope")}</th><th>{tx(i18n, "Enhet", "Entity")}</th><th>{tx(i18n, "Kilde", "Source")}</th><th>{tx(i18n, "Versjon", "Version")}</th><th>{tx(i18n, "Opprettet", "Created")}</th></tr></thead><tbody><For each={props.rows}>{(row) => <><tr class={row.type === "vehicle_lost" || row.type === "vehicle_stale" ? "is-warning" : ""}><td><code>{row.eventId}</code></td><td><strong>{row.type}</strong></td><td><StatusChip state={row.type === "vehicle_lost" || row.type === "vehicle_stale" ? "delayed" : "ok"} label={explainRealtimeEvent(row, i18n.language()).label} /></td><td><code>{row.scope}</code></td><td><code>{row.entityId}</code></td><td><code>{row.source}</code></td><td>{formatOsloDateTime(row.version, i18n.language())}</td><td>{formatOsloDateTime(row.createdAt, i18n.language())}</td></tr><EventDetailRow event={row} columns={8} /></>}</For></tbody></table></div></section>
  </>;
};

const MigrationsPage: Component<{ readonly rows: readonly MigrationRow[]; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  const migrationState = (state: MigrationRow["state"]): string => ({
    applied: tx(i18n, "utført", "applied"),
    pending: tx(i18n, "venter", "pending"),
    failed: tx(i18n, "mislykket", "failed"),
  })[state];
  return <>
    <AdminHeader title={tx(i18n, "Databasemigreringer", "Database migrations")} subtitle={tx(i18n, "Utførte, ventende og mislykkede SurrealDB-skjemamigreringer.", "Applied, pending, and failed SurrealDB schema migrations.")} onRefresh={props.onRefresh} />
    <section class="metric-grid watch-metrics"><article class="metric-card tone-positive"><span>{tx(i18n, "Utført", "Applied")}</span><strong>{formatCount(props.rows.filter((row) => row.state === "applied").length, i18n.language())}</strong><small>{tx(i18n, "Verifiserte kontrollsummer", "Verified checksums")}</small></article><article class="metric-card tone-warning"><span>{tx(i18n, "Venter", "Pending")}</span><strong>{formatCount(props.rows.filter((row) => row.state === "pending").length, i18n.language())}</strong><small>{tx(i18n, "Avventer kjøring", "Awaiting application")}</small></article><article class="metric-card tone-danger"><span>{tx(i18n, "Mislykket", "Failed")}</span><strong>{formatCount(props.rows.filter((row) => row.state === "failed").length, i18n.language())}</strong><small>{tx(i18n, "Krever oppfølging", "Requires attention")}</small></article></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">SCHEMA_MIGRATION</span><h2>{tx(i18n, "Migreringslogg", "Migration ledger")}</h2></div></header><div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Navn", "Name")}</th><th>{tx(i18n, "Kontrollsum", "Checksum")}</th><th>{tx(i18n, "Tilstand", "State")}</th><th>{tx(i18n, "Utført", "Applied at")}</th></tr></thead><tbody><For each={props.rows}>{(row) => <tr><td><strong>{row.name}</strong></td><td><code>{row.checksum}</code></td><td><span class={`watch-state state-${row.state}`}>{migrationState(row.state)}</span></td><td>{row.appliedAt === null ? "—" : formatOsloDateTime(row.appliedAt, i18n.language())}</td></tr>}</For></tbody></table></div></section>
  </>;
};

const AdminLogin: Component<{ readonly error: string | null; readonly busy: boolean; readonly onSubmit: (username: string, password: string) => void }> = (props) => {
  const i18n = useI18n();
  let username: HTMLInputElement | undefined;
  let password: HTMLInputElement | undefined;
  return <main class="admin-login"><form onSubmit={(event) => { event.preventDefault(); props.onSubmit(username?.value ?? "", password?.value ?? ""); }}><LanguageSwitcher class="admin-login-language-switcher" /><FjordPulseLogo /><span class="eyebrow">{tx(i18n, "BESKYTTET DRIFTSFLATE", "PROTECTED OPERATOR SURFACE")}</span><h1>{tx(i18n, "Administratorinnlogging", "Admin sign in")}</h1><p>{tx(i18n, "Bruk operatørpåloggingen din for FjordPulse. Du trenger aldri en konto for å utforske kollektivtransport.", "Use your FjordPulse operator credentials. Public transport browsing never requires an account.")}</p><Show when={props.error !== null}><div class="login-error" role="alert">{props.error}</div></Show><label>{tx(i18n, "Brukernavn", "Username")}<input ref={username} autocomplete="username" required /></label><label>{tx(i18n, "Passord", "Password")}<input ref={password} type="password" autocomplete="current-password" required /></label><Button type="submit" tone="primary" disabled={props.busy}>{tx(i18n, "Logg inn", "Sign in")}</Button><a href="/">← {tx(i18n, "Tilbake til transportkartet", "Return to public map")}</a></form></main>;
};

function localizedAdminError(error: Error | null | undefined, language: Language): string | null {
  if (error === null || error === undefined) return null;
  if (error instanceof ApiClientError) {
    const messages: Readonly<Record<string, readonly [nb: string, en: string]>> = {
      invalid_credentials: ["Brukernavnet eller passordet er feil.", "Admin credentials are invalid."],
      invalid_login_request: ["Skriv inn både brukernavn og passord.", "Enter both a username and password."],
      admin_unauthorized: ["Administratorinnlogging kreves.", "Admin authentication is required."],
      invalid_response: ["Serveren returnerte et svar som ikke kunne leses.", "The server returned an unreadable response."],
      invalid_contract: ["Serverens svar samsvarte ikke med FjordPulse-kontrakten.", "The server response did not match the FjordPulse contract."],
      http_error: ["Forespørselen til serveren mislyktes.", "The server request failed."],
      logout_failed: ["Administratorsesjonen kunne ikke avsluttes.", "Could not end the admin session."],
    };
    const message = messages[error.code];
    if (message !== undefined) return message[language === "nb" ? 0 : 1];
  }
  if (["Failed to fetch", "NetworkError when attempting to fetch resource.", "Load failed", "fetch failed"].includes(error.message)) {
    return localized(language, "Kunne ikke koble til FjordPulse-serveren. Kontroller tilkoblingen og prøv igjen.", "Could not connect to the FjordPulse server. Check your connection and try again.");
  }
  if (error.message === "Admin fixture data is unavailable.") return localized(language, "Testdata for administrasjon er ikke tilgjengelig.", error.message);
  if (error.message === "Sign in failed.") return localized(language, "Innlogging mislyktes.", error.message);
  return error.message;
}

export const AdminApp: Component<{ readonly page: AdminPage; readonly fixture: boolean; readonly fixtureData?: AdminFixtureData; readonly http: HttpClient }> = (props) => {
  const i18n = useI18n();
  const [refresh, setRefresh] = createSignal(0);
  const [loginError, setLoginError] = createSignal<Error | null>(null);
  const [loginBusy, setLoginBusy] = createSignal(false);
  const [operator, setOperator] = createSignal(props.fixture ? "Fixture operator" : "Operator");
  const load = async (): Promise<AdminStatus | AdminEnturLog | AdminRealtime | readonly WatchRow[] | readonly RealtimeEventRow[] | readonly MigrationRow[]> => {
    refresh();
    if (props.fixture) {
      if (props.fixtureData === undefined) throw new Error("Admin fixture data is unavailable.");
      if (props.page === "watches") return props.fixtureData.watches;
      if (props.page === "entur-log") return props.fixtureData.enturLog;
      return props.fixtureData.status;
    }
    const session = await props.http.getAdminSession();
    setOperator(session.username);
    if (props.page === "watches") return props.http.getAdminWatches();
    if (props.page === "entur-log") return props.http.getAdminEnturLog();
    if (props.page === "realtime") return props.http.getAdminRealtime();
    if (props.page === "events") return props.http.getAdminEvents();
    if (props.page === "migrations") return props.http.getAdminMigrations();
    return props.http.getAdminStatus();
  };
  const [data, { refetch }] = createResource(() => [props.page, refresh()] as const, load);
  const unauthorized = () => data.error instanceof ApiClientError && data.error.status === 401;
  const connection = (): { readonly state: ServiceState; readonly label: string } => {
    const value = data();
    if (props.page !== "status" || value === undefined || Array.isArray(value) || !("dependencies" in value)) return { state: "connected", label: tx(i18n, "Admin-API tilkoblet", "Admin API connected") };
    if (value.dependencies.some((dependency) => dependency.state === "offline")) return { state: "offline", label: tx(i18n, "Avhengighet utilgjengelig", "Dependency unavailable") };
    if (value.dependencies.some((dependency) => dependency.state === "degraded" || dependency.state === "delayed" || dependency.state === "reconnecting")) return { state: "delayed", label: tx(i18n, "Redusert systemtilstand", "System degraded") };
    if (value.dependencies.some((dependency) => dependency.state === "idle")) return { state: "idle", label: tx(i18n, "Systemet fungerer", "System operational") };
    return { state: "connected", label: tx(i18n, "Alle avhengigheter fungerer", "All dependencies healthy") };
  };
  const login = async (username: string, password: string) => {
    setLoginBusy(true); setLoginError(null);
    try { const session = await props.http.loginAdmin(username, password); setOperator(session.username); await refetch(); }
    catch (error) { setLoginError(error instanceof Error ? error : new Error("Sign in failed.")); }
    finally { setLoginBusy(false); }
  };
  const logout = async () => { if (!props.fixture) await props.http.logoutAdmin(); window.location.assign("/"); };
  return <Switch>
    <Match when={unauthorized()}><AdminLogin error={localizedAdminError(loginError(), i18n.language())} busy={loginBusy()} onSubmit={(username, password) => void login(username, password)} /></Match>
    <Match when={data.loading}><main class="admin-loading"><LanguageSwitcher class="admin-loading-language-switcher" /><span class="spinner" /><p>{tx(i18n, "Laster beskyttede systemdata …", "Loading protected system data…")}</p></main></Match>
    <Match when={data.error !== undefined}><main class="admin-loading"><LanguageSwitcher class="admin-loading-language-switcher" /><Icon name="alert" size={30} /><h1>{tx(i18n, "Administrasjonsdata er ikke tilgjengelig", "Admin data unavailable")}</h1><p>{data.error instanceof Error ? localizedAdminError(data.error, i18n.language()) : tx(i18n, "Ukjent feil", "Unknown error")}</p><Button onClick={() => void refetch()}>{tx(i18n, "Prøv igjen", "Retry")}</Button></main></Match>
    <Match when={data() !== undefined}><AdminLayout page={props.page} username={operator()} connectionState={connection().state} connectionLabel={connection().label} onLogout={() => void logout()}><Switch><Match when={props.page === "status"}><AdminStatusPage status={data() as AdminStatus} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "watches"}><WatchPage rows={data() as readonly WatchRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "entur-log"}><EnturLogPage data={data() as AdminEnturLog} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "realtime"}><RealtimePage data={data() as AdminRealtime} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "events"}><EventsPage rows={data() as readonly RealtimeEventRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "migrations"}><MigrationsPage rows={data() as readonly MigrationRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match></Switch></AdminLayout></Match>
  </Switch>;
};
