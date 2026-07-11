import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

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
  'desktop_degraded_fallback',
  'desktop_search_results',
  'desktop_search_empty',
  'mobile_default_map',
  'mobile_station_sheet',
  'mobile_station_full_sheet',
  'mobile_vehicle_focus',
  'mobile_vehicle_lost',
  'admin_status',
  'admin_watches',
  'admin_entur_log',
  'design_system_components',
] as const;

test('scenario gallery exposes every approved deterministic state', async ({ page }) => {
  await page.goto('/__scenarios');
  await expect(page.getByRole('heading', { name: 'FjordPulse scenario gallery' })).toBeVisible();
  const links = page.locator('.scenario-grid a');
  await expect(links).toHaveCount(visualIds.length);
  for (const id of visualIds) {
    await expect(page.locator(`a[href="/__scenario/${id}"]`)).toBeVisible();
  }
});

test('keyboard search opens, navigates, and selects an authoritative fixture station', async ({ page }) => {
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

test('welcome panel frees the map, remembers explicit choices, and never replaces selected details', async ({ page }) => {
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
  await expect(page.getByRole('complementary', { name: /vehicle details/ })).toBeVisible();
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

test('station to vehicle to focus lifecycle remains interactive', async ({ page }) => {
  await page.goto('/__scenario/desktop_station_fresh');
  await page.getByRole('button', { name: 'Open Line 100 vehicle' }).first().click();
  await expect(page.getByRole('heading', { name: 'Line 100' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Selected Line 100 vehicle' })).toBeVisible();
  await page.getByRole('button', { name: 'Focus this vehicle' }).click();
  await expect(page.getByText('Following Line 100')).toBeVisible();
  await page.getByRole('button', { name: 'Pause follow' }).click();
  await expect(page.getByText('Follow paused')).toBeVisible();
  await page.getByRole('button', { name: 'Resume follow' }).click();
  await expect(page.getByText('Following Line 100')).toBeVisible();
  await page.getByRole('button', { name: 'Unfocus' }).first().click();
  await expect(page.getByRole('button', { name: 'Focus this vehicle' })).toBeVisible();
});

test('mobile station sheet expands and remains usable without location permission', async ({ page, context }) => {
  await context.grantPermissions([], { origin: 'http://127.0.0.1:4173' });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/__scenario/mobile_station_sheet');
  await expect(page.getByRole('complementary', { name: /station details/ })).toBeVisible();
  await page.getByRole('button', { name: 'Expand station sheet' }).click();
  await expect(page.getByRole('button', { name: 'Collapse station sheet' })).toBeVisible();
  await expect(page.getByText('Nearby vehicles')).toBeVisible();
});

test('admin fixtures expose status, watch, and Entur diagnostics', async ({ page }) => {
  await page.goto('/__scenario/admin_status');
  await expect(page.getByRole('heading', { name: 'System status' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Service dependencies' })).toBeVisible();
  await page.goto('/__scenario/admin_watches');
  await expect(page.getByRole('heading', { name: 'Active watches' })).toBeVisible();
  await expect(page.getByText('Critical priority')).toBeVisible();
  await page.goto('/__scenario/admin_entur_log');
  await expect(page.getByRole('heading', { name: 'Entur request log' })).toBeVisible();
  await page.getByLabel('Status').selectOption('backoff');
  await expect(page.locator('tbody tr')).toHaveCount(1);
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

test('primary public, mobile, and admin surfaces have no serious accessibility violations', async ({ page }) => {
  for (const route of [
    '/__scenario/desktop_default_map',
    '/__scenario/desktop_station_fresh',
    '/__scenario/desktop_vehicle_focus_following',
    '/__scenario/mobile_station_full_sheet',
    '/__scenario/admin_status',
  ]) {
    await page.goto(route);
    await expect(page.locator('[data-scenario], .admin-shell')).toBeVisible();
    const audit = await new AxeBuilder({ page }).analyze();
    const violations = audit.violations
      .filter(({ impact }) => impact === 'critical' || impact === 'serious')
      .map(({ id, impact, nodes }) => ({
        id,
        impact,
        targets: nodes.map(({ target }) => target.join(' ')),
      }));
    expect(violations, `Accessibility violations on ${route}`).toEqual([]);
  }
});
