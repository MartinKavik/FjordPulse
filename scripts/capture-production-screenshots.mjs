import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { copyFile, mkdir, mkdtemp, readFile, rename, rm, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { chromium } from "@playwright/test";

const baseUrl = (process.env.FJORDPULSE_CAPTURE_URL ?? "https://fjordpulse.kavik.cz").replace(/\/$/, "");
const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const outputDirectory = resolve(repositoryRoot, "docs/screenshots");
const stagingRoot = resolve(repositoryRoot, ".run");
const desktopViewport = { width: 1_440, height: 900 };
const mobileViewport = { width: 390, height: 844 };
const screenshotFiles = [
  "production-focus-line-1-alesund.png",
  "production-forde-station.png",
  "production-admin-status.png",
  "production-admin-realtime.png",
  "production-admin-infrastructure.png",
  "production-mobile-map.png",
];
const targetUrl = new URL(baseUrl);
if (targetUrl.href !== "https://fjordpulse.kavik.cz/") {
  throw new Error("Production screenshots may only be captured from https://fjordpulse.kavik.cz");
}
const expectedBuildVersion = process.env.FJORDPULSE_CAPTURE_EXPECTED_VERSION
  ?? execFileSync("git", ["rev-parse", "HEAD"], { cwd: repositoryRoot, encoding: "utf8" }).trim();
if (!/^[0-9a-f]{40}$/.test(expectedBuildVersion)) {
  throw new Error("FJORDPULSE_CAPTURE_EXPECTED_VERSION must be an exact 40-character commit SHA");
}

async function apiData(path) {
  const response = await fetch(new URL(path, baseUrl), { signal: AbortSignal.timeout(30_000) });
  if (!response.ok) throw new Error(`${path} returned HTTP ${response.status}`);
  const envelope = await response.json();
  if (envelope?.ok !== true || envelope.data === undefined) {
    throw new Error(`${path} did not return a successful FjordPulse envelope`);
  }
  return envelope.data;
}

function assertProductionHealth(health) {
  const dependencies = health?.dependencies ?? {};
  if (
    health?.status !== "healthy"
    || health.mode !== "normal"
    || health.dataMode !== "real"
    || health.version !== expectedBuildVersion
    || dependencies.realtime?.status !== "healthy"
    || dependencies.surrealdb?.status !== "healthy"
    || dependencies.liveQueryBridge?.status !== "healthy"
    || dependencies.mapTiles?.status !== "configured"
  ) {
    throw new Error("Production health, data mode, dependencies, or exact build did not match the capture contract");
  }
}

function captureDateLabel(value) {
  return new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "Europe/Oslo",
  }).format(value);
}

async function lineOneCandidates() {
  const ids = new Set();
  const search = await apiData("/api/search?q=Hessa&limit=50");
  for (const result of search.results ?? []) {
    if (
      result.type === "vehicle"
      && result.transportMode === "bus"
      && result.lineCode === "1"
      && result.id?.startsWith("MOR:Vehicle:")
    ) ids.add(result.id);
  }

  const hessaStations = (search.results ?? [])
    .filter((result) => result.type === "station" && result.id?.startsWith("NSR:StopPlace:"))
    .slice(0, 8);
  for (const station of hessaStations) {
    try {
      const stationData = await apiData(`/api/stations/${encodeURIComponent(station.id)}?refresh=true`);
      for (const vehicle of stationData.snapshot?.nearbyVehicles ?? []) {
        if (
          vehicle.transportMode === "bus"
          && vehicle.lineCode === "1"
          && vehicle.id?.startsWith("MOR:Vehicle:")
        ) ids.add(vehicle.id);
      }
    } catch (error) {
      console.warn(`Skipping Ålesund stop ${station.id}: ${error.message}`);
    }
  }

  const moa = await apiData("/api/stations/NSR%3AStopPlace%3A40404?refresh=true");
  for (const vehicle of moa.snapshot?.nearbyVehicles ?? []) {
    if (
      vehicle.transportMode === "bus"
      && vehicle.lineCode === "1"
      && vehicle.id?.startsWith("MOR:Vehicle:")
    ) ids.add(vehicle.id);
  }

  const candidates = [];
  for (const id of ids) {
    try {
      const data = await apiData(`/api/vehicles/${encodeURIComponent(id)}?refresh=true`);
      const vehicle = data.vehicle;
      const routeName = vehicle?.routeName?.toLocaleLowerCase("nb-NO") ?? "";
      if (
        vehicle?.state !== "live"
        || vehicle.transportMode !== "bus"
        || vehicle.lineCode !== "1"
        || !routeName.includes("hessa")
        || !routeName.includes("myrland")
        || !Number.isFinite(vehicle.latitude)
        || !Number.isFinite(vehicle.longitude)
        || vehicle.nextStop === null
        || data.journey?.state !== "fresh"
        || (data.journey?.route?.coordinates?.length ?? 0) < 2
        || (data.upcomingStops?.length ?? 0) < 1
      ) continue;
      candidates.push({
        id,
        routeName: vehicle.routeName,
        upcomingStops: data.upcomingStops?.length ?? 0,
      });
    } catch (error) {
      console.warn(`Skipping ${id}: ${error.message}`);
    }
  }

  candidates.sort((left, right) => right.upcomingStops - left.upcomingStops);
  if (candidates.length === 0) {
    throw new Error("No live Ålesund Line 1 bus with a planned journey is reporting right now");
  }
  return candidates;
}

