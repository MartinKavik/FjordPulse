import { createEffect, createMemo, createResource, createSignal, For, Match, onCleanup, onMount, Show, Switch, type Component, type JSX } from "solid-js";
import { ApiClientError, type HttpClient } from "../services/httpClient";
import type { AdminDatabaseMigrations, AdminDatabaseSchema, AdminDemoCredentials, AdminEnturBudget, AdminEnturLog, AdminEvent, AdminMetric, AdminRealtime, AdminResourceSnapshot, AdminSession, AdminStatus, DatabaseMigration, DatabaseMigrationState, DatabasePermissionMode, DatabaseSchemaTable, HealthDependency, RealtimeEventRow, ServiceState, WatchRow } from "../types/domain";
import { Button, FjordPulseLogo, StatusChip } from "./DesignSystem";
import { Icon, type IconName } from "./Icon";
import { LanguageSwitcher } from "./LanguageSwitcher";
import { useClock } from "../state/clock";
import { useI18n, type Language } from "../state/i18n";
import { formatOsloDateTime, formatOsloTime } from "../utils/format";

export type AdminPage = "status" | "infrastructure" | "watches" | "entur-log" | "realtime" | "events" | "database";
export type AdminDatabaseView = "schema" | "migrations";

export interface AdminFixtureData {
  readonly status: AdminStatus;
  readonly realtime: AdminRealtime;
  readonly watches: readonly WatchRow[];
  readonly enturLog: AdminEnturLog;
  readonly databaseSchema: AdminDatabaseSchema;
  readonly databaseMigrations: AdminDatabaseMigrations;
}

interface AdminEnturPageData {
  readonly log: AdminEnturLog;
  readonly status: AdminStatus | null;
}

const adminNav: readonly { readonly page: AdminPage; readonly icon: IconName; readonly href: string }[] = [
  { page: "status", icon: "activity", href: "/admin/status" },
  { page: "infrastructure", icon: "gear", href: "/admin/infrastructure" },
  { page: "watches", icon: "focus", href: "/admin/watches" },
  { page: "entur-log", icon: "server", href: "/admin/entur-log" },
  { page: "realtime", icon: "wifi", href: "/admin/realtime" },
  { page: "events", icon: "activity", href: "/admin/events" },
  { page: "database", icon: "database", href: "/admin/database/schema" },
];

type I18n = ReturnType<typeof useI18n>;

const tx = (i18n: I18n, nb: string, en: string, values: Readonly<Record<string, string | number>> = {}): string =>
  i18n.text({ nb, en }, values);

function adminPageLabel(page: AdminPage, language: Language): string {
  const labels: Readonly<Record<AdminPage, readonly [nb: string, en: string]>> = {
    status: ["Systemstatus", "System status"],
    infrastructure: ["Infrastruktur", "Infrastructure"],
    watches: ["Overvåkinger", "Watches"],
    "entur-log": ["Logg over Entur-forespørsler", "Entur request log"],
    realtime: ["Sanntidsdiagnostikk", "Realtime diagnostics"],
    events: ["Lagrede hendelser", "Persisted events"],
    database: ["Database", "Database"],
  };
  return labels[page][language === "nb" ? 0 : 1];
}

const AdminLayout: Component<{ readonly page: AdminPage; readonly children: JSX.Element; readonly username: string; readonly access: AdminSession["access"]; readonly connectionState: ServiceState; readonly connectionLabel: string; readonly onNavigate?: ((href: string) => boolean | void) | undefined; readonly onLogout?: () => void }> = (props) => {
  const i18n = useI18n();
  const [navigationOpen, setNavigationOpen] = createSignal(false);
  const initials = () => props.username.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? "").join("") || "OP";
  let menuButton: HTMLButtonElement | undefined;
  let closeButton: HTMLButtonElement | undefined;
  let navigationDrawer: HTMLElement | undefined;
  const closeNavigation = (restoreFocus = false) => {
    setNavigationOpen(false);
    if (restoreFocus) queueMicrotask(() => menuButton?.focus());
  };
  const navigateWithinAdmin: JSX.EventHandler<HTMLDivElement, MouseEvent> = (event) => {
    if (props.onNavigate === undefined || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const eventTarget = event.target;
    const anchor = eventTarget instanceof Element ? eventTarget.closest<HTMLAnchorElement>("a[href]") : null;
    if (anchor === null || !event.currentTarget.contains(anchor) || anchor.target !== "" || anchor.hasAttribute("download")) return;
    const href = anchor.getAttribute("href");
    if (href === null || href.startsWith("#")) return;
    let destination: URL;
    try { destination = new URL(anchor.href, window.location.href); }
    catch { return; }
    if (destination.origin !== window.location.origin || (destination.pathname !== "/admin" && !destination.pathname.startsWith("/admin/"))) return;
    event.preventDefault();
    closeNavigation();
    props.onNavigate(`${destination.pathname}${destination.search}${destination.hash}`);
  };
  onMount(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (!navigationOpen()) return;
      if (event.key === "Escape") {
        event.preventDefault();
        closeNavigation(true);
        return;
      }
      if (event.key !== "Tab" || navigationDrawer === undefined) return;
      const focusable = [...navigationDrawer.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((element) => element.getClientRects().length > 0);
      const first = focusable[0];
      const last = focusable.at(-1);
      if (first === undefined || last === undefined) return;
      if (event.shiftKey && (document.activeElement === first || !navigationDrawer.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !navigationDrawer.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
      }
    };
    window.addEventListener("keydown", onKeyDown);
    onCleanup(() => window.removeEventListener("keydown", onKeyDown));
  });
  return <div class="admin-shell" onClick={navigateWithinAdmin}>
    <button class={`admin-navigation-scrim${navigationOpen() ? " is-visible" : ""}`} type="button" aria-label={tx(i18n, "Lukk administrasjonsmenyen", "Close admin menu")} tabindex={-1} onClick={() => closeNavigation(true)} />
    <aside ref={navigationDrawer} id="admin-navigation-drawer" class={`admin-sidebar${navigationOpen() ? " is-open" : ""}`} aria-label={tx(i18n, "Administrasjonsmeny", "Admin menu")} role={navigationOpen() ? "dialog" : undefined} aria-modal={navigationOpen() ? "true" : undefined}>
      <div class="admin-sidebar-header"><FjordPulseLogo /><button ref={closeButton} class="admin-sidebar-close icon-button" type="button" aria-label={tx(i18n, "Lukk administrasjonsmenyen", "Close admin menu")} onClick={() => closeNavigation(true)}><Icon name="close" size={20} /></button></div>
      <span class="admin-label">{tx(i18n, "ADMINPANEL", "ADMIN CONSOLE")}</span>
      <nav aria-label={tx(i18n, "Administrasjonsnavigasjon", "Admin navigation")}>
        <For each={adminNav}>{(item) => <a href={item.href} class={props.page === item.page ? "is-active" : ""} aria-current={props.page === item.page ? "page" : undefined} onClick={() => closeNavigation()}><Icon name={item.icon} size={19} />{adminPageLabel(item.page, i18n.language())}</a>}</For>
      </nav>
      <div class="admin-sidebar-bottom">
        <StatusChip state={props.connectionState} label={props.connectionLabel} />
        <div class="admin-account">
          <span class="avatar">{initials()}</span>
          <span><Show when={props.access === "demo"}><small class="admin-access-label">{tx(i18n, "Offentlig demo · skrivebeskyttet", "Public demo · read-only")}</small></Show><small>{tx(i18n, "Logget inn som", "Signed in as")}</small><strong>{props.username}</strong></span>
        </div>
        <button class="admin-logout-button" type="button" onClick={props.onLogout} aria-label={tx(i18n, "Logg ut {username}", "Log out {username}", { username: props.username })}>
          <Icon name="logout" size={18} /><span>{tx(i18n, "Logg ut", "Log out")}</span>
        </button>
      </div>
    </aside>
    <main class="admin-main" inert={navigationOpen()} aria-hidden={navigationOpen() ? "true" : undefined}>
      <div class="admin-mobile-toolbar"><FjordPulseLogo /><button ref={menuButton} class="admin-menu-button" type="button" aria-controls="admin-navigation-drawer" aria-expanded={navigationOpen()} onClick={() => { setNavigationOpen(true); queueMicrotask(() => closeButton?.focus()); }}><Icon name="menu" size={20} /><span>{tx(i18n, "Meny", "Menu")}</span></button></div>
      {props.children}
    </main>
  </div>;
};

const AdminHeader: Component<{ readonly title: string; readonly subtitle: string; readonly onRefresh: () => void }> = (props) => {
  const now = useClock();
  const i18n = useI18n();
  return <header class="admin-header"><div><span class="eyebrow">{tx(i18n, "FjordPulse-drift", "FjordPulse operations")}</span><h1 tabindex={-1}>{props.title}</h1><p>{props.subtitle}</p></div><div><LanguageSwitcher class="admin-language-switcher" /><time datetime={new Date(now()).toISOString()}>{formatOsloTime(now(), i18n.language())} Oslo</time><button class="icon-button" type="button" onClick={props.onRefresh} aria-label={tx(i18n, "Oppdater administrasjonsdata", "Refresh admin data")}><Icon name="refresh" size={20} /></button></div></header>;
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
    <h3>{props.card.label}</h3>
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
        label: "CPU",
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
        detail: tx(i18n, "{percent} % brukt · {used} av {total} · {scope}", "{percent}% used · {used} of {total} · {scope}", { percent: formatDecimal(percent, i18n.language(), 1), used: formatBytes(used, i18n.language()), total: formatBytes(memory.totalBytes, i18n.language()), scope: memory.scope === "cgroup" ? tx(i18n, "Containergrense", "Container limit") : tx(i18n, "Vertsmaskinens RAM", "Host RAM") }),
        percent,
        meterLabel: tx(i18n, "Brukt minne", "Memory used"),
      });
    }
    const disk = props.resources.disk;
    if (disk.totalBytes !== null && disk.freeBytes !== null) {
      const used = disk.usedBytes ?? Math.max(0, disk.totalBytes - disk.freeBytes);
      const percent = disk.usedPercent ?? (disk.totalBytes === 0 ? 0 : used / disk.totalBytes * 100);
      result.push({
        label: tx(i18n, "Diskplass", "Disk space"),
        value: tx(i18n, "{amount} ledig", "{amount} free", { amount: formatBytes(disk.freeBytes, i18n.language()) }),
        detail: tx(i18n, "{percent} % brukt · {used} av {total} · {path}", "{percent}% used · {used} of {total} · {path}", { percent: formatDecimal(percent, i18n.language(), 1), used: formatBytes(used, i18n.language()), total: formatBytes(disk.totalBytes, i18n.language()), path: disk.path }),
        percent,
        meterLabel: tx(i18n, "Brukt diskplass på {path}", "Disk used on {path}", { path: disk.path }),
      });
    }
    return result;
  };
  return <Show when={cards().length > 0}><section class="admin-resource-section" aria-labelledby="host-resources-heading">
    <header><div><span class="eyebrow">{tx(i18n, "RESSURSBRUK NÅ", "RESOURCE USE NOW")}</span><h2 id="host-resources-heading">{tx(i18n, "Serverressurser", "Host resources")}</h2></div><time datetime={props.resources.checkedAt}>{tx(i18n, "Målt {time}", "Measured {time}", { time: formatOsloDateTime(props.resources.checkedAt, i18n.language()) })}</time></header>
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

