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
  await waitForFrame(frames, 0, "station_snapshot_changed", (frame) => frame.scope === `station:${stationId}` && frame.eventId !== undefined);

  let from = frames.length;
  await selectScenario(page, "station_empty");
  await refreshStation(page);
  await waitForFrame(frames, from, "station_snapshot_changed", (frame) => frame.payload?.state === "empty");
  await expect(page.getByText("No upcoming departures.")).toBeVisible();
  const emptyNearby = await successfulData(await page.request.get(`/api/stations/${encodeURIComponent(stationId)}/nearby-vehicles`));
  expect(emptyNearby.searchRadiusMeters).toBe(5_000);
  expect(emptyNearby.vehicles).toEqual([]);
  await expect(page.getByText("No nearby vehicles reported.")).toHaveCount(0);
  await expect(page.getByText("Vehicles serving this station")).toHaveCount(0);
  await page.getByRole("tab", { name: /^Vehicles(?:,?\s+\d+)?$/ }).click();
  await expect(page.getByText("No station-serving vehicle reported now.")).toBeVisible();
  await expect(page.getByText("Vehicles serving this station")).toBeVisible();
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
  await admin.getByLabel("Username").fill("admin");
  await admin.getByLabel("Password").fill("local-development-only");
  await admin.getByRole("button", { name: "Sign in" }).click();
  await expect(admin.getByRole("heading", { name: "Active watches" })).toBeVisible();
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
