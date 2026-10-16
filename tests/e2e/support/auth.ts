import { expect, type Page } from '@playwright/test';

// Filament throttles admin logins per IP (5/min). The setup project signs each admin user in once
// and the journeys reuse the saved session, so the suite stays below the limit.
export const adminState = (user: 'owner' | 'designer') => `storage/playwright-auth/${user}.json`;

export async function adminLogin(page: Page, email: string): Promise<void> {
  await page.goto('/admin/login');
  // Typing before Livewire has hydrated the form loses the input under load.
  await page.waitForFunction(() => 'Livewire' in window);
  await page.waitForLoadState('networkidle');
  const password = page.locator('input[autocomplete="current-password"]');
  await page.locator('input[type="email"]').fill(email);
  await password.fill('password');
  await expect(password).toHaveValue('password');
  await page.getByRole('button', { name: /sign in|anmelden/i }).click();
}

export async function expectTenantDashboard(page: Page): Promise<void> {
  await expect(page).toHaveURL(/\/admin\/demo/);
}
