import { test, expect, type BrowserContext } from './support/test';

// Smoke E2E of a freshly installed `fieldset` instance (BUILD_PROMPT_V2 §11 J1/J10, plan U15), project `fieldset-instance`
// only (never part of the desktop run). Everything comes from the environment:
// - FIELDSET_INSTANCE_URL: the instance URL, e.g. https://fieldset-test-j10.test (unset: all tests skip)
// - FIELDSET_SMOKE_LINK: the owner's one-time sign-in link from `fieldset install --secrets-file`
// - FIELDSET_SMOKE_PASSWORD: the password the owner sets through that link
// - FIELDSET_CHROMIUM_ARGS: a JSON array of extra Chromium flags, e.g. host resolver rules for a test domain and
//   --ignore-certificate-errors-spki-list for a test CA's chain (optional)
// The tests run in order in one context: the link signs the owner in, the next tests reuse that session.

const INSTANCE_URL = process.env.FIELDSET_INSTANCE_URL ?? '';
const LINK = process.env.FIELDSET_SMOKE_LINK ?? '';
const PASSWORD = process.env.FIELDSET_SMOKE_PASSWORD ?? '';
const CHROMIUM_ARGS: string[] = JSON.parse(process.env.FIELDSET_CHROMIUM_ARGS ?? '[]') as string[];

test.skip(INSTANCE_URL === '' || LINK === '' || PASSWORD === '', 'FIELDSET_INSTANCE_URL, FIELDSET_SMOKE_LINK and FIELDSET_SMOKE_PASSWORD are needed: these tests run against a fieldset instance.');

test.use({ launchOptions: { args: CHROMIUM_ARGS }, storageState: { cookies: [], origins: [] } });

type StorageState = Awaited<ReturnType<BrowserContext['storageState']>>;

test.describe.serial('fresh fieldset instance', () => {
  let ownerSession: StorageState | undefined;

  test('the owner link leads to setting a password', async ({ browser }) => {
    const context = await browser.newContext({ baseURL: INSTANCE_URL });
    const page = await context.newPage();

    await page.goto(LINK);
    await expect(page).toHaveURL(/\/admin\/password-reset\/reset\?/, { timeout: 30_000 });
    await page.waitForFunction(() => 'Livewire' in window);
    await page.waitForLoadState('networkidle');
    const passwords = page.locator('input[type="password"]');
    await passwords.nth(0).fill(PASSWORD);
    await passwords.nth(1).fill(PASSWORD);
    await page.getByRole('button', { name: /(reset password|passwort zurücksetzen)/i }).click();
    await expect(page).toHaveURL(/\/admin\/profile/, { timeout: 30_000 });
    ownerSession = await context.storageState();
    await context.close();
  });

  test('the owner reaches the dashboard with the capture banner and opens the forms list', async ({ browser }) => {
    const context = await browser.newContext({ baseURL: INSTANCE_URL, storageState: ownerSession });
    const page = await context.newPage();

    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin\/[a-z0-9-]+$/, { timeout: 30_000 });
    await expect(page.locator('[data-capture-banner="full"]')).toBeVisible();
    await page.goto(`${new URL(page.url()).pathname}/forms`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await context.close();
  });

  test('the Field App loads over trusted HTTPS and registers its service worker', async ({ browser }) => {
    const context = await browser.newContext({ baseURL: INSTANCE_URL });
    const page = await context.newPage();

    await page.goto('/app/');
    await expect(page.getByLabel(/^(email|e-mail)$/i)).toBeVisible({ timeout: 30_000 });
    const scope = await page.evaluate(async () => (await navigator.serviceWorker.ready).scope);
    expect(scope).toBe(`${INSTANCE_URL}/app/`);
    await context.close();
  });
});
