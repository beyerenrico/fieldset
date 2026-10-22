import { adminState } from './support/auth';
import {
  continueAfterSetUp,
  createOperator,
  deleteUsers,
  newOperator,
  platformSignIn,
  setUpAuthenticator,
} from './support/platform';
import { ON_DEV_STACK } from './support/target';
import { test, expect, type Locator, type Page } from './support/test';
import { expectClassicBuilderPage } from './support/classic-builder';

// M8-T03 verification (ADR 0040): every journey imports the guarded `test` (support/test.ts), which fails on
// any CSP violation or uncaught page error. This spec proves the guard itself and covers the surfaces no
// other journey opens: Horizon, and the canvas builder (now the default, ADR 0044) with its sandboxed
// preview rendering a form. /platform and the impersonation banner are in m8-platform.spec.ts.

/** Operators the Horizon test created on a fieldset instance (none on the dev stack). */
const operators: string[] = [];
test.afterAll(() => deleteUsers(operators));

test('the guard reports an injected inline script and an uncaught error', async ({ page, browserProblems }) => {
  await page.goto('/admin/login');
  await page.evaluate(() => {
    const script = document.createElement('script');
    script.textContent = 'window.__e2eInjected = true;';
    document.body.append(script);
    setTimeout(() => {
      throw new Error('e2e guard probe');
    }, 0);
  });
  await expect
    .poll(() => browserProblems.list().map((problem) => problem.kind))
    .toEqual(expect.arrayContaining(['csp-violation', 'page-error']));
  // The nonce-less script was blocked, not run.
  expect(await page.evaluate(() => (window as unknown as Record<string, unknown>).__e2eInjected)).toBeUndefined();
  // Provoked on purpose: accepted, so the fixture's own check stays green.
  browserProblems.allow(/script-src|e2e guard probe/);
});

test('Horizon renders under its CSP', async ({ page }) => {
  // Open to everyone in APP_ENV=local (Horizon's gate). A fieldset instance runs in production, where Horizon sits behind
  // the operator's /platform session (ADR 0041 amendment, plan U6): sign in as a fresh operator with MFA first.
  if (!ON_DEV_STACK) {
    test.setTimeout(120_000);
    const operator = newOperator('e2e-horizon');
    operators.push(operator.email);
    createOperator(operator);
    await platformSignIn(page, operator);
    await setUpAuthenticator(page, operator);
    await continueAfterSetUp(page);
  }
  await page.goto('/horizon/dashboard');
  await expect(page.getByText(/^\s*overview\s*$/i).first()).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(/jobs per minute/i).first()).toBeVisible();
});

/** A new form from the service report template; returns its row in the forms list, found by its name. */
async function newFormRow(page: Page, name: string): Promise<Locator> {
  await page.goto('/admin/demo/forms');
  await page.getByRole('button', { name: /from template|aus vorlage|vorlage/i }).click();
  const dialog = page.getByRole('dialog');
  await dialog.locator('select').first().selectOption('service_report');
  await dialog.locator('input[type="text"]').last().fill(name);
  await dialog.getByRole('button', { name: /submit|absenden|erstellen|create/i }).first().click();
  await expect(page).toHaveURL(/\/forms\/\d+\/canvas/, { timeout: 30_000 }); // new forms open in the canvas
  await page.goto('/admin/demo/forms');
  await page.locator('.fi-ta').getByRole('searchbox').fill(name); // the table's search, not the global one
  const row = page.getByRole('row').filter({ has: page.getByRole('cell', { name, exact: true }) });
  await expect(row).toHaveCount(1);
  return row;
}

test.describe('designer', () => {
  test.use({ storageState: adminState('designer') });

  test('the forms list opens the canvas builder, whose sandboxed preview renders the form', async ({ page }) => {
    const name = `CSP Vorschau ${Date.now()}`;
    await (await newFormRow(page, name)).getByRole('link').first().click();
    await expect(page).toHaveURL(/\/forms\/\d+\/canvas/, { timeout: 30_000 });
    await expect(page.locator('[data-builder-root]')).toHaveAttribute('data-builder-ready', '', { timeout: 15_000 });

    const preview = page.locator('iframe[data-builder-preview]');
    await expect(preview).toHaveAttribute('sandbox', 'allow-scripts'); // opaque origin (QUESTIONS #10)
    const frame = page.frameLocator('iframe[data-builder-preview]');
    await expect(frame.getByRole('heading', { name }).first()).toBeVisible({ timeout: 15_000 });
    await expect(frame.getByRole('textbox', { name: /^Kunde/ })).toBeVisible();
  });

  test('the classic builder stays one row action away', async ({ page }) => {
    const row = await newFormRow(page, `CSP Klassisch ${Date.now()}`);
    await row.getByRole('link', { name: /^\s*(classic|klassischer) (builder|editor)\s*$/i }).click();
    await expect(page).toHaveURL(/\/forms\/\d+\/edit/, { timeout: 30_000 });
    await expectClassicBuilderPage(page);
    await expect(page.getByRole('tab', { name: /formulareinstellungen|form settings/i })).toBeVisible({ timeout: 30_000 });
  });
});
