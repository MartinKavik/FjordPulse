import { expect, test, type APIResponse, type Page, type WebSocket } from "@playwright/test";
import { installMapTilerMock } from "./support/maptiler-mock";

interface Frame {
  readonly type?: string;
  readonly scope?: string;
  readonly eventId?: string;
  readonly version?: string;
  readonly payload?: Record<string, unknown>;
}

const stationId = "NSR:StopPlace:36025";
const vehicleId = "SKY:Vehicle:1001";

function updateStatus(page: Page) {
  return page.getByRole("status", { name: "Update status" });
}

async function successfulData(response: APIResponse): Promise<Record<string, unknown>> {
  expect(response.ok(), await response.text()).toBe(true);
  expect(response.headers()["content-type"]?.toLowerCase()).toMatch(/^application\/json(?:\s*;|$)/);
  const envelope = await response.json() as { ok?: boolean; data?: Record<string, unknown> };
  expect(envelope.ok).toBe(true);
  expect(envelope.data).toBeDefined();
  return envelope.data!;
}

async function selectScenario(page: Page, scenario: string): Promise<void> {
  const data = await successfulData(await page.request.post("/api/dev/scenario", { data: { scenario } }));
  expect(data.scenario).toBe(scenario);
}

async function refreshStation(page: Page): Promise<void> {
  await successfulData(await page.request.get(`/api/stations/${encodeURIComponent(stationId)}?refresh=true`));
}

async function refreshVehicle(page: Page): Promise<void> {
  await successfulData(await page.request.get(`/api/vehicles/${encodeURIComponent(vehicleId)}?refresh=true`));
}

async function expectCanonicalDatabaseTabs(page: Page, active: "schema" | "migrations"): Promise<void> {
  const adminNavigation = page.getByRole("navigation", { name: "Admin navigation" });
  await expect(adminNavigation.getByRole("link", { name: "Database", exact: true })).toHaveAttribute("href", "/admin/database/schema");

  const databaseNavigation = page.getByRole("navigation", { name: "Database sections" });
  const schema = databaseNavigation.getByRole("link", { name: /Current schema/ });
  const migrations = databaseNavigation.getByRole("link", { name: /Migrations/ });
  await expect(schema).toHaveAttribute("href", "/admin/database/schema");
  await expect(migrations).toHaveAttribute("href", "/admin/database/migrations");
  await expect(active === "schema" ? schema : migrations).toHaveAttribute("aria-current", "page");
  await expect(active === "schema" ? migrations : schema).not.toHaveAttribute("aria-current", "page");
}

async function waitForFrame(
  frames: readonly Frame[],
  from: number,
  type: string,
  predicate: (frame: Frame) => boolean = () => true,
): Promise<Frame> {
  let match: Frame | undefined;
  await expect.poll(() => {
    match = frames.slice(from).find((frame) => frame.type === type && predicate(frame));
    return match !== undefined;
  }, { timeout: 15_000, message: `waiting for realtime frame ${type}` }).toBe(true);
  return match!;
}

