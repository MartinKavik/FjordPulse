import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const LANGUAGE_STORAGE_KEY = 'fjordpulse.locale.v1';

async function useEnglish(page: Page): Promise<void> {
  await page.addInitScript((storageKey) => localStorage.setItem(storageKey, 'en'), LANGUAGE_STORAGE_KEY);
}

async function expectLocalizedControlsToFit(page: Page, context: string): Promise<void> {
  const documentWidth = await page.evaluate(() => ({
    client: document.documentElement.clientWidth,
    scroll: document.documentElement.scrollWidth,
  }));
  expect(documentWidth.scroll, `${context}: page must not scroll horizontally`).toBeLessThanOrEqual(documentWidth.client + 1);

  const overflow = await page.locator([
    '.language-switcher button',
    '.button',
    '.panel-tabs button',
    '.welcome-restore',
    '.focus-pill button',
    '.status-chip',
    '.admin-sidebar nav a',
    '.admin-header button',
    '.admin-logout-button',
  ].join(', ')).evaluateAll((elements) => elements.flatMap((element) => {
    const node = element as HTMLElement;
    const rect = node.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) return [];
    const clipped = node.scrollWidth > node.clientWidth + 1 || node.scrollHeight > node.clientHeight + 1;
    const outsideViewport = node.closest('.table-wrap') === null
      && (rect.left < -1 || rect.right > window.innerWidth + 1);
    if (!clipped && !outsideViewport) return [];
    return [{
      selector: node.className || node.tagName.toLowerCase(),
      text: node.textContent?.replace(/\s+/g, ' ').trim() ?? '',
      client: `${node.clientWidth}x${node.clientHeight}`,
      scroll: `${node.scrollWidth}x${node.scrollHeight}`,
      horizontalBounds: `${Math.round(rect.left)}..${Math.round(rect.right)} of ${window.innerWidth}`,
    }];
  }));
  expect(overflow, `${context}: localized control labels must not be clipped`).toEqual([]);
}

async function vehicleMarkerGeometry(page: Page) {
  return page.locator('.vehicle-marker').evaluate((element) => {
    const marker = element as HTMLElement;
    const map = marker.closest<HTMLElement>('.map-region');
    const pin = marker.querySelector<HTMLElement>('.vehicle-marker-pin');
    const label = marker.querySelector<HTMLElement>('.vehicle-marker-label');
    if (map === null || pin === null || label === null) throw new Error('Vehicle marker geometry is incomplete.');
    const mapRect = map.getBoundingClientRect();
    const pinRect = pin.getBoundingClientRect();
    const labelRect = label.getBoundingClientRect();
    const anchorX = mapRect.left + Number.parseFloat(marker.style.left);
    const anchorY = mapRect.top + Number.parseFloat(marker.style.top);
    const overlapWidth = Math.max(0, Math.min(pinRect.right, labelRect.right) - Math.max(pinRect.left, labelRect.left));
    const overlapHeight = Math.max(0, Math.min(pinRect.bottom, labelRect.bottom) - Math.max(pinRect.top, labelRect.top));
    return {
      horizontalAnchorError: Math.abs((pinRect.left + pinRect.width / 2) - anchorX),
      verticalAnchorError: Math.abs(pinRect.bottom - anchorY),
      overlapArea: overlapWidth * overlapHeight,
      horizontalGap: labelRect.left >= pinRect.right ? labelRect.left - pinRect.right : pinRect.left - labelRect.right,
    };
  });
}

async function expectVehicleMarkerAnchoredAndClear(page: Page): Promise<void> {
  await expect(page.locator('.vehicle-marker-pin')).toBeVisible();
  await expect(page.locator('.vehicle-marker-label')).toBeVisible();
  await expect.poll(async () => (await vehicleMarkerGeometry(page)).horizontalAnchorError).toBeLessThan(1);
  await expect.poll(async () => (await vehicleMarkerGeometry(page)).verticalAnchorError).toBeLessThan(1);
  const geometry = await vehicleMarkerGeometry(page);
  expect(geometry.overlapArea).toBe(0);
  expect(geometry.horizontalGap).toBeGreaterThanOrEqual(7);
}

