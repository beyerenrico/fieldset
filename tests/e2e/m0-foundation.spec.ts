import { test, expect } from '@playwright/test';
import { adminLogin, adminState } from './support/auth';

// M0 demo: an owner signs in, opens the tenant dashboard and the team pages; a field worker is
// kept out of /admin. Requires the demo seed (`php artisan db:seed`). Screenshots are evidence.
const evidence = (name: string) => `docs/agent/milestones/evidence/M0/${name}.png`;

test.describe('owner', () => {
  // Signed in by the setup project (auth.setup.ts) through the same login form.
  test.use({ storageState: adminState('owner') });

  test('owner reaches the dashboard and team management', async ({ page }) => {
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin\/demo/);
    await page.screenshot({
      path: evidence('01-owner-dashboard'),
      fullPage: true,
    });

    await page.goto('/admin/demo/members');
    await expect(page.getByText('willi', { exact: false }).first()).toBeVisible();
    await page.screenshot({ path: evidence('02-members'), fullPage: true });

    await page.goto('/admin/demo/roles');
    await page.screenshot({ path: evidence('03-roles'), fullPage: true });

    await page.goto('/admin/demo/profile');
    await page.screenshot({
      path: evidence('04-organization-settings'),
      fullPage: true,
    });
  });
});

test('field worker is kept out of the admin panel', async ({ page }) => {
  // Filament refuses the login itself (canAccessPanel = false), so the worker stays on the form.
  await adminLogin(page, 'worker@demo.test');
  await expect(page.getByText(/credentials do not match|Zugangsdaten/i)).toBeVisible();
  await page.screenshot({
    path: evidence('05-worker-login-refused'),
    fullPage: true,
  });
  await page.goto('/admin/demo');
  await expect(page).toHaveURL(/\/admin\/login/);
  await page.screenshot({
    path: evidence('05-worker-forbidden'),
    fullPage: true,
  });
});

test('health check answers', async ({ request }) => {
  const response = await request.get('/up');
  expect(response.ok()).toBeTruthy();
});
