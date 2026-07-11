import { createMemo, createResource, createSignal, For, Match, Show, Switch, type Component, type JSX } from "solid-js";
import { ApiClientError, type HttpClient } from "../services/httpClient";
import type { AdminEnturLog, AdminEvent, AdminRealtime, AdminResourceSnapshot, AdminStatus, MigrationRow, RealtimeEventRow, ServiceState, WatchRow } from "../types/domain";
import { Button, FjordPulseLogo, StatusChip } from "./DesignSystem";
import { Icon, type IconName } from "./Icon";
import { useClock } from "../state/clock";
import { formatOsloDateTime, formatOsloTime } from "../utils/format";

export type AdminPage = "status" | "watches" | "entur-log" | "realtime" | "events" | "migrations";

export interface AdminFixtureData {
  readonly status: AdminStatus;
  readonly watches: readonly WatchRow[];
  readonly enturLog: AdminEnturLog;
}

const adminNav: readonly { readonly page: AdminPage; readonly label: string; readonly icon: IconName }[] = [
  { page: "status", label: "System status", icon: "activity" },
  { page: "watches", label: "Active watches", icon: "focus" },
  { page: "entur-log", label: "Entur request log", icon: "server" },
  { page: "realtime", label: "Realtime diagnostics", icon: "wifi" },
  { page: "events", label: "Persisted events", icon: "activity" },
  { page: "migrations", label: "Migrations", icon: "database" },
];

const AdminLayout: Component<{ readonly page: AdminPage; readonly children: JSX.Element; readonly username: string; readonly connectionState: ServiceState; readonly connectionLabel: string; readonly onLogout?: () => void }> = (props) => {
  const initials = () => props.username.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? "").join("") || "OP";
  return <div class="admin-shell">
    <aside class="admin-sidebar">
      <FjordPulseLogo />
      <span class="admin-label">ADMIN CONSOLE</span>
      <nav aria-label="Admin navigation">
        <a href="/admin/status"><Icon name="map" size={19} />Overview</a>
        <For each={adminNav}>{(item) => <a href={`/admin/${item.page}`} class={props.page === item.page ? "is-active" : ""} aria-current={props.page === item.page ? "page" : undefined}><Icon name={item.icon} size={19} />{item.label}</a>}</For>
      </nav>
      <div class="admin-sidebar-bottom">
        <StatusChip state={props.connectionState} label={props.connectionLabel} />
        <div class="admin-account">
          <span class="avatar">{initials()}</span>
          <span><small>Signed in as</small><strong>{props.username}</strong></span>
        </div>
        <button class="admin-logout-button" type="button" onClick={props.onLogout} aria-label={`Log out ${props.username}`}>
          <Icon name="logout" size={18} /><span>Log out</span>
        </button>
      </div>
    </aside>
    <main class="admin-main">{props.children}</main>
  </div>;
};

