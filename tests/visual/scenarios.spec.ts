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
  'admin_database',
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

for (const language of languages) {
  test(`admin_database migrations ${language} visual baseline`, async ({ page }) => {
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
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/__scenario/admin_database?databaseView=migrations', { waitUntil: 'networkidle' });
    await expect(page.locator('html')).toHaveAttribute('lang', language);
    await expect(page.locator('.admin-shell')).toBeVisible();
    await expect(page.locator('.migration-state')).toHaveCount(5);
    await page.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }' });
    await page.evaluate(() => document.fonts.ready);
    const snapshotName = language === 'nb' ? 'admin_database_migrations.png' : 'admin_database_migrations.en.png';
    await expect(page).toHaveScreenshot(snapshotName);
    expect(externalRequests).toEqual([]);
  });
}

const mobileAdminStates = [
  { route: 'admin_status', snapshot: 'admin_status_mobile', openMenu: false, openDisclosure: null, scrollTarget: null },
  { route: 'admin_infrastructure', snapshot: 'admin_infrastructure_mobile', openMenu: false, openDisclosure: null, scrollTarget: '#host-resources-heading' },
  { route: 'admin_infrastructure', snapshot: 'admin_infrastructure_mobile_menu', openMenu: true, openDisclosure: null, scrollTarget: null },
  { route: 'admin_database', snapshot: 'admin_database_mobile', openMenu: false, openDisclosure: '.schema-table-disclosure', scrollTarget: '.schema-table-disclosure[open]' },
  { route: 'admin_database?databaseView=migrations', snapshot: 'admin_database_migrations_mobile', openMenu: false, openDisclosure: null, scrollTarget: '.migration-disclosure.state-failed' },
] as const;

for (const language of languages) {
  for (const state of mobileAdminStates) {
    test(`${state.snapshot} ${language} visual baseline`, async ({ page }) => {
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
      await page.goto(`/__scenario/${state.route}`, { waitUntil: 'networkidle' });
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expect(page.locator('.admin-shell')).toBeVisible();
      if (state.openMenu) {
        await page.getByRole('button', { name: language === 'nb' ? 'Meny' : 'Menu', exact: true }).click();
        await expect(page.getByLabel(language === 'nb' ? 'Administrasjonsmeny' : 'Admin menu', { exact: true })).toHaveClass(/is-open/);
      }
      if (state.openDisclosure !== null) {
        const disclosure = page.locator(state.openDisclosure).first();
        await disclosure.locator('summary').click();
        await expect(disclosure).toHaveAttribute('open', '');
      }
      if (state.scrollTarget !== null) {
        await page.locator(state.scrollTarget).scrollIntoViewIfNeeded();
      }
      await page.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }' });
      await page.evaluate(() => document.fonts.ready);
      const snapshotName = language === 'nb'
        ? `${state.snapshot}.png`
        : `${state.snapshot}.en.png`;
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
