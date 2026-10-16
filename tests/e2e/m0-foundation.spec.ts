import { test, expect, type Page } from '@playwright/test';

// M0 demo: an owner signs in, opens the tenant dashboard and the team pages; a field worker is
// kept out of /admin. Requires the demo seed (`php artisan db:seed`). Screenshots are evidence.
const evidence = (name: string) => `docs/agent/milestones/evidence/M0/${name}.png`;

async function login(page: Page, email: string) {
  await page.goto('/admin/login');
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[autocomplete="current-password"]').fill('password');
  await page.getByRole('button', { name: /sign in|anmelden/i }).click();
}

test('owner reaches the dashboard and team management', async ({ page }) => {
  await login(page, 'owner@demo.test');
  await expect(page).toHaveURL(/\/admin\/demo/);
  await page.screenshot({ path: evidence('01-owner-dashboard'), fullPage: true });

  await page.goto('/admin/demo/members');
  await expect(page.getByText('willi', { exact: false }).first()).toBeVisible();
  await page.screenshot({ path: evidence('02-members'), fullPage: true });

  await page.goto('/admin/demo/roles');
  await page.screenshot({ path: evidence('03-roles'), fullPage: true });

  await page.goto('/admin/demo/profile');
  await page.screenshot({ path: evidence('04-organization-settings'), fullPage: true });
});

test('field worker is kept out of the admin panel', async ({ page }) => {
  // Filament refuses the login itself (canAccessPanel = false), so the worker stays on the form.
  await login(page, 'worker@demo.test');
  await expect(page.getByText(/credentials do not match|Zugangsdaten/i)).toBeVisible();
  await page.screenshot({ path: evidence('05-worker-login-refused'), fullPage: true });
  await page.goto('/admin/demo');
  await expect(page).toHaveURL(/\/admin\/login/);
  await page.screenshot({ path: evidence('05-worker-forbidden'), fullPage: true });
});

test('health check answers', async ({ request }) => {
  const response = await request.get('/up');
  expect(response.ok()).toBeTruthy();
});
