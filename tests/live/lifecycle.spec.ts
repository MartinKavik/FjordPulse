import { expect, test, type APIResponse, type Locator, type Page, type WebSocket } from "@playwright/test";
import { PNG } from "pngjs";
import { installMapTilerMock } from "./support/maptiler-mock";

const stationId = "NSR:StopPlace:36025";
const controlOrigin = `http://127.0.0.1:${Number(process.env.FJORDPULSE_LIVE_CONTROL_PORT ?? "19082")}`;

interface Frame {
  readonly type?: string;
  readonly scope?: string;
}

interface ApiEnvelope {
  readonly ok?: boolean;
  readonly data?: Record<string, unknown>;
  readonly meta?: Record<string, unknown>;
}

function telemetryValue(page: Page, label: string): Locator {
  const telemetry = page.getByLabel("System telemetry");
  return telemetry.locator(".telemetry-item").filter({
    has: page.getByText(label, { exact: true }),
  }).locator("strong");
}

async function jsonEnvelope(response: APIResponse): Promise<ApiEnvelope> {
  expect(response.headers()["content-type"]?.toLowerCase()).toMatch(/^application\/json(?:\s*;|$)/);
  const body = await response.json() as ApiEnvelope;
  expect(body.ok, JSON.stringify(body)).toBe(true);
  expect(body.data).toBeDefined();
  return body;
}

async function control(page: Page, action: "start" | "stop"): Promise<void> {
  const response = await page.request.post(`${controlOrigin}/realtime/${action}`);
  expect(response.status(), await response.text()).toBe(200);
  expect(response.headers()["content-type"]?.toLowerCase()).toMatch(/^application\/json(?:\s*;|$)/);
  expect(await response.json()).toMatchObject({ ok: true, realtimeRunning: action === "start" });
}

async function selectNormalScenario(page: Page): Promise<void> {
  const response = await page.request.post("/api/dev/scenario", { data: { scenario: "normal" } });
  const envelope = await jsonEnvelope(response);
  expect(envelope.data?.scenario).toBe("normal");
}

async function waitForHealthyApplication(page: Page): Promise<ApiEnvelope> {
  let latest: ApiEnvelope | undefined;
  await expect.poll(async () => {
    latest = await jsonEnvelope(await page.request.get("/api/health"));
    const dependencies = latest.data?.dependencies as {
      http?: { status?: string };
      realtime?: { status?: string };
      surrealdb?: { status?: string };
      liveQueryBridge?: { status?: string };
    } | undefined;
    return [
      latest.data?.status,
      latest.data?.mode,
      dependencies?.http?.status,
      dependencies?.realtime?.status,
      dependencies?.surrealdb?.status,
      dependencies?.liveQueryBridge?.status,
    ].join("/");
  }, { timeout: 25_000 }).toBe("healthy/normal/healthy/healthy/healthy/healthy");
  return latest!;
}

function transportOverlayPixels(image: Buffer): number {
  const png = PNG.sync.read(image);
  let matches = 0;
  for (let index = 0; index < png.data.length; index += 4) {
    const [red, green, blue, alpha] = png.data.subarray(index, index + 4);
    if (alpha > 200 && red < 130 && green > 100 && blue > 170) matches += 1;
  }
  return matches;
}

function isConcreteRelativeAge(value: string): boolean {
  return /^(?:now|\d+[smhd] ago)$/i.test(value.trim());
}