function dependencyStateLabel(dependency: HealthDependency, language: Language): string {
  if (dependency.name === "Entur API" && dependency.state === "idle") {
    return language === "nb" ? "IKKE BRUKT NYLIG" : "NOT RECENTLY USED";
  }
  return serviceStateLabel(dependency.state, language);
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
  return <article class={`status-health-row service-state-${props.dependency.state}`}>
    <span class={`service-icon state-${props.dependency.state}`}><Icon name={icon()} size={25} /></span>
    <div class="status-health-row-copy">
      <div class="status-health-row-title">
        <h3>{dependencyLabel(props.dependency.name, i18n.language())}</h3>
        <strong class={`state-${props.dependency.state}`}>{dependencyStateLabel(props.dependency, i18n.language())}</strong>
      </div>
      <Show when={!isHealthyServiceState(props.dependency.state)}><small>{operationalDetail(props.dependency.detail, i18n.language())}</small></Show>
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
  return <article class={`status-health-row realtime-delivery-card service-state-${state()}`}>
    <span class={`service-icon state-${state()}`}><Icon name="wifi" size={25} /></span>
    <div class="status-health-row-copy">
      <div class="status-health-row-title">
        <h3>{tx(i18n, "Sanntidslevering", "Realtime delivery")}</h3>
        <strong class={`state-${state()}`}>{serviceStateLabel(state(), i18n.language())}</strong>
      </div>
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
  const labels: Readonly<Record<string, readonly [nb: string, en: string]>> = {
    "Active WebSocket clients": ["Nettlesertilkoblinger", "Browser connections"],
    "Active station watches": ["Overvåkede holdeplasser", "Watched stations"],
    "Active vehicle watches": ["Overvåkede kjøretøy", "Watched vehicles"],
    "Active Focus sessions": ["Fokusøkter", "Focus sessions"],
  };
  const translated = labels[label];
  return translated === undefined ? label : translated[language === "nb" ? 0 : 1];
}

