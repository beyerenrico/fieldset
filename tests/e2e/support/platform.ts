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

/**
 * The Compose project whose app container runs artisan (plan U22, BUILD_PROMPT_V2 §11 dogfooding): an `fsa` instance's
 * (e.g. `fsa-fsa-test-dogfood-1a2b`), or the dev stack's when Playwright runs outside the checkout. Unset or empty:
 * `docker compose exec` in the working directory, the checkout's own project.
 */
export const E2E_COMPOSE_PROJECT = process.env.E2E_COMPOSE_PROJECT ?? '';

let instanceApp: string | undefined;

/** The running app container of the instance's project (by Compose labels; never a one-off). */
function instanceAppContainer(): string {
  instanceApp ??= execFileSync(
    'docker',
    [
      'ps',
      '-q',
      '--filter',
      `label=com.docker.compose.project=${E2E_COMPOSE_PROJECT}`,
      '--filter',
      'label=com.docker.compose.service=app',
      '--filter',
      'label=com.docker.compose.oneoff=False',
    ],
    { encoding: 'utf8' },
  ).trim();
  if (!/^[0-9a-f]+$/.test(instanceApp)) {
    throw new Error(`E2E_COMPOSE_PROJECT=${E2E_COMPOSE_PROJECT}: expected one running app container, found "${instanceApp}"`);
  }
  return instanceApp;
}

export function artisan(...args: string[]): string {
  // psysh (tinker) needs a writable config directory; the image's HOME is read-only.
  const target =
    E2E_COMPOSE_PROJECT === ''
      ? ['compose', 'exec', '-T', '-e', 'XDG_CONFIG_HOME=/tmp', 'app']
      : ['exec', '-e', 'XDG_CONFIG_HOME=/tmp', instanceAppContainer()];
  return execFileSync('docker', [...target, 'php', 'artisan', ...args], { encoding: 'utf8' });
}

/**
 * Deletes users a spec created, by exact email; memberships, operator grants and sign-in links go with them
 * (ON DELETE CASCADE). Specs call it in afterAll: the dev database is not reseeded between runs, and
 * leftover demo members push seeded ones off the first page of member tables.
 */
export function deleteUsers(emails: readonly string[]): void {
  if (emails.length === 0) return;
  artisan(
    'tinker',
    '--execute',
    `App\\Domain\\Identity\\Models\\User::query()->whereIn('email', json_decode('${JSON.stringify(emails)}', true))` +
      `->get()->each(fn ($user) => $user->delete());`,
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