const AdminHeader: Component<{ readonly title: string; readonly subtitle: string; readonly onRefresh: () => void }> = (props) => {
  const now = useClock();
  return <header class="admin-header"><div><span class="eyebrow">FjordPulse operations</span><h1>{props.title}</h1><p>{props.subtitle}</p></div><div><time datetime={new Date(now()).toISOString()}>{formatOsloTime(now())} Oslo</time><button class="icon-button" type="button" onClick={props.onRefresh} aria-label="Refresh admin data"><Icon name="refresh" size={20} /></button></div></header>;
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

function elapsedText(from: string, to: string): string | null {
  const elapsedSeconds = Math.max(0, Math.round((Date.parse(to) - Date.parse(from)) / 1_000));
  if (!Number.isFinite(elapsedSeconds)) return null;
  if (elapsedSeconds < 60) return `${elapsedSeconds} sec`;
  const minutes = Math.floor(elapsedSeconds / 60);
  const seconds = elapsedSeconds % 60;
  if (minutes < 60) return seconds === 0 ? `${minutes} min` : `${minutes} min ${seconds} sec`;
  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;
  return remainingMinutes === 0 ? `${hours} hr` : `${hours} hr ${remainingMinutes} min`;
}

export function explainRealtimeEvent(event: EventEvidence): { readonly label: string; readonly summary: string } {
  const vehicle = recordValue(event.payload.vehicle);
  const observation = recordValue(event.payload.observation);
  const observedAt = stringValue(observation?.observedAt) ?? stringValue(vehicle?.lastSeenAt);
  const refreshedAt = stringValue(vehicle?.refreshedAt) ?? event.version;
  const age = observedAt === null ? null : elapsedText(observedAt, refreshedAt);

  if (event.type === "vehicle_lost") {
    return {
      label: "LOST",
      summary: age === null
        ? "No recent position was available when this vehicle was marked lost."
        : `No recent position. The last observation was ${age} old when this vehicle was marked lost.`,
    };
  }
  if (event.type === "vehicle_stale") {
    return {
      label: "STALE",
      summary: age === null
        ? "The latest vehicle position was older than the live threshold."
        : `The latest vehicle position was ${age} old when this stale state was recorded.`,
    };
  }
  if (event.type === "vehicle_moved") return { label: "LIVE", summary: "A newer vehicle position was persisted." };
  if (event.type === "station_snapshot_changed") return { label: "UPDATED", summary: "A newer station snapshot was persisted." };
  return { label: "RECORDED", summary: "A durable realtime notification was persisted." };
}

const EventDetails: Component<{ readonly event: EventEvidence }> = (props) => {
  const explanation = () => explainRealtimeEvent(props.event);
  return <details class="event-details">
    <summary role="button" aria-label={`Details for ${props.event.type} ${props.event.scope}`}>Details</summary>
    <div class="event-detail-content">
      <p>{explanation().summary}</p>
      <dl>
        <div><dt>Source</dt><dd><code>{props.event.source}</code></dd></div>
        <div><dt>Entity</dt><dd><code>{props.event.entityId}</code></dd></div>
        <div><dt>Version</dt><dd>{formatOsloDateTime(props.event.version)}</dd></div>
        <div><dt>Recorded</dt><dd>{formatOsloDateTime(props.event.createdAt)}</dd></div>
      </dl>
      <pre aria-label="Raw event payload"><code>{JSON.stringify(props.event.payload, null, 2)}</code></pre>
    </div>
  </details>;
};

const EventDetailRow: Component<{ readonly event: EventEvidence; readonly columns: number }> = (props) => (
  <tr class="event-detail-row"><td colSpan={props.columns}><EventDetails event={props.event} /></td></tr>
);

const formatCount = (value: number): string => new Intl.NumberFormat("en-GB").format(value);

function formatBytes(value: number): string {
  const units = ["B", "KiB", "MiB", "GiB", "TiB"] as const;
  let size = Math.max(0, value);
  let unit = 0;
  while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit += 1; }
  return `${size >= 100 || unit === 0 ? Math.round(size) : size.toFixed(1)} ${units[unit]}`;
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
  const percent = () => props.card.percent === null ? null : Math.max(0, Math.min(100, props.card.percent));
  return <article class={`metric-card resource-card tone-${percent() === null ? "info" : utilizationTone(percent()!)}`}>
    <span>{props.card.label}</span>
    <strong>{props.card.value}</strong>
    <small>{props.card.detail}</small>
    <Show when={percent() !== null}><div class="resource-meter" role="progressbar" aria-label={props.card.meterLabel} aria-valuemin="0" aria-valuemax="100" aria-valuenow={Math.round(percent()!)} aria-valuetext={`${Math.round(percent()!)}% used`}><span style={{ width: `${percent()!}%` }} /></div></Show>
  </article>;
};