test("real fake stack carries HTTP writes through SurrealDB LIVE to visible WebSocket updates", async ({ page, context }) => {
  await installMapTilerMock(page);
  const frames: Frame[] = [];
  const liveSockets: WebSocket[] = [];
  const forbiddenBrowserRequests: string[] = [];
  const apiResponses: Array<{ readonly status: number; readonly path: string }> = [];
  const pageErrors: string[] = [];

  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("request", (request) => {
    const url = new URL(request.url());
    if (url.hostname.endsWith("entur.io") || url.port === "19000") forbiddenBrowserRequests.push(request.url());
  });
  page.on("response", (response) => {
    const url = new URL(response.url());
    if (url.pathname.startsWith("/api/")) apiResponses.push({ status: response.status(), path: url.pathname });
  });
  page.on("websocket", (socket) => {
    if (new URL(socket.url()).pathname !== "/live") return;
    liveSockets.push(socket);
    socket.on("framereceived", ({ payload }) => {
      if (typeof payload !== "string") return;
      try { frames.push(JSON.parse(payload) as Frame); } catch { /* contract validator handles malformed frames */ }
    });
  });

  await page.goto("/?controls=1");
  await expect(page.locator(".app-shell")).toHaveAttribute("data-scenario", "live");
  await expect(page.getByRole("heading", { name: "Norway in motion." })).toBeVisible();
  await expect(page.getByLabel("Scenario")).toHaveValue("normal");
  await expect.poll(() => apiResponses.some(({ status, path }) => status === 200 && path === "/api/stations")).toBe(true);

  await page.keyboard.press("/");
  const search = page.getByRole("searchbox", { name: "Search for station, place, line, or vehicle" });
  await search.fill("Førde");
  await expect(page.getByRole("option", { name: /Førde rutebilstasjon/ })).toBeVisible();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("heading", { name: "Førde rutebilstasjon" })).toBeVisible();
  await expect(page.getByText("4 upcoming")).toBeVisible();
  const stationDetails = page.getByRole("complementary", { name: /station details/i });
  await expect(stationDetails.getByText(/^Data updated (?:now|\d+[smhd] ago)$/i)).toBeVisible();
  await expect(updateStatus(page)).toHaveCount(0);
  await expect(page.getByLabel("System telemetry")).toHaveCount(0);
  const fakeSource = page.getByRole("note", { name: "Transport data source" });
  await expect(fakeSource).toContainText("Demo data");
  await expect(fakeSource.locator("strong")).toHaveText("Demo data");
  await expect.poll(() => liveSockets.length).toBe(1);
  await waitForFrame(frames, 0, "watch_station_ack", (frame) => frame.scope === `station:${stationId}`);
  // A newly selected station can race its first collector write: the initial
  // state is either read as a subscription snapshot or delivered by LIVE once
  // the write commits. The explicit state changes below are the canonical-path
  // assertions; each must cross SurrealDB LIVE and update the visible UI.

  const dailyResponsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === `/api/stations/${encodeURIComponent(stationId)}/departures`
      && url.searchParams.has("date")
      && url.searchParams.get("limit") === "50";
  });
  await stationDetails.getByRole("button", { name: "View today's timetable" }).click();
  const dailyData = await successfulData(await dailyResponsePromise);
  expect(dailyData.mode).toBe("day");
  expect(dailyData.timeZone).toBe("Europe/Oslo");
  await expect(stationDetails.getByRole("heading", { name: "Today's timetable" })).toBeVisible();
  await expect(stationDetails.getByText("4 departures today")).toBeVisible();
  await stationDetails.getByRole("button", { name: "Back to next departures" }).click();
  await expect(stationDetails.getByRole("heading", { name: "Next departures" })).toBeVisible();

  let from = frames.length;
  await selectScenario(page, "station_empty");
  await refreshStation(page);
  await waitForFrame(frames, from, "station_snapshot_changed", (frame) => frame.payload?.state === "empty");
  await expect(page.getByText("No more departures today.")).toBeVisible();
  const emptyNearby = await successfulData(await page.request.get(`/api/stations/${encodeURIComponent(stationId)}/nearby-vehicles`));
  expect(emptyNearby.searchRadiusMeters).toBe(5_000);
  expect(emptyNearby.vehicles).toEqual([]);
  await expect(page.getByText("No nearby vehicles reported.")).toHaveCount(0);
  await expect(page.getByText("Vehicles connected to this station")).toHaveCount(0);
  await page.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }).click();
  await expect(page.getByText("No station-serving vehicle reported now.")).toBeVisible();
  await expect(page.getByText("Vehicles connected to this station")).toBeVisible();
  await expect(page.getByText("No live vehicle positions were found within 5 km of this station. The search is complete; check again shortly.")).toBeVisible();
  await page.getByRole("tab", { name: /^Departures(?:,?\s+\d+)?$/ }).click();

  from = frames.length;
  await selectScenario(page, "entur_backoff");
  await refreshStation(page);
  await waitForFrame(frames, from, "station_snapshot_changed", (frame) => frame.payload?.state === "rate_limited");
  await page.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }).click();
  await expect(page.getByText("Nearby vehicle refresh paused.")).toBeVisible();
  await expect(page.getByText("FjordPulse will retry automatically.", { exact: false }).first()).toBeVisible();
  await expect(page.getByText("The search is complete", { exact: false })).toHaveCount(0);
  await page.getByRole("tab", { name: /^Departures(?:,?\s+\d+)?$/ }).click();

  from = frames.length;
  await selectScenario(page, "station_stale");
  await refreshStation(page);
  await waitForFrame(frames, from, "station_snapshot_changed", (frame) => frame.payload?.state === "stale");
  await expect(page.getByText("Live data delayed")).toBeVisible();

  from = frames.length;
  await selectScenario(page, "station_error");
  await refreshStation(page);
  await waitForFrame(frames, from, "station_snapshot_changed", (frame) => frame.payload?.state === "error");
  await expect(page.getByText("Deterministic station source failure.")).toBeVisible();
  await expect(page.getByText("Departures unavailable")).toBeVisible();

  from = frames.length;
  await selectScenario(page, "normal");
  await refreshStation(page);
  await waitForFrame(frames, from, "station_snapshot_changed", (frame) => frame.payload?.state === "fresh");
  await expect(page.getByText("4 upcoming")).toBeVisible();

  await page.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }).click();
  await page.getByRole("button", { name: /Open Bus on Line 100\./ }).click();
  await expect(page.getByRole("heading", { name: "Line 100" })).toBeVisible();
  await waitForFrame(frames, 0, "watch_vehicle_ack", (frame) => frame.scope === `vehicle:${vehicleId}`);
  await page.getByRole("button", { name: "Focus this vehicle" }).click();
  await expect(page.getByText("Following Line 100")).toBeVisible();
  await waitForFrame(frames, 0, "focus_started", (frame) => frame.payload?.vehicleId === vehicleId);
  from = frames.length;
  await selectScenario(page, "vehicle_stale");
  await refreshVehicle(page);
  await waitForFrame(frames, from, "vehicle_stale", (frame) => frame.scope === `vehicle:${vehicleId}` && frame.eventId !== undefined);
  from = frames.length;
  await selectScenario(page, "vehicle_live");
  await refreshVehicle(page);
  const moved = await waitForFrame(frames, from, "vehicle_moved", (frame) => frame.scope === `vehicle:${vehicleId}` && frame.eventId !== undefined);
  expect(typeof (moved.payload?.vehicle as Record<string, unknown> | undefined)?.latitude).toBe("number");
  const routeOverview = page.getByRole("button", { name: "Show full route overview" });
  await expect(routeOverview).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText("Florø terminal")).toBeVisible();
  await routeOverview.click();
  await expect(page.getByRole("button", { name: "Resume follow" }).first()).toBeVisible();
  await page.getByRole("button", { name: "Resume follow" }).first().click();
  await expect(page.getByText("Following Line 100")).toBeVisible();

  from = frames.length;
  await selectScenario(page, "vehicle_lost");
  await refreshVehicle(page);
  await waitForFrame(frames, from, "vehicle_lost", (frame) => frame.scope === `vehicle:${vehicleId}`);
  await expect(page.getByText("Live position temporarily unavailable")).toBeVisible();
  await expect(page.getByText(/FjordPulse keeps checking and resumes following automatically/)).toBeVisible();
  await expect(page.getByText("Last known journey")).toBeVisible();
  await page.keyboard.press("/");
  await search.fill(vehicleId);
  await expect(page.getByRole("option", { name: new RegExp(vehicleId.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")) })).toBeVisible();
  await page.keyboard.press("Escape");

  from = frames.length;
  await selectScenario(page, "fallback");
  const fallbackFrame = await waitForFrame(frames, from, "realtime_degraded", (frame) => frame.payload?.bridgeStatus === "degraded");
  expect(fallbackFrame.payload?.fallbackPolling).toBe(true);
  await expect(updateStatus(page)).toHaveText("Live connection interrupted · Updating periodically", { timeout: 20_000 });
  await expect(updateStatus(page)).toHaveCount(1);
  const fallbackHealth = await successfulData(await page.request.get("/api/health"));
  expect(fallbackHealth.mode).toBe("fallback_polling");

  from = frames.length;
  await selectScenario(page, "realtime_reconnect");
  await waitForFrame(frames, from, "realtime_degraded", (frame) => frame.payload?.bridgeStatus === "reconnecting");
  // Periodic refresh is already active, so that rider-facing capability remains
  // more useful than exposing the bridge's lower-level reconnect transition.
  await expect(updateStatus(page)).toHaveText("Live connection interrupted · Updating periodically", { timeout: 20_000 });
  await expect(updateStatus(page)).toHaveCount(1);

  from = frames.length;
  await selectScenario(page, "normal");
  await waitForFrame(frames, from, "resync_required", (frame) => frame.payload?.reason === "bridge_recovered");
  await expect(updateStatus(page)).toHaveCount(0, { timeout: 20_000 });

  const admin = await context.newPage();
  const adminPageErrors: string[] = [];
  admin.on("pageerror", (error) => adminPageErrors.push(error.message));
  await admin.goto("/admin/watches");
  await expect(admin.getByRole("heading", { name: "Admin sign in" })).toBeVisible();
  await expect(admin.getByRole("link", { name: "Return to public map" })).toHaveAttribute("href", "/");
  await admin.getByRole("button", { name: "Fill demo credentials" }).click();
  await expect(admin.getByLabel("Username")).toHaveValue("demo");
  await expect(admin.getByLabel("Password")).toHaveValue("fjordpulse-demo");
  await admin.getByRole("button", { name: "Sign in" }).click();
  await expect(admin.getByRole("heading", { name: "Watch records" })).toBeVisible();
  await expect(admin.getByText("Public demo · read-only")).toBeVisible();
  await expect(admin.getByRole("cell", { name: `vehicle:${vehicleId}` })).toBeVisible();
  await expect(admin.getByText("critical", { exact: true })).toBeVisible();

  await admin.goto("/admin/status");
  await expect(admin.getByRole("heading", { name: "System status" })).toBeVisible();
  await expect(admin.getByRole("heading", { name: "System operational" })).toBeVisible();
  await expect(admin.getByRole("heading", { name: "Host resources" })).toHaveCount(0);
  const serviceOverview = admin.getByRole("region", { name: "Service health" });
  const realtimeDelivery = serviceOverview.getByRole("heading", { name: "Realtime delivery" }).locator("xpath=ancestor::article");
  await expect(realtimeDelivery.getByRole("list", { name: "Realtime delivery checks" })).toContainText("Server");
  await expect(realtimeDelivery.getByRole("list", { name: "Realtime delivery checks" })).toContainText("Database events");
  await expect(realtimeDelivery.getByRole("link", { name: "Open realtime diagnostics" })).toHaveAttribute("href", "/admin/realtime");
  await expect(serviceOverview.getByText("Live-query bridge", { exact: true })).toHaveCount(0);
  await expect(admin.getByText(/connections, not unique visitors/i)).toBeVisible();
  await expect(admin.getByText("Focus sessions")).toBeVisible();
  await expect(admin.getByText("One high-priority watch per focused browser session")).toBeVisible();
  await expect(admin.getByText(/HTTP p95 latency/i)).toHaveCount(0);
  await expect(admin.getByRole("heading", { name: "Internal Entur request limit" })).toHaveCount(0);
  await expect(admin.getByRole("heading", { name: "Latest persisted events" })).toHaveCount(0);
  const statusTextSizes = {
    serviceDetail: await admin.locator(".realtime-delivery-checks li").first().evaluate((element) => Number.parseFloat(getComputedStyle(element).fontSize)),
    demandDetail: await admin.locator(".admin-demand-panel small").first().evaluate((element) => Number.parseFloat(getComputedStyle(element).fontSize)),
  };
  expect(statusTextSizes.serviceDetail).toBeGreaterThanOrEqual(14);
  expect(statusTextSizes.demandDetail).toBeGreaterThanOrEqual(14);

  await admin.goto("/admin/infrastructure");
  await expect(admin.getByRole("heading", { name: "Infrastructure" })).toBeVisible();
  await expect(admin.getByRole("heading", { name: "Deployment identity" })).toBeVisible();
  await expect(admin.getByRole("heading", { name: "Host resources" })).toBeVisible();
  await expect(admin.getByRole("progressbar", { name: "Memory used" })).toBeVisible();
  await expect(admin.getByRole("progressbar", { name: /Disk used on/i })).toBeVisible();
  const resourceMeasuredAt = admin.locator(".admin-resource-section time");
  const firstResourceMeasurement = await resourceMeasuredAt.getAttribute("datetime");
  await admin.getByRole("button", { name: "Refresh admin data" }).click();
  await expect.poll(() => resourceMeasuredAt.getAttribute("datetime")).not.toBe(firstResourceMeasurement);
  await expect(admin.getByText("SurrealDB", { exact: true }).last()).toBeVisible();
  await expect(admin.locator(".database-endpoint")).toHaveText(/^ws:\/\/127\.0\.0\.1:\d+$/);
  await expect(admin.getByRole("heading", { name: "Stored data" })).toBeVisible();

  await admin.goto("/admin/entur-log");
  await expect(admin.getByRole("heading", { name: "Internal Entur request limit" })).toBeVisible();
  await expect(admin.getByText("Not used")).toBeVisible();
  await expect(admin.getByText("Demo adapters do not send requests to Entur.")).toBeVisible();
  await expect(admin.getByText("The limits below are configured but inactive while FjordPulse uses demo data.")).toBeVisible();
  await expect(admin.getByRole("link", { name: "Jump to request history" })).toHaveAttribute("href", "#entur-request-history");
  const metricDetailSize = await admin.evaluate(() => Number.parseFloat(getComputedStyle(document.querySelector(".metric-card small")!).fontSize));
  expect(metricDetailSize).toBeGreaterThanOrEqual(14);

  await admin.goto("/admin/realtime");
  await expect(admin.getByRole("heading", { name: "Realtime diagnostics" })).toBeVisible();
  await expect(admin.getByRole("cell", { name: `vehicle:${vehicleId}` })).toBeVisible();
  await expect.poll(async () => {
    const realtime = await successfulData(await admin.request.get("/api/admin/realtime"));
    return Number(realtime.messagesPerMinute ?? 0);
  }, { timeout: 20_000, message: "persisted Admin realtime telemetry should record the active watched vehicle" })
    .toBeGreaterThan(0);
  await expect.poll(async () => {
    const status = await successfulData(await admin.request.get("/api/admin/status"));
    const metrics = status.metrics as Record<string, unknown> | undefined;
    return Number(metrics?.messagesPerMinute ?? 0);
  }, { timeout: 20_000 }).toBeGreaterThan(0);
  await admin.goto("/admin/events");
  await expect(admin.getByRole("heading", { name: "Persisted realtime events" })).toBeVisible();
  await expect(admin.getByText("station_snapshot_changed").first()).toBeVisible();
  await expect(admin.getByText("vehicle_lost").first()).toBeVisible();
  const eventStateSize = await admin.evaluate(() => Number.parseFloat(getComputedStyle(document.querySelector(".admin-table-card .status-chip")!).fontSize));
  expect(eventStateSize).toBeGreaterThanOrEqual(12);
  const persistedEventDetails = admin.getByRole("button", { name: `Details for vehicle_lost vehicle:${vehicleId}` }).first();
  await persistedEventDetails.click();
  await expect(persistedEventDetails.locator("xpath=..").getByLabel("Raw event payload")).toContainText("vehicle");

  await page.close();
  await expect.poll(async () => {
    const closedPageStatus = await successfulData(await admin.request.get("/api/admin/status"));
    return closedPageStatus.metrics;
  }, { timeout: 20_000 }).toMatchObject({
    activeClients: 0,
    stationWatches: 0,
    vehicleWatches: 0,
    focusWatches: 0,
  });

  expect(apiResponses.some(({ status, path }) => status === 201 && path === "/api/realtime-token")).toBe(true);
  expect(forbiddenBrowserRequests).toEqual([]);
  expect(pageErrors).toEqual([]);
  expect(adminPageErrors).toEqual([]);
});

test("authenticated Admin Database routes expose real read-only diagnostics and preserve navigation", async ({ page, context }) => {
  const pageErrors: string[] = [];
  let mainDocumentRequests = 0;
  const databaseRequests: Array<{ readonly method: string; readonly path: string }> = [];
  const databaseResponses: Array<{ readonly status: number; readonly path: string }> = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("request", (request) => {
    const url = new URL(request.url());
    if (request.resourceType() === "document" && request.frame() === page.mainFrame()) mainDocumentRequests += 1;
    if (url.pathname.startsWith("/api/admin/database/")) {
      databaseRequests.push({ method: request.method(), path: url.pathname });
    }
  });
  page.on("response", (response) => {
    const url = new URL(response.url());
    if (url.pathname.startsWith("/api/admin/database/")) {
      databaseResponses.push({ status: response.status(), path: url.pathname });
    }
  });

  await page.setViewportSize({ width: 320, height: 700 });
  await page.goto("/admin/database/schema");
  await expect(page.getByRole("heading", { name: "Admin sign in" })).toBeVisible();
  const fillDemo = page.getByRole("button", { name: "Fill demo credentials" });
  const returnToMap = page.getByRole("link", { name: "Return to public map" });
  await expect(fillDemo).toBeVisible();
  await expect(returnToMap).toBeVisible();
  for (const action of [fillDemo, returnToMap]) {
    const box = await action.boundingBox();
    expect(box?.height).toBeGreaterThanOrEqual(32);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByLabel("Username").fill("admin");
  await page.getByLabel("Password").fill("local-development-only");
  await page.getByRole("button", { name: "Sign in" }).click();

  await expect(page).toHaveURL(/\/admin\/database\/schema$/);
  await expect(page.getByRole("heading", { name: "Database", level: 1 })).toBeVisible();
  await expectCanonicalDatabaseTabs(page, "schema");
  await expect(page.getByLabel("Read-only database view")).toContainText("cannot run queries, edit the schema, or apply migrations");
  await expect(page.getByRole("heading", { name: "Current schema", level: 2 })).toBeVisible();
  await expect(page.getByText("schema_migration", { exact: true })).toBeVisible();
  await expect(page.getByText("schema_migration_attempt", { exact: true })).toBeVisible();
  const currentVehicleSummary = page.locator(".schema-table-disclosure > summary").filter({ hasText: /^current_vehicle/ });
  const currentVehicleSchema = currentVehicleSummary.locator("..");
  await currentVehicleSummary.click();
  await expect(currentVehicleSchema).toHaveAttribute("open", "");
  await expect(currentVehicleSchema.getByText("publish_current_vehicle", { exact: true })).toBeVisible();
  await expect(page.getByText("FjordPulse and Surrealist have different roles")).toBeVisible();

  const navigationDocumentCount = mainDocumentRequests;
  await page.locator(".admin-shell").evaluate((element) => { element.setAttribute("data-test-navigation-sentinel", "same-shell"); });
  await page.evaluate(() => { (window as Window & { __fjordPulseAdminSentinel?: string }).__fjordPulseAdminSentinel = "same-document"; });

  await page.getByRole("navigation", { name: "Database sections" }).getByRole("link", { name: /Migrations/ }).click();
  await expect(page).toHaveURL(/\/admin\/database\/migrations$/);
  expect(mainDocumentRequests).toBe(navigationDocumentCount);
  await expect(page.locator('.admin-shell[data-test-navigation-sentinel="same-shell"]')).toHaveCount(1);
  expect(await page.evaluate(() => (window as Window & { __fjordPulseAdminSentinel?: string }).__fjordPulseAdminSentinel)).toBe("same-document");
  await expectCanonicalDatabaseTabs(page, "migrations");
  await expect(page.getByRole("heading", { name: "Database matches this release" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Migration history" })).toBeVisible();
  const migration = page.locator(".migration-disclosure").filter({ hasText: "010_migration_attempt_history.surql" });
  await expect(migration).toContainText("Applied");
  await migration.locator("summary").click();
  await expect(migration).toHaveAttribute("open", "");
  await expect(migration.getByLabel("Read-only source for 010_migration_attempt_history.surql"))
    .toContainText("DEFINE TABLE OVERWRITE schema_migration_attempt");
  await expect(page.getByRole("button", { name: /apply|execute|edit|run migration/i })).toHaveCount(0);

  await page.reload();
  await expect(page).toHaveURL(/\/admin\/database\/migrations$/);
  await expectCanonicalDatabaseTabs(page, "migrations");
  await expect(page.getByText("010_migration_attempt_history.surql", { exact: true })).toBeVisible();

  const sharedDatabase = await context.newPage();
  sharedDatabase.on("pageerror", (error) => pageErrors.push(error.message));
  await sharedDatabase.goto(new URL("/admin/database/migrations", page.url()).href);
  await expect(sharedDatabase).toHaveURL(/\/admin\/database\/migrations$/);
  await expect(sharedDatabase.getByRole("heading", { name: "Database matches this release" })).toBeVisible();
  await expect(sharedDatabase.getByText("010_migration_attempt_history.surql", { exact: true })).toBeVisible();
  await expectCanonicalDatabaseTabs(sharedDatabase, "migrations");
  await sharedDatabase.close();

  await page.getByRole("navigation", { name: "Database sections" }).getByRole("link", { name: /Current schema/ }).click();
  await expect(page).toHaveURL(/\/admin\/database\/schema$/);
  await expectCanonicalDatabaseTabs(page, "schema");
  const historyDocumentCount = mainDocumentRequests;
  await page.locator(".admin-shell").evaluate((element) => { element.setAttribute("data-test-history-sentinel", "same-shell"); });
  await page.goBack();
  await expect(page).toHaveURL(/\/admin\/database\/migrations$/);
  await expect(page.getByRole("heading", { name: "Migration history" })).toBeVisible();
  await page.goForward();
  await expect(page).toHaveURL(/\/admin\/database\/schema$/);
  await expect(page.getByRole("heading", { name: "Current schema", level: 2 })).toBeVisible();
  expect(mainDocumentRequests).toBe(historyDocumentCount);
  await expect(page.locator('.admin-shell[data-test-history-sentinel="same-shell"]')).toHaveCount(1);

  await page.goto("/admin/migrations");
  await expect(page).toHaveURL(/\/admin\/migrations$/);
  await expect(page.getByRole("heading", { name: "Migration history" })).toBeVisible();
  await expectCanonicalDatabaseTabs(page, "migrations");
  await expect(page.getByText("010_migration_attempt_history.surql", { exact: true })).toBeVisible();

  await expect.poll(() => databaseResponses).toEqual(expect.arrayContaining([
    { status: 200, path: "/api/admin/database/schema" },
    { status: 200, path: "/api/admin/database/migrations" },
  ]));
  expect(databaseRequests.length).toBeGreaterThanOrEqual(2);
  expect(databaseRequests.every(({ method }) => method === "GET")).toBe(true);
  expect(pageErrors).toEqual([]);
});

test("Admin loading and route errors stay dark, padded, and inside the persistent mobile shell", async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 720 });
  await page.emulateMedia({ reducedMotion: "reduce" });

  let releaseSession!: () => void;
  let holdSession = true;
  const sessionGate = new Promise<void>((resolve) => { releaseSession = resolve; });
  await page.route("**/api/admin/session", async (route) => {
    if (holdSession && route.request().method() === "GET") await sessionGate;
    await route.continue();
  });

  await page.goto("/admin/status");
  const initialLoading = page.locator(".admin-loading");
  await expect(initialLoading.getByRole("status")).toContainText("Loading protected system data");
  const initialGeometry = await initialLoading.evaluate((element) => {
    const host = element as HTMLElement;
    const card = host.querySelector<HTMLElement>(".admin-state-card")!;
    const hostStyle = getComputedStyle(host);
    const cardStyle = getComputedStyle(card);
    const cardRect = card.getBoundingClientRect();
    return {
      hostBackgroundImage: hostStyle.backgroundImage,
      cardBackground: cardStyle.backgroundColor,
      paddingTop: Number.parseFloat(hostStyle.paddingTop),
      cardPaddingTop: Number.parseFloat(cardStyle.paddingTop),
      cardPaddingLeft: Number.parseFloat(cardStyle.paddingLeft),
      cardCenter: cardRect.top + cardRect.height / 2,
      viewportCenter: window.innerHeight / 2,
      horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  });
  expect(initialGeometry.hostBackgroundImage).toContain("radial-gradient");
  expect(initialGeometry.cardBackground).toBe("rgba(6, 22, 35, 0.92)");
  expect(initialGeometry.paddingTop).toBeGreaterThanOrEqual(70);
  expect(initialGeometry.cardPaddingTop).toBeGreaterThanOrEqual(20);
  expect(initialGeometry.cardPaddingLeft).toBeGreaterThanOrEqual(18);
  expect(Math.abs(initialGeometry.cardCenter - initialGeometry.viewportCenter)).toBeLessThan(90);
  expect(initialGeometry.horizontalOverflow).toBeLessThanOrEqual(0);
  expect(await page.locator("html").evaluate((element) => getComputedStyle(element).backgroundColor)).toBe("rgb(3, 13, 23)");

  await page.setViewportSize({ width: 568, height: 320 });
  const shortLandscapeGeometry = await initialLoading.evaluate((element) => {
    const language = element.querySelector<HTMLElement>(".language-switcher")!;
    const card = element.querySelector<HTMLElement>(".admin-state-card")!;
    const languageRect = language.getBoundingClientRect();
    const cardRect = card.getBoundingClientRect();
    return {
      languageBottom: languageRect.bottom,
      cardTop: cardRect.top,
      horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  });
  expect(shortLandscapeGeometry.cardTop).toBeGreaterThanOrEqual(shortLandscapeGeometry.languageBottom);
  expect(shortLandscapeGeometry.horizontalOverflow).toBeLessThanOrEqual(0);
  await page.setViewportSize({ width: 320, height: 720 });

  holdSession = false;
  releaseSession();
  await expect(page.getByRole("heading", { name: "Admin sign in" })).toBeVisible();
  await page.getByLabel("Username").fill("admin");
  await page.getByLabel("Password").fill("local-development-only");
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page.getByRole("heading", { name: "System status" })).toBeVisible();
  await page.locator(".admin-shell").evaluate((element) => element.setAttribute("data-test-mobile-shell", "persistent"));

  let abortWatches!: () => void;
  let markWatchRequested!: () => void;
  const watchRequested = new Promise<void>((resolve) => { markWatchRequested = resolve; });
  const watchGate = new Promise<void>((resolve) => { abortWatches = resolve; });
  await page.route("**/api/admin/watches", async (route) => {
    markWatchRequested();
    await watchGate;
    await route.abort("failed");
  });

  await page.getByRole("button", { name: "Menu", exact: true }).click();
  await page.getByRole("navigation", { name: "Admin navigation" }).getByRole("link", { name: "Watches" }).click();
  await watchRequested;
  await expect(page).toHaveURL(/\/admin\/watches$/);
  await expect(page.getByRole("progressbar", { name: "Loading Admin page" })).toBeVisible();
  await expect(page.locator('.admin-shell[data-test-mobile-shell="persistent"]')).toHaveCount(1);
  const reducedMotionDurations = await page.evaluate(() => {
    const toMilliseconds = (value: string): number => value.split(",").reduce((maximum, part) => {
      const token = part.trim();
      const milliseconds = token.endsWith("ms") ? Number.parseFloat(token) : Number.parseFloat(token) * 1_000;
      return Math.max(maximum, Number.isFinite(milliseconds) ? milliseconds : 0);
    }, 0);
    const content = document.querySelector<HTMLElement>(".admin-page-content")!;
    const progress = document.querySelector<HTMLElement>(".admin-page-progress")!;
    return {
      contentInert: content.inert,
      contentAnimationMs: toMilliseconds(getComputedStyle(content).animationDuration),
      contentTransitionMs: toMilliseconds(getComputedStyle(content).transitionDuration),
      progressAnimationMs: toMilliseconds(getComputedStyle(progress, "::after").animationDuration),
    };
  });
  expect(reducedMotionDurations.contentInert).toBe(true);
  expect(reducedMotionDurations.contentAnimationMs).toBeLessThanOrEqual(0.1);
  expect(reducedMotionDurations.contentTransitionMs).toBeLessThanOrEqual(0.1);
  expect(reducedMotionDurations.progressAnimationMs).toBeLessThanOrEqual(0.1);

  abortWatches();
  const pageError = page.locator(".admin-page-state.is-error");
  await expect(pageError.getByRole("heading", { name: "Admin page unavailable" })).toBeVisible();
  const errorGeometry = await pageError.evaluate((element) => {
    const state = element as HTMLElement;
    const main = state.closest<HTMLElement>(".admin-main")!;
    const card = state.querySelector<HTMLElement>(".admin-state-card")!;
    const stateStyle = getComputedStyle(state);
    const cardStyle = getComputedStyle(card);
    const mainRect = main.getBoundingClientRect();
    const cardRect = card.getBoundingClientRect();
    return {
      paddingTop: Number.parseFloat(stateStyle.paddingTop),
      cardBackground: cardStyle.backgroundColor,
      cardPaddingTop: Number.parseFloat(cardStyle.paddingTop),
      cardPaddingLeft: Number.parseFloat(cardStyle.paddingLeft),
      cardTop: cardRect.top,
      mainTop: mainRect.top,
      cardRight: cardRect.right,
      mainRight: mainRect.right,
      horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  });
  expect(errorGeometry.paddingTop).toBeGreaterThanOrEqual(30);
  expect(errorGeometry.cardBackground).toBe("rgba(6, 22, 35, 0.92)");
  expect(errorGeometry.cardPaddingTop).toBeGreaterThanOrEqual(20);
  expect(errorGeometry.cardPaddingLeft).toBeGreaterThanOrEqual(18);
  expect(errorGeometry.cardTop).toBeGreaterThanOrEqual(errorGeometry.mainTop);
  expect(errorGeometry.cardRight).toBeLessThanOrEqual(errorGeometry.mainRight + 1);
  expect(errorGeometry.horizontalOverflow).toBeLessThanOrEqual(0);
  await expect(page.locator('.admin-shell[data-test-mobile-shell="persistent"]')).toHaveCount(1);

  await page.unroute("**/api/admin/watches");
  await pageError.getByRole("button", { name: "Retry" }).click();
  await expect(page.getByRole("heading", { name: "Watch records" })).toBeVisible();
  await expect(page.locator('.admin-shell[data-test-mobile-shell="persistent"]')).toHaveCount(1);

  await page.setViewportSize({ width: 1280, height: 720 });
  let releaseEvents!: () => void;
  let markEventsRequested!: () => void;
  const eventsRequested = new Promise<void>((resolve) => { markEventsRequested = resolve; });
  const eventsGate = new Promise<void>((resolve) => { releaseEvents = resolve; });
  await page.route("**/api/admin/events", async (route) => {
    markEventsRequested();
    await eventsGate;
    await route.continue();
  });
  await page.getByRole("navigation", { name: "Admin navigation" }).getByRole("link", { name: "Persisted events" }).click();
  await eventsRequested;
  const desktopProgress = page.getByRole("progressbar", { name: "Loading Admin page" });
  await expect(desktopProgress).toBeVisible();
  const desktopAlignment = await page.evaluate(() => {
    const sidebar = document.querySelector<HTMLElement>(".admin-sidebar")!.getBoundingClientRect();
    const progress = document.querySelector<HTMLElement>(".admin-page-progress")!.getBoundingClientRect();
    return { sidebarRight: sidebar.right, progressLeft: progress.left };
  });
  expect(Math.abs(desktopAlignment.progressLeft - desktopAlignment.sidebarRight)).toBeLessThanOrEqual(1);
  releaseEvents();
  await expect(page.getByRole("heading", { name: "Persisted realtime events" })).toBeVisible();
  await page.unroute("**/api/admin/events");
});