test("FP-001/002/003/007/040 fresh reload uses the complete station catalog and keeps realtime on demand", async ({ page }) => {
  await selectNormalScenario(page);
  const mapProvider = await installMapTilerMock(page);
  const sockets: WebSocket[] = [];
  let tokenRequests = 0;
  let releaseToken!: () => void;
  let reportTokenRequest!: () => void;
  const tokenHold = new Promise<void>((resolve) => { releaseToken = resolve; });
  const tokenRequested = new Promise<void>((resolve) => { reportTokenRequest = resolve; });

  page.on("websocket", (socket) => {
    if (new URL(socket.url()).pathname === "/live") sockets.push(socket);
  });
  await page.route("**/api/realtime-token", async (route) => {
    tokenRequests += 1;
    reportTokenRequest();
    await tokenHold;
    await route.continue();
  });

  const health = await waitForHealthyApplication(page);
  expect(health.data).toMatchObject({
    status: "healthy",
    mode: "normal",
    dataMode: "fake",
    dependencies: {
      http: { status: "healthy" },
      realtime: { status: "healthy" },
      surrealdb: { status: "healthy" },
      liveQueryBridge: { status: "healthy" },
    },
  });

  const stationMapResponse = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return response.request().method() === "GET"
      && url.pathname === "/api/stations";
  });
  await page.goto("/");
  const mapResponse = await stationMapResponse;
  expect(mapResponse.status()).toBe(200);
  expect(mapResponse.headers()["content-type"]?.toLowerCase()).toMatch(/^application\/json(?:\s*;|$)/);
  const mapEnvelope = await mapResponse.json() as ApiEnvelope;
  expect(mapEnvelope.ok).toBe(true);
  const mapData = mapEnvelope.data as { readonly dataSource?: string; readonly items?: readonly Record<string, unknown>[] };
  expect(mapData.dataSource).toBe("surrealdb");
  expect(mapData.items?.some((item) => item.kind === "cluster" && Number(item.count) > 1)).toBe(true);

  const reloadedStationMapResponse = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return response.request().method() === "GET" && url.pathname === "/api/stations";
  });
  await page.reload();
  const reloadResponse = await reloadedStationMapResponse;
  expect(reloadResponse.status()).toBe(200);
  expect(reloadResponse.headers()["content-type"]?.toLowerCase()).toMatch(/^application\/json(?:\s*;|$)/);
  const reloadEnvelope = await reloadResponse.json() as ApiEnvelope;
  expect(reloadEnvelope.ok).toBe(true);
  const reloadData = reloadEnvelope.data as { readonly dataSource?: string; readonly items?: readonly Record<string, unknown>[] };
  expect(reloadData.dataSource).toBe("surrealdb");
  expect(reloadData.items?.some((item) => item.kind === "cluster" && Number(item.count) > 1)).toBe(true);

  const completeCatalog = await jsonEnvelope(await page.request.get("/api/stations?bbox=4,57,32,72&zoom=14"));
  const completeItems = completeCatalog.data?.items;
  expect(Array.isArray(completeItems)).toBe(true);
  expect(completeItems).toHaveLength(7);
  expect((completeItems as readonly Record<string, unknown>[]).every((item) => item.kind === "station")).toBe(true);

  const map = page.locator(".map-region");
  await expect(map).toHaveAttribute("data-map-state", "ready", { timeout: 20_000 });
  await expect.poll(() => mapProvider.tileRequests.length).toBeGreaterThan(0);
  const canvas = page.locator("canvas.maplibregl-canvas").first();
  await expect.poll(async () => transportOverlayPixels(await canvas.screenshot()), { timeout: 20_000 }).toBeGreaterThan(10);

  await expect(telemetryValue(page, "Backend")).toHaveText("ok");
  await expect(telemetryValue(page, "Realtime")).toHaveText("ready");
  await expect(telemetryValue(page, "Transport")).toHaveText("demo data");
  await expect(telemetryValue(page, "Refresh")).toHaveText("on demand");
  await expect.poll(async () => isConcreteRelativeAge(await telemetryValue(page, "Last update").innerText())).toBe(true);
  expect(sockets).toHaveLength(0);
  expect(tokenRequests).toBe(0);

  await page.keyboard.press("/");
  const search = page.getByRole("searchbox", { name: "Search for station, place, line, or vehicle" });
  await search.fill("Førde");
  await expect(page.getByRole("option", { name: /Førde rutebilstasjon/ })).toBeVisible();
  const stationResponse = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return response.request().method() === "GET" && decodeURIComponent(url.pathname) === `/api/stations/${stationId}`;
  });
  await page.keyboard.press("Enter");
  await tokenRequested;
  await expect(telemetryValue(page, "Realtime")).toHaveText("connecting");
  releaseToken();

  const selectedResponse = await stationResponse;
  expect(selectedResponse.status()).toBe(200);
  expect(selectedResponse.headers()["content-type"]?.toLowerCase()).toMatch(/^application\/json(?:\s*;|$)/);
  const selectedEnvelope = await selectedResponse.json() as ApiEnvelope;
  expect(selectedEnvelope.ok).toBe(true);
  expect((selectedEnvelope.data?.snapshot as { stationId?: string } | undefined)?.stationId).toBe(stationId);
  await expect(page.getByRole("heading", { name: "Førde rutebilstasjon" })).toBeVisible();
  await expect(telemetryValue(page, "Realtime")).toHaveText("connected");
  await expect(telemetryValue(page, "Refresh")).toHaveText("realtime");
  await expect.poll(async () => isConcreteRelativeAge(await telemetryValue(page, "Last update").innerText())).toBe(true);
  expect(sockets).toHaveLength(1);
  expect(tokenRequests).toBe(1);
});