const HostResources: Component<{ readonly resources: AdminResourceSnapshot }> = (props) => {
  const cards = (): readonly ResourceCard[] => {
    const result: ResourceCard[] = [];
    const cpu = props.resources.cpu;
    if (cpu.usagePercent !== null || cpu.load1 !== null) {
      const normalizedLoad = cpu.load1 !== null && cpu.logicalCores !== null ? Math.min(100, cpu.load1 / cpu.logicalCores * 100) : null;
      const loads = [cpu.load1, cpu.load5, cpu.load15].map((value) => value === null ? "—" : value.toFixed(2)).join(" / ");
      result.push({
        label: cpu.usagePercent === null ? "CPU load" : "CPU usage",
        value: cpu.usagePercent === null ? `${cpu.load1?.toFixed(2) ?? "—"} load` : `${cpu.usagePercent.toFixed(1)}% used`,
        detail: `Load 1/5/15: ${loads}${cpu.logicalCores === null ? "" : ` · ${cpu.logicalCores} logical CPUs`}`,
        percent: cpu.usagePercent ?? normalizedLoad,
        meterLabel: cpu.usagePercent === null ? "CPU load relative to available CPUs" : "CPU usage",
      });
    }
    const memory = props.resources.memory;
    if (memory.totalBytes !== null && memory.availableBytes !== null) {
      const used = memory.usedBytes ?? Math.max(0, memory.totalBytes - memory.availableBytes);
      const percent = memory.usedPercent ?? (memory.totalBytes === 0 ? 0 : used / memory.totalBytes * 100);
      result.push({
        label: "Memory",
        value: `${formatBytes(memory.availableBytes)} free`,
        detail: `${formatBytes(used)} used of ${formatBytes(memory.totalBytes)} · ${memory.scope === "cgroup" ? "Container limit" : "Host RAM"}`,
        percent,
        meterLabel: "Memory used",
      });
    }
    const disk = props.resources.disk;
    if (disk.totalBytes !== null && disk.freeBytes !== null) {
      const used = disk.usedBytes ?? Math.max(0, disk.totalBytes - disk.freeBytes);
      const percent = disk.usedPercent ?? (disk.totalBytes === 0 ? 0 : used / disk.totalBytes * 100);
      result.push({
        label: "Application disk",
        value: `${formatBytes(disk.freeBytes)} free`,
        detail: `${formatBytes(used)} used of ${formatBytes(disk.totalBytes)} · ${disk.path}`,
        percent,
        meterLabel: `Disk used on ${disk.path}`,
      });
    }
    return result;
  };
  return <Show when={cards().length > 0}><section class="admin-resource-section" aria-labelledby="host-resources-heading">
    <header><div><span class="eyebrow">CURRENT SERVER SNAPSHOT</span><h2 id="host-resources-heading">Host resources</h2></div><time datetime={props.resources.checkedAt}>Measured {formatOsloDateTime(props.resources.checkedAt)}</time></header>
    <div class="metric-grid resource-metrics"><For each={cards()}>{(card) => <ResourceMetricCard card={card} />}</For></div>
  </section></Show>;
};

