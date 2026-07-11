import { expect, test, type Locator, type Page } from "@playwright/test";
import { PNG } from "pngjs";
import { installMapTilerMock, type MapTilerMock } from "./support/maptiler-mock";

interface TileCoordinate {
  readonly basemap: "satellite" | "streets";
  readonly z: number;
  readonly x: number;
  readonly y: number;
}

interface MapCamera {
  readonly zoom: number;
  readonly latitude: number;
  readonly longitude: number;
}

interface StationViewport extends MapCamera {
  readonly bounds: readonly [number, number, number, number];
}

function cameraFromUrl(value: string): MapCamera {
  const mapPart = new URL(value).hash.slice(1).split("&").find((part) => part.startsWith("map="));
  expect(mapPart).toBeDefined();
  const [zoom, latitude, longitude] = mapPart!.slice("map=".length).split("/").map(Number);
  expect([zoom, latitude, longitude].every(Number.isFinite)).toBe(true);
  return { zoom: zoom!, latitude: latitude!, longitude: longitude! };
}

async function nextStationViewport(page: Page): Promise<StationViewport> {
  const response = await page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    return candidate.request().method() === "GET" && url.pathname === "/api/stations" && candidate.status() === 200;
  }, { timeout: 30_000 });
  const url = new URL(response.url());
  const bounds = (url.searchParams.get("bbox") ?? "").split(",").map(Number);
  expect(bounds).toHaveLength(4);
  expect(bounds.every(Number.isFinite)).toBe(true);
  const [minLongitude, minLatitude, maxLongitude, maxLatitude] = bounds as [number, number, number, number];
  return {
    zoom: Number(url.searchParams.get("zoom")),
    latitude: (minLatitude + maxLatitude) / 2,
    longitude: (minLongitude + maxLongitude) / 2,
    bounds: [minLongitude, minLatitude, maxLongitude, maxLatitude],
  };
}

function expectCameraInsideViewport(camera: MapCamera, viewport: StationViewport): void {
  const [minLongitude, minLatitude, maxLongitude, maxLatitude] = viewport.bounds;
  expect(camera.longitude).toBeGreaterThan(minLongitude);
  expect(camera.longitude).toBeLessThan(maxLongitude);
  expect(camera.latitude).toBeGreaterThan(minLatitude);
  expect(camera.latitude).toBeLessThan(maxLatitude);
  expect(viewport.zoom).toBeCloseTo(camera.zoom, 2);
}

function tileCoordinate(path: string): TileCoordinate | null {
  const match = /^\/mock\/(satellite|streets)\/(\d+)\/(\d+)\/(\d+)\.png$/.exec(path);
  if (match === null) return null;
  return {
    basemap: match[1] as TileCoordinate["basemap"],
    z: Number(match[2]),
    x: Number(match[3]),
    y: Number(match[4]),
  };
}

function coordinates(mock: MapTilerMock, basemap: TileCoordinate["basemap"]): readonly TileCoordinate[] {
  return mock.tileRequests
    .map(tileCoordinate)
    .filter((coordinate): coordinate is TileCoordinate => coordinate?.basemap === basemap);
}

function coordinateKey(coordinate: TileCoordinate): string {
  return `${coordinate.z}/${coordinate.x}/${coordinate.y}`;
}

async function waitForReady(map: Locator, basemap: TileCoordinate["basemap"]): Promise<void> {
  await expect(map).toHaveAttribute("data-basemap", basemap);
  await expect(map).toHaveAttribute("data-map-state", "ready", { timeout: 20_000 });
}

async function dragMap(page: Page): Promise<void> {
  const canvas = page.locator("canvas.maplibregl-canvas").first();
  const box = await canvas.boundingBox();
  expect(box).not.toBeNull();
  await page.mouse.move(box!.x + box!.width * 0.7, box!.y + box!.height * 0.5);
  await page.mouse.down();
  await page.mouse.move(box!.x + box!.width * 0.25, box!.y + box!.height * 0.5, { steps: 12 });
  await page.mouse.up();
}

