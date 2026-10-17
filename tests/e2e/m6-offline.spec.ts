import { test, expect, devices, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { adminState } from './support/auth';
import { fieldAppLogin } from './support/field-app';
import {
  apiGet,
  countRows,
  fillServiceReport,
  startServiceReport,
  submissionsFor,
  submitFromReview,
  tableKeys,
  waitForOfflineReady,
  type ApiSubmission,
} from './support/offline';

// M6 offline-first (BUILD_PROMPT §9.2, §9.3, §12 journeys 3 and 7; ADR 0035). Each test uses its own
// phone context; the service worker serves the app shell offline and the Dexie store keeps the work.
const evidence = (name: string) => `docs/agent/milestones/evidence/M6/${name}.png`;
const NUMBER = /SR-\d{4}-\d{5}/;
const QUEUED = /ready to send|bereit zum senden|number pending|nummer ausstehend/i;
const SAVED_LOCAL = /saved on this device|auf diesem gerät gespeichert/i;

async function phone(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  // An explicit empty state: inside a describe with an admin storageState, newContext would inherit it.
  const context = await browser.newContext({
    ...devices['Pixel 7'],
    storageState: { cookies: [], origins: [] },
  });
  return { context, page: await context.newPage() };
}

function uuidFrom(page: Page): string {
  const match = /submissions\/([0-9a-f-]{36})/.exec(page.url());
  if (match === null) throw new Error(`no submission uuid in ${page.url()}`);
  return match[1];
}

test.describe('offline-first field app', () => {
  test.use({ actionTimeout: 30_000 }); // fail at the step, not at the test timeout

  test('journey 3: offline fill with 30 lines, a photo and a signature syncs exactly once', async ({ browser }) => {
    test.setTimeout(300_000);
    const customer = `Offline ${Date.now()} GmbH`;
    const { context, page } = await phone(browser);
    await fieldAppLogin(page, 'worker@demo.test');
    await waitForOfflineReady(page);

    await context.setOffline(true);
    await page.reload(); // app shell from the service worker, session from the device
    await expect(page.getByText(/^(hello|hallo), /i)).toBeVisible();
    await page.screenshot({ path: evidence('01-offline-home') });

    await fillServiceReport(page, { customer, lines: 30, photo: true });
    expect(await countRows(page, 'attachments')).toBe(2); // photo + signature, only on the device
    // 30 × 18.51 = 555.30 net; VAT 105.51; gross 660.81, calculated on the device.
    await expect(page.getByText(/660,81/).first()).toBeVisible();
    await submitFromReview(page);
    await expect(page.getByText(QUEUED).first()).toBeVisible({ timeout: 20_000 });
    await page.screenshot({ path: evidence('02-queued-offline'), fullPage: true });

    await context.setOffline(false);
    await expect(page.getByText(NUMBER).first()).toBeVisible({ timeout: 90_000 });
    await page.screenshot({ path: evidence('03-synced-number'), fullPage: true });

    const uuid = uuidFrom(page);
    const { submission } = await apiGet<{ submission: ApiSubmission }>(page, `/submissions/${uuid}`);
    expect(submission.number).toMatch(NUMBER);
    expect(submission.attachments).toHaveLength(2);
    expect((submission.data.items as unknown[]).length).toBe(30);
    expect(Number(submission.data.gross_total)).toBe(660.81); // server totals = device totals
    expect(await submissionsFor(page, customer)).toHaveLength(1); // synced exactly once
    await expect.poll(() => countRows(page, 'outbox')).toBe(0);
    await context.close();
  });

  test('a killed tab loses no data', async ({ browser }) => {
    test.setTimeout(120_000);
    const customer = `Absturz ${Date.now()} KG`;
    const { context, page } = await phone(browser);
    await fieldAppLogin(page, 'worker@demo.test');
    await waitForOfflineReady(page);
    await context.setOffline(true); // the device copy is then the only copy
    await startServiceReport(page, customer);
    await expect(page.getByText(SAVED_LOCAL).first()).toBeVisible({ timeout: 10_000 });
    const fillUrl = page.url();

    const cdp = await context.newCDPSession(page);
    const crashed = page.waitForEvent('crash');
    void cdp.send('Page.crash').catch(() => undefined); // never answers; no unload events, like a killed tab
    await crashed;

    const again = await context.newPage();
    await again.goto(fillUrl);
    await expect(again.getByRole('textbox', { name: /^Kunde/ })).toHaveValue(customer, { timeout: 20_000 });
    await again.screenshot({ path: evidence('04-restored-after-crash') });
    await context.close();
  });

  test('journey 7: the session expires offline; after signing in again the outbox syncs', async ({ browser }) => {
    test.setTimeout(240_000);
    const customer = `Sitzung ${Date.now()} AG`;
    const { context, page } = await phone(browser);
    await fieldAppLogin(page, 'worker@demo.test');
    await waitForOfflineReady(page);

    await context.setOffline(true);
    await fillServiceReport(page, { customer, lines: 1, photo: false });
    await submitFromReview(page);
    await expect(page.getByText(QUEUED).first()).toBeVisible({ timeout: 20_000 });
    const queued = await countRows(page, 'outbox');
    expect(queued).toBeGreaterThan(0);

    await context.clearCookies(); // the session expired while the device was offline
    await context.setOffline(false);
    await expect(page.getByRole('heading', { name: /^(sign in|anmelden)$/i })).toBeVisible({ timeout: 60_000 });
    await expect(page.getByText(/session has ended|sitzung ist abgelaufen|sitzung wurde beendet/i)).toBeVisible();
    expect(await countRows(page, 'outbox')).toBe(queued); // nothing lost, nothing sent
    await page.screenshot({ path: evidence('05-reauth') });

    await page.getByLabel(/^(email|e-mail)$/i).fill('worker@demo.test');
    await page.getByLabel(/^(password|passwort)$/i).fill('password');
    await page.getByRole('button', { name: /^(sign in|anmelden)$/i }).click();
    await expect(page.getByText(/^(hello|hallo), /i)).toBeVisible({ timeout: 20_000 });

    await expect
      .poll(async () => (await submissionsFor(page, customer)).map((s) => s.number ?? 'none'), { timeout: 60_000 })
      .toEqual([expect.stringMatching(NUMBER)]);
    await expect.poll(() => countRows(page, 'outbox')).toBe(0);
    await context.close();
  });

  test.describe('deactivation', () => {
    test.use({ storageState: adminState('owner') });

    test("a deactivated user's next sync leaves no local data", async ({ browser, page: admin }) => {
      test.setTimeout(180_000);
      const { context, page } = await phone(browser);
      await fieldAppLogin(page, 'mobile@demo.test');
      await waitForOfflineReady(page);
      await context.setOffline(true);
      await startServiceReport(page, `Wipe ${Date.now()}`);
      await expect(page.getByText(SAVED_LOCAL).first()).toBeVisible({ timeout: 10_000 });
      expect(await countRows(page, 'drafts')).toBe(1);

      const member = async (action: RegExp): Promise<void> => {
        await admin.goto('/admin/demo/members');
        const row = admin.getByRole('row').filter({ hasText: 'Mia Mobil' });
        await row.getByRole('button', { name: action }).click();
        await admin.getByRole('button', { name: /^(bestätigen|confirm)$/i }).filter({ visible: true }).click();
        await expect(admin.locator('.fi-modal-window').filter({ visible: true })).toHaveCount(0);
      };
      await member(/^deaktivieren$/i);
      try {
        await context.setOffline(false);
        await expect(page.getByText(/local data was removed|lokalen daten wurden gelöscht/i)).toBeVisible({
          timeout: 60_000,
        });
        await page.screenshot({ path: evidence('06-wiped') });
        for (const table of ['drafts', 'outbox', 'attachments', 'forms', 'formVersions']) {
          expect(await countRows(page, table), table).toBe(0);
        }
        // No device credentials, cached session or catalog; the reloaded app may note its sync state.
        const meta = await tableKeys(page, 'meta');
        expect(meta.filter((key) => key === 'device' || key === 'session' || key.startsWith('catalog:'))).toEqual([]);
        const keys = await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('fsa.')));
        expect(keys.filter((key) => !['fsa.settings', 'fsa.pwa.installDismissed'].includes(key))).toEqual([]);
      } finally {
        await member(/^reaktivieren$/i);
        await context.close();
      }
    });
  });
});
