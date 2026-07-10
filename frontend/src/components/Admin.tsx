import { createMemo, createResource, createSignal, For, Match, Show, Switch, type Component, type JSX } from "solid-js";
import { adminStatusFixture, enturLogFixture, watchRowsFixture } from "../fixtures/scenarios";
import { ApiClientError, type HttpClient } from "../services/httpClient";
import type { AdminRealtime, AdminStatus, EnturLogRow, MigrationRow, RealtimeEventRow, WatchRow } from "../types/domain";
import { Button, FjordPulseLogo, StatusChip } from "./DesignSystem";
import { Icon, type IconName } from "./Icon";

export type AdminPage = "status" | "watches" | "entur-log" | "realtime" | "events" | "migrations";

const adminNav: readonly { readonly page: AdminPage; readonly label: string; readonly icon: IconName }[] = [
  { page: "status", label: "System status", icon: "activity" },
  { page: "watches", label: "Active watches", icon: "focus" },
  { page: "entur-log", label: "Entur request log", icon: "server" },
  { page: "realtime", label: "Realtime diagnostics", icon: "wifi" },
  { page: "events", label: "Persisted events", icon: "activity" },
  { page: "migrations", label: "Migrations", icon: "database" },
];

const AdminLayout: Component<{ readonly page: AdminPage; readonly children: JSX.Element; readonly onLogout?: () => void }> = (props) => (
  <div class="admin-shell">
    <aside class="admin-sidebar">
      <FjordPulseLogo />
      <span class="admin-label">ADMIN CONSOLE</span>
      <nav aria-label="Admin navigation">
        <a href="/admin/status"><Icon name="map" size={19} />Overview</a>
        <For each={adminNav}>{(item) => <a href={`/admin/${item.page}`} class={props.page === item.page ? "is-active" : ""} aria-current={props.page === item.page ? "page" : undefined}><Icon name={item.icon} size={19} />{item.label}</a>}</For>
      </nav>
      <div class="admin-sidebar-bottom"><StatusChip state="connected" label="All systems operational" /><button type="button" onClick={props.onLogout}><span class="avatar">MK</span><span><strong>Admin</strong><small>Operator</small></span><Icon name="chevron" size={15} /></button></div>
    </aside>
    <main class="admin-main">{props.children}</main>
  </div>
);

const AdminHeader: Component<{ readonly title: string; readonly subtitle: string; readonly onRefresh: () => void }> = (props) => (
  <header class="admin-header"><div><span class="eyebrow">FjordPulse operations</span><h1>{props.title}</h1><p>{props.subtitle}</p></div><div><time>10:42:30 Oslo</time><button class="icon-button" type="button" onClick={props.onRefresh} aria-label="Refresh admin data"><Icon name="refresh" size={20} /></button></div></header>
);

const AdminStatusPage: Component<{ readonly status: AdminStatus; readonly onRefresh: () => void }> = (props) => (
  <>
    <AdminHeader title="System status" subtitle="Operational overview of the HTTP, realtime, database, and source services." onRefresh={props.onRefresh} />
    <section class="service-grid" aria-label="Service dependencies">
      <For each={props.status.dependencies}>{(dependency) => (
        <article class="service-card"><span class="service-icon"><Icon name={dependency.name === "SurrealDB" ? "database" : dependency.name === "Realtime server" ? "wifi" : dependency.name === "Backend" ? "server" : "refresh"} size={25} /></span><div><span>{dependency.name}</span><strong class={`state-${dependency.state}`}>{dependency.state === "connected" ? "Connected" : dependency.state.toUpperCase()}</strong><small>{dependency.detail}</small></div><Show when={dependency.latencyMs !== undefined}><span class="latency">{dependency.latencyMs} ms</span></Show></article>
      )}</For>
    </section>
    <section class="metric-grid" aria-label="System metrics"><For each={props.status.metrics}>{(metric) => <article class={`metric-card tone-${metric.tone}`}><span>{metric.label}</span><strong>{metric.value}</strong><small>{metric.detail}</small></article>}</For></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">LIVE QUERY PIPELINE</span><h2>Recent events</h2></div><a href="/admin/events">View all events <Icon name="chevron" size={15} /></a></header><div class="table-wrap"><table><thead><tr><th>Event</th><th>Scope</th><th>Time</th><th>Status</th></tr></thead><tbody><For each={props.status.events}>{(event) => <tr><td><span class={`event-dot tone-${event.status === "ok" ? "positive" : event.status === "warning" ? "warning" : "danger"}`}><Icon name="activity" size={14} /></span><strong>{event.type}</strong></td><td><code>{event.scope}</code></td><td>{event.createdAt.slice(11, 19)}</td><td><StatusChip state={event.status === "ok" ? "ok" : event.status === "warning" ? "delayed" : "offline"} label={event.status.toUpperCase()} /></td></tr>}</For></tbody></table></div></section>
  </>
);

