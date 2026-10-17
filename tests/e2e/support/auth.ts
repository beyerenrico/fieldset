import { expect, type Page } from '@playwright/test';

// Filament throttles admin logins per IP (5/min). The setup project signs each admin user in once
// and the journeys reuse the saved session, so the suite stays below the limit.
export const adminState = (user: 'owner' | 'designer') => `storage/playwright-auth/${user}.json`;

/**
 * Password sign-in to /admin. The login is email-first (M7, ADR 0036): email → "Continue" (home-realm
 * discovery) → password. Users on a domain with SSO would be sent to their identity provider instead,
 * so this helper is for password users. Without a "Continue" button (older login) it fills both
 * fields at once.
 */
export async function adminLogin(page: Page, email: string): Promise<void> {
  await page.goto('/admin/login');
  // Typing before Livewire has hydrated the form loses the input under load.
  await page.waitForFunction(() => 'Livewire' in window);
  await page.waitForLoadState('networkidle');
  const password = page.locator('input[autocomplete="current-password"]');
  await page.locator('input[type="email"]').fill(email);

  const continueButton = page.getByRole('button', { name: /^\s*(continue|weiter)\s*$/i });
  if ((await continueButton.count()) > 0) {
    await continueButton.click();
    await expect(password).toBeVisible();
  }

  await password.fill('password');
  await expect(password).toHaveValue('password');
  await page.getByRole('button', { name: /^\s*(sign in|anmelden)\s*$/i }).click();
}

export async function expectTenantDashboard(page: Page): Promise<void> {
  await expect(page).toHaveURL(/\/admin\/demo/);
}