function metricDetail(detail: string, language: Language): string {
  if (language === "en") return detail;
  if (detail === "Shared station scopes" || detail === "Shared monitored scopes") return "Delte overvåkingsområder";
  if (detail === "Shared selected-vehicle scopes") return "Delte områder for valgte kjøretøy";
  if (detail === "One high-priority watch per focused browser session") return "Én høyprioritert overvåking per fokusert nettleserøkt";
  const messages = /^(\S+) WebSocket messages in the last 60 seconds · connections, not unique visitors$/.exec(detail);
  if (messages !== null) return `${messages[1] ?? "0"} WebSocket-meldinger de siste 60 sekundene · tilkoblinger, ikke unike besøkende`;
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

export const EnturAllowanceCard: Component<{ readonly status: AdminStatus }> = (props) => {
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
    <header><span class="eyebrow">{tx(i18n, "FJORDPULSE-BESKYTTELSE", "FJORDPULSE SAFEGUARD")}</span><h2 id="entur-allowance-heading">{tx(i18n, "Intern grense for Entur-kall", "Internal Entur request limit")}</h2></header>
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
          <a href="#entur-request-history">{tx(i18n, "Gå til forespørselshistorikk", "Jump to request history")}</a>
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

interface SystemHealthSummary {
  readonly state: ServiceState;
  readonly tone: "positive" | "warning" | "danger";
  readonly title: string;
  readonly detail: string;
}

function summarizeSystemHealth(dependencies: readonly HealthDependency[], i18n: I18n): SystemHealthSummary {
  const unavailable = dependencies.filter((dependency) => dependency.state === "offline");
  if (unavailable.length > 0) return {
    state: "offline",
    tone: "danger",
    title: unavailable.length === 1
      ? tx(i18n, "En tjeneste er utilgjengelig", "A service is unavailable")
      : tx(i18n, "{count} tjenester er utilgjengelige", "{count} services are unavailable", { count: unavailable.length }),
    detail: unavailable.length === 1
      ? tx(i18n, "{name} må gjenopprettes. Åpne den relevante diagnostikksiden før du endrer konfigurasjon.", "{name} needs recovery. Open its diagnostics before changing configuration.", { name: dependencyLabel(unavailable[0]!.name, i18n.language()) })
      : tx(i18n, "{names} må gjenopprettes. Åpne de relevante diagnostikksidene før du endrer konfigurasjon.", "{names} need recovery. Open their diagnostics before changing configuration.", { names: unavailable.map((dependency) => dependencyLabel(dependency.name, i18n.language())).join(", ") }),
  };
  const degraded = dependencies.filter((dependency) => ["degraded", "delayed", "reconnecting"].includes(dependency.state));
  if (degraded.length > 0) return {
    state: "delayed",
    tone: "warning",
    title: tx(i18n, "Systemet trenger oppfølging", "System needs attention"),
    detail: degraded.length === 1
      ? tx(i18n, "{name} rapporterer redusert drift eller gjenoppretting.", "{name} reports degraded operation or recovery.", { name: dependencyLabel(degraded[0]!.name, i18n.language()) })
      : tx(i18n, "{names} rapporterer redusert drift eller gjenoppretting.", "{names} report degraded operation or recovery.", { names: degraded.map((dependency) => dependencyLabel(dependency.name, i18n.language())).join(", ") }),
  };
  const connecting = dependencies.filter((dependency) => dependency.state === "connecting");
  if (connecting.length > 0) return {
    state: "connecting",
    tone: "warning",
    title: connecting.length === 1 ? tx(i18n, "En tjeneste kobler til", "A service is connecting") : tx(i18n, "Tjenester kobler til", "Services are connecting"),
    detail: connecting.length === 1
      ? tx(i18n, "FjordPulse venter på {name}.", "FjordPulse is waiting for {name}.", { name: dependencyLabel(connecting[0]!.name, i18n.language()) })
      : tx(i18n, "FjordPulse venter på {names}.", "FjordPulse is waiting for {names}.", { names: connecting.map((dependency) => dependencyLabel(dependency.name, i18n.language())).join(", ") }),
  };
  const hasIdleSource = dependencies.some((dependency) => dependency.state === "idle");
  return {
    state: hasIdleSource ? "idle" : "connected",
    tone: "positive",
    title: tx(i18n, "Systemet fungerer", "System operational"),
    detail: hasIdleSource
      ? tx(i18n, "Kjernetjenestene fungerer. Behovsstyrte kilder kan være inaktive frem til neste forespørsel.", "Core services are healthy. Demand-driven sources can stay idle until the next request.")
      : tx(i18n, "Alle overvåkede tjenestebaner fungerer.", "All monitored service paths are healthy."),
  };
}

const SystemHealthBanner: Component<{ readonly status: AdminStatus }> = (props) => {
  const i18n = useI18n();
  const summary = () => summarizeSystemHealth(props.status.dependencies, i18n);
  return <section class={`admin-health-banner tone-${summary().tone}`} aria-labelledby="overall-health-heading">
    <span class="admin-health-icon"><Icon name={summary().tone === "positive" ? "check" : "alert"} size={27} /></span>
    <div class="admin-health-copy">
      <span class="eyebrow">{tx(i18n, "SAMLET TILSTAND", "OVERALL HEALTH")}</span>
      <h2 id="overall-health-heading">{summary().title}</h2>
      <p>{summary().detail}</p>
    </div>
    <div class="admin-health-context" aria-label={tx(i18n, "Kjøremiljø", "Runtime context")}>
      <span>{environmentLabel(props.status.build.environment, i18n.language())}</span>
      <span>{props.status.build.dataMode === "real" ? tx(i18n, "EKTE DATA", "REAL DATA") : tx(i18n, "DEMODATA", "DEMO DATA")}</span>
      <span>{tx(i18n, "BYGG {version}", "BUILD {version}", { version: props.status.build.version })}</span>
    </div>
    <nav class="admin-health-links" aria-label={tx(i18n, "Relaterte systemdetaljer", "Related system details")}>
      <a href="/admin/infrastructure">{tx(i18n, "Åpne infrastruktur", "Open infrastructure")} <Icon name="chevron" size={14} /></a>
      <a href="/admin/events">{tx(i18n, "Se lagrede hendelser", "View persisted events")} <Icon name="chevron" size={14} /></a>
    </nav>
  </section>;
};

export const AdminStatusPage: Component<{ readonly status: AdminStatus; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  const realtimeServer = () => props.status.dependencies.find((dependency) => dependency.name === "Realtime server");
  const liveQueryBridge = () => props.status.dependencies.find((dependency) => dependency.name === "Live-query bridge");
  const groupedRealtimeDelivery = () => realtimeServer() !== undefined && liveQueryBridge() !== undefined;
  const standaloneDependencies = () => props.status.dependencies.filter((dependency) => !groupedRealtimeDelivery() || (dependency.name !== "Realtime server" && dependency.name !== "Live-query bridge"));
  const leadingDependencies = () => standaloneDependencies().filter((dependency) => dependency.name === "Backend");
  const coreDependencies = () => standaloneDependencies().filter((dependency) => dependency.name !== "Backend" && dependency.name !== "Map tiles");
  return <>
    <AdminHeader title={tx(i18n, "Systemstatus", "System status")} subtitle={tx(i18n, "Rask vurdering av om FjordPulse fungerer og hvor du bør undersøke videre.", "A quick view of whether FjordPulse is working and where to investigate next.")} onRefresh={props.onRefresh} />
    <SystemHealthBanner status={props.status} />
    <section class="admin-status-section" aria-labelledby="service-health-heading">
      <header><div><span class="eyebrow">{tx(i18n, "BRUKERRETTET DRIFT", "USER-FACING OPERATION")}</span><h2 id="service-health-heading">{tx(i18n, "Tjenestehelse", "Service health")}</h2></div></header>
      <div class="status-health-list" aria-label={tx(i18n, "Tjenesteavhengigheter", "Service dependencies")}>
        <For each={leadingDependencies()}>{(dependency) => <DependencyCard dependency={dependency} />}</For>
        <Show when={groupedRealtimeDelivery()}>
          <RealtimeDeliveryCard server={realtimeServer()!} bridge={liveQueryBridge()!} />
        </Show>
        <For each={coreDependencies()}>{(dependency) => <DependencyCard dependency={dependency} />}</For>
      </div>
    </section>
    <section class="admin-status-section" aria-labelledby="live-demand-heading">
      <header><div><span class="eyebrow">{tx(i18n, "AKTIVITET NÅ", "ACTIVITY NOW")}</span><h2 id="live-demand-heading">{tx(i18n, "Sanntidsaktivitet", "Live demand")}</h2></div><div class="admin-section-links"><a href="/admin/watches">{tx(i18n, "Åpne overvåkinger", "Open watches")} <Icon name="chevron" size={14} /></a><a href="/admin/realtime">{tx(i18n, "Se tilkoblingsdetaljer", "View connection details")} <Icon name="chevron" size={14} /></a></div></header>
      <div class="admin-demand-panel" aria-label={tx(i18n, "Gjeldende sanntidsaktivitet", "Current live demand")}>
        <For each={props.status.metrics}>{(metric) => <article><span>{metricLabel(metric.label, i18n.language())}</span><strong>{metric.value}</strong><small>{metricDetail(metric.detail, i18n.language())}</small></article>}</For>
      </div>
    </section>
  </>;
};

export const AdminInfrastructurePage: Component<{ readonly status: AdminStatus; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  const mapTiles = () => props.status.dependencies.find((dependency) => dependency.name === "Map tiles");
  return <>
    <AdminHeader title={tx(i18n, "Infrastruktur", "Infrastructure")} subtitle={tx(i18n, "Kjøremiljø, kapasitet, databasemål og lagret databeholdning.", "Runtime identity, capacity, database target, and stored-data inventory.")} onRefresh={props.onRefresh} />
    <section class="admin-infrastructure-section" aria-labelledby="deployment-heading">
      <header><div><span class="eyebrow">{tx(i18n, "HVA KJØRER HER", "WHAT IS RUNNING HERE")}</span><h2 id="deployment-heading">{tx(i18n, "Kjøremiljø", "Deployment identity")}</h2></div></header>
      <div class="metric-grid infrastructure-identity-grid">
        <article class={`metric-card tone-${props.status.build.dataMode === "fake" ? "warning" : "info"}`}><h3>{tx(i18n, "Miljø og datakilde", "Environment and data source")}</h3><strong>{environmentLabel(props.status.build.environment, i18n.language())}</strong><small>{props.status.build.dataMode === "real" ? tx(i18n, "Ekte Entur-data", "Real Entur data") : tx(i18n, "Demodata · Entur-kall er slått av", "Demo data · Entur calls disabled")} · {tx(i18n, "bygg", "build")} {props.status.build.version}</small></article>
        <article class={`metric-card tone-${props.status.database.warning === null ? "positive" : "warning"}`}><h3>{tx(i18n, "Databasemål", "Database target")}</h3><strong>SurrealDB</strong><code class="database-endpoint">{props.status.database.endpointOrigin}</code><small>{props.status.database.namespace} / {props.status.database.name}</small><Show when={props.status.database.warning}>{(warning) => <small class="database-warning">{databaseWarning(warning(), i18n.language())}</small>}</Show></article>
        <Show when={mapTiles()}>{(dependency) => <article class={`metric-card tone-${isHealthyServiceState(dependency().state) ? "positive" : "warning"}`}><h3>{tx(i18n, "Kartkonfigurasjon", "Map configuration")}</h3><strong class={`state-${dependency().state}`}>{isHealthyServiceState(dependency().state) ? tx(i18n, "KONFIGURERT", "CONFIGURED") : serviceStateLabel(dependency().state, i18n.language())}</strong><small>{operationalDetail(dependency().detail, i18n.language())}</small></article>}</Show>
      </div>
    </section>
    <HostResources resources={props.status.resources} />
    <section class="admin-infrastructure-section" aria-labelledby="stored-data-heading">
      <header><div><span class="eyebrow">{tx(i18n, "SURREALDB-BEHOLDNING", "SURREALDB INVENTORY")}</span><h2 id="stored-data-heading">{tx(i18n, "Lagrede data", "Stored data")}</h2></div><div class="admin-section-links"><a href="/admin/events">{tx(i18n, "Åpne hendelser", "Open events")} <Icon name="chevron" size={14} /></a><a href="/admin/entur-log">{tx(i18n, "Åpne Entur-logg", "Open Entur log")} <Icon name="chevron" size={14} /></a></div></header>
      <div class="metric-grid infrastructure-data-grid">
        <article class="metric-card tone-info"><h3>{tx(i18n, "Holdeplasskatalog", "Station catalog")}</h3><strong>{formatCount(props.status.stationImport.count, i18n.language())}</strong><small>{props.status.stationImport.lastImportedAt === null ? tx(i18n, "Ingen fullført import registrert", "No completed import recorded") : tx(i18n, "Importert {time}", "Imported {time}", { time: formatOsloDateTime(props.status.stationImport.lastImportedAt, i18n.language()) })}{props.status.stationImport.sourceVersion === null ? "" : ` · ${props.status.stationImport.sourceVersion}`}</small></article>
        <article class="metric-card tone-info"><h3>{tx(i18n, "Gjeldende transporttilstand", "Current transport state")}</h3><strong>{tx(i18n, "{vehicles} kjøretøy", "{vehicles} vehicles", { vehicles: formatCount(props.status.dataCounts.currentVehicles, i18n.language()) })}</strong><small>{tx(i18n, "{snapshots} holdeplassøyeblikksbilder · {observations} lagrede observasjoner", "{snapshots} station snapshots · {observations} retained observations", { snapshots: formatCount(props.status.dataCounts.stationSnapshots, i18n.language()), observations: formatCount(props.status.dataCounts.vehicleObservations, i18n.language()) })}</small></article>
        <article class="metric-card tone-info"><h3>{tx(i18n, "Lagrede hendelser", "Persisted events")}</h3><strong>{formatCount(props.status.dataCounts.realtimeEvents, i18n.language())}</strong><small>{tx(i18n, "Databasevarsler som kan inspiseres i hendelsesloggen", "Database notifications available in the event log")}</small></article>
        <article class="metric-card tone-info"><h3>{tx(i18n, "Entur-forespørsler", "Entur requests")}</h3><strong>{formatCount(props.status.dataCounts.enturRequestLogs, i18n.language())}</strong><small>{tx(i18n, "Lagrede kildeforespørsler fra FjordPulse-serveren", "Stored source requests from the FjordPulse backend")}</small></article>
      </div>
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
    <AdminHeader title={tx(i18n, "Overvåkingsposter", "Watch records")} subtitle={tx(i18n, "Behovsstyrte oppdateringsområder for holdeplasser, kjøretøy og fokus.", "Demand-driven station, vehicle, and Focus refresh scopes.")} onRefresh={props.onRefresh} />
    <section class="metric-grid watch-metrics"><article class="metric-card tone-info"><span>{tx(i18n, "Overvåkingsposter", "Watch records")}</span><strong>{formatCount(props.rows.length, i18n.language())}</strong><small>{tx(i18n, "Aktive områder og frakoblingsperioder", "Active scopes and disconnect grace")}</small></article><article class="metric-card tone-positive"><span>{tx(i18n, "Fokusområder", "Focus scopes")}</span><strong>{formatCount(props.rows.filter((row) => row.type === "focus").length, i18n.language())}</strong><small>{tx(i18n, "Inkluderer frakoblingsperioder", "Includes disconnect grace")}</small></article><article class="metric-card tone-warning"><span>{tx(i18n, "Utløper snart", "Expiring soon")}</span><strong>{formatCount(count("expiring"), i18n.language())}</strong><small>{tx(i18n, "Ingen aktive klienter", "No active clients")}</small></article><article class="metric-card tone-danger"><span>{tx(i18n, "Mislykkede overvåkinger", "Failed watches")}</span><strong>{formatCount(count("failed"), i18n.language())}</strong><small>{tx(i18n, "Krever oppfølging", "Needs attention")}</small></article></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">{tx(i18n, "PLANLEGGER", "SCHEDULER")}</span><h2>{tx(i18n, "Delte oppdateringsområder", "Shared refresh scopes")}</h2></div><span class="table-count">{tx(i18n, "{count} rader", "{count} rows", { count: formatCount(props.rows.length, i18n.language()) })}</span></header><div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Type", "Type")}</th><th>{tx(i18n, "Omfang", "Scope")}</th><th>{tx(i18n, "Klienter", "Clients")}</th><th>{tx(i18n, "Prioritet", "Priority")}</th><th>{tx(i18n, "Sist oppdatert", "Last refresh")}</th><th>{tx(i18n, "Neste oppdatering", "Next refresh")}</th><th>{tx(i18n, "Tilstand", "State")}</th></tr></thead><tbody><For each={props.rows}>{(row) => <tr class={row.state === "stale" ? "is-warning" : ""}><td><span class="type-cell"><Icon name={row.type === "station" ? "map" : row.type === "focus" ? "focus" : "bus"} size={17} />{watchTypeLabel(row.type, i18n.language())}</span></td><td><code>{row.scope}</code></td><td>{formatCount(row.clients, i18n.language())}</td><td><span class={`priority priority-${row.priority}`}>{watchPriorityLabel(row.priority, i18n.language())}</span></td><td>{row.lastRefreshAt === null ? tx(i18n, "Aldri", "Never") : formatOsloDateTime(row.lastRefreshAt, i18n.language())}</td><td>{row.nextRefreshAt === null ? "—" : formatOsloDateTime(row.nextRefreshAt, i18n.language())}</td><td><span class={`watch-state state-${row.state}`}>{watchStateLabel(row.state, i18n.language())}</span></td></tr>}</For></tbody></table></div></section>
  </>;
};