function transportColourPixels(image: Buffer): number {
  const png = PNG.sync.read(image);
  let matches = 0;
  for (let index = 0; index < png.data.length; index += 4) {
    const [red, green, blue, alpha] = png.data.subarray(index, index + 4);
    if (alpha > 200 && red < 100 && green > 110 && green < 205 && blue > 190) matches += 1;
  }
  return matches;
}

test("satellite is the first-visit basemap and pan/zoom fetch new tile coordinates", async ({ page }) => {
  const mock = await installMapTilerMock(page);
  await page.goto("/");

  const map = page.locator(".map-region");
  await waitForReady(map, "satellite");
  await expect(map).toHaveAttribute("data-cartography", "applied");
  await expect.poll(() => coordinates(mock, "satellite").length).toBeGreaterThan(0);
  expect(mock.styleRequests[0]).toBe("/maps/hybrid-v4/style.json");

  const initialCoordinates = coordinates(mock, "satellite");
  const initialZooms = new Set(initialCoordinates.map(({ z }) => z));
  const initialKeys = new Set(initialCoordinates.map(coordinateKey));

  await page.getByRole("button", { name: "Zoom in" }).click();
  await expect.poll(() => coordinates(mock, "satellite").some(({ z }) => !initialZooms.has(z))).toBe(true);

  const beforePan = new Set(coordinates(mock, "satellite").map(coordinateKey));
  await dragMap(page);
  await expect.poll(() => coordinates(mock, "satellite").some((coordinate) => !beforePan.has(coordinateKey(coordinate)))).toBe(true);

  expect(coordinates(mock, "satellite").some((coordinate) => !initialKeys.has(coordinateKey(coordinate)))).toBe(true);
  expect(await page.evaluate(() => localStorage.getItem("fjordpulse.basemap.v1"))).toBe("satellite");
});

test("FP-005 map camera URL is shareable and survives reload", async ({ page, context }) => {
  await installMapTilerMock(page);
  const requestedCamera = { zoom: 9.25, latitude: 61.452, longitude: 5.857 };
  const initialViewportPromise = nextStationViewport(page);
  await page.goto(`/#map=${requestedCamera.zoom}/${requestedCamera.latitude}/${requestedCamera.longitude}`);
  const initialViewport = await initialViewportPromise;
  await waitForReady(page.locator(".map-region"), "satellite");
  expectCameraInsideViewport(requestedCamera, initialViewport);
  await expect.poll(() => page.url()).toContain("#map=9.25/61.452/5.857");

  const beforeMovement = page.url();
  const movedViewportPromise = nextStationViewport(page);
  await page.getByRole("button", { name: "Zoom in" }).click();
  await movedViewportPromise;
  await expect.poll(() => page.url()).not.toBe(beforeMovement);
  const sharedUrl = page.url();
  const sharedCamera = cameraFromUrl(sharedUrl);

  const reloadViewportPromise = nextStationViewport(page);
  await page.reload();
  const reloadViewport = await reloadViewportPromise;
  await waitForReady(page.locator(".map-region"), "satellite");
  expect(page.url()).toBe(sharedUrl);
  expectCameraInsideViewport(sharedCamera, reloadViewport);

  const sharedPage = await context.newPage();
  await installMapTilerMock(sharedPage);
  const sharedViewportPromise = nextStationViewport(sharedPage);
  await sharedPage.goto(sharedUrl);
  const sharedViewport = await sharedViewportPromise;
  await waitForReady(sharedPage.locator(".map-region"), "satellite");
  expectCameraInsideViewport(sharedCamera, sharedViewport);
  await sharedPage.close();
});

