import { expect, test } from '@playwright/test';

const languageStorageKey = 'fjordpulse.locale.v1';
const languages = ['nb', 'en'] as const;

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
  'admin_infrastructure',
  'admin_watches',
  'admin_entur_log',
  'design_system_components',
] as const;

for (const language of languages) {
  for (const scenario of scenarios) {
    test(`${scenario} ${language} visual baseline`, async ({ page }) => {
      await page.addInitScript(({ key, value }) => {
        window.localStorage.setItem(key, value);
      }, { key: languageStorageKey, value: language });
      const externalRequests: string[] = [];
      await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if ((url.protocol === 'http:' || url.protocol === 'https:') && url.origin !== 'http://127.0.0.1:4173') {
          externalRequests.push(route.request().url());
          await route.abort('blockedbyclient');
          return;
        }
        await route.continue();
      });
      const mobile = scenario.startsWith('mobile_');
      await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1440, height: 900 });
      await page.goto(`/__scenario/${scenario}`, { waitUntil: 'networkidle' });
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expect(page.locator('[data-scenario], .admin-shell, .design-board')).toBeVisible();
      if (!scenario.startsWith('admin_') && scenario !== 'design_system_components') {
        await expect(page.locator('.map-region')).toHaveAttribute('data-map-state', 'ready');
      }
      await page.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }' });
      await page.evaluate(() => document.fonts.ready);
      const fontState = await page.evaluate(() => {
        const loadedFamilies: string[] = [];
        document.fonts.forEach((font) => {
          if (font.status === 'loaded') loadedFamilies.push(font.family);
        });
        return {
          rootFamily: getComputedStyle(document.documentElement).fontFamily,
          loadedFamilies,
        };
      });
      expect(fontState.rootFamily).toContain('Inter Variable');
      expect(fontState.loadedFamilies).toContain('Inter Variable');
      const snapshotName = language === 'nb' ? `${scenario}.png` : `${scenario}.en.png`;
      await expect(page).toHaveScreenshot(snapshotName, { fullPage: scenario === 'design_system_components' });
      expect(externalRequests).toEqual([]);
    });
  }
}

const mobileAdminStates = [
  { scenario: 'admin_status', suffix: 'mobile', openMenu: false, scrollTarget: null },
  { scenario: 'admin_infrastructure', suffix: 'mobile', openMenu: false, scrollTarget: '#host-resources-heading' },
  { scenario: 'admin_infrastructure', suffix: 'mobile_menu', openMenu: true, scrollTarget: null },
] as const;

for (const language of languages) {
  for (const state of mobileAdminStates) {
    test(`${state.scenario} ${state.suffix} ${language} visual baseline`, async ({ page }) => {
      await page.addInitScript(({ key, value }) => {
        window.localStorage.setItem(key, value);
      }, { key: languageStorageKey, value: language });
      const externalRequests: string[] = [];
      await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if ((url.protocol === 'http:' || url.protocol === 'https:') && url.origin !== 'http://127.0.0.1:4173') {
          externalRequests.push(route.request().url());
          await route.abort('blockedbyclient');
          return;
        }
        await route.continue();
      });
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto(`/__scenario/${state.scenario}`, { waitUntil: 'networkidle' });
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expect(page.locator('.admin-shell')).toBeVisible();
      if (state.openMenu) {
        await page.getByRole('button', { name: language === 'nb' ? 'Meny' : 'Menu', exact: true }).click();
        await expect(page.getByLabel(language === 'nb' ? 'Administrasjonsmeny' : 'Admin menu', { exact: true })).toHaveClass(/is-open/);
      }
      if (state.scrollTarget !== null) {
        await page.locator(state.scrollTarget).scrollIntoViewIfNeeded();
      }
      await page.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }' });
      await page.evaluate(() => document.fonts.ready);
      const snapshotName = language === 'nb'
        ? `${state.scenario}_${state.suffix}.png`
        : `${state.scenario}_${state.suffix}.en.png`;
      await expect(page).toHaveScreenshot(snapshotName);
      expect(externalRequests).toEqual([]);
    });
  }
}

const stationTabVisuals = [
  { scenario: 'desktop_station_fresh', tab: 'vehicles', labels: { nb: 'Kjøretøy', en: 'Vehicles' } },
  { scenario: 'desktop_station_fresh', tab: 'details', labels: { nb: 'Detaljer', en: 'Details' } },
  { scenario: 'mobile_station_full_sheet', tab: 'vehicles', labels: { nb: 'Kjøretøy', en: 'Vehicles' } },
  { scenario: 'mobile_station_full_sheet', tab: 'details', labels: { nb: 'Detaljer', en: 'Details' } },
] as const;

for (const language of languages) {
  for (const state of stationTabVisuals) {
    test(`${state.scenario} ${state.tab} ${language} visual baseline`, async ({ page }) => {
      await page.addInitScript(({ key, value }) => {
        window.localStorage.setItem(key, value);
      }, { key: languageStorageKey, value: language });
      const externalRequests: string[] = [];
      await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if ((url.protocol === 'http:' || url.protocol === 'https:') && url.origin !== 'http://127.0.0.1:4173') {
          externalRequests.push(route.request().url());
          await route.abort('blockedbyclient');
          return;
        }
        await route.continue();
      });
      const mobile = state.scenario.startsWith('mobile_');
      await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1440, height: 900 });
      await page.goto(`/__scenario/${state.scenario}`, { waitUntil: 'networkidle' });
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expect(page.locator('[data-scenario]')).toBeVisible();
      await expect(page.locator('.map-region')).toHaveAttribute('data-map-state', 'ready');
      await page.getByRole('tab').filter({ hasText: state.labels[language] }).click();
      await expect(page.getByRole('tab').filter({ hasText: state.labels[language] })).toHaveAttribute('aria-selected', 'true');
      await page.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }' });
      await page.evaluate(() => document.fonts.ready);
      const fontState = await page.evaluate(() => {
        const loadedFamilies: string[] = [];
        document.fonts.forEach((font) => {
          if (font.status === 'loaded') loadedFamilies.push(font.family);
        });
        return {
          rootFamily: getComputedStyle(document.documentElement).fontFamily,
          loadedFamilies,
        };
      });
      expect(fontState.rootFamily).toContain('Inter Variable');
      expect(fontState.loadedFamilies).toContain('Inter Variable');
      const snapshotName = language === 'nb'
        ? `${state.scenario}_${state.tab}.png`
        : `${state.scenario}_${state.tab}.en.png`;
      await expect(page).toHaveScreenshot(snapshotName);
      expect(externalRequests).toEqual([]);
    });
  }
}
