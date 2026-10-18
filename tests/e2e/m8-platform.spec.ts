import {
  continueAfterSetUp,
  createOperator,
  deleteUsers,
  newOperator,
  platformSignIn,
  setUpAuthenticator,
} from './support/platform';
import { test, expect } from './support/test';

// M8 /platform operator panel (BUILD_PROMPT §8.12, ADR 0041): a new operator signs in with the panel's own
// login, is sent to the required MFA set-up, then sees organizations and health and impersonates an admin
// member of the demo organization: the banner shows on /admin pages and "Exit" returns to /platform. The
// shared `test` fails on any CSP violation on these pages (ADR 0040). No operator is seeded, so the test
// creates a fresh user and grants the role with `php artisan app:platform-admin` in the app container, and
// deletes it afterwards.
const evidence = (name: string) => `docs/agent/milestones/evidence/M8/${name}.png`;
const OPERATOR = newOperator('ops');
const TARGET = 'owner@demo.test';

test.describe.serial('platform operator panel', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test.beforeAll(() => {
    createOperator(OPERATOR);
  });

  test.afterAll(() => {
    deleteUsers([OPERATOR.email]);
  });

  test('operator signs in with MFA set-up, sees organizations and health, impersonates and exits', async ({
    page,
  }) => {
    test.setTimeout(120_000);
    await platformSignIn(page, OPERATOR); // the panel's own login, then the required MFA set-up
    await setUpAuthenticator(page, OPERATOR);
    await page.screenshot({ path: evidence('platform-00-mfa-set-up') });
    await continueAfterSetUp(page);

    await page.goto('/platform/organizations');
    // The slug cell, exactly: "sso-demo" and "saml-demo" contain "demo" too.
    const demo = page.getByRole('row').filter({ has: page.getByRole('cell', { name: 'demo', exact: true }) });
    await expect(demo).toBeVisible();
    await page.screenshot({ path: evidence('platform-01-organizations'), fullPage: true });

    await page.goto('/platform/health');
    for (const check of [/queues|warteschlangen/i, /gotenberg/i, /mail/i, /scheduler|zeitplan/i, /disk|speicher/i]) {
      await expect(page.getByText(check).first()).toBeVisible();
    }
    await page.screenshot({ path: evidence('platform-02-health'), fullPage: true });

    await page.goto('/platform/organizations');
    await demo.getByRole('link', { name: /^\s*(members|mitglieder)\s*$/i }).click();
    await expect(page).toHaveURL(/\/members/);
    await page.waitForLoadState('networkidle'); // typing before Livewire hydrated loses the input
    // Through the table search: the list is paged and newest first, so the seeded owner is not always on page 1.
    const searched = page.waitForResponse(
      (response) => /\/livewire-[^/]+\/update/.test(response.url()) && (response.request().postData() ?? '').includes('tableSearch'),
    );
    await page.locator('.fi-ta-search-field input[type="search"]').fill(TARGET);
    await searched;
    const member = page.getByRole('row').filter({ has: page.getByRole('cell', { name: TARGET, exact: true }) });
    await member.getByRole('button', { name: /^\s*(impersonate|als mitglied anmelden)\s*$/i }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('textbox').fill(`E2E ${Date.now()}: check the dashboard for a support ticket`);
    await dialog.getByRole('button', { name: /(start impersonation|anmeldung als mitglied starten)/i }).click();

    await expect(page).toHaveURL(/\/admin\/demo/, { timeout: 20_000 });
    const banner = page.locator('[data-platform-impersonation-banner]');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText(TARGET);
    await expect(banner).toContainText(OPERATOR.email);
    await page.screenshot({ path: evidence('platform-03-impersonation-banner') });
    await page.goto('/admin/demo/forms'); // the banner is on every admin page
    await expect(banner).toBeVisible();

    await banner.locator('[data-platform-impersonation-exit]').click();
    await expect(page).toHaveURL(/\/platform(\/organizations)?\/?$/, { timeout: 20_000 });
    await expect(page.locator('[data-platform-impersonation-banner]')).toHaveCount(0);
  });
});