export const AdminStatusPage: Component<{ readonly status: AdminStatus; readonly onRefresh: () => void }> = (props) => (
  <>
    <AdminHeader title="System status" subtitle="Operational overview of the HTTP, realtime, database, and source services." onRefresh={props.onRefresh} />
    <section class="service-grid" aria-label="Service dependencies">
      <For each={props.status.dependencies}>{(dependency) => (
        <article class="service-card"><span class="service-icon"><Icon name={dependency.name === "SurrealDB" ? "database" : dependency.name === "Realtime server" ? "wifi" : dependency.name === "Backend" ? "server" : "refresh"} size={25} /></span><div><span>{dependency.name}</span><strong class={`state-${dependency.state}`}>{dependency.state === "connected" ? "Connected" : dependency.state.toUpperCase()}</strong><small>{dependency.detail}</small></div><Show when={dependency.latencyMs !== undefined}><span class="latency">{dependency.latencyMs} ms</span></Show></article>
      )}</For>
    </section>
    <section class="metric-grid" aria-label="System metrics"><For each={props.status.metrics}>{(metric) => <article class={`metric-card tone-${metric.tone}`}><span>{metric.label}</span><strong>{metric.value}</strong><small>{metric.detail}</small></article>}</For></section>
    <HostResources resources={props.status.resources} />
    <section class="admin-diagnostics-section" aria-labelledby="deployment-data-heading">
      <header><span class="eyebrow">DEPLOYMENT &amp; DATABASE</span><h2 id="deployment-data-heading">Runtime and stored data</h2></header>
      <div class="metric-grid admin-data-metrics">
        <article class={`metric-card tone-${props.status.build.dataMode === "fake" ? "warning" : "info"}`}><span>Environment</span><strong>{props.status.build.environment.toUpperCase()}</strong><small>{props.status.build.dataMode === "real" ? "Real Entur data" : "Demo fixture data"} · build {props.status.build.version}</small></article>
        <article class={`metric-card tone-${props.status.database.warning === null ? "positive" : "warning"}`}><span>Database target</span><strong>SurrealDB</strong><code class="database-endpoint">{props.status.database.endpointOrigin}</code><small>{props.status.database.namespace} / {props.status.database.name} · {formatCount(props.status.dataCounts.stationSnapshots)} station snapshots · {formatCount(props.status.dataCounts.watches)} watches</small><Show when={props.status.database.warning}>{(warning) => <small class="database-warning">{warning()}</small>}</Show></article>
        <article class="metric-card tone-info"><span>Station catalog</span><strong>{formatCount(props.status.stationImport.count)}</strong><small>{props.status.stationImport.lastImportedAt === null ? "No completed import recorded" : `Imported ${formatOsloDateTime(props.status.stationImport.lastImportedAt)}`}{props.status.stationImport.sourceVersion === null ? "" : ` · ${props.status.stationImport.sourceVersion}`}</small></article>
        <article class="metric-card tone-info"><span>Current vehicles</span><strong>{formatCount(props.status.dataCounts.currentVehicles)}</strong><small>{formatCount(props.status.dataCounts.vehicleObservations)} retained observations</small></article>
        <article class="metric-card tone-info"><span>Durable events</span><strong>{formatCount(props.status.dataCounts.realtimeEvents)}</strong><small>Database-originated notifications</small></article>
        <article class="metric-card tone-info"><span>Entur request records</span><strong>{formatCount(props.status.dataCounts.enturRequestLogs)}</strong><small>Backend source-request history</small></article>
      </div>
    </section>
    <section class="admin-table-card"><header><div><span class="eyebrow">LIVE QUERY PIPELINE</span><h2>Recent events</h2></div><a href="/admin/events">View all events <Icon name="chevron" size={15} /></a></header><div class="table-wrap"><table><thead><tr><th>Event</th><th>Scope</th><th>Time</th><th>State</th></tr></thead><tbody><For each={props.status.events}>{(event) => <><tr class={event.status === "warning" ? "is-warning" : ""}><td><span class={`event-dot tone-${event.status === "ok" ? "positive" : event.status === "warning" ? "warning" : "danger"}`}><Icon name="activity" size={14} /></span><strong>{event.type}</strong></td><td><code>{event.scope}</code></td><td>{formatOsloDateTime(event.createdAt)}</td><td><StatusChip state={event.status === "ok" ? "ok" : event.status === "warning" ? "delayed" : "offline"} label={explainRealtimeEvent(event).label} /></td></tr><EventDetailRow event={event} columns={4} /></>}</For></tbody></table></div></section>
  </>
);

const WatchPage: Component<{ readonly rows: readonly WatchRow[]; readonly onRefresh: () => void }> = (props) => {
  const count = (state: WatchRow["state"]) => props.rows.filter((row) => row.state === state).length;
  return <>
    <AdminHeader title="Active watches" subtitle="Demand-driven station, vehicle, and Focus refresh scopes." onRefresh={props.onRefresh} />
    <section class="metric-grid watch-metrics"><article class="metric-card tone-info"><span>Total watches</span><strong>{props.rows.length}</strong><small>Across active clients</small></article><article class="metric-card tone-positive"><span>Focus watches</span><strong>{props.rows.filter((row) => row.type === "focus").length}</strong><small>Critical priority</small></article><article class="metric-card tone-warning"><span>Expiring soon</span><strong>{count("expiring")}</strong><small>No active clients</small></article><article class="metric-card tone-danger"><span>Failed watches</span><strong>{count("failed")}</strong><small>Needs attention</small></article></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">SCHEDULER</span><h2>Shared refresh scopes</h2></div><span class="table-count">{props.rows.length} rows</span></header><div class="table-wrap"><table><thead><tr><th>Type</th><th>Scope</th><th>Clients</th><th>Priority</th><th>Last refresh</th><th>Next refresh</th><th>State</th></tr></thead><tbody><For each={props.rows}>{(row) => <tr class={row.state === "stale" ? "is-warning" : ""}><td><span class="type-cell"><Icon name={row.type === "station" ? "map" : row.type === "focus" ? "focus" : "bus"} size={17} />{row.type}</span></td><td><code>{row.scope}</code></td><td>{row.clients}</td><td><span class={`priority priority-${row.priority}`}>{row.priority}</span></td><td>{row.lastRefreshAt === null ? "Never" : formatOsloDateTime(row.lastRefreshAt)}</td><td>{row.nextRefreshAt === null ? "—" : formatOsloDateTime(row.nextRefreshAt)}</td><td><span class={`watch-state state-${row.state}`}>{row.state}</span></td></tr>}</For></tbody></table></div></section>
  </>;
};