async function expectJourneyRailCentered(page: Page): Promise<void> {
  const rows = page.locator('.upcoming-stops li');
  await expect(rows.first()).toBeVisible();
  const measurements = await rows.evaluateAll((elements) => elements.map((element) => {
    const row = element as HTMLElement;
    const marker = row.querySelector<HTMLElement>('.stop-marker');
    if (marker === null) throw new Error('Upcoming stop marker is missing.');
    const rowRect = row.getBoundingClientRect();
    const markerRect = marker.getBoundingClientRect();
    const rail = getComputedStyle(row, '::before');
    const transformX = rail.transform === 'none' ? 0 : new DOMMatrixReadOnly(rail.transform).e;
    const railCenter = rowRect.left + Number.parseFloat(rail.left) + transformX + Number.parseFloat(rail.width) / 2;
    return {
      current: row.classList.contains('is-current'),
      offset: Math.abs(markerRect.left + markerRect.width / 2 - railCenter),
    };
  }));
  expect(measurements.some(({ current }) => current)).toBe(true);
  expect(measurements.some(({ current }) => !current)).toBe(true);
  expect(Math.max(...measurements.map(({ offset }) => offset))).toBeLessThan(0.1);
}

const visualIds = [
  'desktop_default_map',
  'desktop_station_fresh',
  'desktop_station_loading',
  'desktop_station_empty',
  'desktop_station_stale',
  'desktop_station_error',
  'desktop_vehicle_selected',
  'desktop_vehicle_focus_following',
  'desktop_vehicle_focus_paused',
  'desktop_vehicle_stale',
  'desktop_vehicle_lost',
  'desktop_vehicle_non_passenger',
  'desktop_degraded_fallback',
  'desktop_search_results',
  'desktop_search_empty',
  'mobile_default_map',
  'mobile_station_sheet',
  'mobile_station_full_sheet',
  'mobile_vehicle_focus',
  'mobile_vehicle_lost',
  'mobile_vehicle_non_passenger',
  'admin_status',
  'admin_watches',
  'admin_entur_log',
  'design_system_components',
] as const;

test('scenario gallery exposes every approved deterministic state', async ({ page }) => {
  await page.goto('/__scenarios');
  await expect(page.locator('html')).toHaveAttribute('lang', 'nb');
  await expect(page.getByRole('heading', { name: 'FjordPulse-scenarier' })).toBeVisible();
  const links = page.locator('.scenario-grid a');
  await expect(links).toHaveCount(visualIds.length);
  for (const id of visualIds) {
    await expect(page.locator(`a[href="/__scenario/${id}"]`)).toBeVisible();
  }
});

test('Norwegian is the default and a language choice updates immediately and survives reloads', async ({ page }) => {
  await page.goto('/__scenario/desktop_default_map');

  await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', '/fjordpulse-mark.svg');
  await expect(page.locator('.brand-mark')).toHaveAttribute('src', '/fjordpulse-mark.svg');
  const favicon = await page.request.get('/fjordpulse-mark.svg');
  expect(favicon.ok()).toBe(true);
  expect(favicon.headers()['content-type']).toContain('image/svg+xml');
  await expect(page.locator('html')).toHaveAttribute('lang', 'nb');
  await expect(page.getByRole('button', { name: 'Bytt språk til norsk' })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByRole('searchbox', { name: 'Søk etter holdeplass, sted, linje eller kjøretøy' })).toBeVisible();
  await expect(page.getByLabel('Velkommen')).toContainText('Finn en holdeplass, se kommende avganger og følg et kjøretøy langs ruten.');
  await expect(page.locator('.norway-label')).toHaveText('NORGE');
  expect(await page.evaluate((storageKey) => localStorage.getItem(storageKey), LANGUAGE_STORAGE_KEY)).toBeNull();

  await page.getByRole('button', { name: 'Bytt språk til engelsk' }).click();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await expect(page.getByRole('searchbox', { name: 'Search for station, place, line, or vehicle' })).toBeVisible();
  await expect(page.getByLabel('Welcome')).toContainText('Find a station, see upcoming departures, and follow a vehicle along its route.');
  await expect(page.locator('.norway-label')).toHaveText('NORWAY');
  expect(await page.evaluate((storageKey) => localStorage.getItem(storageKey), LANGUAGE_STORAGE_KEY)).toBe('en');

  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await expect(page.getByRole('button', { name: 'Switch language to English' })).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Switch language to Norwegian' }).click();
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('lang', 'nb');
  await expect(page.getByRole('searchbox', { name: 'Søk etter holdeplass, sted, linje eller kjøretøy' })).toBeVisible();
});