test("FP-005 malformed map camera falls back safely and becomes canonical", async ({ page }) => {
  await installMapTilerMock(page);
  const viewportPromise = nextStationViewport(page);
  await page.goto("/?controls=0#map=999/not-a-number/500");
  const viewport = await viewportPromise;
  await waitForReady(page.locator(".map-region"), "satellite");

  expect(viewport.zoom).toBeCloseTo(3.6, 2);
  expectCameraInsideViewport({ zoom: 3.6, latitude: 64.2, longitude: 10.2 }, viewport);
  await expect.poll(() => new URL(page.url()).hash).toMatch(/^#map=3\.6\/64\.2\/10\.2$/);
  expect(new URL(page.url()).searchParams.get("controls")).toBe("0");
});

test("layer switching preserves transport overlays and remembers the last successful map", async ({ page }) => {
  const mock = await installMapTilerMock(page);
  await page.goto("/");

  const map = page.locator(".map-region");
  await waitForReady(map, "satellite");
  const canvas = page.locator("canvas.maplibregl-canvas").first();
  await expect.poll(async () => transportColourPixels(await canvas.screenshot()), { timeout: 20_000 }).toBeGreaterThan(10);

  await page.keyboard.press("/");
  const search = page.getByRole("searchbox", { name: "Search for station, place, line, or vehicle" });
  const searchResponse = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === "/api/search" && url.searchParams.get("q") === "Førde" && response.status() === 200;
  }, { timeout: 30_000 });
  await search.fill("Førde");
  await searchResponse;
  await expect(page.getByRole("option", { name: /Førde rutebilstasjon/ })).toBeVisible({ timeout: 20_000 });
  await page.keyboard.press("Enter");
  await expect(page.getByRole("heading", { name: "Førde rutebilstasjon" })).toBeVisible();

  const beforePan = new Set(coordinates(mock, "satellite").map(coordinateKey));
  await dragMap(page);
  await expect.poll(() => coordinates(mock, "satellite").some((coordinate) => !beforePan.has(coordinateKey(coordinate)))).toBe(true);
  const pannedOnlyCoordinates = new Set(
    coordinates(mock, "satellite")
      .map(coordinateKey)
      .filter((coordinate) => !beforePan.has(coordinate)),
  );
  expect(pannedOnlyCoordinates.size).toBeGreaterThan(0);

  await page.getByRole("button", { name: "Map layers" }).click();
  const satellite = page.getByRole("radio", { name: /^Satellite\b/ });
  const streets = page.getByRole("radio", { name: /^Map\b/ });
  await expect(satellite).toHaveAttribute("aria-checked", "true");
  await streets.click();
  await waitForReady(map, "streets");
  await expect(map).toHaveAttribute("data-cartography", "not-applicable");

  expect(mock.styleRequests).toContain("/maps/streets-v4/style.json");
  await expect.poll(() => coordinates(mock, "streets").length).toBeGreaterThan(0);
  expect(coordinates(mock, "streets").some((coordinate) => pannedOnlyCoordinates.has(coordinateKey(coordinate)))).toBe(true);
  await expect(page.getByRole("heading", { name: "Førde rutebilstasjon" })).toBeVisible();
  await expect.poll(async () => transportColourPixels(await canvas.screenshot()), { timeout: 20_000 }).toBeGreaterThan(10);
  expect(await page.evaluate(() => localStorage.getItem("fjordpulse.basemap.v1"))).toBe("streets");

  await page.getByRole("button", { name: "Map layers" }).click();
  await page.getByRole("radio", { name: /^Satellite\b/ }).click();
  await waitForReady(map, "satellite");
  await expect(map).toHaveAttribute("data-cartography", "applied");
  await expect.poll(async () => transportColourPixels(await canvas.screenshot()), { timeout: 20_000 }).toBeGreaterThan(10);

  await page.getByRole("button", { name: "Map layers" }).click();
  await page.getByRole("radio", { name: /^Map\b/ }).click();
  await waitForReady(map, "streets");

  const stylesBeforeReload = mock.styleRequests.length;
  await page.reload();
  await waitForReady(page.locator(".map-region"), "streets");
  expect(mock.styleRequests.slice(stylesBeforeReload)[0]).toBe("/maps/streets-v4/style.json");
});