async function configureEnglishProduction(context) {
  await context.addInitScript(() => {
    localStorage.setItem("fjordpulse.locale.v1", "en");
    localStorage.setItem("fjordpulse.basemap.v1", "satellite");
    localStorage.setItem("fjordpulse:welcome-panel", "collapsed");
  });
}

async function waitForMap(page) {
  const map = page.locator(".map-region");
  await map.waitFor({ timeout: 20_000 });
  await page.waitForFunction(() => {
    const region = document.querySelector(".map-region");
    return region?.getAttribute("data-map-state") === "ready"
      || region?.getAttribute("data-map-state") === "error";
  }, undefined, { timeout: 45_000 });
  const state = await map.getAttribute("data-map-state");
  const basemap = await map.getAttribute("data-basemap");
  if (state !== "ready" || basemap !== "satellite") {
    throw new Error(`Map did not become ready on satellite imagery (state=${state}, basemap=${basemap})`);
  }
  await page.evaluate(() => document.fonts.ready);
}

async function waitForBrandReady(page) {
  const requireWordmark = (page.viewportSize()?.width ?? desktopViewport.width) > 900;
  await page.bringToFront();
  await page.waitForFunction(async (wordmarkRequired) => {
    await document.fonts.ready;
    const isVisibleInViewport = (element) => {
      if (!(element instanceof HTMLElement)) return false;
      const style = window.getComputedStyle(element);
      const bounds = element.getBoundingClientRect();
      return style.display !== "none"
        && style.visibility === "visible"
        && Number.parseFloat(style.opacity) > 0
        && bounds.width > 0
        && bounds.height > 0
        && bounds.left >= 0
        && bounds.top >= 0
        && bounds.right <= window.innerWidth
        && bounds.bottom <= window.innerHeight;
    };

    return [...document.querySelectorAll(".brand")].some((brand) => {
      const mark = brand.querySelector("img.brand-mark");
      const wordmark = brand.querySelector(":scope > span");
      return isVisibleInViewport(brand)
        && mark instanceof HTMLImageElement
        && mark.complete
        && mark.naturalWidth > 0
        && mark.naturalHeight > 0
        && isVisibleInViewport(mark)
        && (!wordmarkRequired || (
          isVisibleInViewport(wordmark)
          && wordmark?.textContent?.replace(/\s+/g, "") === "FjordPulse"
        ));
    });
  }, requireWordmark, { timeout: 20_000 });
  await page.evaluate(async () => {
    const visibleMarks = [...document.querySelectorAll("img.brand-mark")].filter((mark) => {
      const bounds = mark.getBoundingClientRect();
      return bounds.width > 0 && bounds.height > 0;
    });
    await Promise.all(visibleMarks.map((mark) => mark.decode()));
    await new Promise((resolvePaint) => requestAnimationFrame(() => requestAnimationFrame(resolvePaint)));
  });
  await page.waitForTimeout(150);
}

async function captureBrandedScreenshot(page, filename, stillReady = async () => true) {
  await waitForBrandReady(page);
  if (!(await stillReady())) throw new Error(`${filename} changed after its brand became ready`);
  await page.screenshot({ path: resolve(captureDirectory, filename), scale: "css" });
}