test("FP-047/048 actual realtime outage polls HTTP and reconnects with the station preserved", async ({ page }) => {
  test.setTimeout(150_000);
  await control(page, "start");
  await selectNormalScenario(page);
  await installMapTilerMock(page);

  const frames: Frame[] = [];
  const sockets: WebSocket[] = [];
  const successfulStationPolls: APIResponse[] = [];
  page.on("websocket", (socket) => {
    if (new URL(socket.url()).pathname !== "/live") return;
    sockets.push(socket);
    socket.on("framereceived", ({ payload }) => {
      if (typeof payload !== "string") return;
      try { frames.push(JSON.parse(payload) as Frame); } catch { /* protocol validation owns malformed frames */ }
    });
  });
  page.on("response", (response) => {
    const url = new URL(response.url());
    if (response.status() === 200
      && response.request().method() === "GET"
      && decodeURIComponent(url.pathname) === `/api/stations/${stationId}`
      && url.searchParams.get("refresh") === "true") {
      successfulStationPolls.push(response);
    }
  });

  await page.goto("/");
  await page.keyboard.press("/");
  const search = page.getByRole("searchbox", { name: "Search for station, place, line, or vehicle" });
  await search.fill("Førde");
  await expect(page.getByRole("option", { name: /Førde rutebilstasjon/ })).toBeVisible();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("heading", { name: "Førde rutebilstasjon" })).toBeVisible();
  await expect(telemetryValue(page, "Realtime")).toHaveText("connected");
  await expect.poll(() => frames.some((frame) => frame.type === "watch_station_ack" && frame.scope === `station:${stationId}`)).toBe(true);
  const socketsBeforeOutage = sockets.length;
  expect(socketsBeforeOutage).toBeGreaterThan(0);

  try {
    await control(page, "stop");
    await expect(telemetryValue(page, "Realtime")).toHaveText("reconnecting", { timeout: 10_000 });
    await expect(page.getByRole("heading", { name: "Førde rutebilstasjon" })).toBeVisible();
    await expect(telemetryValue(page, "Realtime")).toHaveText("offline", { timeout: 20_000 });
    await expect(telemetryValue(page, "Refresh")).toHaveText("polling");
    await expect.poll(() => successfulStationPolls.length, { timeout: 15_000 }).toBeGreaterThan(0);
    const poll = successfulStationPolls.at(-1)!;
    expect(poll.headers()["content-type"]?.toLowerCase()).toMatch(/^application\/json(?:\s*;|$)/);
    const pollEnvelope = await poll.json() as ApiEnvelope;
    expect(pollEnvelope.ok).toBe(true);
    expect((pollEnvelope.data?.snapshot as { stationId?: string } | undefined)?.stationId).toBe(stationId);
    await expect.poll(async () => isConcreteRelativeAge(await telemetryValue(page, "Last update").innerText())).toBe(true);

    const framesBeforeRestart = frames.length;
    await control(page, "start");
    await expect(telemetryValue(page, "Realtime")).toHaveText("connected", { timeout: 25_000 });
    await expect(telemetryValue(page, "Refresh")).toHaveText("realtime");
    await expect(page.getByRole("heading", { name: "Førde rutebilstasjon" })).toBeVisible();
    await expect.poll(() => sockets.length, { timeout: 25_000 }).toBeGreaterThan(socketsBeforeOutage);
    await expect.poll(
      () => frames.slice(framesBeforeRestart).some((frame) => frame.type === "watch_station_ack" && frame.scope === `station:${stationId}`),
      { timeout: 25_000 },
    ).toBe(true);

    await expect.poll(async () => {
      const recoveredHealth = await jsonEnvelope(await page.request.get("/api/health"));
      const dependencies = recoveredHealth.data?.dependencies as {
        realtime?: { status?: string };
        liveQueryBridge?: { status?: string };
      } | undefined;
      return `${dependencies?.realtime?.status}/${dependencies?.liveQueryBridge?.status}`;
    }, { timeout: 20_000 }).toBe("healthy/healthy");
  } finally {
    await control(page, "start");
    await selectNormalScenario(page);
  }
});