test("a provider failure shows retryable error UI without a fixture fallback", async ({ page }) => {
  const mock = await installMapTilerMock(page, { failStyles: true });
  await page.goto("/");

  const map = page.locator(".map-region");
  await expect(map).toHaveAttribute("data-basemap", "satellite");
  await expect(map).toHaveAttribute("data-map-state", "error", { timeout: 20_000 });
  await expect(page.getByRole("alert")).toContainText("The map could not be loaded");
  await expect(page.getByRole("button", { name: "Retry" })).toBeVisible();
  await expect(map.locator(".map-texture, .map-label, .map-markers")).toHaveCount(0);
  await expect(map).toHaveClass(/is-public-map/);
  expect(mock.tileRequests).toEqual([]);

  const attempts = mock.styleRequests.length;
  await page.getByRole("button", { name: "Retry" }).click();
  await expect.poll(() => mock.styleRequests.length).toBeGreaterThan(attempts);
  await expect(map).toHaveAttribute("data-map-state", "error");
  await expect(map).toHaveAttribute("data-basemap", "satellite");
});

test("raster tile failures stay in a retryable error state without a fixture fallback", async ({ page }) => {
  const mock = await installMapTilerMock(page, { failTiles: true });
  await page.goto("/");

  const map = page.locator(".map-region");
  await expect(map).toHaveAttribute("data-basemap", "satellite");
  await expect.poll(() => mock.styleRequests.length).toBeGreaterThan(0);
  await expect.poll(() => mock.tileRequests.length).toBeGreaterThanOrEqual(3);
  await expect(map).toHaveAttribute("data-map-state", "error", { timeout: 20_000 });
  await expect(page.getByRole("alert")).toContainText("The map could not be loaded");
  await expect(page.getByRole("button", { name: "Retry" })).toBeVisible();
  await expect(map.locator(".map-texture, .map-label, .map-markers")).toHaveCount(0);
  await expect(map).toHaveClass(/is-public-map/);

  await page.waitForTimeout(750);
  await expect(map).toHaveAttribute("data-map-state", "error");
  const tileAttempts = mock.tileRequests.length;
  await page.getByRole("button", { name: "Retry" }).click();
  await expect.poll(() => mock.tileRequests.length).toBeGreaterThan(tileAttempts);
  await expect(map).toHaveAttribute("data-map-state", "error", { timeout: 20_000 });
  await page.waitForTimeout(750);
  await expect(map).toHaveAttribute("data-map-state", "error");
  await expect(map).toHaveAttribute("data-basemap", "satellite");
});

test("Retry refetches map configuration after a configuration request failure", async ({ page }) => {
  const mock = await installMapTilerMock(page);
  let configRequests = 0;
  await page.route("**/api/map/config", async (route) => {
    configRequests += 1;
    if (configRequests === 1) {
      await route.fulfill({
        status: 503,
        contentType: "application/json",
        body: JSON.stringify({
          ok: false,
          error: {
            code: "map_provider_misconfigured",
            message: "Map tiles are not configured.",
            details: {},
          },
          meta: { requestId: "req_playwright_map_config" },
        }),
      });
      return;
    }
    await route.continue();
  });

  await page.goto("/");
  const map = page.locator(".map-region");
  await expect(map).toHaveAttribute("data-map-state", "error");
  await expect(page.getByRole("alert")).toContainText("Map service is not configured");
  expect(configRequests).toBe(1);

  await page.getByRole("button", { name: "Retry" }).click();
  await expect.poll(() => configRequests).toBe(2);
  await waitForReady(map, "satellite");
  expect(mock.styleRequests[0]).toBe("/maps/hybrid-v4/style.json");
});