test('keyboard search opens, navigates, and selects an authoritative fixture station', async ({ page }) => {
  await useEnglish(page);
  await page.goto('/__scenario/desktop_default_map');
  const welcome = page.getByLabel('Welcome');
  await expect(welcome).toContainText('Find a station, see upcoming departures, and follow a vehicle along its route.');
  await expect(welcome).not.toContainText(/loading every bus|clusters|on demand|high-priority watch/i);
  const search = page.getByRole('searchbox', { name: 'Search for station, place, line, or vehicle' });
  await expect(search).toBeVisible();
  await page.keyboard.press('/');
  await expect(search).toBeFocused();
  await search.fill('Førde');
  await expect(page.getByRole('option', { name: /Førde rutebilstasjon/ })).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Førde rutebilstasjon' })).toBeVisible();
  await expect(page.getByRole('complementary', { name: /station details/ })).toBeVisible();
  const selectedStation = page.getByRole('button', { name: 'Selected station Førde rutebilstasjon' });
  await expect(selectedStation).toBeVisible();
  await page.locator('canvas.maplibregl-canvas').first().hover({ position: { x: 100, y: 100 }, force: true });
  await page.mouse.wheel(0, -900);
  await expect(selectedStation).toBeVisible();
});

test('public update status is exceptional, singular, and separate from data attribution', async ({ page }) => {
  await useEnglish(page);
  const updateStatus = () => page.getByRole('status', { name: 'Update status' });

  await page.goto('/__scenario/desktop_default_map');
  await expect(updateStatus()).toHaveCount(0);
  await expect(page.getByLabel('System telemetry')).toHaveCount(0);
  const fakeSource = page.getByRole('note', { name: 'Transport data source' });
  await expect(fakeSource).toContainText('Demo data');
  await expect(fakeSource.locator('strong')).toHaveText('Demo data');

  await page.goto('/__scenario/desktop_station_fresh');
  const stationDetails = page.getByRole('complementary', { name: /station details/i });
  await expect(stationDetails.getByText('Data updated 8s ago')).toBeVisible();
  await expect(updateStatus()).toHaveCount(0);

  await page.goto('/__scenario/desktop_vehicle_selected');
  const vehicleDetails = page.getByRole('complementary', { name: /details on Line/i });
  const lastSeen = vehicleDetails.locator('.vehicle-summary > div').filter({ hasText: 'Last seen' });
  await expect(lastSeen.locator('strong')).toHaveText('6s ago');
  await expect(updateStatus()).toHaveCount(0);

  await page.goto('/__scenario/desktop_station_error');
  await expect(page.getByText('Departures unavailable')).toBeVisible();
  await expect(updateStatus()).toHaveCount(0);

  await page.goto('/__scenario/desktop_station_stale');
  await expect(updateStatus()).toHaveText('Reconnecting to live updates…');
  await expect(updateStatus()).toHaveCount(1);

  await page.goto('/__scenario/desktop_degraded_fallback');
  await expect(updateStatus()).toHaveText('Live connection interrupted · Updating periodically');
  await expect(updateStatus()).toHaveCount(1);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  await expect(page.getByRole('complementary', { name: /station details/i })).toBeVisible();
  await expect(updateStatus()).toHaveText('Live connection interrupted · Updating periodically');
  await expect(updateStatus()).toHaveCount(1);
  await expect(page.getByLabel('System telemetry')).toHaveCount(0);
  await expect(page.locator('.telemetry-strip')).toHaveCount(0);
});