export const EnturLogPage: Component<{ readonly data: AdminEnturLog; readonly status: AdminStatus | null; readonly onRefresh: () => void }> = (props) => {
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
    <AdminHeader title={tx(i18n, "Logg over Entur-forespørsler", "Entur request log")} subtitle={tx(i18n, "Kildeforespørsler kun fra serveren, med hurtigbufferbruk, svartid, interne grenser og ventetid.", "Backend-only source requests, cache behavior, latency, internal limits, and backoff.")} onRefresh={props.onRefresh} />
    <Show when={props.status} fallback={<section class="admin-diagnostics-section admin-inline-warning" aria-labelledby="entur-allowance-unavailable"><span class="eyebrow">{tx(i18n, "FJORDPULSE-BESKYTTELSE", "FJORDPULSE SAFEGUARD")}</span><h2 id="entur-allowance-unavailable">{tx(i18n, "Interne Entur-grenser er midlertidig utilgjengelige", "Internal Entur limits are temporarily unavailable")}</h2><p>{tx(i18n, "Forespørselshistorikken er fortsatt tilgjengelig nedenfor.", "Request history remains available below.")}</p></section>}>
      {(status) => <EnturAllowanceCard status={status()} />}
    </Show>
    <section class="metric-grid entur-metrics"><article class="metric-card tone-info"><span>{tx(i18n, "Utgående kall/min", "Outbound calls / min")}</span><strong>{formatCount(props.data.metrics.requestsPerMinute, i18n.language())}</strong><small>{tx(i18n, "Faktiske Entur-kall de siste 60 sekundene i serverutvalget", "Actual Entur calls in the last 60 seconds of the server sample")}</small></article><article class="metric-card tone-positive"><span>{tx(i18n, "Treffrate i hurtigbuffer", "Cache hit rate")}</span><strong>{formatCount(Math.round(props.data.metrics.cacheHitRate * 100), i18n.language())}%</strong><small>{tx(i18n, "Serverens metrikkutvalg · tabellfiltre endrer ikke kortene", "Server metric sample · table filters do not change these cards")}</small></article><article class="metric-card tone-info"><span>{tx(i18n, "p95-svartid", "p95 latency")}</span><strong>{props.data.metrics.p95LatencyMs === null ? "—" : `${formatCount(Math.round(props.data.metrics.p95LatencyMs), i18n.language())} ms`}</strong><small>{props.data.metrics.p95LatencyMs === null ? tx(i18n, "Ingen utgående kall i metrikkutvalget", "No outbound calls in the metric sample") : tx(i18n, "Utgående Entur-kall i serverens metrikkutvalg", "Outbound Entur calls in the server metric sample")}</small></article><article class={`metric-card tone-${props.data.metrics.inBackoff ? "warning" : "positive"}`}><span>{tx(i18n, "Ventestatus", "Backoff state")}</span><strong>{props.data.metrics.inBackoff ? tx(i18n, "Aktiv", "Active") : tx(i18n, "Ingen venting", "Clear")}</strong><small>{tx(i18n, "Aktiv bare mens en registrert tidspunktfrist i serverutvalget ligger i fremtiden", "Active only while a recorded retry deadline in the server sample is in the future")}</small></article></section>
    <section class="filter-bar" aria-label={tx(i18n, "Filtre for Entur-logg", "Entur log filters")}><label>API<select value={api()} onChange={(event) => setApi(event.currentTarget.value)}><option value="all">{tx(i18n, "Alle API-er", "All APIs")}</option><option>Journey Planner</option><option>Vehicle Positions</option><option>Geocoder</option><option>Stop Place Register</option></select></label><label>{tx(i18n, "Status", "Status")}<select value={status()} onChange={(event) => setStatus(event.currentTarget.value)}><option value="all">{tx(i18n, "Alle statuser", "All statuses")}</option><option value="ok">OK</option><option value="backoff">{tx(i18n, "Venter", "Backoff")}</option><option value="rate_limited">{tx(i18n, "Begrenset", "Rate limited")}</option><option value="error">{tx(i18n, "Feil", "Error")}</option></select></label><label class="scope-filter">{tx(i18n, "Omfang", "Scope")}<input value={scope()} onInput={(event) => setScope(event.currentTarget.value)} placeholder={tx(i18n, "Filtrer omfang …", "Filter scope…")} /></label></section>
    <section id="entur-request-history" class="admin-table-card"><header><div><span class="eyebrow">{tx(i18n, "ANSVARLIG API-BRUK", "RESPONSIBLE API USE")}</span><h2>{tx(i18n, "Forespørselshistorikk", "Request history")}</h2></div><span class="table-count">{tx(i18n, "{count} rader", "{count} rows", { count: formatCount(filtered().length, i18n.language()) })}</span></header><div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Tid", "Time")}</th><th>API</th><th>{tx(i18n, "Omfang", "Scope")}</th><th>{tx(i18n, "Status", "Status")}</th><th>{tx(i18n, "Svartid", "Latency")}</th><th>{tx(i18n, "Antall", "Count")}</th><th>{tx(i18n, "Hurtigbuffer", "Cache")}</th><th>{tx(i18n, "Nytt forsøk", "Retry")}</th></tr></thead><tbody><For each={filtered()}>{(row) => <tr class={row.status === "backoff" ? "is-warning" : ""}><td>{formatOsloDateTime(row.createdAt, i18n.language())}</td><td><strong>{row.api}</strong></td><td><code>{row.scope}</code></td><td><span class={`log-status state-${row.status}`}>{statusLabel(row.status)}</span></td><td>{row.latencyMs === null ? "—" : `${formatCount(row.latencyMs, i18n.language())} ms`}</td><td>{row.requestCount === null ? "—" : formatCount(row.requestCount, i18n.language())}</td><td><span class={`cache cache-${row.cache}`}>{cacheLabel(row.cache)}</span></td><td>{row.retryAt === null ? "—" : formatOsloDateTime(row.retryAt, i18n.language())}</td></tr>}</For></tbody></table></div></section>
  </>;
};

export const RealtimePage: Component<{ readonly data: AdminRealtime; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  return <>
    <AdminHeader title={tx(i18n, "Sanntidsdiagnostikk", "Realtime diagnostics")} subtitle={tx(i18n, "Telemetri for tilkobling, rom, Live Query-bro, gjenoppkobling og utsending.", "Connection, room, live-query bridge, reconnect, and broadcast telemetry.")} onRefresh={props.onRefresh} />
    <section class="service-grid realtime-services" aria-label={tx(i18n, "Sanntidstjenester", "Realtime services")}><For each={[props.data.server, props.data.liveQueryBridge]}>{(service) => <article class={`service-card service-state-${service.state}`}><span class={`service-icon state-${service.state}`}><Icon name="wifi" size={25} /></span><div><span>{dependencyLabel(service.name, i18n.language())}</span><strong class={`state-${service.state}`}>{serviceStateLabel(service.state, i18n.language())}</strong><small>{operationalDetail(service.detail, i18n.language())}</small></div></article>}</For></section>
    <section class="metric-grid watch-metrics"><article class="metric-card tone-info"><span>{tx(i18n, "Aktive klienter", "Active clients")}</span><strong>{formatCount(props.data.activeClients, i18n.language())}</strong><small>{tx(i18n, "Nettleser-WebSocket-er, ikke unike personer", "Browser WebSockets, not unique people")}</small></article><article class="metric-card tone-info"><span>{tx(i18n, "WebSocket-meldinger", "WebSocket messages")}</span><strong>{formatCount(Math.round(props.data.messagesPerMinute), i18n.language())}</strong><small>{tx(i18n, "Mottatt og levert de siste 60 sekundene", "Received and delivered in the last 60 seconds")}</small></article><article class="metric-card tone-warning"><span>{tx(i18n, "Gjenopprettinger av databasebro", "Database-bridge recoveries")}</span><strong>{formatCount(props.data.reconnectCount, i18n.language())}</strong><small>{tx(i18n, "Siden sanntidsprosessen startet", "Since the realtime process started")}</small></article><article class="metric-card tone-danger"><span>{tx(i18n, "Feil i sanntidsflyten", "Realtime pipeline failures")}</span><strong>{formatCount(props.data.failureCount, i18n.language())}</strong><small>{tx(i18n, "Feil i databasebroen og WebSocket-sendinger siden prosessstart", "Database-bridge and WebSocket-send failures since process start")}</small></article></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">{tx(i18n, "ROMREGISTER", "ROOM REGISTRY")}</span><h2>{tx(i18n, "Aktive rom", "Active rooms")}</h2></div><span class="table-count">{tx(i18n, "Siste leverte utsending {time}", "Last delivered broadcast {time}", { time: props.data.lastBroadcastAt === null ? "—" : formatOsloDateTime(props.data.lastBroadcastAt, i18n.language()) })}</span></header><div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Omfang", "Scope")}</th><th>{tx(i18n, "Klienter", "Clients")}</th><th>{tx(i18n, "Levering", "Delivery")}</th></tr></thead><tbody><For each={props.data.rooms}>{(room) => <tr><td><code>{room.scope}</code></td><td>{formatCount(room.clientCount, i18n.language())}</td><td>{tx(i18n, "Avgrenset til rommet", "Room-scoped")}</td></tr>}</For></tbody></table></div></section>
  </>;
};

