import { defineConfig, devices } from '@playwright/test';

// E2E journeys (BUILD_PROMPT §12) run against the Docker stack (`docker compose up -d`).
// Browsers live in storage/playwright (PLAYWRIGHT_BROWSERS_PATH), never in global caches.

/** The phone trust page's origin as the `fsa-instance` project's base URL; nothing when FSA_PHONE_TRUST_URL is unset. */
function fsaInstanceBaseUrl(): { baseURL?: string } {
  const page = process.env.FSA_PHONE_TRUST_URL;
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
      testIgnore: /fsa-phone-trust\.spec\.ts/,
    },
    // A running `fsa` instance in local-CA mode (plan U14): no Docker stack, no sign-in setup. The page's origin is
    // the base URL, so the CSP watcher in support/test.ts covers it; the spec skips without FSA_PHONE_TRUST_URL.
    {
      name: 'fsa-instance',
      testMatch: /fsa-phone-trust\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], ...fsaInstanceBaseUrl() },
    },
  ],
});