async function openSearchResult(page, query, resultText) {
  await page.keyboard.press("/");
  const search = page.getByRole("searchbox", { name: "Search for station, place, line, or vehicle" });
  await search.fill(query);
  const option = page.getByRole("option").filter({ hasText: resultText }).first();
  await option.waitFor({ timeout: 20_000 });
  await option.click();
}

async function focusLineOne(page, candidates) {
  let lastError;
  for (const candidate of candidates) {
    try {
      await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
      await waitForMap(page);
      await openSearchResult(page, "Hessa", candidate.id);
      await page.getByRole("heading", { name: "Line 1", exact: true }).waitFor({ timeout: 20_000 });
      await page.getByText(candidate.routeName, { exact: true }).waitFor();
      await page.getByRole("button", { name: "Selected Bus on Line 1" }).waitFor();
      await page.getByRole("button", { name: "Show full route overview" }).waitFor({ timeout: 20_000 });
      await page.getByRole("heading", { name: "Upcoming stops", exact: true }).waitFor();
      await page.locator(".upcoming-stops li").first().waitFor();
      const summary = await page.locator(".vehicle-summary:not(.is-compact) > div").evaluateAll((rows) => Object.fromEntries(
        rows.map((row) => [
          row.querySelector("span")?.textContent?.trim() ?? "",
          row.querySelector("strong")?.textContent?.trim() ?? "",
        ]),
      ));
      if (!summary["Next stop"] || summary["Next stop"] === "Not reported") throw new Error("Next stop is not visible");
      if (!summary["Previous stop"] || summary["Previous stop"] === "Not available") throw new Error("Previous stop is not visible");
      await page.getByRole("button", { name: "Focus this vehicle" }).click();
      await page.getByText("Following Line 1").waitFor({ timeout: 20_000 });
      await page.waitForTimeout(2_000);
      await page.evaluate(() => document.fonts.ready);
      return candidate.id;
    } catch (error) {
      lastError = error;
      console.warn(`Could not focus ${candidate.id}; trying the next live candidate: ${error.message}`);
    }
  }
  throw lastError ?? new Error("Could not focus a live Ålesund Line 1 bus");
}

async function loginToAdmin(page) {
  await page.goto(`${baseUrl}/admin/status`, { waitUntil: "domcontentloaded" });
  await page.locator('.admin-login, [data-admin-page="status"]').first().waitFor({ timeout: 20_000 });
  const loginHeading = page.getByRole("heading", { name: "Admin sign in" });
  if (await loginHeading.isVisible().catch(() => false)) {
    await loginHeading.waitFor();
    const fillDemo = page.getByRole("button", { name: "Fill demo credentials" });
    await fillDemo.waitFor({ timeout: 20_000 });
    await fillDemo.click();
    await page.waitForFunction(() => {
      const username = document.querySelector('input[autocomplete="username"]');
      const password = document.querySelector('input[autocomplete="current-password"]');
      return username instanceof HTMLInputElement
        && password instanceof HTMLInputElement
        && username.value.length > 0
        && password.value.length > 0;
    }, undefined, { timeout: 20_000 });
    await page.getByRole("button", { name: "Sign in" }).click();
  }
  await page.getByRole("heading", { name: "System status", exact: true }).waitFor({ timeout: 20_000 });
}

async function waitForConfirmedRealtime(page, vehicleId) {
  await page.waitForFunction(async (expectedVehicleId) => {
    try {
      const response = await fetch("/api/admin/realtime", {
        cache: "no-store",
        signal: AbortSignal.timeout(5_000),
      });
      if (!response.ok) return false;
      const envelope = await response.json();
      const data = envelope?.data;
      const deliveredAt = Date.parse(data?.lastBroadcastAt ?? "");
      const deliveryAge = Date.now() - deliveredAt;
      return data?.server?.status === "healthy"
        && data?.liveQueryBridge?.status === "healthy"
        && Number.isFinite(deliveredAt)
        && deliveryAge >= -5_000
        && deliveryAge <= 65_000
        && Number(data?.activeClients ?? 0) > 0
        && Number(data?.messagesPerMinute ?? 0) > 0
        && (data?.rooms ?? []).some((room) => (
          room.scope === `vehicle:${expectedVehicleId}` && Number(room.clientCount ?? 0) > 0
        ));
    } catch {
      return false;
    }
  }, vehicleId, { polling: 1_000, timeout: 60_000 });
}

