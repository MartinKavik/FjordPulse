import { cleanup, fireEvent, render, screen, within } from "@solidjs/testing-library";
import type { Component, JSX } from "solid-js";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AdminApp, AdminInfrastructurePage, AdminStatusPage, EnturAllowanceCard, EnturLogPage, EventsPage, RealtimePage, explainRealtimeEvent } from "../src/components/Admin";
import { adminDatabaseMigrationsFixture, adminDatabaseSchemaFixture, adminRealtimeFixture } from "../src/fixtures/scenarios";
import { ApiClientError, type HttpClient } from "../src/services/httpClient";
import { I18nProvider } from "../src/state/i18n";
import type { AdminEnturLog, AdminRealtime, AdminStatus, HealthDependency, RealtimeEventRow } from "../src/types/domain";

const EnglishWrapper: Component<{ readonly children: JSX.Element }> = (props) => (
  <I18nProvider initialLanguage="en">{props.children}</I18nProvider>
);

function renderEnglish(view: () => JSX.Element) {
  return render(view, { wrapper: EnglishWrapper });
}

const lostEvent: RealtimeEventRow = {
  eventId: "019f52b3-15fb-71e1-a34b-50754e9600e7",
  type: "vehicle_lost",
  scope: "vehicle:RL_x0020_72190",
  entityId: "RL_x0020_72190",
  version: "2026-07-11T19:41:36.105Z",
  source: "current_vehicle",
  payload: {
    observation: {
      bearing: null,
      latitude: 58.7351115580479,
      longitude: 5.64892688436163,
      observedAt: "2026-07-11T19:35:30.002010Z",
    },
    vehicle: {
      id: "RL_x0020_72190",
      state: "lost",
      lastSeenAt: "2026-07-11T19:35:30.002010Z",
      refreshedAt: "2026-07-11T19:41:36.105591Z",
      version: "2026-07-11T19:41:36.105Z",
    },
  },
  createdAt: "2026-07-11T19:41:36.123726710Z",
};

const status: AdminStatus = {
  build: { version: "dev", environment: "local", dataMode: "real" },
  database: { engine: "surrealdb", endpointOrigin: "ws://127.0.0.1:8000", namespace: "fjordpulse", name: "fjordpulse_real", warning: null },
  resources: {
    checkedAt: "2026-07-11T19:41:36.123Z",
    cpu: { usagePercent: 25, load1: 1, load5: 0.8, load15: 0.6, logicalCores: 8 },
    memory: { totalBytes: 16_000_000_000, availableBytes: 10_000_000_000, usedBytes: 6_000_000_000, usedPercent: 37.5, scope: "host" },
    disk: { path: "/", totalBytes: 500_000_000_000, freeBytes: 300_000_000_000, usedBytes: 200_000_000_000, usedPercent: 40 },
  },
  dataCounts: {
    stations: 57_964,
    stationSnapshots: 1,
    currentVehicles: 1,
    vehicleObservations: 1,
    watches: 1,
    realtimeEvents: 2,
    enturRequestLogs: 1,
  },
  stationImport: {
    count: 57_964,
    lastImportedAt: "2026-07-10T14:51:12.168Z",
    sourceVersion: "2026-07-10",
  },
  enturBudgets: [
    { service: "global", limit: 60, remaining: 52, windowSeconds: 60, backoffUntil: null },
    { service: "stop_place_register", limit: 60, remaining: 60, windowSeconds: 60, backoffUntil: null },
    { service: "geocoder", limit: 20, remaining: 18, windowSeconds: 60, backoffUntil: null },
    { service: "journey_planner", limit: 30, remaining: 26, windowSeconds: 60, backoffUntil: null },
    { service: "vehicle_positions", limit: 30, remaining: 28, windowSeconds: 60, backoffUntil: null },
  ],
  dependencies: [],
  metrics: [],
  events: [{
    id: lostEvent.eventId,
    type: lostEvent.type,
    scope: lostEvent.scope,
    entityId: lostEvent.entityId,
    version: lostEvent.version,
    createdAt: lostEvent.createdAt,
    status: "warning",
    source: lostEvent.source,
    payload: lostEvent.payload,
  }],
};

