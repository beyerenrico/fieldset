import { defineConfig, devices } from '@playwright/test';

// E2E journeys (BUILD_PROMPT §12) run against the Docker stack (`docker compose up -d`).
// Browsers live in storage/playwright (PLAYWRIGHT_BROWSERS_PATH), never in global caches.
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
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
  ],
});