const WatchPage: Component<{ readonly rows: readonly WatchRow[]; readonly onRefresh: () => void }> = (props) => {
  const count = (state: WatchRow["state"]) => props.rows.filter((row) => row.state === state).length;
  return <>
    <AdminHeader title="Active watches" subtitle="Demand-driven station, vehicle, and Focus refresh scopes." onRefresh={props.onRefresh} />
    <section class="metric-grid watch-metrics"><article class="metric-card tone-info"><span>Total watches</span><strong>{props.rows.length}</strong><small>Across active clients</small></article><article class="metric-card tone-positive"><span>Focus watches</span><strong>{props.rows.filter((row) => row.type === "focus").length}</strong><small>Critical priority</small></article><article class="metric-card tone-warning"><span>Expiring soon</span><strong>{count("expiring")}</strong><small>No active clients</small></article><article class="metric-card tone-danger"><span>Failed watches</span><strong>{count("failed")}</strong><small>Needs attention</small></article></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">SCHEDULER</span><h2>Shared refresh scopes</h2></div><span class="table-count">{props.rows.length} rows</span></header><div class="table-wrap"><table><thead><tr><th>Type</th><th>Scope</th><th>Clients</th><th>Priority</th><th>Last refresh</th><th>Next refresh</th><th>State</th></tr></thead><tbody><For each={props.rows}>{(row) => <tr class={row.state === "stale" ? "is-warning" : ""}><td><span class="type-cell"><Icon name={row.type === "station" ? "map" : row.type === "focus" ? "focus" : "bus"} size={17} />{row.type}</span></td><td><code>{row.scope}</code></td><td>{row.clients}</td><td><span class={`priority priority-${row.priority}`}>{row.priority}</span></td><td>{row.lastRefreshAt.slice(11, 19)}</td><td>{row.nextRefreshAt.slice(11, 19)}</td><td><span class={`watch-state state-${row.state}`}>{row.state}</span></td></tr>}</For></tbody></table></div></section>
  </>;
};

const EnturLogPage: Component<{ readonly rows: readonly EnturLogRow[]; readonly onRefresh: () => void }> = (props) => {
  const [api, setApi] = createSignal("all");
  const [status, setStatus] = createSignal("all");
  const [scope, setScope] = createSignal("");
  const filtered = createMemo(() => props.rows.filter((row) => (api() === "all" || row.api === api()) && (status() === "all" || row.status === status()) && row.scope.toLowerCase().includes(scope().toLowerCase())));
  return <>
    <AdminHeader title="Entur request log" subtitle="Backend-only source requests, cache behavior, latency, budgets, and backoff." onRefresh={props.onRefresh} />
    <section class="metric-grid entur-metrics"><article class="metric-card tone-info"><span>Requests / min</span><strong>26</strong><small>Budget 30</small></article><article class="metric-card tone-positive"><span>Cache hit rate</span><strong>76%</strong><small>Last 15 minutes</small></article><article class="metric-card tone-info"><span>p95 latency</span><strong>213 ms</strong><small>All source APIs</small></article><article class="metric-card tone-warning"><span>Backoff state</span><strong>1 scope</strong><small>Retry in 61s</small></article></section>
    <section class="filter-bar" aria-label="Entur log filters"><label>API<select value={api()} onChange={(event) => setApi(event.currentTarget.value)}><option value="all">All APIs</option><option>Journey Planner</option><option>Vehicle Positions</option><option>Geocoder</option><option>Stop Place Register</option></select></label><label>Status<select value={status()} onChange={(event) => setStatus(event.currentTarget.value)}><option value="all">All statuses</option><option value="ok">OK</option><option value="backoff">Backoff</option><option value="error">Error</option></select></label><label class="scope-filter">Scope<input value={scope()} onInput={(event) => setScope(event.currentTarget.value)} placeholder="Filter scope…" /></label><label>Time range<select><option>Last hour</option><option>Last 24 hours</option></select></label></section>
    <section class="admin-table-card"><header><div><span class="eyebrow">RESPONSIBLE API USE</span><h2>Request history</h2></div><span class="table-count">{filtered().length} rows</span></header><div class="table-wrap"><table><thead><tr><th>Time</th><th>API</th><th>Scope</th><th>Status</th><th>Latency</th><th>Count</th><th>Cache</th><th>Retry</th></tr></thead><tbody><For each={filtered()}>{(row) => <tr class={row.status === "backoff" ? "is-warning" : ""}><td>{row.createdAt.slice(11, 19)}</td><td><strong>{row.api}</strong></td><td><code>{row.scope}</code></td><td><span class={`log-status state-${row.status}`}>{row.status.replace("_", " ")}</span></td><td>{row.latencyMs} ms</td><td>{row.requestCount}</td><td><span class={`cache cache-${row.cache}`}>{row.cache}</span></td><td>{row.retryAt?.slice(11, 19) ?? "—"}</td></tr>}</For></tbody></table></div></section>
  </>;
};