export const EventsPage: Component<{ readonly rows: readonly RealtimeEventRow[]; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  return <>
    <AdminHeader title={tx(i18n, "Lagrede sanntidshendelser", "Persisted realtime events")} subtitle={tx(i18n, "Varsler fra databasen via den kanoniske SurrealDB-hendelsesflyten.", "Database-originated notifications from the canonical SurrealDB event path.")} onRefresh={props.onRefresh} />
    <section class="admin-table-card"><header><div><span class="eyebrow">REALTIME_EVENT</span><h2>{tx(i18n, "Nylige varige varsler", "Recent durable notifications")}</h2></div><span class="table-count">{tx(i18n, "{count} rader", "{count} rows", { count: formatCount(props.rows.length, i18n.language()) })}</span></header><div class="table-wrap"><table><thead><tr><th>{tx(i18n, "Hendelses-ID", "Event ID")}</th><th>{tx(i18n, "Type", "Type")}</th><th>{tx(i18n, "Tilstand", "State")}</th><th>{tx(i18n, "Omfang", "Scope")}</th><th>{tx(i18n, "Enhet", "Entity")}</th><th>{tx(i18n, "Kilde", "Source")}</th><th>{tx(i18n, "Versjon", "Version")}</th><th>{tx(i18n, "Opprettet", "Created")}</th></tr></thead><tbody><For each={props.rows}>{(row) => <><tr class={row.type === "vehicle_lost" || row.type === "vehicle_stale" ? "is-warning" : ""}><td><code>{row.eventId}</code></td><td><strong>{row.type}</strong></td><td><StatusChip state={row.type === "vehicle_lost" || row.type === "vehicle_stale" ? "delayed" : "ok"} label={explainRealtimeEvent(row, i18n.language()).label} /></td><td><code>{row.scope}</code></td><td><code>{row.entityId}</code></td><td><code>{row.source}</code></td><td>{formatOsloDateTime(row.version, i18n.language())}</td><td>{formatOsloDateTime(row.createdAt, i18n.language())}</td></tr><EventDetailRow event={row} columns={8} /></>}</For></tbody></table></div></section>
  </>;
};

function databasePermissionLabel(mode: DatabasePermissionMode, i18n: I18n): string {
  return ({
    full: tx(i18n, "Full", "Full"),
    none: tx(i18n, "Ingen", "None"),
    conditional: tx(i18n, "Betinget", "Conditional"),
  })[mode];
}

function databaseMigrationStateLabel(state: DatabaseMigrationState, i18n: I18n): string {
  return ({
    applied: tx(i18n, "Utført", "Applied"),
    pending: tx(i18n, "Venter", "Pending"),
    checksum_mismatch: tx(i18n, "Kontrollsum avviker", "Checksum mismatch"),
    orphaned: tx(i18n, "Bare i databasen", "Database only"),
    failed: tx(i18n, "Mislykket", "Failed"),
  })[state];
}

function databaseObjectKindLabel(kind: "table" | "field" | "index" | "event", i18n: I18n): string {
  return ({
    table: tx(i18n, "tabell", "table"),
    field: tx(i18n, "felt", "field"),
    index: tx(i18n, "indeks", "index"),
    event: tx(i18n, "hendelse", "event"),
  })[kind];
}

function databaseObjectOperationLabel(operation: "define" | "remove", i18n: I18n): string {
  return operation === "define" ? tx(i18n, "definer", "define") : tx(i18n, "fjern", "remove");
}

const PermissionValue: Component<{ readonly label: string; readonly mode: DatabasePermissionMode }> = (props) => (
  <div><dt>{props.label}</dt><dd class={`permission-${props.mode}`}>{databasePermissionLabel(props.mode, useI18n())}</dd></div>
);

const DatabaseBoundary: Component = () => {
  const i18n = useI18n();
  return <aside class="database-boundary" role="note">
    <Icon name="database" size={22} />
    <div><strong>{tx(i18n, "FjordPulse og Surrealist har ulike roller", "FjordPulse and Surrealist have different roles")}</strong><p>{tx(i18n, "FjordPulse Admin viser bare skjema- og migreringsdiagnostikk. Bruk Surrealist via den private operatørtilkoblingen for å utforske poster eller kjøre spørringer.", "FjordPulse Admin only exposes schema and migration diagnostics. Use Surrealist through the private operator connection to inspect records or run queries.")}</p></div>
  </aside>;
};

const DatabaseSectionNav: Component<{ readonly view: AdminDatabaseView; readonly schemaCount?: number; readonly migrationCount?: number }> = (props) => {
  const i18n = useI18n();
  return <nav class="database-tabs" aria-label={tx(i18n, "Databaseseksjoner", "Database sections")}>
    <a href="/admin/database/schema" class={props.view === "schema" ? "is-active" : ""} aria-current={props.view === "schema" ? "page" : undefined}>
      <Icon name="database" size={18} /><span>{tx(i18n, "Gjeldende skjema", "Current schema")}</span><Show when={props.schemaCount !== undefined}><small>{formatCount(props.schemaCount ?? 0, i18n.language())}</small></Show>
    </a>
    <a href="/admin/database/migrations" class={props.view === "migrations" ? "is-active" : ""} aria-current={props.view === "migrations" ? "page" : undefined}>
      <Icon name="activity" size={18} /><span>{tx(i18n, "Migreringer", "Migrations")}</span><Show when={props.migrationCount !== undefined}><small>{formatCount(props.migrationCount ?? 0, i18n.language())}</small></Show>
    </a>
  </nav>;
};

const DatabaseReadOnlyBanner: Component<{ readonly checkedAt: string }> = (props) => {
  const i18n = useI18n();
  return <section class="database-readonly-banner" aria-label={tx(i18n, "Skrivebeskyttet databasevisning", "Read-only database view")}>
    <span class="database-readonly-icon"><Icon name="check" size={20} /></span>
    <div><strong>{tx(i18n, "Trygg inspeksjon", "Safe inspection")}</strong><p>{tx(i18n, "Denne siden kan ikke kjøre spørringer, redigere skjemaet eller utføre migreringer.", "This page cannot run queries, edit the schema, or apply migrations.")}</p></div>
    <div class="database-readonly-meta"><span>{tx(i18n, "SKRIVEBESKYTTET", "READ ONLY")}</span><time datetime={props.checkedAt}>{tx(i18n, "Kontrollert {time}", "Checked {time}", { time: formatOsloDateTime(props.checkedAt, i18n.language()) })}</time></div>
  </section>;
};

const SchemaPermissionSummary: Component<{ readonly table: DatabaseSchemaTable }> = (props) => {
  const i18n = useI18n();
  return <section class="database-definition-section" aria-labelledby={`permissions-${props.table.name}`}>
    <h3 id={`permissions-${props.table.name}`}>{tx(i18n, "Tilganger", "Permissions")}</h3>
    <dl class="database-permission-grid">
      <PermissionValue label={tx(i18n, "Les", "Select")} mode={props.table.permissions.select} />
      <PermissionValue label={tx(i18n, "Opprett", "Create")} mode={props.table.permissions.create} />
      <PermissionValue label={tx(i18n, "Oppdater", "Update")} mode={props.table.permissions.update} />
      <PermissionValue label={tx(i18n, "Slett", "Delete")} mode={props.table.permissions.delete} />
    </dl>
  </section>;
};

const SchemaTableDisclosure: Component<{ readonly table: DatabaseSchemaTable }> = (props) => {
  const i18n = useI18n();
  const fieldPermission = (label: string, mode: DatabasePermissionMode): JSX.Element => <span class={`permission-${mode}`}>{label}: {databasePermissionLabel(mode, i18n)}</span>;
  return <details class="database-disclosure schema-table-disclosure">
    <summary>
      <Icon name="chevron" size={18} />
      <span class="database-row-identity"><strong><code>{props.table.name}</code></strong><small>{props.table.schemaMode.toUpperCase()} · {props.table.kind}</small></span>
      <span class="database-row-counts"><span>{tx(i18n, "{count} felt", "{count} fields", { count: formatCount(props.table.fields.length, i18n.language()) })}</span><span>{tx(i18n, "{count} indekser", "{count} indexes", { count: formatCount(props.table.indexes.length, i18n.language()) })}</span><span>{tx(i18n, "{count} hendelser", "{count} events", { count: formatCount(props.table.events.length, i18n.language()) })}</span></span>
    </summary>
    <div class="database-disclosure-content">
      <SchemaPermissionSummary table={props.table} />
      <section class="database-definition-section" aria-labelledby={`fields-${props.table.name}`}>
        <h3 id={`fields-${props.table.name}`}>{tx(i18n, "Felter", "Fields")} <span>{formatCount(props.table.fields.length, i18n.language())}</span></h3>
        <Show when={props.table.fields.length > 0} fallback={<p class="database-empty">{tx(i18n, "Ingen definerte felt.", "No defined fields.")}</p>}>
          <dl class="database-definition-list"><For each={props.table.fields}>{(field) => <div><dt><code>{field.name}</code><span>{field.type}</span><Show when={field.readonly}><span class="database-code-badge">{tx(i18n, "SKRIVEBESKYTTET", "READ ONLY")}</span></Show></dt><dd><Show when={field.assertion !== null}><span><strong>ASSERT</strong><code>{field.assertion}</code></span></Show><Show when={field.defaultValue !== null}><span><strong>DEFAULT</strong><code>{field.defaultValue}</code></span></Show><span class="database-field-permissions">{fieldPermission(tx(i18n, "Les", "Select"), field.permissions.select)}{fieldPermission(tx(i18n, "Opprett", "Create"), field.permissions.create)}{fieldPermission(tx(i18n, "Oppdater", "Update"), field.permissions.update)}</span></dd></div>}</For></dl>
        </Show>
      </section>
      <section class="database-definition-section" aria-labelledby={`indexes-${props.table.name}`}>
        <h3 id={`indexes-${props.table.name}`}>{tx(i18n, "Indekser", "Indexes")} <span>{formatCount(props.table.indexes.length, i18n.language())}</span></h3>
        <Show when={props.table.indexes.length > 0} fallback={<p class="database-empty">{tx(i18n, "Ingen definerte indekser.", "No defined indexes.")}</p>}>
          <dl class="database-compact-list"><For each={props.table.indexes}>{(index) => <div><dt><code>{index.name}</code><Show when={index.unique}><span class="database-code-badge">UNIQUE</span></Show></dt><dd><code>{index.fields.join(", ")}</code><Show when={index.mode !== null}><span>{index.mode}</span></Show></dd></div>}</For></dl>
        </Show>
      </section>
      <section class="database-definition-section" aria-labelledby={`events-${props.table.name}`}>
        <h3 id={`events-${props.table.name}`}>{tx(i18n, "Hendelser", "Events")} <span>{formatCount(props.table.events.length, i18n.language())}</span></h3>
        <Show when={props.table.events.length > 0} fallback={<p class="database-empty">{tx(i18n, "Ingen definerte databasehendelser.", "No defined database events.")}</p>}>
          <div class="database-event-list"><For each={props.table.events}>{(event) => <article><h4><code>{event.name}</code></h4><Show when={event.condition !== null}><div><span>WHEN</span><pre>{event.condition}</pre></div></Show><div><span>THEN</span><pre>{event.actions.join("\n")}</pre></div></article>}</For></div>
        </Show>
      </section>
    </div>
  </details>;
};

