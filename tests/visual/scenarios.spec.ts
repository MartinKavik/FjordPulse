import { expect, test } from '@playwright/test';

const scenarios = [
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

for (const scenario of scenarios) {
  test(`${scenario} visual baseline`, async ({ page }) => {
    const mobile = scenario.startsWith('mobile_');
    await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1440, height: 900 });
    await page.goto(`/__scenario/${scenario}`, { waitUntil: 'networkidle' });
    await expect(page.locator('[data-scenario], .admin-shell, .design-board')).toBeVisible();
    await page.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }' });
    await page.evaluate(() => document.fonts.ready);
    await expect(page).toHaveScreenshot(`${scenario}.png`, { fullPage: scenario === 'design_system_components' });
  });
}
