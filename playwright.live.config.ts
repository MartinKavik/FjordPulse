import { defineConfig, devices } from "@playwright/test";

const frontendPort = Number(process.env.FJORDPULSE_LIVE_FRONTEND_PORT ?? "19073");
const frontendOrigin = `http://127.0.0.1:${frontendPort}`;

export default defineConfig({
  testDir: "./tests/live",
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  workers: 1,
  reporter: "line",
  outputDir: "test-results/playwright-live",
  timeout: 120_000,
  expect: { timeout: 10_000 },
  use: {
    ...devices["Desktop Chrome"],
    baseURL: frontendOrigin,
    // The clean-stack suite predates localization and deliberately exercises
    // its existing English assertions. Norwegian-default behavior and locale
    // persistence are covered by the fixture browser matrix.
    storageState: {
      cookies: [],
      origins: [{
        origin: frontendOrigin,
        localStorage: [{ name: "fjordpulse.locale.v1", value: "en" }],
      }],
    },
    viewport: { width: 1440, height: 900 },
    colorScheme: "dark",
    locale: "en-GB",
    timezoneId: "Europe/Oslo",
    reducedMotion: "reduce",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
  },
  webServer: {
    command: "node tests/live/support/stack.mjs",
    url: `http://127.0.0.1:${frontendPort}/api/health`,
    reuseExistingServer: false,
    timeout: 120_000,
  },
});