export const DatabaseSchemaPage: Component<{ readonly data: AdminDatabaseSchema; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  const [filter, setFilter] = createSignal("");
  const filteredTables = createMemo(() => {
    const needle = filter().trim().toLocaleLowerCase(i18n.language() === "nb" ? "nb-NO" : "en");
    if (needle === "") return props.data.tables;
    return props.data.tables.filter((table) => table.name.toLocaleLowerCase("en").includes(needle) || table.fields.some((field) => field.name.toLocaleLowerCase("en").includes(needle)));
  });
  const counts = createMemo(() => props.data.tables.reduce((total, table) => ({ fields: total.fields + table.fields.length, indexes: total.indexes + table.indexes.length, events: total.events + table.events.length }), { fields: 0, indexes: 0, events: 0 }));
  return <>
    <AdminHeader title={tx(i18n, "Database", "Database")} subtitle={tx(i18n, "Gjeldende SurrealDB-skjema og samsvar med migreringene i denne versjonen.", "Effective SurrealDB schema and compatibility with this release's migrations.")} onRefresh={props.onRefresh} />
    <DatabaseReadOnlyBanner checkedAt={props.data.checkedAt} />
    <DatabaseSectionNav view="schema" schemaCount={props.data.tables.length} />
    <section class="database-content-panel" aria-labelledby="database-schema-heading">
      <header><div><span class="eyebrow">SURREALDB INFO</span><h2 id="database-schema-heading">{tx(i18n, "Gjeldende skjema", "Current schema")}</h2><p>{tx(i18n, "Det effektive skjemaet som databasen håndhever akkurat nå.", "The effective schema enforced by the database right now.")}</p></div><label class="database-filter"><span>{tx(i18n, "Filtrer tabeller eller felt", "Filter tables or fields")}</span><span><Icon name="search" size={17} /><input type="search" value={filter()} onInput={(event) => setFilter(event.currentTarget.value)} placeholder={tx(i18n, "Søk i skjemaet …", "Search schema…")} /></span></label></header>
      <div class="database-inline-totals" aria-label={tx(i18n, "Skjemasammendrag", "Schema summary")}><span><strong>{formatCount(props.data.tables.length, i18n.language())}</strong>{tx(i18n, "tabeller", "tables")}</span><span><strong>{formatCount(counts().fields, i18n.language())}</strong>{tx(i18n, "felt", "fields")}</span><span><strong>{formatCount(counts().indexes, i18n.language())}</strong>{tx(i18n, "indekser", "indexes")}</span><span><strong>{formatCount(counts().events, i18n.language())}</strong>{tx(i18n, "hendelser", "events")}</span></div>
      <p class="database-permissions-note"><Icon name="server" size={18} /><span>{tx(i18n, "Tilgangene nedenfor er SurrealDB-regler for poster og API-er. FjordPulse-backenden bruker en autentisert, databaseavgrenset EDITOR-tilkobling; «Ingen» betyr ikke at databasen er utilgjengelig, og nettleseren kobler aldri direkte til.", "The permissions below are SurrealDB record and API rules. FjordPulse's backend uses an authenticated, database-scoped EDITOR connection; “None” does not mean the database is unavailable, and the browser never connects directly.")}</span></p>
      <div class="database-disclosure-list"><For each={filteredTables()}>{(table) => <SchemaTableDisclosure table={table} />}</For><Show when={filteredTables().length === 0}><p class="database-no-results">{tx(i18n, "Ingen tabeller eller felt samsvarer med søket.", "No tables or fields match this search.")}</p></Show></div>
    </section>
    <DatabaseBoundary />
  </>;
};

function migrationCompatibility(data: AdminDatabaseMigrations, i18n: I18n): { readonly title: string; readonly detail: string; readonly icon: IconName; readonly tone: string } {
  if (data.state === "in_sync") return { title: tx(i18n, "Databasen samsvarer med denne versjonen", "Database matches this release"), detail: tx(i18n, "Alle migreringer er utført med forventede kontrollsummer.", "Every migration is applied with the expected checksum."), icon: "check", tone: "positive" };
  if (data.state === "pending") return { title: tx(i18n, "Migreringer venter", "Migrations are pending"), detail: tx(i18n, "Databasen er ikke oppdatert til hele skjemaet i denne versjonen.", "The database has not reached this release's complete schema."), icon: "clock", tone: "warning" };
  if (data.state === "failed") return { title: tx(i18n, "En migrering mislyktes", "A migration failed"), detail: tx(i18n, "Åpne den markerte migreringen for siste registrerte feil.", "Open the highlighted migration for the latest recorded failure."), icon: "alert", tone: "danger" };
  return { title: tx(i18n, "Databasen og denne versjonen avviker", "Database and release have drifted"), detail: tx(i18n, "En kontrollsum avviker, eller databasen inneholder en ukjent migrering.", "A checksum differs or the database contains an unknown migration."), icon: "alert", tone: "danger" };
}

const MigrationDisclosure: Component<{ readonly migration: DatabaseMigration; readonly initiallyOpen: boolean }> = (props) => {
  const i18n = useI18n();
  const timestamp = () => props.migration.appliedAt ?? props.migration.lastAttemptedAt;
  return <details class={`database-disclosure migration-disclosure state-${props.migration.state}`} open={props.initiallyOpen}>
    <summary>
      <Icon name="chevron" size={18} />
      <span class="database-row-identity"><strong><code>{props.migration.name}</code></strong><small>{props.migration.description || tx(i18n, "Ingen beskrivelse i migreringskilden.", "No description in migration source.")}</small></span>
      <span class={`migration-state state-${props.migration.state}`}>{databaseMigrationStateLabel(props.migration.state, i18n)}</span>
      <time datetime={timestamp() ?? undefined}>{timestamp() === null ? "—" : formatOsloDateTime(timestamp()!, i18n.language())}</time>
    </summary>
    <div class="database-disclosure-content migration-content">
      <Show when={props.migration.failureMessage !== null}><div class="migration-failure" role="alert"><Icon name="alert" size={19} /><div><strong>{tx(i18n, "Siste registrerte feil", "Latest recorded failure")}</strong><p>{props.migration.failureMessage}</p></div></div></Show>
      <dl class="migration-facts">
        <div><dt>{tx(i18n, "Kontrollsum i versjonen", "Release checksum")}</dt><dd><code>{props.migration.releaseChecksum ?? "—"}</code></dd></div>
        <div><dt>{tx(i18n, "Kontrollsum i databasen", "Database checksum")}</dt><dd><code>{props.migration.databaseChecksum ?? "—"}</code></dd></div>
        <div><dt>{tx(i18n, "Utført", "Applied at")}</dt><dd>{props.migration.appliedAt === null ? "—" : formatOsloDateTime(props.migration.appliedAt, i18n.language())}</dd></div>
        <div><dt>{tx(i18n, "Siste forsøk", "Last attempted")}</dt><dd>{props.migration.lastAttemptedAt === null ? "—" : formatOsloDateTime(props.migration.lastAttemptedAt, i18n.language())}</dd></div>
      </dl>
      <section class="migration-objects" aria-labelledby={`objects-${props.migration.name}`}><h3 id={`objects-${props.migration.name}`}>{tx(i18n, "Påvirkede skjemaobjekter", "Affected schema objects")} <span>{formatCount(props.migration.affectedObjects.length, i18n.language())}</span></h3><Show when={props.migration.affectedObjects.length > 0} fallback={<p class="database-empty">{tx(i18n, "Ingen strukturelle skjemaendringer ble funnet i kilden.", "No structural schema changes were found in the source.")}</p>}><ul><For each={props.migration.affectedObjects}>{(object) => <li><span class={`object-operation operation-${object.operation}`}>{databaseObjectOperationLabel(object.operation, i18n)}</span><span>{databaseObjectKindLabel(object.kind, i18n)}</span><code>{object.table === null || object.kind === "table" ? object.name : `${object.table}.${object.name}`}</code></li>}</For></ul></Show></section>
      <section class="migration-source" aria-labelledby={`source-${props.migration.name}`}><h3 id={`source-${props.migration.name}`}>{tx(i18n, "Kilde fra denne versjonen", "Source bundled with this release")}</h3><Show when={props.migration.source !== null} fallback={<p class="database-empty">{tx(i18n, "Migreringen finnes bare i databaseloggen; denne versjonen har ingen kildefil.", "This migration exists only in the database ledger; this release has no source file.")}</p>}><pre aria-label={tx(i18n, "Skrivebeskyttet kildekode for {name}", "Read-only source for {name}", { name: props.migration.name })}>{props.migration.source}</pre></Show></section>
    </div>
  </details>;
};