test('welcome panel frees the map, remembers explicit choices, and never replaces selected details', async ({ page }) => {
  await useEnglish(page);
  await page.goto('/__scenario/desktop_default_map');
  await page.evaluate(() => localStorage.removeItem('fjordpulse:welcome-panel'));
  await page.reload();

  const map = page.locator('.map-region');
  await expect(map).toBeVisible();
  await expect(map).toHaveAttribute('data-map-state', 'ready');
  const initialMapWidth = await map.evaluate((element) => element.getBoundingClientRect().width);
  expect(await page.evaluate(() => localStorage.getItem('fjordpulse:welcome-panel'))).toBeNull();
  await expect(page.getByLabel('Welcome')).toBeVisible();
  await page.getByRole('button', { name: 'Hide FjordPulse introduction' }).click();
  expect(await page.evaluate(() => localStorage.getItem('fjordpulse:welcome-panel'))).toBe('collapsed');
  await expect(page.getByLabel('Welcome')).toBeHidden();
  const restoreWelcome = page.getByRole('button', { name: 'Show FjordPulse introduction' });
  await expect(restoreWelcome).toBeFocused();
  await expect(restoreWelcome).toContainText('About');
  await expect.poll(() => map.evaluate((element) => element.getBoundingClientRect().width)).toBeGreaterThan(initialMapWidth + 250);

  await page.reload();
  await expect(page.getByRole('button', { name: 'Show FjordPulse introduction' })).toBeVisible();
  await page.keyboard.press('/');
  const search = page.getByRole('searchbox', { name: 'Search for station, place, line, or vehicle' });
  await search.fill('Førde');
  await expect(page.getByRole('option', { name: /Førde rutebilstasjon/ })).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('complementary', { name: /station details/ })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Show FjordPulse introduction' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Close station panel' }).click();
  await expect(page.getByRole('button', { name: 'Show FjordPulse introduction' })).toBeVisible();

  await page.goto('/__scenario/desktop_vehicle_selected');
  await expect(page.getByRole('complementary', { name: /details on Line/ })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Show FjordPulse introduction' })).toHaveCount(0);
  await page.goto('/__scenario/desktop_default_map');
  await expect(page.getByRole('button', { name: 'Show FjordPulse introduction' })).toBeVisible();

  await page.getByRole('button', { name: 'Show FjordPulse introduction' }).click();
  expect(await page.evaluate(() => localStorage.getItem('fjordpulse:welcome-panel'))).toBe('expanded');
  await page.reload();
  await expect(page.getByLabel('Welcome')).toBeVisible();

  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  const mobileWelcome = page.getByLabel('Welcome');
  await expect(mobileWelcome).toBeVisible();
  await expect(mobileWelcome).toHaveCSS('position', 'absolute');
  await page.getByRole('button', { name: 'Hide FjordPulse introduction' }).click();
  await page.reload();
  await expect(page.getByLabel('Welcome')).toBeHidden();
  await expect(page.getByRole('button', { name: 'Show FjordPulse introduction' })).toBeVisible();
  await page.evaluate(() => localStorage.removeItem('fjordpulse:welcome-panel'));
  await page.reload();
  expect(await page.evaluate(() => localStorage.getItem('fjordpulse:welcome-panel'))).toBeNull();
  await expect(page.getByLabel('Welcome')).toBeHidden();
  await expect(page.getByRole('button', { name: 'Show FjordPulse introduction' })).toBeVisible();
});

test('a station-serving vehicle outside the nearby radius can be opened and focused', async ({ page }) => {
  await useEnglish(page);
  await page.goto('/__scenario/desktop_station_fresh');
  await page.getByRole('tab', { name: 'Vehicles' }).click();
  await page.getByRole('button', { name: /Open Bus on Line 90\./ }).click();
  const vehicleHeading = page.getByRole('heading', { name: 'Line 90' });
  await expect(vehicleHeading).toBeVisible();
  await expect(vehicleHeading).toBeFocused();
  await expect(page.getByRole('button', { name: 'Selected Bus on Line 90' })).toBeVisible();
  await page.getByRole('button', { name: 'Focus this vehicle' }).click();
  await expect(page.getByText('Following Line 90')).toBeVisible();
  await page.getByRole('button', { name: 'Pause follow' }).click();
  await expect(page.getByText('Follow paused')).toBeVisible();
  await page.getByRole('button', { name: 'Resume follow' }).click();
  await expect(page.getByText('Following Line 90')).toBeVisible();
  await page.getByRole('button', { name: 'Unfocus' }).first().click();
  await expect(page.getByRole('button', { name: 'Focus this vehicle' })).toBeVisible();
});

test('selected vehicle pin tip marks the reported position without covering its label', async ({ page }) => {
  await useEnglish(page);
  for (const current of [
    { route: '/__scenario/desktop_vehicle_focus_following', width: 1_440, height: 900 },
    { route: '/__scenario/mobile_vehicle_focus', width: 390, height: 844 },
  ] as const) {
    await page.setViewportSize({ width: current.width, height: current.height });
    await page.goto(current.route);
    await expectVehicleMarkerAnchoredAndClear(page);
    if (current.width < 600) await page.getByRole('button', { name: 'Expand vehicle sheet' }).click();
    await expectJourneyRailCentered(page);
  }
});