async function captureAdmin(page, route, heading, pageName, filename, isReady = async () => true) {
  await page.goto(`${baseUrl}${route}`, { waitUntil: "domcontentloaded" });
  await page.getByRole("heading", { name: heading, exact: true }).waitFor({ timeout: 20_000 });
  await page.locator(`[data-admin-page="${pageName}"]`).waitFor();
  const refresh = page.getByRole("button", { name: "Refresh admin data" });
  const progress = page.locator(".admin-page-progress");
  const pageContent = page.locator(`[data-admin-page="${pageName}"]`);
  const deadline = Date.now() + 60_000;
  do {
    if (await refresh.isEnabled().catch(() => false)) await refresh.click();
    await page.waitForTimeout(150);
    if (await progress.isVisible().catch(() => false)) {
      await progress.waitFor({ state: "detached", timeout: 20_000 });
    } else {
      await page.waitForTimeout(850);
    }
    const contentVisible = await pageContent.isVisible().catch(() => false);
    const alertVisible = await page.getByRole("alert").first().isVisible().catch(() => false);
    if (contentVisible && !alertVisible && await isReady()) break;
    if (Date.now() >= deadline) throw new Error(`${heading} did not reach its required visible capture state`);
    await page.waitForTimeout(1_000);
  } while (true);
  await page.evaluate(() => {
    const main = document.querySelector(".admin-main");
    if (main instanceof HTMLElement) main.scrollTop = 0;
    return document.fonts.ready;
  });
  if (
    !(await pageContent.isVisible().catch(() => false))
    || await page.getByRole("alert").first().isVisible().catch(() => false)
    || !(await isReady())
  ) throw new Error(`${heading} changed before its screenshot was written`);
  await captureBrandedScreenshot(page, filename, isReady);
}

async function realtimePageShowsActiveWatch(page, vehicleId) {
  const activeClientsCard = page.locator("article").filter({ hasText: "Active clients" }).first();
  const messagesCard = page.locator("article").filter({ hasText: "WebSocket messages" }).first();
  const serverCard = page.locator("article").filter({ hasText: "Realtime server" }).first();
  const bridgeCard = page.locator("article").filter({ hasText: "Live-query bridge" }).first();
  if (!(await activeClientsCard.isVisible().catch(() => false))) return false;
  if (!(await messagesCard.isVisible().catch(() => false))) return false;
  if (!(await serverCard.isVisible().catch(() => false)) || !(await bridgeCard.isVisible().catch(() => false))) return false;
  if (!(await serverCard.innerText()).includes("OK") || !(await bridgeCard.innerText()).includes("OK")) return false;
  const activeClients = Number.parseFloat(await activeClientsCard.locator("strong").first().innerText());
  const messagesPerMinute = Number.parseFloat(await messagesCard.locator("strong").first().innerText());
  const room = page.getByText(`vehicle:${vehicleId}`, { exact: true }).first();
  const apiConfirmed = await page.evaluate(async (expectedVehicleId) => {
    try {
      const response = await fetch("/api/admin/realtime", {
        cache: "no-store",
        signal: AbortSignal.timeout(5_000),
      });
      if (!response.ok) return false;
      const data = (await response.json())?.data;
      const deliveredAt = Date.parse(data?.lastBroadcastAt ?? "");
      const deliveryAge = Date.now() - deliveredAt;
      return data?.server?.status === "healthy"
        && data?.liveQueryBridge?.status === "healthy"
        && Number.isFinite(deliveredAt)
        && deliveryAge >= -5_000
        && deliveryAge <= 65_000
        && Number(data?.activeClients ?? 0) > 0
        && Number(data?.messagesPerMinute ?? 0) > 0
        && (data?.rooms ?? []).some((candidate) => (
          candidate.scope === `vehicle:${expectedVehicleId}` && Number(candidate.clientCount ?? 0) > 0
        ));
    } catch {
      return false;
    }
  }, vehicleId);
  return activeClients > 0
    && messagesPerMinute > 0
    && await room.isVisible().catch(() => false)
    && apiConfirmed;
}

async function statusPageShowsActiveWatch(page) {
  if (!(await page.getByRole("heading", { name: "System operational", exact: true }).isVisible().catch(() => false))) {
    return false;
  }
  const metrics = await page.locator(".admin-demand-panel article").evaluateAll((articles) => Object.fromEntries(
    articles.map((article) => [
      article.querySelector("span")?.textContent?.trim() ?? "",
      Number.parseFloat(article.querySelector("strong")?.textContent ?? "0"),
    ]),
  ));
  return (metrics["Browser connections"] ?? 0) > 0
    && (metrics["Watched vehicles"] ?? 0) > 0
    && (metrics["Focus sessions"] ?? 0) > 0
    && (await page.locator("body").innerText()).includes(expectedBuildVersion);
}

