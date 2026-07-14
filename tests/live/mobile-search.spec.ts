import { expect, test } from "@playwright/test";
import { installMapTilerMock } from "./support/maptiler-mock";

test("mobile search stays readable and waits for a quiet typing pause", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await installMapTilerMock(page);

  const searchRequests: Array<{ readonly query: string; readonly startedAt: number }> = [];
  let releaseSearchResponse!: () => void;
  const searchResponseGate = new Promise<void>((resolve) => {
    releaseSearchResponse = resolve;
  });

  await page.route("**/api/search?**", async (route) => {
    const query = new URL(route.request().url()).searchParams.get("q") ?? "";
    searchRequests.push({ query, startedAt: Date.now() });
    await searchResponseGate;
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        ok: true,
        data: {
          query,
          results: Array.from({ length: 20 }, (_, index) => ({
            type: "station",
            id: `NSR:StopPlace:${36_025 + index}`,
            label: index === 0 ? "Førde rutebilstasjon" : `Førde stop ${index + 1}`,
            secondaryText: "Station · Vestland",
            stationId: `NSR:StopPlace:${36_025 + index}`,
            lineCode: null,
            latitude: 61.4522 + index * 0.001,
            longitude: 5.8572 + index * 0.001,
          })),
        },
        meta: { requestId: "req_mobile_search", updatedAt: "2026-07-14T10:00:00Z" },
      }),
    });
  });

  await page.goto("/");

  const search = page.getByRole("searchbox", { name: "Search for station, place, line, or vehicle" });
  await expect(search).toBeVisible();
  const presentation = await search.evaluate((element) => {
    const input = element as HTMLInputElement;
    const bounds = input.getBoundingClientRect();
    const style = getComputedStyle(input);
    return {
      width: bounds.width,
      height: bounds.height,
      left: bounds.left,
      right: bounds.right,
      opacity: Number.parseFloat(style.opacity),
      pointerEvents: style.pointerEvents,
      fontSize: Number.parseFloat(style.fontSize),
      viewportWidth: window.innerWidth,
    };
  });
  expect(presentation.width, "the default mobile search field must be wide enough to read a query").toBeGreaterThan(150);
  expect(presentation.height, "the default mobile search field must remain a usable touch target").toBeGreaterThanOrEqual(40);
  expect(presentation.left).toBeGreaterThanOrEqual(0);
  expect(presentation.right).toBeLessThanOrEqual(presentation.viewportWidth);
  expect(presentation.opacity).toBe(1);
  expect(presentation.pointerEvents).not.toBe("none");
  expect(presentation.fontSize, "mobile search text must not trigger browser zoom or become unreadable").toBeGreaterThanOrEqual(16);

  await page.locator(".topbar .search-field > .icon").click();
  await expect(search).toBeFocused();
  let resultsRegion = page.getByRole("region", { name: "Search results" });
  await expect(resultsRegion).toBeVisible();

  await page.locator(".search-scrim").click({ position: { x: 2, y: 100 } });
  await expect(resultsRegion).toHaveCount(0);
  await expect(search).not.toBeFocused();

  const footerSearch = page.getByRole("navigation", { name: "Main navigation" }).getByRole("link", { name: "Search" });
  await footerSearch.click();
  await expect(search).toBeFocused();
  resultsRegion = page.getByRole("region", { name: "Search results" });
  await expect(resultsRegion).toBeVisible();

  await search.evaluate((element) => {
    const timingWindow = window as typeof window & { __fjordPulseLastSearchInputAt?: number };
    timingWindow.__fjordPulseLastSearchInputAt = 0;
    element.addEventListener("input", () => {
      timingWindow.__fjordPulseLastSearchInputAt = Date.now();
    });
  });

  let expectedQuery = "";
  for (const character of "Forde") {
    await search.pressSequentially(character);
    expectedQuery += character;
    await expect(search).toHaveValue(expectedQuery);
    expect(searchRequests, `typing ${expectedQuery} must not start an eager search`).toHaveLength(0);
    if (expectedQuery !== "Forde") await page.waitForTimeout(300);
  }

  await expect(search).toHaveValue("Forde");
  await expect(resultsRegion.getByRole("status")).toHaveText("Search starts after a short pause…");
  await expect(resultsRegion.getByText("Searching FjordPulse…")).toHaveCount(0);

  const lastInputAt = await page.evaluate(() => (
    window as typeof window & { __fjordPulseLastSearchInputAt?: number }
  ).__fjordPulseLastSearchInputAt ?? 0);
  const remainingPreDebounceTime = Math.max(0, 650 - (Date.now() - lastInputAt));
  if (remainingPreDebounceTime > 0) await page.waitForTimeout(remainingPreDebounceTime);
  expect(searchRequests, "search must remain local before the 700 ms quiet-period debounce expires").toHaveLength(0);

  await expect.poll(() => searchRequests.length, { message: "the settled Forde query should start once" }).toBe(1);
  expect(searchRequests[0]?.query).toBe("Forde");
  expect(searchRequests[0]!.startedAt - lastInputAt, "the backend request must trail the final input by the full debounce").toBeGreaterThanOrEqual(690);
  await expect(resultsRegion.getByRole("status")).toHaveText("Searching FjordPulse…");
  await expect(resultsRegion.getByText("Search starts after a short pause…")).toHaveCount(0);
  await expect(search).toHaveValue("Forde");

  releaseSearchResponse();
  await expect(page.getByRole("option", { name: /Førde rutebilstasjon/ })).toBeVisible();
  await expect(search).toHaveValue("Forde");

  const resultList = page.getByRole("listbox");
  const scroll = await resultList.evaluate((element) => ({
    clientHeight: element.clientHeight,
    scrollHeight: element.scrollHeight,
    overflowY: getComputedStyle(element).overflowY,
  }));
  expect(scroll.scrollHeight, "a long mobile result list must scroll inside the viewport").toBeGreaterThan(scroll.clientHeight);
  expect(scroll.overflowY).toMatch(/auto|scroll/);

  await page.waitForTimeout(750);
  expect(searchRequests.map(({ query }) => query)).toEqual(["Forde"]);

  await page.locator(".search-scrim").click({ position: { x: 2, y: 100 } });
  await expect(resultsRegion).toHaveCount(0);
  await expect(search).not.toBeFocused();
});
