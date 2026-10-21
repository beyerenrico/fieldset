import { defineConfig, devices } from '@playwright/test';

// E2E journeys (BUILD_PROMPT §12) run against the Docker stack (`docker compose up -d`).
// Browsers live in storage/playwright (PLAYWRIGHT_BROWSERS_PATH), never in global caches.

/**
 * The `fsa-instance` project's base URL: the phone trust page's origin (FIELDSET_PHONE_TRUST_URL, J6) or the instance URL
 * (FIELDSET_INSTANCE_URL, the smoke spec); nothing when neither is set.
 */
function fsaInstanceBaseUrl(): { baseURL?: string } {
  const page = process.env.FIELDSET_PHONE_TRUST_URL || process.env.FIELDSET_INSTANCE_URL;
  return page === undefined || page === '' ? {} : { baseURL: new URL(page).origin };
}

export default defineConfig({
  testDir: 'tests/e2e',
  outputDir: 'storage/playwright-results',
  timeout: 60_000,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
      testIgnore: /fsa-(phone-trust|smoke)\.spec\.ts/,
    },
    // A running `fsa` instance (plans U14, U15): no Docker stack, no sign-in setup. The instance's origin is the base
    // URL, so the CSP watcher in support/test.ts covers it; each spec skips without its environment.
    {
      name: 'fsa-instance',
      testMatch: /fsa-(phone-trust|smoke)\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], ...fsaInstanceBaseUrl() },
    },
  ],
});