async function infrastructurePageIsComplete(page) {
  const requiredHeadings = ["Deployment identity", "Host resources", "Stored data"];
  for (const heading of requiredHeadings) {
    if (!(await page.getByRole("heading", { name: heading, exact: true }).isVisible().catch(() => false))) return false;
  }
  const body = await page.locator("body").innerText();
  return body.includes(expectedBuildVersion)
    && body.includes("PRODUCTION")
    && body.includes("Real Entur data")
    && body.includes("CONFIGURED")
    && await page.getByRole("progressbar", { name: /^CPU (?:usage|load relative to available CPUs)$/ }).isVisible().catch(() => false)
    && await page.getByRole("progressbar", { name: "Memory used" }).isVisible().catch(() => false)
    && await page.getByRole("progressbar", { name: /Disk used on/i }).isVisible().catch(() => false);
}

async function captureForde(context) {
  const page = await context.newPage();
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  await waitForMap(page);
  await openSearchResult(page, "Forde", "Førde rutebilstasjon");
  await page.getByRole("heading", { name: "Førde rutebilstasjon", exact: true }).waitFor({ timeout: 30_000 });
  await page.getByRole("button", { name: "Selected station Førde rutebilstasjon" }).waitFor();
  await page.getByRole("heading", { name: "Next departures", exact: true }).waitFor({ timeout: 30_000 });
  await page.locator(".departure-row").first().waitFor({ timeout: 30_000 });
  await page.waitForTimeout(2_000);
  await page.evaluate(() => document.fonts.ready);
  await captureBrandedScreenshot(page, "production-forde-station.png");
  await page.close();
}

async function captureMobile(browser) {
  const context = await browser.newContext({ viewport: mobileViewport, deviceScaleFactor: 1 });
  await context.addInitScript(() => {
    localStorage.setItem("fjordpulse.basemap.v1", "satellite");
    localStorage.setItem("fjordpulse:welcome-panel", "collapsed");
  });
  const page = await context.newPage();
  await page.goto(`${baseUrl}/#map=10.5/62.472/6.19`, { waitUntil: "domcontentloaded" });
  await waitForMap(page);
  const navigation = page.getByRole("navigation", { name: "Hovedmeny" });
  const admin = navigation.getByRole("link", { name: "Admin" });
  await admin.waitFor();
  if (await admin.getAttribute("href") !== "/admin/status") throw new Error("Mobile Admin link has the wrong target");
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 0) throw new Error(`Mobile page overflows horizontally by ${overflow}px`);
  await page.waitForTimeout(1_500);
  await captureBrandedScreenshot(page, "production-mobile-map.png");
  await context.close();
}

async function createCaptureManifest(buildVersion, vehicleId, capturedAt) {
  const files = {};
  for (const filename of screenshotFiles) {
    const contents = await readFile(resolve(captureDirectory, filename));
    const signature = contents.subarray(0, 8).toString("hex");
    if (signature !== "89504e470d0a1a0a" || contents.length < 24) {
      throw new Error(`${filename} is not a complete PNG capture`);
    }
    const width = contents.readUInt32BE(16);
    const height = contents.readUInt32BE(20);
    const expected = filename === "production-mobile-map.png" ? mobileViewport : desktopViewport;
    if (width !== expected.width || height !== expected.height) {
      throw new Error(`${filename} is ${width}x${height}; expected ${expected.width}x${expected.height}`);
    }
    files[filename] = {
      width,
      height,
      sha256: createHash("sha256").update(contents).digest("hex"),
    };
  }

  const manifest = {
    schemaVersion: 1,
    source: baseUrl,
    capturedAt: capturedAt.toISOString(),
    buildVersion,
    focusedVehicleId: vehicleId,
    files,
  };
  await writeFile(resolve(captureDirectory, "capture.json"), `${JSON.stringify(manifest, null, 2)}\n`, "utf8");
}