const EnturLogPage: Component<{ readonly data: AdminEnturLog; readonly onRefresh: () => void }> = (props) => {
  const [api, setApi] = createSignal("all");
  const [status, setStatus] = createSignal("all");
  const [scope, setScope] = createSignal("");
  const filtered = createMemo(() => props.data.entries.filter((row) => (api() === "all" || row.api === api()) && (status() === "all" || row.status === status()) && row.scope.toLowerCase().includes(scope().toLowerCase())));
  return <>
    <AdminHeader title="Entur request log" subtitle="Backend-only source requests, cache behavior, latency, budgets, and backoff." onRefresh={props.onRefresh} />
    <section class="metric-grid entur-metrics"><article class="metric-card tone-info"><span>Requests / min</span><strong>{props.data.metrics.requestsPerMinute}</strong><small>Observed requests</small></article><article class="metric-card tone-positive"><span>Cache hit rate</span><strong>{Math.round(props.data.metrics.cacheHitRate * 100)}%</strong><small>Current result window</small></article><article class="metric-card tone-info"><span>p95 latency</span><strong>{props.data.metrics.p95LatencyMs === null ? "—" : `${Math.round(props.data.metrics.p95LatencyMs)} ms`}</strong><small>{props.data.metrics.p95LatencyMs === null ? "No measured requests" : "Measured source calls"}</small></article><article class={`metric-card tone-${props.data.metrics.inBackoff ? "warning" : "positive"}`}><span>Backoff state</span><strong>{props.data.metrics.inBackoff ? "Active" : "Clear"}</strong><small>Current source window</small></article></section>
    <section class="filter-bar" aria-label="Entur log filters"><label>API<select value={api()} onChange={(event) => setApi(event.currentTarget.value)}><option value="all">All APIs</option><option>Journey Planner</option><option>Vehicle Positions</option><option>Geocoder</option><option>Stop Place Register</option></select></label><label>Status<select value={status()} onChange={(event) => setStatus(event.currentTarget.value)}><option value="all">All statuses</option><option value="ok">OK</option><option value="backoff">Backoff</option><option value="error">Error</option></select></label><label class="scope-filter">Scope<input value={scope()} onInput={(event) => setScope(event.currentTarget.value)} placeholder="Filter scope…" /></label></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">RESPONSIBLE API USE</span><h2>Request history</h2></div><span class="table-count">{filtered().length} rows</span></header><div class="table-wrap"><table><thead><tr><th>Time</th><th>API</th><th>Scope</th><th>Status</th><th>Latency</th><th>Count</th><th>Cache</th><th>Retry</th></tr></thead><tbody><For each={filtered()}>{(row) => <tr class={row.status === "backoff" ? "is-warning" : ""}><td>{formatOsloDateTime(row.createdAt)}</td><td><strong>{row.api}</strong></td><td><code>{row.scope}</code></td><td><span class={`log-status state-${row.status}`}>{row.status.replace("_", " ")}</span></td><td>{row.latencyMs === null ? "—" : `${row.latencyMs} ms`}</td><td>{row.requestCount ?? "—"}</td><td><span class={`cache cache-${row.cache}`}>{row.cache}</span></td><td>{row.retryAt === null ? "—" : formatOsloDateTime(row.retryAt)}</td></tr>}</For></tbody></table></div></section>
  </>;
};