export const DatabaseMigrationsPage: Component<{ readonly data: AdminDatabaseMigrations; readonly onRefresh: () => void }> = (props) => {
  const i18n = useI18n();
  const compatibility = createMemo(() => migrationCompatibility(props.data, i18n));
  const firstIssue = createMemo(() => {
    for (const state of ["failed", "checksum_mismatch", "orphaned", "pending"] as const) {
      const index = props.data.migrations.findIndex((migration) => migration.state === state);
      if (index >= 0) return index;
    }
    return -1;
  });
  const issueCounts = createMemo(() => [
    { label: tx(i18n, "Utført", "Applied"), value: props.data.counts.applied, state: "applied", always: true },
    { label: tx(i18n, "Venter", "Pending"), value: props.data.counts.pending, state: "pending", always: false },
    { label: tx(i18n, "Kontrollsum avviker", "Checksum mismatch"), value: props.data.counts.checksumMismatch, state: "checksum_mismatch", always: false },
    { label: tx(i18n, "Bare i databasen", "Database only"), value: props.data.counts.orphaned, state: "orphaned", always: false },
    { label: tx(i18n, "Mislykket", "Failed"), value: props.data.counts.failed, state: "failed", always: false },
  ] as const);
  return <>
    <AdminHeader title={tx(i18n, "Database", "Database")} subtitle={tx(i18n, "Gjeldende SurrealDB-skjema og samsvar med migreringene i denne versjonen.", "Effective SurrealDB schema and compatibility with this release's migrations.")} onRefresh={props.onRefresh} />
    <DatabaseReadOnlyBanner checkedAt={props.data.checkedAt} />
    <DatabaseSectionNav view="migrations" migrationCount={props.data.migrations.length} />
    <section class={`migration-compatibility tone-${compatibility().tone}`} aria-labelledby="migration-compatibility-title"><span class="migration-compatibility-icon"><Icon name={compatibility().icon} size={24} /></span><div><span class="eyebrow">{tx(i18n, "VERSJONSSAMSVAR", "RELEASE COMPATIBILITY")}</span><h2 id="migration-compatibility-title">{compatibility().title}</h2><p>{compatibility().detail}</p></div><div class="migration-counts"><For each={issueCounts()}>{(item) => <Show when={item.always || item.value > 0}><span class={`state-${item.state}`}><strong>{formatCount(item.value, i18n.language())}</strong>{item.label}</span></Show>}</For></div><small>{tx(i18n, "Sist utført: {time}", "Last applied: {time}", { time: props.data.lastAppliedAt === null ? "—" : formatOsloDateTime(props.data.lastAppliedAt, i18n.language()) })}</small></section>
    <section class="database-content-panel migration-panel" aria-labelledby="database-migrations-heading"><header><div><span class="eyebrow">SCHEMA_MIGRATION</span><h2 id="database-migrations-heading">{tx(i18n, "Migreringshistorikk", "Migration history")}</h2><p>{tx(i18n, "Sammenligner kildefilene i denne versjonen med databasens logg.", "Compares this release's source files with the database ledger.")}</p></div></header><div class="database-disclosure-list"><For each={props.data.migrations}>{(migration, index) => <MigrationDisclosure migration={migration} initiallyOpen={index() === firstIssue()} />}</For><Show when={props.data.migrations.length === 0}><p class="database-no-results">{tx(i18n, "Ingen migreringer ble funnet.", "No migrations were found.")}</p></Show></div></section>
    <DatabaseBoundary />
  </>;
};