const RealtimePage: Component<{ readonly data: AdminRealtime; readonly onRefresh: () => void }> = (props) => <>
  <AdminHeader title="Realtime diagnostics" subtitle="Connection, room, live-query bridge, reconnect, and broadcast telemetry." onRefresh={props.onRefresh} />
  <section class="service-grid realtime-services"><For each={[props.data.server, props.data.liveQueryBridge]}>{(service) => <article class="service-card"><span class="service-icon"><Icon name="wifi" size={25} /></span><div><span>{service.name}</span><strong class={`state-${service.state}`}>{service.state}</strong><small>{service.detail}</small></div></article>}</For></section>
  <section class="metric-grid watch-metrics"><article class="metric-card tone-info"><span>Active clients</span><strong>{props.data.activeClients}</strong><small>Browser WebSockets</small></article><article class="metric-card tone-info"><span>Messages / min</span><strong>{Math.round(props.data.messagesPerMinute)}</strong><small>Validated frames</small></article><article class="metric-card tone-warning"><span>Reconnects</span><strong>{props.data.reconnectCount}</strong><small>Since process start</small></article><article class="metric-card tone-danger"><span>Failures</span><strong>{props.data.failureCount}</strong><small>Supervised failures</small></article></section>
  <section class="admin-table-card"><header><div><span class="eyebrow">ROOM REGISTRY</span><h2>Active rooms</h2></div><span class="table-count">Last broadcast {props.data.lastBroadcastAt?.slice(11, 19) ?? "—"}</span></header><div class="table-wrap"><table><thead><tr><th>Scope</th><th>Clients</th><th>Isolation</th></tr></thead><tbody><For each={props.data.rooms}>{(room) => <tr><td><code>{room.scope}</code></td><td>{room.clientCount}</td><td><StatusChip state="ok" label="Scoped" /></td></tr>}</For></tbody></table></div></section>
</>;

const EventsPage: Component<{ readonly rows: readonly RealtimeEventRow[]; readonly onRefresh: () => void }> = (props) => <>
  <AdminHeader title="Persisted realtime events" subtitle="Database-originated notifications from the canonical SurrealDB event path." onRefresh={props.onRefresh} />
  <section class="admin-table-card"><header><div><span class="eyebrow">REALTIME_EVENT</span><h2>Recent durable notifications</h2></div><span class="table-count">{props.rows.length} rows</span></header><div class="table-wrap"><table><thead><tr><th>Event ID</th><th>Type</th><th>Scope</th><th>Entity</th><th>Version</th><th>Created</th></tr></thead><tbody><For each={props.rows}>{(row) => <tr><td><code>{row.eventId}</code></td><td><strong>{row.type}</strong></td><td><code>{row.scope}</code></td><td><code>{row.entityId}</code></td><td>{row.version.slice(11, 19)}</td><td>{row.createdAt.slice(11, 19)}</td></tr>}</For></tbody></table></div></section>
</>;

const MigrationsPage: Component<{ readonly rows: readonly MigrationRow[]; readonly onRefresh: () => void }> = (props) => <>
  <AdminHeader title="Database migrations" subtitle="Applied, pending, and failed SurrealDB schema migrations." onRefresh={props.onRefresh} />
  <section class="metric-grid watch-metrics"><article class="metric-card tone-positive"><span>Applied</span><strong>{props.rows.filter((row) => row.state === "applied").length}</strong><small>Verified checksums</small></article><article class="metric-card tone-warning"><span>Pending</span><strong>{props.rows.filter((row) => row.state === "pending").length}</strong><small>Awaiting application</small></article><article class="metric-card tone-danger"><span>Failed</span><strong>{props.rows.filter((row) => row.state === "failed").length}</strong><small>Requires attention</small></article></section>
  <section class="admin-table-card"><header><div><span class="eyebrow">SCHEMA_MIGRATION</span><h2>Migration ledger</h2></div></header><div class="table-wrap"><table><thead><tr><th>Name</th><th>Checksum</th><th>State</th><th>Applied at</th></tr></thead><tbody><For each={props.rows}>{(row) => <tr><td><strong>{row.name}</strong></td><td><code>{row.checksum}</code></td><td><span class={`watch-state state-${row.state}`}>{row.state}</span></td><td>{row.appliedAt?.replace("T", " ").slice(0, 19) ?? "—"}</td></tr>}</For></tbody></table></div></section>