async function publishCapture() {
  const backupDirectory = await mkdtemp(resolve(stagingRoot, "production-screenshot-backup-"));
  const published = [];
  const backedUp = new Set();
  const files = [...screenshotFiles, "capture.json"];
  try {
    for (const filename of files) {
      const destination = resolve(outputDirectory, filename);
      try {
        await copyFile(destination, resolve(backupDirectory, filename));
        backedUp.add(filename);
      } catch (error) {
        if (error?.code !== "ENOENT") throw error;
      }
      await rename(resolve(captureDirectory, filename), destination);
      published.push(filename);
    }
  } catch (error) {
    for (const filename of published.reverse()) {
      const destination = resolve(outputDirectory, filename);
      if (backedUp.has(filename)) {
        await copyFile(resolve(backupDirectory, filename), destination);
      } else {
        await rm(destination, { force: true });
      }
    }
    throw error;
  } finally {
    await rm(backupDirectory, { recursive: true, force: true });
  }
}

await mkdir(outputDirectory, { recursive: true });
await mkdir(stagingRoot, { recursive: true });
const captureDirectory = await mkdtemp(resolve(stagingRoot, "production-screenshot-capture-"));
let browser;

try {
  const captureStartedAt = new Date();
  const intendedDateLabel = captureDateLabel(captureStartedAt);
  const provenance = await readFile(resolve(outputDirectory, "README.md"), "utf8");
  for (const filename of screenshotFiles) {
    const row = provenance.split("\n").find((line) => line.includes(`\`${filename}\``));
    if (row === undefined || !row.includes(expectedBuildVersion) || !row.includes(intendedDateLabel)) {
      throw new Error(`The provenance row for ${filename} must name the intended build and capture date`);
    }
  }
  const publicReadme = await readFile(resolve(repositoryRoot, "README.md"), "utf8");
  if (!publicReadme.includes(expectedBuildVersion) || !publicReadme.includes(intendedDateLabel)) {
    throw new Error("The public README must name the intended build and capture date before images can be replaced");
  }
  const captureHealth = await apiData("/api/health");
  assertProductionHealth(captureHealth);
  const captureBuildVersion = captureHealth.version;
  browser = await chromium.launch({
    // This machine's current Chromium disables WebGL in its native headless
    // backend. Run the script through `xvfb-run -a` so MapLibre exercises the
    // same WebGL 2 path as an ordinary browser instead of capturing an error UI.
    headless: false,
    args: [
      "--disable-background-timer-throttling",
      "--disable-renderer-backgrounding",
      "--disable-backgrounding-occluded-windows",
    ],
  });
  const context = await browser.newContext({ viewport: desktopViewport, deviceScaleFactor: 1 });
  await configureEnglishProduction(context);
  const publicPage = await context.newPage();
  const candidates = await lineOneCandidates();
  const vehicleId = await focusLineOne(publicPage, candidates);
  await captureBrandedScreenshot(publicPage, "production-focus-line-1-alesund.png");

  const adminPage = await context.newPage();
  await loginToAdmin(adminPage);
  await waitForConfirmedRealtime(adminPage, vehicleId);
  await captureAdmin(
    adminPage,
    "/admin/realtime",
    "Realtime diagnostics",
    "realtime",
    "production-admin-realtime.png",
    () => realtimePageShowsActiveWatch(adminPage, vehicleId),
  );
  await captureAdmin(
    adminPage,
    "/admin/status",
    "System status",
    "status",
    "production-admin-status.png",
    () => statusPageShowsActiveWatch(adminPage),
  );
  await captureAdmin(
    adminPage,
    "/admin/infrastructure",
    "Infrastructure",
    "infrastructure",
    "production-admin-infrastructure.png",
    () => infrastructurePageIsComplete(adminPage),
  );

  await captureForde(context);
  await captureMobile(browser);
  await context.close();
  const captureCompletedAt = new Date();
  if (captureDateLabel(captureCompletedAt) !== intendedDateLabel) {
    throw new Error("The production capture crossed an Oslo calendar-day boundary");
  }
  const finalHealth = await apiData("/api/health");
  assertProductionHealth(finalHealth);
  if (finalHealth.version !== captureBuildVersion) throw new Error("Production changed during capture");
  await createCaptureManifest(captureBuildVersion, vehicleId, captureCompletedAt);
  await publishCapture();
  console.log(`Captured production gallery from ${baseUrl} while following ${vehicleId}`);
} finally {
  await browser?.close();
  await rm(captureDirectory, { recursive: true, force: true });
}