test('non-passenger movements keep their live marker and focus without exposing passenger metadata', async ({ page }) => {
  await page.goto('/__scenarios');
  const languages = {
    nb: {
      panel: 'Detaljer for buss utenfor passasjertrafikk',
      marker: 'Valgt buss, ikke i passasjertrafikk',
      heading: 'Ikke i passasjertrafikk',
      serviceHeading: 'Ingen aktiv passasjerreise',
      serviceStatus: 'Status for passasjertrafikk',
      explanation: 'Kjøretøyet rapporterer fortsatt posisjon, men er ikke i passasjertrafikk nå.',
      following: 'Følger kjøretøyet',
      focusStatus: 'Ikke i passasjertrafikk · Sist sett',
      pausePill: 'Pause',
      pausePanel: 'Sett følging på pause',
      unfocus: 'Slutt å følge',
      expand: 'Utvid kjøretøypanelet',
      forbidden: [
        'Linje 4',
        'Forsinkelse',
        'Neste holdeplass',
        'Forrige holdeplass',
        'Reiseforløp',
        'Kommende holdeplasser',
        'Reiseplanen kan være utdatert',
      ],
    },
    en: {
      panel: 'Bus details, not in passenger service',
      marker: 'Selected bus, not in passenger service',
      heading: 'Not in passenger service',
      serviceHeading: 'No active passenger journey',
      serviceStatus: 'Passenger service status',
      explanation: 'The vehicle is still reporting its position but is not operating a public passenger service right now.',
      following: 'Following vehicle',
      focusStatus: 'Not in passenger service · Last seen',
      pausePill: 'Pause',
      pausePanel: 'Pause follow',
      unfocus: 'Unfocus',
      expand: 'Expand vehicle sheet',
      forbidden: [
        'Line 4',
        'Delay',
        'Next stop',
        'Previous stop',
        'Journey progress',
        'Upcoming stops',
        'Journey schedule may be stale',
      ],
    },
  } as const;
  const routes = [
    { route: '/__scenario/desktop_vehicle_non_passenger', width: 1_440, height: 900, mobile: false },
    { route: '/__scenario/mobile_vehicle_non_passenger', width: 390, height: 844, mobile: true },
  ] as const;

  for (const language of ['nb', 'en'] as const) {
    const copy = languages[language];
    await page.evaluate(([storageKey, value]) => localStorage.setItem(storageKey, value), [LANGUAGE_STORAGE_KEY, language]);
    for (const current of routes) {
      await page.setViewportSize({ width: current.width, height: current.height });
      await page.goto(current.route);
      await expect(page.locator('html')).toHaveAttribute('lang', language);

      const marker = page.getByRole('button', { name: copy.marker });
      await expect(marker).toBeVisible();
      await expectVehicleMarkerAnchoredAndClear(page);

      const focusPill = page.locator('.focus-pill.service-non-passenger');
      await expect(focusPill).toBeVisible();
      await expect(focusPill).toContainText(copy.following);
      await expect(focusPill).toContainText(copy.focusStatus);
      await expect(focusPill.getByRole('button', { name: copy.pausePill, exact: true })).toBeVisible();
      await expect(focusPill.getByRole('button', { name: copy.unfocus, exact: true })).toBeVisible();

      if (current.mobile) {
        await page.getByRole('button', { name: copy.expand }).click();
      }
      const panel = page.getByRole('complementary', { name: copy.panel });
      await expect(panel).toBeVisible();
      await expect(panel.getByRole('heading', { name: copy.heading, exact: true })).toBeVisible();
      await expect(panel.getByRole('heading', { name: copy.serviceHeading, exact: true })).toBeVisible();
      await expect(panel.getByRole('status', { name: copy.serviceStatus })).toContainText(copy.explanation);
      await expect(panel.locator('.vehicle-summary.is-compact')).toBeVisible();
      await expect(panel.getByRole('button', { name: copy.pausePanel, exact: true })).toBeVisible();
      await expect(panel.getByRole('button', { name: copy.unfocus, exact: true })).toBeVisible();

      for (const forbidden of copy.forbidden) {
        await expect(panel.getByText(forbidden, { exact: true })).toHaveCount(0);
      }
      await expect(panel.locator('.upcoming-stops')).toHaveCount(0);
      await expect(panel).not.toContainText('Flaktveit - Hesjaholtet');
      await expect(panel).not.toContainText('GAR4.402');
      await expect(panel).not.toContainText('+18 min');
      await expect(panel).not.toContainText('Entur did not return the referenced service journey.');
      await expect(page.getByRole('button', { name: language === 'nb' ? 'Vis hele ruten' : 'Show full route overview' })).toHaveCount(0);
      await expectLocalizedControlsToFit(page, `${language} ${current.route} at ${current.width}x${current.height}`);
    }
  }
});