const RealtimePage: Component<{ readonly data: AdminRealtime; readonly onRefresh: () => void }> = (props) => <>
  <AdminHeader title="Realtime diagnostics" subtitle="Connection, room, live-query bridge, reconnect, and broadcast telemetry." onRefresh={props.onRefresh} />
  <section class="service-grid realtime-services"><For each={[props.data.server, props.data.liveQueryBridge]}>{(service) => <article class="service-card"><span class="service-icon"><Icon name="wifi" size={25} /></span><div><span>{service.name}</span><strong class={`state-${service.state}`}>{service.state}</strong><small>{service.detail}</small></div></article>}</For></section>
  <section class="metric-grid watch-metrics"><article class="metric-card tone-info"><span>Active clients</span><strong>{props.data.activeClients}</strong><small>Browser WebSockets</small></article><article class="metric-card tone-info"><span>Messages / min</span><strong>{Math.round(props.data.messagesPerMinute)}</strong><small>Validated frames</small></article><article class="metric-card tone-warning"><span>Reconnects</span><strong>{props.data.reconnectCount}</strong><small>Since process start</small></article><article class="metric-card tone-danger"><span>Failures</span><strong>{props.data.failureCount}</strong><small>Supervised failures</small></article></section>
  <section class="admin-table-card"><header><div><span class="eyebrow">ROOM REGISTRY</span><h2>Active rooms</h2></div><span class="table-count">Last broadcast {props.data.lastBroadcastAt === null ? "—" : formatOsloDateTime(props.data.lastBroadcastAt)}</span></header><div class="table-wrap"><table><thead><tr><th>Scope</th><th>Clients</th><th>Isolation</th></tr></thead><tbody><For each={props.data.rooms}>{(room) => <tr><td><code>{room.scope}</code></td><td>{room.clientCount}</td><td><StatusChip state="ok" label="Scoped" /></td></tr>}</For></tbody></table></div></section>
</>;

export const EventsPage: Component<{ readonly rows: readonly RealtimeEventRow[]; readonly onRefresh: () => void }> = (props) => <>
  <AdminHeader title="Persisted realtime events" subtitle="Database-originated notifications from the canonical SurrealDB event path." onRefresh={props.onRefresh} />
  <section class="admin-table-card"><header><div><span class="eyebrow">REALTIME_EVENT</span><h2>Recent durable notifications</h2></div><span class="table-count">{props.rows.length} rows</span></header><div class="table-wrap"><table><thead><tr><th>Event ID</th><th>Type</th><th>State</th><th>Scope</th><th>Entity</th><th>Source</th><th>Version</th><th>Created</th></tr></thead><tbody><For each={props.rows}>{(row) => <><tr class={row.type === "vehicle_lost" || row.type === "vehicle_stale" ? "is-warning" : ""}><td><code>{row.eventId}</code></td><td><strong>{row.type}</strong></td><td><StatusChip state={row.type === "vehicle_lost" || row.type === "vehicle_stale" ? "delayed" : "ok"} label={explainRealtimeEvent(row).label} /></td><td><code>{row.scope}</code></td><td><code>{row.entityId}</code></td><td><code>{row.source}</code></td><td>{formatOsloDateTime(row.version)}</td><td>{formatOsloDateTime(row.createdAt)}</td></tr><EventDetailRow event={row} columns={8} /></>}</For></tbody></table></div></section>
</>;

const MigrationsPage: Component<{ readonly rows: readonly MigrationRow[]; readonly onRefresh: () => void }> = (props) => <>
  <AdminHeader title="Database migrations" subtitle="Applied, pending, and failed SurrealDB schema migrations." onRefresh={props.onRefresh} />
  <section class="metric-grid watch-metrics"><article class="metric-card tone-positive"><span>Applied</span><strong>{props.rows.filter((row) => row.state === "applied").length}</strong><small>Verified checksums</small></article><article class="metric-card tone-warning"><span>Pending</span><strong>{props.rows.filter((row) => row.state === "pending").length}</strong><small>Awaiting application</small></article><article class="metric-card tone-danger"><span>Failed</span><strong>{props.rows.filter((row) => row.state === "failed").length}</strong><small>Requires attention</small></article></section>
  <section class="admin-table-card"><header><div><span class="eyebrow">SCHEMA_MIGRATION</span><h2>Migration ledger</h2></div></header><div class="table-wrap"><table><thead><tr><th>Name</th><th>Checksum</th><th>State</th><th>Applied at</th></tr></thead><tbody><For each={props.rows}>{(row) => <tr><td><strong>{row.name}</strong></td><td><code>{row.checksum}</code></td><td><span class={`watch-state state-${row.state}`}>{row.state}</span></td><td>{row.appliedAt === null ? "—" : formatOsloDateTime(row.appliedAt)}</td></tr>}</For></tbody></table></div></section>