const AdminLogin: Component<{ readonly error: string | null; readonly busy: boolean; readonly demoCredentials?: AdminDemoCredentials | undefined; readonly onSubmit: (username: string, password: string) => void }> = (props) => {
  const i18n = useI18n();
  const [demoFilled, setDemoFilled] = createSignal(false);
  const demoAvailable = () => props.demoCredentials?.enabled === true;
  let username: HTMLInputElement | undefined;
  let password: HTMLInputElement | undefined;
  const fillDemoCredentials = () => {
    const credentials = props.demoCredentials;
    if (credentials?.enabled !== true || username === undefined || password === undefined) return;
    username.value = credentials.username;
    password.value = credentials.password;
    setDemoFilled(true);
    password.focus();
  };
  return <main class="admin-login"><form onSubmit={(event) => { event.preventDefault(); props.onSubmit(username?.value ?? "", password?.value ?? ""); }}><LanguageSwitcher class="admin-login-language-switcher" /><FjordPulseLogo /><span class="eyebrow">{demoAvailable() ? tx(i18n, "DRIFT · SKRIVEBESKYTTET DEMO", "OPERATIONS · READ-ONLY DEMO") : tx(i18n, "BESKYTTET DRIFTSFLATE", "PROTECTED OPERATOR SURFACE")}</span><h1>{tx(i18n, "Administratorinnlogging", "Admin sign in")}</h1><p>{demoAvailable() ? tx(i18n, "En offentlig, skrivebeskyttet demo er tilgjengelig. Fyll inn demoopplysningene nedenfor, eller bruk operatørpåloggingen din.", "A public read-only demo is available. Fill the demo credentials below, or use your operator credentials.") : tx(i18n, "Bruk operatørpåloggingen din for FjordPulse. Du trenger aldri en konto for å utforske kollektivtransport.", "Use your FjordPulse operator credentials. Public transport browsing never requires an account.")}</p><Show when={props.error !== null}><div class="login-error" role="alert">{props.error}</div></Show><label>{tx(i18n, "Brukernavn", "Username")}<input ref={username} autocomplete="username" required /></label><label>{tx(i18n, "Passord", "Password")}<input ref={password} type="password" autocomplete="current-password" required /></label><Button type="submit" tone="primary" disabled={props.busy}>{tx(i18n, "Logg inn", "Sign in")}</Button><div class="admin-login-secondary-actions"><Show when={demoAvailable()}><button type="button" onClick={fillDemoCredentials}>{tx(i18n, "Fyll inn demoopplysninger", "Fill demo credentials")}</button></Show><a href="/"><span aria-hidden="true">← </span>{tx(i18n, "Tilbake til transportkartet", "Return to public map")}</a></div><Show when={demoFilled()}><span class="sr-only" role="status">{tx(i18n, "Demoopplysningene er fylt inn. Velg Logg inn for å fortsette.", "Demo credentials filled. Select Sign in to continue.")}</span></Show></form></main>;
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

type AdminPageData = AdminStatus | AdminDatabaseSchema | AdminDatabaseMigrations | AdminEnturPageData | AdminRealtime | readonly WatchRow[] | readonly RealtimeEventRow[];

interface AdminPageRequest {
  readonly key: string;
  readonly page: AdminPage;
  readonly databaseView: AdminDatabaseView | undefined;
}

interface AdminPageSnapshot extends AdminPageRequest {
  readonly data: AdminPageData;
}

interface AdminPageLoadState {
  readonly requestedKey: string | null;
  readonly snapshot: AdminPageSnapshot | null;
  readonly pending: boolean;
  readonly error: Error | null;
}

function adminPageKey(page: AdminPage, databaseView: AdminDatabaseView | undefined): string {
  return page === "database" ? `${page}:${databaseView ?? "schema"}` : page;
}

function unauthorizedAdminError(error: Error | null): error is ApiClientError {
  return error instanceof ApiClientError && error.status === 401;
}

export const AdminApp: Component<{ readonly page: AdminPage; readonly databaseView?: AdminDatabaseView | undefined; readonly fixture: boolean; readonly fixtureData?: AdminFixtureData; readonly http: HttpClient; readonly onNavigate?: ((href: string) => boolean | void) | undefined }> = (props) => {
  const i18n = useI18n();
  const fixtureSession: AdminSession = { authenticated: true, username: "Fixture operator", access: "operator", expiresAt: "2099-01-01T00:00:00Z" };
  const [refresh, setRefresh] = createSignal(0);
  const [loginError, setLoginError] = createSignal<Error | null>(null);
  const [loginBusy, setLoginBusy] = createSignal(false);
  const [session, setSession] = createSignal<AdminSession | null>(props.fixture ? fixtureSession : null);
  const [sessionPending, setSessionPending] = createSignal(!props.fixture);
  const [sessionError, setSessionError] = createSignal<Error | null>(null);
  const [pageState, setPageState] = createSignal<AdminPageLoadState>({ requestedKey: null, snapshot: null, pending: false, error: null });
  const pageCache = new Map<string, AdminPageSnapshot>();
  let sessionGeneration = 0;
  let pageGeneration = 0;
  let sessionAbortController: AbortController | null = null;
  let pageAbortController: AbortController | null = null;
  let lastResolvedPageKey: string | null = null;
  let pageStage: HTMLDivElement | undefined;

  const loadSession = async () => {
    if (props.fixture) return;
    const generation = ++sessionGeneration;
    sessionAbortController?.abort();
    const controller = new AbortController();
    sessionAbortController = controller;
    setSessionPending(true);
    setSessionError(null);
    try {
      const value = await props.http.getAdminSession(controller.signal);
      if (generation !== sessionGeneration || controller.signal.aborted) return;
      setSession(value);
    } catch (error) {
      if (generation !== sessionGeneration || controller.signal.aborted) return;
      setSession(null);
      setSessionError(error instanceof Error ? error : new Error("Admin session request failed."));
    } finally {
      if (generation === sessionGeneration) setSessionPending(false);
    }
  };

  const fetchPageData = async (request: AdminPageRequest, signal: AbortSignal): Promise<AdminPageData> => {
    if (props.fixture) {
      const fixture = props.fixtureData;
      if (fixture === undefined) throw new Error("Admin fixture data is unavailable.");
      if (request.page === "watches") return fixture.watches;
      if (request.page === "entur-log") return { log: fixture.enturLog, status: fixture.status };
      if (request.page === "realtime") return fixture.realtime;
      if (request.page === "database") return request.databaseView === "migrations" ? fixture.databaseMigrations : fixture.databaseSchema;
      return fixture.status;
    }
    if (request.page === "watches") return props.http.getAdminWatches(signal);
    if (request.page === "entur-log") {
      const [logResult, statusResult] = await Promise.allSettled([props.http.getAdminEnturLog(signal), props.http.getAdminStatus(signal)]);
      if (logResult.status === "rejected") throw logResult.reason;
      return { log: logResult.value, status: statusResult.status === "fulfilled" ? statusResult.value : null };
    }
    if (request.page === "realtime") return props.http.getAdminRealtime(signal);
    if (request.page === "events") return props.http.getAdminEvents(signal);
    if (request.page === "database") return request.databaseView === "migrations" ? props.http.getAdminDatabaseMigrations(signal) : props.http.getAdminDatabaseSchema(signal);
    return props.http.getAdminStatus(signal);
  };

  const focusResolvedPage = () => queueMicrotask(() => {
    const main = pageStage?.closest<HTMLElement>(".admin-main");
    if (main !== null && main !== undefined) main.scrollTop = 0;
    pageStage?.querySelector<HTMLElement>("h1")?.focus({ preventScroll: true });
  });

  const loadPage = async (request: AdminPageRequest) => {
    const generation = ++pageGeneration;
    pageAbortController?.abort();
    const controller = new AbortController();
    pageAbortController = controller;
    const cached = pageCache.get(request.key) ?? null;
    setPageState((previous) => ({ requestedKey: request.key, snapshot: cached ?? previous.snapshot, pending: true, error: null }));
    try {
      const data = await fetchPageData(request, controller.signal);
      if (generation !== pageGeneration || controller.signal.aborted) return;
      const snapshot: AdminPageSnapshot = { ...request, data };
      const routeChanged = lastResolvedPageKey !== null && lastResolvedPageKey !== request.key;
      pageCache.set(request.key, snapshot);
      lastResolvedPageKey = request.key;
      setPageState({ requestedKey: request.key, snapshot, pending: false, error: null });
      if (routeChanged) focusResolvedPage();
    } catch (error) {
      if (generation !== pageGeneration || controller.signal.aborted) return;
      const normalized = error instanceof Error ? error : new Error("Admin page request failed.");
      if (unauthorizedAdminError(normalized)) {
        pageCache.clear();
        setPageState({ requestedKey: null, snapshot: null, pending: false, error: null });
        setSession(null);
        setSessionError(normalized);
        return;
      }
      setPageState((previous) => ({ ...previous, requestedKey: request.key, pending: false, error: normalized }));
      focusResolvedPage();
    }
  };

  onMount(() => { if (!props.fixture) void loadSession(); });
  createEffect(() => {
    const activeSession = session();
    const page = props.page;
    const databaseView = page === "database" ? props.databaseView ?? "schema" : undefined;
    refresh();
    if (activeSession === null) {
      pageAbortController?.abort();
      return;
    }
    void loadPage({ key: adminPageKey(page, databaseView), page, databaseView });
  });
  onCleanup(() => {
    sessionGeneration += 1;
    pageGeneration += 1;
    sessionAbortController?.abort();
    pageAbortController?.abort();
  });

  const unauthorized = () => unauthorizedAdminError(sessionError());
  const [demoCredentials] = createResource(
    () => unauthorized() && !props.fixture,
    () => props.http.getAdminDemoCredentials(),
  );
  const connection = (): { readonly state: ServiceState; readonly label: string } => {
    const snapshot = pageState().snapshot;
    if (snapshot === null || (snapshot.page !== "status" && snapshot.page !== "infrastructure")) return { state: "connected", label: tx(i18n, "Admin-API tilkoblet", "Admin API connected") };
    const value = snapshot.data as AdminStatus;
    if (value.dependencies.some((dependency) => dependency.state === "offline")) return { state: "offline", label: tx(i18n, "Avhengighet utilgjengelig", "Dependency unavailable") };
    if (value.dependencies.some((dependency) => dependency.state === "degraded" || dependency.state === "delayed" || dependency.state === "reconnecting")) return { state: "delayed", label: tx(i18n, "Redusert systemtilstand", "System degraded") };
    if (value.dependencies.some((dependency) => dependency.state === "idle")) return { state: "idle", label: tx(i18n, "Systemet fungerer", "System operational") };
    return { state: "connected", label: tx(i18n, "Alle avhengigheter fungerer", "All dependencies healthy") };
  };
  const login = async (username: string, password: string) => {
    setLoginBusy(true); setLoginError(null);
    try {
      const value = await props.http.loginAdmin(username, password);
      setSessionError(null);
      setSession(value);
    } catch (error) { setLoginError(error instanceof Error ? error : new Error("Sign in failed.")); }
    finally { setLoginBusy(false); }
  };
  const logout = async () => { if (!props.fixture) await props.http.logoutAdmin(); window.location.assign("/"); };
  const refreshPage = () => setRefresh((value) => value + 1);

  const PageContent: Component<{ readonly snapshot: AdminPageSnapshot }> = (pageProps) => <Switch>
    <Match when={pageProps.snapshot.page === "status"}><AdminStatusPage status={pageProps.snapshot.data as AdminStatus} onRefresh={refreshPage} /></Match>
    <Match when={pageProps.snapshot.page === "infrastructure"}><AdminInfrastructurePage status={pageProps.snapshot.data as AdminStatus} onRefresh={refreshPage} /></Match>
    <Match when={pageProps.snapshot.page === "watches"}><WatchPage rows={pageProps.snapshot.data as readonly WatchRow[]} onRefresh={refreshPage} /></Match>
    <Match when={pageProps.snapshot.page === "entur-log"}><EnturLogPage data={(pageProps.snapshot.data as AdminEnturPageData).log} status={(pageProps.snapshot.data as AdminEnturPageData).status} onRefresh={refreshPage} /></Match>
    <Match when={pageProps.snapshot.page === "realtime"}><RealtimePage data={pageProps.snapshot.data as AdminRealtime} onRefresh={refreshPage} /></Match>
    <Match when={pageProps.snapshot.page === "events"}><EventsPage rows={pageProps.snapshot.data as readonly RealtimeEventRow[]} onRefresh={refreshPage} /></Match>
    <Match when={pageProps.snapshot.page === "database" && pageProps.snapshot.databaseView !== "migrations"}><DatabaseSchemaPage data={pageProps.snapshot.data as AdminDatabaseSchema} onRefresh={refreshPage} /></Match>
    <Match when={pageProps.snapshot.page === "database" && pageProps.snapshot.databaseView === "migrations"}><DatabaseMigrationsPage data={pageProps.snapshot.data as AdminDatabaseMigrations} onRefresh={refreshPage} /></Match>
  </Switch>;

  return <Switch>
    <Match when={unauthorized()}><AdminLogin error={localizedAdminError(loginError(), i18n.language())} busy={loginBusy()} demoCredentials={demoCredentials()} onSubmit={(username, password) => void login(username, password)} /></Match>
    <Match when={sessionPending() && session() === null}><main class="admin-loading"><LanguageSwitcher class="admin-loading-language-switcher" /><section class="admin-state-card" role="status" aria-live="polite"><span class="spinner" /><p>{tx(i18n, "Laster beskyttede systemdata …", "Loading protected system data…")}</p></section></main></Match>
    <Match when={sessionError() !== null}><main class="admin-loading"><LanguageSwitcher class="admin-loading-language-switcher" /><section class="admin-state-card" role="alert"><Icon name="alert" size={30} /><h1>{tx(i18n, "Administrasjonsdata er ikke tilgjengelig", "Admin data unavailable")}</h1><p>{localizedAdminError(sessionError(), i18n.language()) ?? tx(i18n, "Ukjent feil", "Unknown error")}</p><Button onClick={() => void loadSession()}>{tx(i18n, "Prøv igjen", "Retry")}</Button></section></main></Match>
    <Match when={session() !== null}><AdminLayout page={props.page} username={session()!.username} access={session()!.access} connectionState={connection().state} connectionLabel={connection().label} onNavigate={props.onNavigate} onLogout={() => void logout()}>
      <div ref={pageStage} class={`admin-page-stage${pageState().pending ? " is-loading" : ""}`} aria-busy={pageState().pending}>
        <Show when={pageState().pending && pageState().snapshot !== null}><div class="admin-page-progress" role="progressbar" aria-label={tx(i18n, "Laster administrasjonssiden", "Loading Admin page")}><span class="sr-only">{tx(i18n, "Laster oppdaterte administrasjonsdata …", "Loading updated Admin data…")}</span></div></Show>
        <Switch>
          <Match when={pageState().error !== null}><section class="admin-page-state is-error" role="alert"><div class="admin-state-card"><Icon name="alert" size={30} /><h1 tabindex={-1}>{tx(i18n, "Administrasjonssiden er ikke tilgjengelig", "Admin page unavailable")}</h1><p>{localizedAdminError(pageState().error, i18n.language()) ?? tx(i18n, "Ukjent feil", "Unknown error")}</p><Button onClick={refreshPage}>{tx(i18n, "Prøv igjen", "Retry")}</Button></div></section></Match>
          <Match when={pageState().snapshot !== null}><Show when={pageState().snapshot} keyed>{(snapshot) => <div class="admin-page-content" data-admin-page={snapshot.key} inert={pageState().pending ? true : undefined}><PageContent snapshot={snapshot} /></div>}</Show></Match>
          <Match when={pageState().pending}><section class="admin-page-state" role="status" aria-live="polite"><div class="admin-state-card"><span class="spinner" /><p>{tx(i18n, "Laster administrasjonssiden …", "Loading Admin page…")}</p></div></section></Match>
        </Switch>
      </div>
    </AdminLayout></Match>
  </Switch>;
};