test('completed zero nearby-vehicle results never leave the Vehicles tab blank', async ({ page }) => {
  await useEnglish(page);
  await page.goto('/__scenario/desktop_station_empty');
  const emptyNearbyResult = () => page.locator('[role="status"][data-state="empty"]').filter({ hasText: 'No nearby vehicles reported.' });
  await expect(page.getByText('No upcoming departures.')).toBeVisible();
  await expect(page.getByText('Vehicles serving this station')).toHaveCount(0);
  await expect(emptyNearbyResult()).toHaveCount(0);
  await page.getByRole('tab', { name: /^Vehicles(?:,?\s+\d+)?$/ }).click();
  const emptyResult = emptyNearbyResult();
  await expect(emptyResult).toBeVisible();
  await expect(emptyResult).toContainText('No live vehicle positions were found within 5 km of this station. The search is complete; check again shortly.');
  await expect(page.getByText('No station-serving vehicle reported now.')).toBeVisible();
  await expect(page.getByText('Vehicles serving this station')).toBeVisible();

  await page.goto('/__scenario/desktop_station_loading');
  await page.getByRole('tab', { name: /^Vehicles(?:,?\s+\d+)?$/ }).click();
  await expect(page.getByText('Loading station vehicles')).toBeVisible();
  await expect(page.getByText('Checking for vehicles that stop here and other nearby positions.')).toBeVisible();
  await expect(page.getByText('No nearby vehicles reported.')).toHaveCount(0);
});

test('station Details remain useful while live content loads or fails', async ({ page }) => {
  await useEnglish(page);
  for (const scenario of ['desktop_station_loading', 'desktop_station_error']) {
    await page.goto(`/__scenario/${scenario}`);
    await page.getByRole('tab', { name: 'Details' }).click();
    await expect(page.getByRole('heading', { name: 'About this station' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'What you can see here' })).toBeVisible();
    await page.getByText('Technical details').click();
    await expect(page.getByText('NSR:StopPlace:58366')).toBeVisible();
    await expect(page.getByText(/61\.4522.*5\.8572/)).toBeVisible();
    await expect(page.getByText('Europe/Oslo')).toBeVisible();
    await expect(page.getByText('Loading station details')).toHaveCount(0);
    await expect(page.getByText('Departures unavailable')).toHaveCount(0);
    await expect(page.getByText('Vehicle positions unavailable')).toHaveCount(0);
  }
});

test('mobile station sheet expands and remains usable without location permission', async ({ page, context }) => {
  await useEnglish(page);
  await context.grantPermissions([], { origin: 'http://127.0.0.1:4173' });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/__scenario/mobile_station_sheet');
  await expect(page.getByRole('complementary', { name: /station details/ })).toBeVisible();
  await page.getByRole('tab', { name: /^Vehicles(?:,?\s+\d+)?$/ }).click();
  const nearbyVehicles = page.getByRole('heading', { name: 'Other nearby vehicles' });
  await nearbyVehicles.scrollIntoViewIfNeeded();
  await expect(nearbyVehicles).toBeVisible();
  await page.getByRole('button', { name: 'Expand station sheet' }).click();
  await expect(page.getByRole('button', { name: 'Collapse station sheet' })).toBeVisible();
  await expect(page.getByText('Vehicles serving this station')).toBeVisible();
  await page.getByRole('tab', { name: 'Details' }).click();
  await expect(page.getByRole('heading', { name: 'About this station' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'What you can see here' })).toBeVisible();
  await page.getByText('Technical details').click();
  await expect(page.getByText('NSR:StopPlace:58366')).toBeVisible();
  await expect(page.getByText(/61\.4522.*5\.8572/)).toBeVisible();
  await expect(page.getByText('Europe/Oslo')).toBeVisible();
});