afterEach(() => cleanup());

describe("admin realtime-event evidence", () => {
  it("renders Norwegian admin labels by default", () => {
    render(() => <AdminStatusPage status={status} onRefresh={() => undefined} />);

    expect(screen.getByRole("heading", { name: "Systemstatus" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "Systemet fungerer" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "Tjenestehelse" })).toBeVisible();
    expect(screen.getByRole("link", { name: "Åpne infrastruktur" })).toHaveAttribute("href", "/admin/infrastructure");
    expect(screen.queryByRole("heading", { name: "Serverressurser" })).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Intern grense for Entur-kall" })).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "System status" })).not.toBeInTheDocument();
  });

  it("groups service health into compact rows and keeps realtime states beside their labels", async () => {
    const dependencies: AdminStatus["dependencies"] = [
      { name: "Backend", state: "ok", detail: "CakePHP HTTP/control plane is serving.", latencyMs: 18 },
      { name: "Realtime server", state: "ok", detail: "Realtime service and live-query bridge are healthy.", latencyMs: 31 },
      { name: "SurrealDB", state: "ok", detail: "Authoritative state database is reachable; the real station catalog contains 57964 records.", latencyMs: 12 },
      { name: "Entur API", state: "idle", detail: "No Entur request recorded in five minutes. Availability will be checked on the next demand-driven request." },
      { name: "Live-query bridge", state: "ok", detail: "SurrealDB live-query bridge is subscribed and receiving database events.", latencyMs: 9 },
      { name: "Map tiles", state: "ok", detail: "MapTiler browser configuration is present; provider availability is verified by the browser at load time, not by this endpoint." },
    ];
    render(() => <I18nProvider initialLanguage="nb"><AdminStatusPage status={{ ...status, dependencies }} onRefresh={() => undefined} /></I18nProvider>);

    const card = screen.getByText("Sanntidslevering").closest("article");
    expect(card).not.toBeNull();
    expect(card).toHaveClass("status-health-row");
    expect(screen.getByRole("heading", { name: "Backend", level: 3 }).closest("article")).toHaveClass("status-health-row");
    const checks = within(card!).getByRole("list", { name: "Kontroller for sanntidslevering" });
    expect(within(checks).getByText("Server")).toBeVisible();
    expect(within(checks).getByText("Databasehendelser")).toBeVisible();
    expect(within(checks).getByText("31 ms")).toBeVisible();
    expect(within(checks).getByText("9 ms")).toBeVisible();
    expect(within(card!).getByRole("link", { name: "Åpne sanntidsdiagnostikk" })).toHaveAttribute("href", "/admin/realtime");
    expect(screen.queryByText("Live Query-bro", { exact: true })).not.toBeInTheDocument();
    expect(screen.queryByText("SurrealDB live-query bridge is subscribed and receiving database events.")).not.toBeInTheDocument();

    await fireEvent.click(screen.getByRole("button", { name: "Bytt språk til engelsk" }));
    expect(screen.getByText("Realtime delivery")).toBeVisible();
    expect(screen.getByText("Database events")).toBeVisible();
    expect(screen.getByRole("link", { name: "Open realtime diagnostics" })).toHaveAttribute("href", "/admin/realtime");
  });

  it("labels realtime activity by its measured rolling window and delivery semantics", () => {
    const realtime: AdminRealtime = {
      server: { name: "Realtime server", state: "ok", detail: "Realtime service and live-query bridge are healthy." },
      liveQueryBridge: { name: "Live-query bridge", state: "ok", detail: "SurrealDB live-query bridge is subscribed and receiving database events." },
      activeClients: 1,
      rooms: [{ scope: "vehicle:bus-1", clientCount: 1 }],
      messagesPerMinute: 9,
      reconnectCount: 2,
      failureCount: 1,
      lastBroadcastAt: "2026-07-15T12:00:00Z",
    };

    renderEnglish(() => <RealtimePage data={realtime} onRefresh={() => undefined} />);

    expect(screen.getByText("WebSocket messages").closest("article")).toHaveTextContent("9");
    expect(screen.getByText("Received and delivered in the last 60 seconds")).toBeVisible();
    expect(screen.queryByText("Validated frames")).not.toBeInTheDocument();
    expect(screen.getByText("Database-bridge recoveries")).toBeVisible();
    expect(screen.getByText("Realtime pipeline failures").closest("article")).toHaveTextContent("1");
    expect(screen.queryByText("Delivery failures")).not.toBeInTheDocument();
    expect(screen.getByText(/Last delivered broadcast/)).toBeVisible();
    expect(screen.getByText("Room-scoped")).toBeVisible();
  });

  it("keeps a degraded database-event signal explicit in the grouped overview", () => {
    renderEnglish(() => <AdminStatusPage status={{
      ...status,
      dependencies: [
        { name: "Realtime server", state: "ok", detail: "Realtime service is healthy.", latencyMs: 20 },
        { name: "Live-query bridge", state: "degraded", detail: "Database event subscription is stale.", latencyMs: 900 },
      ],
    }} onRefresh={() => undefined} />);

    const card = screen.getByText("Realtime delivery").closest("article");
    expect(card).not.toBeNull();
    expect(within(card!).getAllByText("DEGRADED")).toHaveLength(2);
    expect(within(card!).getByText("Database event subscription is stale.")).toBeVisible();
    expect(within(card!).queryByText("Realtime service is healthy.")).not.toBeInTheDocument();
    expect(within(card!).getByText("900 ms")).toBeVisible();
  });

  it("names hidden infrastructure failures in the overall health banner with correct plurality", () => {
    const mapFailure: HealthDependency = { name: "Map tiles", state: "degraded", detail: "Map provider configuration is missing." };
    renderEnglish(() => <AdminStatusPage status={{ ...status, dependencies: [mapFailure] }} onRefresh={() => undefined} />);

    expect(screen.getByRole("heading", { name: "System needs attention" })).toBeVisible();
    expect(screen.getByText("Map tiles reports degraded operation or recovery.")).toBeVisible();
    expect(screen.queryByText("Map provider configuration is missing.")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Open infrastructure" })).toHaveAttribute("href", "/admin/infrastructure");

    cleanup();
    renderEnglish(() => <AdminStatusPage status={{
      ...status,
      dependencies: [
        { name: "Backend", state: "offline", detail: "Backend unavailable." },
        { name: "Map tiles", state: "offline", detail: "Map configuration unavailable." },
      ],
    }} onRefresh={() => undefined} />);

    expect(screen.getByRole("heading", { name: "2 services are unavailable" })).toBeVisible();
    expect(screen.getByText("Backend, Map tiles need recovery. Open their diagnostics before changing configuration.")).toBeVisible();
  });

  it("explains the internal Entur allowance and exposes every configured service limit", async () => {
    renderEnglish(() => <EnturAllowanceCard status={status} />);

    expect(screen.getByRole("heading", { name: "Internal Entur request limit" })).toBeVisible();
    expect(screen.getByText("52 of 60 available")).toBeVisible();
    expect(screen.getByText("Shared across Entur services · rolling 60-second window")).toBeVisible();
    expect(screen.getByText(/not a quota reported by Entur/i)).toBeVisible();
    expect(screen.queryByText("Current rate budget")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Jump to request history" })).toHaveAttribute("href", "#entur-request-history");
    expect(screen.getByRole("link", { name: /Entur Journey Planner rate-limit documentation/ })).toHaveAttribute("href", "https://developer.entur.no/docs/open-services/journey-planner/rate-limiting");

    await fireEvent.click(screen.getByText("Show configured limits for all Entur APIs"));
    const table = screen.getByRole("table", { name: "Internal FjordPulse-to-Entur request limits" });
    expect(within(table).getByText("All Entur services (shared)")).toBeVisible();
    expect(within(table).getByText("Stop Place Register")).toBeVisible();
    expect(within(table).getByText("Geocoder")).toBeVisible();
    expect(within(table).getByText("Journey Planner")).toBeVisible();
    expect(within(table).getByText("Vehicle Positions")).toBeVisible();
    expect(within(table).getByText("ENTUR_GLOBAL_REQUESTS_PER_MINUTE")).toBeVisible();
    expect(within(table).getByText("ENTUR_JOURNEY_REQUESTS_PER_MINUTE")).toBeVisible();
    expect(within(table).getAllByRole("row")).toHaveLength(6);
  });

  it("makes clear that configured Entur limits are inactive in demo mode", () => {
    renderEnglish(() => <EnturAllowanceCard status={{ ...status, build: { ...status.build, dataMode: "fake" } }} />);

    expect(screen.getByText("Not used")).toBeVisible();
    expect(screen.getByText("Demo adapters do not send requests to Entur.")).toBeVisible();
    expect(screen.getByText("The limits below are configured but inactive while FjordPulse uses demo data.")).toBeVisible();
    expect(screen.queryByText("52 of 60 available")).not.toBeInTheDocument();
  });

  it("surfaces an active service backoff without calling it an Entur quota", () => {
    renderEnglish(() => <EnturAllowanceCard status={{
      ...status,
      enturBudgets: status.enturBudgets.map((budget) => budget.service === "journey_planner" ? { ...budget, backoffUntil: "2099-07-11T20:00:00Z" } : budget),
    }} />);

    expect(screen.getByText(/At least one Entur service is paused until/)).toBeVisible();
    expect(screen.getByText(/not a quota reported by Entur/i)).toBeVisible();
  });

  it("filters rate-limited Entur requests independently from backoff requests", async () => {
    const data: AdminEnturLog = {
      metrics: { requestsPerMinute: 1, cacheHitRate: 0, p95LatencyMs: 120, inBackoff: true },
      entries: [
        { id: "rate-limited", createdAt: "2026-07-15T12:00:00Z", api: "Journey Planner", scope: "station:rate-limited", status: "rate_limited", latencyMs: 120, requestCount: 1, cache: "miss", retryAt: "2026-07-15T12:01:00Z" },
        { id: "backoff", createdAt: "2026-07-15T11:59:00Z", api: "Vehicle Positions", scope: "vehicle:backoff", status: "backoff", latencyMs: null, requestCount: 0, cache: "stale", retryAt: "2026-07-15T12:02:00Z" },
      ],
    };

    renderEnglish(() => <EnturLogPage data={data} status={null} onRefresh={() => undefined} />);

    const statusFilter = screen.getByLabelText("Status");
    expect(within(statusFilter).getByRole("option", { name: "Rate limited" })).toHaveValue("rate_limited");
    await fireEvent.change(statusFilter, { target: { value: "rate_limited" } });

    const history = screen.getByRole("heading", { name: "Request history" }).closest("section");
    expect(history).not.toBeNull();
    expect(within(history!).getAllByRole("row")).toHaveLength(2);
    expect(within(history!).getByRole("row", { name: /station:rate-limited/ })).toBeVisible();
    expect(within(history!).queryByRole("row", { name: /vehicle:backoff/ })).not.toBeInTheDocument();
  });

  it("reactively translates canonical operational details and preserves unknown diagnostics", async () => {
    render(() => <I18nProvider initialLanguage="nb"><AdminStatusPage status={{
      ...status,
      dependencies: [
        { name: "Live-query bridge", state: "degraded", detail: "Live-query bridge status is missing, degraded, or stale." },
        { name: "Entur API", state: "degraded", detail: "Latest Entur outcome: rate_limited." },
        { name: "Backend", state: "degraded", detail: "Provider diagnostic 42" },
      ],
    }} onRefresh={() => undefined} /></I18nProvider>);

    expect(screen.getByText("Status for Live Query-broen mangler, har redusert funksjon eller er utdatert.")).toBeVisible();
    expect(screen.getByText("Siste Entur-resultat: begrenset av Entur.")).toBeVisible();
    expect(screen.getByText("Provider diagnostic 42")).toBeVisible();

    await fireEvent.click(screen.getByRole("button", { name: "Bytt språk til engelsk" }));
    expect(screen.getByText("Live-query bridge status is missing, degraded, or stale.")).toBeVisible();
    expect(screen.getByText("Latest Entur outcome: rate_limited.")).toBeVisible();
    expect(screen.getByText("Provider diagnostic 42")).toBeVisible();
  });

  it("keeps language switching available while protected data is loading", () => {
    const http = {
      getAdminSession: vi.fn(() => new Promise(() => undefined)),
    } as unknown as HttpClient;

    render(() => <I18nProvider initialLanguage="nb"><AdminApp page="status" fixture={false} http={http} /></I18nProvider>);

    expect(screen.getByText("Laster beskyttede systemdata …")).toBeVisible();
    expect(screen.getByRole("group", { name: "Språk" })).toBeVisible();
  });

  it("offers separate public demo credentials beside the public-map return link", async () => {
    const http = {
      getAdminSession: vi.fn()
        .mockRejectedValueOnce(new ApiClientError("Admin authentication is required.", 401, "admin_unauthorized"))
        .mockResolvedValue({ authenticated: true, username: "demo", access: "demo", expiresAt: "2026-07-13T20:00:00Z" }),
      getAdminDemoCredentials: vi.fn().mockResolvedValue({ enabled: true, username: "demo", password: "fjordpulse-demo" }),
      loginAdmin: vi.fn().mockResolvedValue({ authenticated: true, username: "demo", access: "demo", expiresAt: "2026-07-13T20:00:00Z" }),
      getAdminStatus: vi.fn().mockResolvedValue(status),
    } as unknown as HttpClient;

    renderEnglish(() => <AdminApp page="status" fixture={false} http={http} />);

    const fill = await screen.findByRole("button", { name: "Fill demo credentials" });
    expect(screen.getByRole("link", { name: "Return to public map" })).toHaveAttribute("href", "/");
    expect(screen.getByText("A public read-only demo is available. Fill the demo credentials below, or use your operator credentials.")).toBeVisible();
    await fireEvent.click(fill);
    expect(screen.getByLabelText("Username")).toHaveValue("demo");
    expect(screen.getByLabelText("Password")).toHaveValue("fjordpulse-demo");
    expect(screen.getByLabelText("Password")).toHaveFocus();
    expect(screen.getByRole("status")).toHaveTextContent("Demo credentials filled. Select Sign in to continue.");

    await fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(http.loginAdmin).toHaveBeenCalledWith("demo", "fjordpulse-demo");
    expect(await screen.findByRole("heading", { name: "System status" })).toBeVisible();
    expect(screen.getByText("Public demo · read-only")).toBeVisible();
  });

  it("keeps demo credentials hidden when public demo access is disabled", async () => {
    const http = {
      getAdminSession: vi.fn().mockRejectedValue(new ApiClientError("Admin authentication is required.", 401, "admin_unauthorized")),
      getAdminDemoCredentials: vi.fn().mockResolvedValue({ enabled: false }),
    } as unknown as HttpClient;

    render(() => <I18nProvider initialLanguage="nb"><AdminApp page="status" fixture={false} http={http} /></I18nProvider>);

    expect(await screen.findByRole("link", { name: "Tilbake til transportkartet" })).toHaveAttribute("href", "/");
    expect(screen.getByText("Bruk operatørpåloggingen din for FjordPulse. Du trenger aldri en konto for å utforske kollektivtransport.")).toBeVisible();
    expect(screen.queryByRole("button", { name: "Fyll inn demoopplysninger" })).not.toBeInTheDocument();
  });

  it("exposes one canonical System status destination and a distinct Infrastructure page", async () => {
    const http = {} as HttpClient;

    renderEnglish(() => <AdminApp
      page="status"
      fixture
      http={http}
      fixtureData={{
        status,
        realtime: adminRealtimeFixture,
        watches: [],
        enturLog: {
          metrics: { requestsPerMinute: 0, cacheHitRate: 0, p95LatencyMs: null, inBackoff: false },
          entries: [],
        },
        databaseSchema: adminDatabaseSchemaFixture,
        databaseMigrations: adminDatabaseMigrationsFixture,
      }}
    />);

    const navigation = await screen.findByRole("navigation", { name: "Admin navigation" });
    const statusLinks = within(navigation).getAllByRole("link").filter((link) => link.getAttribute("href") === "/admin/status");

    expect(statusLinks).toHaveLength(1);
    expect(statusLinks[0]).toHaveAccessibleName("System status");
    expect(statusLinks[0]).toHaveAttribute("aria-current", "page");
    expect(within(navigation).getByRole("link", { name: "Infrastructure" })).toHaveAttribute("href", "/admin/infrastructure");
    expect(within(navigation).getByRole("link", { name: "Database" })).toHaveAttribute("href", "/admin/database/schema");
    expect(within(navigation).queryByRole("link", { name: "Migrations" })).not.toBeInTheDocument();
    expect(within(navigation).getByRole("link", { name: "Infrastructure" })).not.toHaveAttribute("aria-current");
    expect(within(navigation).queryByRole("link", { name: "Overview" })).not.toBeInTheDocument();
  });

  it("shows a safe localized network error and lets the operator switch language", async () => {
    const http = {
      getAdminSession: vi.fn().mockRejectedValue(new TypeError("Failed to fetch")),
    } as unknown as HttpClient;

    render(() => <I18nProvider initialLanguage="nb"><AdminApp page="status" fixture={false} http={http} /></I18nProvider>);

    expect(await screen.findByRole("heading", { name: "Administrasjonsdata er ikke tilgjengelig" })).toBeVisible();
    expect(screen.getByText("Kunne ikke koble til FjordPulse-serveren. Kontroller tilkoblingen og prøv igjen.")).toBeVisible();
    expect(screen.queryByText("Failed to fetch")).not.toBeInTheDocument();

    await fireEvent.click(screen.getByRole("button", { name: "Bytt språk til engelsk" }));
    expect(screen.getByRole("heading", { name: "Admin data unavailable" })).toBeVisible();
    expect(screen.getByText("Could not connect to the FjordPulse server. Check your connection and try again.")).toBeVisible();
  });

  it("keeps Entur request history available when the broader status request fails", async () => {
    const http = {
      getAdminSession: vi.fn().mockResolvedValue({ authenticated: true, username: "admin", access: "operator", expiresAt: "2026-07-13T20:00:00Z" }),
      getAdminEnturLog: vi.fn().mockResolvedValue({
        metrics: { requestsPerMinute: 1, cacheHitRate: 1, p95LatencyMs: 42, inBackoff: false },
        entries: [],
      }),
      getAdminStatus: vi.fn().mockRejectedValue(new TypeError("Failed to fetch")),
    } as unknown as HttpClient;

    renderEnglish(() => <AdminApp page="entur-log" fixture={false} http={http} />);

    expect(await screen.findByRole("heading", { name: "Entur request log" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "Internal Entur limits are temporarily unavailable" })).toBeVisible();
    expect(screen.getByText("Request history remains available below.")).toBeVisible();
    expect(screen.getByRole("heading", { name: "Request history" })).toBeVisible();
    expect(screen.queryByRole("heading", { name: "Admin data unavailable" })).not.toBeInTheDocument();
  });

  it("omits host-resource placeholders when the platform cannot measure them", () => {
    renderEnglish(() => <AdminInfrastructurePage status={{
      ...status,
      resources: {
        ...status.resources,
        cpu: { usagePercent: null, load1: null, load5: null, load15: null, logicalCores: null },
        memory: { totalBytes: null, availableBytes: null, usedBytes: null, usedPercent: null, scope: "host" },
        disk: { path: "/unavailable", totalBytes: null, freeBytes: null, usedBytes: null, usedPercent: null },
      },
    }} onRefresh={() => undefined} />);

    expect(screen.queryByRole("heading", { name: "Host resources" })).not.toBeInTheDocument();
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
  });

  it("shows the credential-free database target and a deployment warning", () => {
    renderEnglish(() => <AdminInfrastructurePage status={{
      ...status,
      build: { ...status.build, environment: "staging" },
      database: {
        ...status.database,
        warning: "Loopback database target configured for staging; localhost resolves inside the running service.",
      },
    }} onRefresh={() => undefined} />);

    expect(screen.getByText("ws://127.0.0.1:8000")).toBeVisible();
    expect(screen.getByText("fjordpulse / fjordpulse_real", { exact: false })).toBeVisible();
    expect(screen.getByText(/loopback database target configured for staging/i)).toBeVisible();
    expect(screen.getByRole("heading", { name: "Host resources" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "CPU", level: 3 })).toBeVisible();
    expect(screen.getByRole("heading", { name: "Memory", level: 3 })).toBeVisible();
    expect(screen.getByRole("heading", { name: "Disk space", level: 3 })).toBeVisible();
    expect(screen.getByText("9.3 GiB free")).toBeVisible();
    expect(screen.getByText("279 GiB free")).toBeVisible();
    expect(screen.getByText("37.5% used · 5.6 GiB of 14.9 GiB · Host RAM")).toBeVisible();
    expect(screen.getByText("40.0% used · 186 GiB of 466 GiB · /")).toBeVisible();
    expect(screen.getAllByRole("progressbar")).toHaveLength(3);
    expect(document.body).not.toHaveTextContent("/rpc");
  });

  it("explains a lost vehicle from persisted observation timestamps", () => {
    const explanation = explainRealtimeEvent(lostEvent);

    expect(explanation.label).toBe("LOST");
    expect(explanation.summary).toMatch(/no recent position/i);
    expect(explanation.summary).toMatch(/6 min 6 sec/i);
  });

  it("delegates persisted-event evidence to its dedicated page instead of duplicating a table", () => {
    const events = Array.from({ length: 7 }, (_, index) => ({
      ...status.events[0]!,
      id: `event-${index}`,
      scope: `vehicle:test-${index}`,
    }));
    renderEnglish(() => <AdminStatusPage status={{ ...status, events }} onRefresh={() => undefined} />);

    expect(screen.queryByRole("heading", { name: "Latest persisted events" })).not.toBeInTheDocument();
    expect(screen.queryByRole("table")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "View persisted events" })).toHaveAttribute("href", "/admin/events");
  });

  it("exposes source and raw persisted payload on the full Events page", async () => {
    renderEnglish(() => <EventsPage rows={[lostEvent]} onRefresh={() => undefined} />);

    const table = screen.getByRole("table");
    expect(within(table).getByText("LOST")).toBeInTheDocument();
    expect(within(table).getAllByText("current_vehicle").length).toBeGreaterThan(0);

    await fireEvent.click(within(table).getByRole("button", {
      name: "Details for vehicle_lost vehicle:RL_x0020_72190",
    }));
    const evidence = within(table).getByLabelText("Raw event payload");
    expect(evidence).toBeVisible();
    expect(evidence).toHaveTextContent("RL_x0020_72190");
    expect(evidence).toHaveTextContent("lastSeenAt");
    expect(evidence).toHaveTextContent("refreshedAt");
  });
});
