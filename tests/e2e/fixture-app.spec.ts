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
  await page.keyboard.press('/');
  const search = page.getByRole('searchbox', { name: 'Search for station, place, line, or vehicle' });
  await expect(search).toBeFocused();
  await search.fill('Førde');
  await expect(page.getByRole('option', { name: /Førde rutebilstasjon/ })).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Førde rutebilstasjon' })).toBeVisible();
  await expect(page.getByRole('complementary', { name: /station details/ })).toBeVisible();
});

test('station to vehicle to focus lifecycle remains interactive', async ({ page }) => {
  await page.goto('/__scenario/desktop_station_fresh');
  await page.getByRole('button', { name: 'Open Line 100 vehicle' }).first().click();
  await expect(page.getByRole('heading', { name: 'Line 100' })).toBeVisible();
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

test('fixture browser traffic never targets Entur or SurrealDB', async ({ page }) => {
  const forbidden: string[] = [];
  page.on('request', (request) => {
    const hostname = new URL(request.url()).hostname;
    if (hostname.includes('entur.io') || hostname === '127.0.0.1' && request.url().includes(':8000')) {
      forbidden.push(request.url());
    }
  });
  for (const id of ['desktop_default_map', 'desktop_station_fresh', 'desktop_vehicle_focus_following'] as const) {
    await page.goto(`/__scenario/${id}`);
    await expect(page.locator('.app-shell')).toHaveAttribute('data-scenario', id);
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