test('Norwegian and English controls fit representative desktop, mobile, and admin layouts', async ({ page }) => {
  // This matrix performs 18 route/locale navigations; software-rendered CI is
  // intentionally slower than a local GPU-backed browser.
  test.slow();
  await page.goto('/__scenarios');
  const cases = [
    { route: '/__scenario/desktop_station_fresh', width: 1440, height: 900 },
    { route: '/__scenario/desktop_vehicle_focus_following', width: 1024, height: 768 },
    { route: '/__scenario/desktop_vehicle_non_passenger', width: 1024, height: 768 },
    { route: '/__scenario/desktop_degraded_fallback', width: 1024, height: 768 },
    { route: '/__scenario/desktop_degraded_fallback', width: 768, height: 768 },
    { route: '/__scenario/mobile_station_full_sheet', width: 320, height: 720 },
    { route: '/__scenario/mobile_vehicle_non_passenger', width: 320, height: 720 },
    { route: '/__scenario/admin_status', width: 1440, height: 900 },
    { route: '/__scenario/admin_status', width: 390, height: 844 },
  ] as const;

  for (const language of ['nb', 'en'] as const) {
    await page.evaluate(([storageKey, value]) => localStorage.setItem(storageKey, value), [LANGUAGE_STORAGE_KEY, language]);
    for (const current of cases) {
      await page.setViewportSize({ width: current.width, height: current.height });
      await page.goto(current.route);
      await expect(page.locator('[data-scenario], .admin-shell')).toBeVisible();
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expect(page.locator('.language-switcher')).toBeVisible();
      await expectLocalizedControlsToFit(page, `${language} ${current.route} at ${current.width}x${current.height}`);
      const horizontalLayout = await page.evaluate(() => ({
        viewportWidth: document.documentElement.clientWidth,
        contentWidth: document.documentElement.scrollWidth,
        scrollX: window.scrollX,
        adminViewportWidth: document.querySelector<HTMLElement>('.admin-main')?.clientWidth ?? null,
        adminContentWidth: document.querySelector<HTMLElement>('.admin-main')?.scrollWidth ?? null,
        adminScrollLeft: document.querySelector<HTMLElement>('.admin-main')?.scrollLeft ?? null,
      }));
      expect(horizontalLayout.contentWidth, `Horizontal overflow in ${language} ${current.route}`).toBeLessThanOrEqual(horizontalLayout.viewportWidth);
      expect(horizontalLayout.scrollX, `Unexpected horizontal scroll in ${language} ${current.route}`).toBe(0);
      if (horizontalLayout.adminViewportWidth !== null && horizontalLayout.adminContentWidth !== null) {
        expect(horizontalLayout.adminContentWidth, `Admin horizontal overflow in ${language} ${current.route}`).toBeLessThanOrEqual(horizontalLayout.adminViewportWidth);
        expect(horizontalLayout.adminScrollLeft, `Unexpected admin horizontal scroll in ${language} ${current.route}`).toBe(0);
      }
    }
  }
});

test('admin fixtures expose status, watch, and Entur diagnostics', async ({ page }) => {
  await useEnglish(page);
  await page.goto('/__scenario/admin_status');
  await expect(page.getByRole('heading', { name: 'System status' })).toBeVisible();
  const adminNavigation = page.getByRole('navigation', { name: 'Admin navigation' });
  await expect(adminNavigation.locator('a[href="/admin/status"]')).toHaveCount(1);
  await expect(adminNavigation.getByRole('link', { name: 'System status' })).toHaveAttribute('aria-current', 'page');
  await expect(adminNavigation.getByRole('link', { name: 'Overview' })).toHaveCount(0);
  const serviceOverview = page.getByRole('region', { name: 'Service dependencies' });
  await expect(serviceOverview).toBeVisible();
  const realtimeDelivery = serviceOverview.getByText('Realtime delivery').locator('..');
  await expect(realtimeDelivery.getByRole('list', { name: 'Realtime delivery checks' })).toContainText('Server');
  await expect(realtimeDelivery.getByRole('list', { name: 'Realtime delivery checks' })).toContainText('Database events');
  await expect(realtimeDelivery.getByRole('link', { name: 'Open realtime diagnostics' })).toHaveAttribute('href', '/admin/realtime');
  await expect(serviceOverview.getByText('Live-query bridge', { exact: true })).toHaveCount(0);
  await expect(serviceOverview.getByText('Map tiles', { exact: true })).toBeVisible();
  await expect(page.getByText('Entur API').locator('..').getByText('IDLE')).toBeVisible();
  await expect(page.getByText('Active Focus sessions')).toBeVisible();
  await expect(page.getByText('One high-priority watch per focused browser session')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'FjordPulse → Entur request allowance' })).toBeVisible();
  await expect(page.getByText('Not used')).toBeVisible();
  await expect(page.getByText('The limits below are configured but inactive while FjordPulse uses demo data.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Open Entur request log' })).toHaveAttribute('href', '/admin/entur-log');
  await expect(page.getByRole('link', { name: /Entur Journey Planner rate-limit documentation/ })).toHaveAttribute('href', 'https://developer.entur.no/docs/open-services/journey-planner/rate-limiting');
  await page.getByText('Show configured limits for all Entur APIs').click();
  const allowanceTable = page.getByRole('table', { name: 'Internal FjordPulse-to-Entur request limits' });
  await expect(allowanceTable.getByRole('row')).toHaveCount(6);
  await expect(allowanceTable.getByText('Vehicle Positions')).toBeVisible();
  await expect(allowanceTable.getByText('ENTUR_GLOBAL_REQUESTS_PER_MINUTE')).toBeVisible();
  await expect(page.getByText('System operational', { exact: true })).toBeVisible();
  await expect(page.getByText('System degraded', { exact: true })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Host resources' })).toBeVisible();
  await expect(page.getByText('10.0 GiB free')).toBeVisible();
  await expect(page.getByText('330 GiB free')).toBeVisible();
  await expect(page.getByRole('progressbar')).toHaveCount(3);
  const eventPreview = page.locator('.admin-event-preview');
  await expect(eventPreview.getByRole('heading', { name: 'Latest persisted events' })).toBeVisible();
  await expect(eventPreview.locator('tbody tr')).toHaveCount(5);
  await expect(eventPreview.getByRole('button', { name: /Details for/ })).toHaveCount(0);
  await expect(eventPreview.getByRole('link', { name: 'Open full event history' })).toHaveAttribute('href', '/admin/events');
  const logout = page.getByRole('button', { name: 'Log out Fixture operator' });
  await expect(logout).toBeVisible();
  await expect(logout).toContainText('Log out');
  await page.goto('/__scenario/admin_watches');
  await expect(page.getByRole('heading', { name: 'Active watches' })).toBeVisible();
  await expect(page.getByText('Critical priority')).toBeVisible();
  await page.goto('/__scenario/admin_entur_log');
  await expect(page.getByRole('heading', { name: 'Entur request log' })).toBeVisible();
  await page.getByLabel('Status').selectOption('backoff');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await page.getByRole('button', { name: 'Log out Fixture operator' }).click();
  await expect(page).toHaveURL('/');
});