</>;

const AdminLogin: Component<{ readonly error: string | null; readonly busy: boolean; readonly onSubmit: (username: string, password: string) => void }> = (props) => {
  let username: HTMLInputElement | undefined;
  let password: HTMLInputElement | undefined;
  return <main class="admin-login"><form onSubmit={(event) => { event.preventDefault(); props.onSubmit(username?.value ?? "", password?.value ?? ""); }}><FjordPulseLogo /><span class="eyebrow">PROTECTED OPERATOR SURFACE</span><h1>Admin sign in</h1><p>Use your FjordPulse operator credentials. Public transport browsing never requires an account.</p><Show when={props.error !== null}><div class="login-error" role="alert">{props.error}</div></Show><label>Username<input ref={username} autocomplete="username" required /></label><label>Password<input ref={password} type="password" autocomplete="current-password" required /></label><Button type="submit" tone="primary" disabled={props.busy}>Sign in</Button><a href="/">← Return to public map</a></form></main>;
};

export const AdminApp: Component<{ readonly page: AdminPage; readonly fixture: boolean; readonly http: HttpClient }> = (props) => {
  const [refresh, setRefresh] = createSignal(0);
  const [loginError, setLoginError] = createSignal<string | null>(null);
  const [loginBusy, setLoginBusy] = createSignal(false);
  const load = async (): Promise<AdminStatus | AdminRealtime | readonly WatchRow[] | readonly EnturLogRow[] | readonly RealtimeEventRow[] | readonly MigrationRow[]> => {
    refresh();
    if (props.fixture) {
      if (props.page === "watches") return watchRowsFixture;
      if (props.page === "entur-log") return enturLogFixture;
      return adminStatusFixture;
    }
    if (props.page === "watches") return props.http.getAdminWatches();
    if (props.page === "entur-log") return props.http.getAdminEnturLog();
    if (props.page === "realtime") return props.http.getAdminRealtime();
    if (props.page === "events") return props.http.getAdminEvents();
    if (props.page === "migrations") return props.http.getAdminMigrations();
    return props.http.getAdminStatus();
  };
  const [data, { refetch }] = createResource(() => [props.page, refresh()] as const, load);
  const unauthorized = () => data.error instanceof ApiClientError && data.error.status === 401;
  const login = async (username: string, password: string) => {
    setLoginBusy(true); setLoginError(null);
    try { await props.http.loginAdmin(username, password); await refetch(); }
    catch (error) { setLoginError(error instanceof Error ? error.message : "Sign in failed."); }
    finally { setLoginBusy(false); }
  };
  const logout = async () => { if (!props.fixture) await props.http.logoutAdmin(); window.location.assign("/"); };
  return <Switch>
    <Match when={unauthorized()}><AdminLogin error={loginError()} busy={loginBusy()} onSubmit={(username, password) => void login(username, password)} /></Match>
    <Match when={data.loading}><main class="admin-loading"><span class="spinner" /><p>Loading protected system data…</p></main></Match>
    <Match when={data.error !== undefined}><main class="admin-loading"><Icon name="alert" size={30} /><h1>Admin data unavailable</h1><p>{data.error instanceof Error ? data.error.message : "Unknown error"}</p><Button onClick={() => void refetch()}>Retry</Button></main></Match>
    <Match when={data() !== undefined}><AdminLayout page={props.page} onLogout={() => void logout()}><Switch><Match when={props.page === "status"}><AdminStatusPage status={data() as AdminStatus} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "watches"}><WatchPage rows={data() as readonly WatchRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "entur-log"}><EnturLogPage rows={data() as readonly EnturLogRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "realtime"}><RealtimePage data={data() as AdminRealtime} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "events"}><EventsPage rows={data() as readonly RealtimeEventRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match><Match when={props.page === "migrations"}><MigrationsPage rows={data() as readonly MigrationRow[]} onRefresh={() => setRefresh((value) => value + 1)} /></Match></Switch></AdminLayout></Match>
  </Switch>;
};
