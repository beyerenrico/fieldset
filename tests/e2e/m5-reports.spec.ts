import { test, expect, devices, type Page } from '@playwright/test';
import { adminState } from './support/auth';
import { fieldAppLogin, fillAndSubmitServiceReport } from './support/field-app';

// M5 journey: a submission appears in the explorer with columns from the schema, a queued XLSX export
// finishes and is offered for download in the notifications, and the explorer filter is saved as a
// report that can be pinned to the dashboard.
const evidence = (name: string) => `docs/agent/milestones/evidence/M5/${name}.png`;
const CUSTOMER = `Explorer ${Date.now()} AG`;
const REPORT = `Berichte E2E ${Date.now()}`;

async function exportLink(page: Page): Promise<string> {
  let href = '';
  await expect(async () => {
    await page.reload();
    await page
      .getByRole('button', { name: /benachrichtigungen|notifications/i })
      .first()
      .click();
    const link = page.locator('a[href*="/exports/"]').filter({ visible: true }).first();
    await expect(link).toBeVisible({ timeout: 2_000 });
    href = (await link.getAttribute('href')) ?? '';
  }).toPass({ timeout: 90_000, intervals: [2_000, 5_000] });
  return href;
}

test.describe.serial('reports and dashboards', () => {
  let number = '';

  test('a field worker submits a report', async ({ browser }) => {
    test.setTimeout(120_000);
    const context = await browser.newContext({ ...devices['Pixel 7'] });
    const page = await context.newPage();
    await fieldAppLogin(page, 'worker@demo.test');
    number = await fillAndSubmitServiceReport(page, CUSTOMER);
    await context.close();
  });

  test.describe('office', () => {
    test.use({ storageState: adminState('owner') });

    test('explores, exports and saves a report', async ({ page }) => {
      test.setTimeout(180_000);
      await page.goto('/admin/demo/explorer?form=1');
      for (const column of ['Nummer', 'Kunde', 'Art des Einsatzes', 'Brutto']) {
        await expect(page.getByRole('columnheader', { name: column }).first()).toBeVisible();
      }
      const row = page.getByRole('row').filter({ hasText: number });
      await expect(row).toContainText(CUSTOMER);
      await expect(row).toContainText('116,03');
      await page.screenshot({ path: evidence('01-explorer'), fullPage: true });

      await page.getByRole('button', { name: 'Exportieren' }).click();
      await page.getByText('Alle Zeilen als Excel (XLSX)').filter({ visible: true }).click();
      const confirm = page
        .getByRole('button', { name: /^(bestätigen|absenden|exportieren)$/i })
        .filter({ visible: true });
      if (await confirm.count()) {
        await confirm.last().click();
      }
      const href = await exportLink(page);
      await page.screenshot({ path: evidence('02-export-ready'), fullPage: true });
      const file = await page.request.get(href);
      expect(file.ok()).toBeTruthy();
      expect(file.headers()['content-type']).toContain('spreadsheetml');

      await page.goto('/admin/demo/explorer?form=1');
      await page.getByRole('button', { name: 'Als Bericht speichern' }).click();
      const modal = page.locator('.fi-modal-window').filter({ visible: true });
      await modal.getByRole('textbox').first().fill(REPORT);
      await modal.locator('select').first().selectOption({ label: 'Nur ich' });
      await modal.getByRole('button', { name: 'Absenden' }).click();
      await expect(page.getByText(REPORT).first()).toBeVisible({ timeout: 20_000 });
      await page.screenshot({ path: evidence('03-saved-report'), fullPage: true });

      await page.getByRole('link', { name: /^(anzeigen|view)$/i }).first().click(); // saving opens the edit page
      await page.getByRole('button', { name: /an dashboard anheften|pin to dashboard/i }).first().click();
      await expect(page.getByRole('button', { name: /lösen|unpin/i }).first()).toBeVisible();
      await page.goto('/admin/demo');
      // Filament widgets load lazily once they scroll into view.
      await expect(async () => {
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await expect(page.getByText(REPORT).first()).toBeVisible({ timeout: 2_000 });
      }).toPass({ timeout: 30_000 });
      await page.screenshot({ path: evidence('04-dashboard-pinned'), fullPage: true });
    });
  });
});