test('fixture browser traffic never leaves the local fixture origin', async ({ page }) => {
  const forbidden: string[] = [];
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if ((url.protocol === 'http:' || url.protocol === 'https:') && url.origin !== 'http://127.0.0.1:4173') {
      forbidden.push(route.request().url());
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  });
  page.on('request', (request) => {
    const url = new URL(request.url());
    if ((url.protocol === 'http:' || url.protocol === 'https:') && url.origin !== 'http://127.0.0.1:4173') {
      forbidden.push(request.url());
    }
  });
  page.on('websocket', (socket) => {
    const url = new URL(socket.url());
    if (url.hostname !== '127.0.0.1' || url.port !== '4173') forbidden.push(socket.url());
  });
  for (const id of ['desktop_default_map', 'desktop_station_fresh', 'desktop_vehicle_focus_following'] as const) {
    await page.goto(`/__scenario/${id}`);
    await expect(page.locator('.app-shell')).toHaveAttribute('data-scenario', id);
    await expect(page.locator('.map-region')).toHaveAttribute('data-basemap', 'fixture');
    await expect(page.locator('.map-region')).toHaveAttribute('data-map-state', 'ready');
  }
  expect(forbidden).toEqual([]);
});

test('primary public, mobile, and admin surfaces have no serious accessibility violations in either language', async ({ page }) => {
  // Axe runs on every primary route and both secondary station tabs in both
  // locales, so retain the full audit matrix with an appropriate CI budget.
  test.slow();
  const expectNoSeriousViolations = async (context: string) => {
    const audit = await new AxeBuilder({ page }).analyze();
    const violations = audit.violations
      .filter(({ impact }) => impact === 'critical' || impact === 'serious')
      .map(({ id, impact, nodes }) => ({
        id,
        impact,
        targets: nodes.map(({ target }) => target.join(' ')),
      }));
    expect(violations, `Accessibility violations in ${context}`).toEqual([]);
  };

  await page.goto('/__scenarios');
  for (const language of ['nb', 'en'] as const) {
    await page.evaluate(([storageKey, value]) => localStorage.setItem(storageKey, value), [LANGUAGE_STORAGE_KEY, language]);
    for (const route of [
      '/__scenario/desktop_default_map',
      '/__scenario/desktop_station_fresh',
      '/__scenario/desktop_vehicle_focus_following',
      '/__scenario/desktop_vehicle_non_passenger',
      '/__scenario/mobile_station_full_sheet',
      '/__scenario/mobile_vehicle_non_passenger',
      '/__scenario/admin_status',
    ]) {
      await page.goto(route);
      await expect(page.locator('[data-scenario], .admin-shell')).toBeVisible();
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expectNoSeriousViolations(`${language} on ${route}`);

      if (route === '/__scenario/desktop_station_fresh' || route === '/__scenario/mobile_station_full_sheet') {
        for (const tabName of language === 'nb' ? ['Kjøretøy', 'Detaljer'] : ['Vehicles', 'Details']) {
          await page.getByRole('tab', { name: tabName }).click();
          await expect(page.getByRole('tab', { name: tabName })).toHaveAttribute('aria-selected', 'true');
          await expectNoSeriousViolations(`${language} on ${route}, ${tabName} tab`);
        }
      }
    }
  }
});