</>;

const AdminLogin: Component<{ readonly error: string | null; readonly busy: boolean; readonly onSubmit: (username: string, password: string) => void }> = (props) => {
  let username: HTMLInputElement | undefined;
  let password: HTMLInputElement | undefined;
  return <main class="admin-login"><form onSubmit={(event) => { event.preventDefault(); props.onSubmit(username?.value ?? "", password?.value ?? ""); }}><FjordPulseLogo /><span class="eyebrow">PROTECTED OPERATOR SURFACE</span><h1>Admin sign in</h1><p>Use your FjordPulse operator credentials. Public transport browsing never requires an account.</p><Show when={props.error !== null}><div class="login-error" role="alert">{props.error}</div></Show><label>Username<input ref={username} autocomplete="username" required /></label><label>Password<input ref={password} type="password" autocomplete="current-password" required /></label><Button type="submit" tone="primary" disabled={props.busy}>Sign in</Button><a href="/">← Return to public map</a></form></main>;
};

export const AdminApp: Component<{ readonly page: AdminPage; readonly fixture: boolean; readonly fixtureData?: AdminFixtureData; readonly http: HttpClient }> = (props) => {
  const [refresh, setRefresh] = createSignal(0);
  const [loginError, setLoginError] = createSignal<string | null>(null);
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
    if (props.page !== "status" || value === undefined || Array.isArray(value) || !("dependencies" in value)) return { state: "connected", label: "Admin API connected" };
    if (value.dependencies.some((dependency) => dependency.state === "offline")) return { state: "offline", label: "Dependency unavailable" };
    if (value.dependencies.some((dependency) => dependency.state === "degraded" || dependency.state === "delayed" || dependency.state === "reconnecting")) return { state: "delayed", label: "System degraded" };
    if (value.dependencies.some((dependency) => dependency.state === "idle")) return { state: "idle", label: "System operational" };
    return { state: "connected", label: "All dependencies healthy" };
  };
  const login = async (username: string, password: string) => {
    setLoginBusy(true); setLoginError(null);
    try { const session = await props.http.loginAdmin(username, password); setOperator(session.username); await refetch(); }
    catch (error) { setLoginError(error instanceof Error ? error.message : "Sign in failed."); }
    finally { setLoginBusy(false); }
  };
  const logout = async () => { if (!props.fixture) await props.http.logoutAdmin(); window.location.assign("/"); };
  return <Switch>
    <Match when={unauthorized()}><AdminLogin error={loginError()} busy={loginBusy()} onSubmit={(username, password) => void login(username, password)} /></Match>
    <Match when={data.loading}><main class="admin-loading"><span class="spinner" /><p>Loading protected system data…</p></main></Match>
    <Match when={data.error !== undefined}><main class="admin-loading"><Icon name="alert" size={30} /><h1>Admin data unavailable</h1><p>{data.error instanceof Error ? data.error.message : "Unknown error"}</p><Button onClick={() => void refetch()}>Retry</Button></main></Match>
    <Match when={data() !== undefined}><AdminLayout page={props.page} username={operator()} connectionState={connection().state} connectionLabel={connection().label} onLogout={() => void logout()}><Switch><Match when={props.page === "status"}><AdminStatusPage status={data() as AdminStatus} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "watches"}><WatchPage rows={data() as readonly WatchRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "entur-log"}><EnturLogPage data={data() as AdminEnturLog} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "realtime"}><RealtimePage data={data() as AdminRealtime} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "events"}><EventsPage rows={data() as readonly RealtimeEventRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "migrations"}><MigrationsPage rows={data() as readonly MigrationRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match></Switch></AdminLayout></Match>
  </Switch>;
};
