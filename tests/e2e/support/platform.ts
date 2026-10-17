import { expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { freshTotp } from './totp';

// /platform operators for E2E (ADR 0041). None is seeded: each spec creates a fresh user in the app
// container and grants the role with `php artisan app:platform-admin`, so reruns without a reseed work.

export interface Operator {
  readonly name: string;
  readonly email: string;
  readonly password: string;
}

export function newOperator(prefix: string): Operator {
  return {
    name: 'Paula Plattform',
    email: `${prefix}-${Date.now()}@platform.test`,
    password: 'Plattform-Passwort-2026!',
  };
}

export function artisan(...args: string[]): string {
  // psysh (tinker) needs a writable config directory; the image's HOME is read-only.
  return execFileSync(
    'docker',
    ['compose', 'exec', '-T', '-e', 'XDG_CONFIG_HOME=/tmp', 'app', 'php', 'artisan', ...args],
    { encoding: 'utf8' },
  );
}

/** A fresh, verified user with the operator role (tinker: there is no "create user" command besides app:install). */
export function createOperator(operator: Operator): void {
  const attributes = JSON.stringify({ name: operator.name, email: operator.email, password: operator.password });
  artisan(
    'tinker',
    '--execute',
    `App\\Domain\\Identity\\Models\\User::query()->create(json_decode('${attributes}', true))` +
      `->forceFill(['email_verified_at' => now()])->save();`,
  );
  expect(artisan('app:platform-admin', operator.email)).toMatch(/granted/i);
}

export async function platformSignIn(page: Page, operator: Operator): Promise<void> {
  await page.goto('/platform/login');
  await page.waitForFunction(() => 'Livewire' in window); // typing before hydration loses the input
  await page.waitForLoadState('networkidle');
  await page.locator('input[type="email"]').fill(operator.email);
  await page.locator('input[type="password"]').fill(operator.password);
  await page.getByRole('button', { name: /^\s*(sign in|anmelden)\s*$/i }).click();
  await expect(page).toHaveURL(/\/platform\/.*multi-factor/i, { timeout: 20_000 });
}

/** Opens Filament's set-up dialog (required MFA) and confirms it with a code from its secret and the password. */
export async function setUpAuthenticator(page: Page, operator: Operator, beforeConfirm?: () => Promise<void>): Promise<void> {
  await page.getByRole('button', { name: /^\s*(set up|einrichten)\s*$/i }).click();
  const dialog = page.getByRole('dialog');
  const secret = dialog.locator('.fi-font-mono').filter({ hasText: /^\s*[A-Z2-7]{16,}\s*$/ });
  await expect(secret).toBeVisible();
  // Contrast checks need the end of the opening transition (opacity).
  await expect(dialog.locator('.fi-modal-window')).not.toHaveClass(/fi-transition-enter/);
  await beforeConfirm?.();
  const code = await freshTotp(((await secret.textContent()) ?? '').trim());
  await dialog.locator('input[autocomplete="one-time-code"]').fill(code);
  await dialog.locator('input[type="password"]').fill(operator.password);
  await dialog.getByRole('button', { name: /^\s*(next|weiter)\s*$/i }).click();
  await dialog.getByRole('button', { name: /(enable|aktivieren)/i }).click();
  await expect(page.getByText(/^\s*(enabled|aktiviert)\s*$/i)).toBeVisible();
}

export async function continueAfterSetUp(page: Page): Promise<void> {
  const name = /^\s*(continue|weiter)\s*$/i;
  await page.getByRole('link', { name }).or(page.getByRole('button', { name })).click();
  // /platform redirects to its first page, the organizations.
  await expect(page).toHaveURL(/\/platform(\/organizations)?\/?$/, { timeout: 20_000 });
}
