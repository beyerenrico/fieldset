import { test, expect, devices, type Page } from './support/test';
import { gotoClassicBuilder } from './support/classic-builder';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { adminState } from './support/auth';
import { fieldAppLogin, fillAndSubmitServiceReport } from './support/field-app';

// Journey 5 (§12): the designer publishes v2 with a renamed label and a new field; re-rendering the v1
// submission keeps the same input_hash, extracted text and page count; a rollback to v1 creates v3.
// Needs pdftotext/pdfinfo (poppler-utils) on the machine running Playwright.
const evidence = (name: string) => `docs/agent/milestones/evidence/M4/${name}.png`;
const FORM_URL = '/admin/demo/forms/1/edit'; // the seeded "Servicebericht" in the classic builder (ADR 0044)

interface RenditionRow {
  hash: string;
  pages: string;
  href: string;
}

async function renditionRows(page: Page): Promise<RenditionRow[]> {
  return page.locator('tr[data-rendition]').evaluateAll((rows) =>
    rows.map((row) => ({
      hash: row.querySelector('code')?.getAttribute('title') ?? '',
      pages: row.querySelectorAll('td')[4]?.textContent?.trim() ?? '',
      href: row.querySelector('a')?.getAttribute('href') ?? '',
    })),
  );
}

async function pdfFacts(page: Page, href: string): Promise<{ text: string; pages: string }> {
  const response = await page.request.get(href);
  expect(response.headers()['content-type']).toContain('application/pdf');
  const file = join(mkdtempSync(join(tmpdir(), 'j5-')), 'r.pdf');
  writeFileSync(file, await response.body());
  const text = execFileSync('pdftotext', ['-layout', file, '-']).toString();
  const info = execFileSync('pdfinfo', [file]).toString();
  return { text, pages: /Pages:\s+(\d+)/.exec(info)?.[1] ?? '' };
}

test.describe.serial('journey 5: versions and re-rendering', () => {
  let number = '';

  test('a field worker submits the v1 service report and gets its PDF', async ({ browser }) => {
    test.setTimeout(180_000);
    const context = await browser.newContext({ ...devices['Pixel 7'] });
    const page = await context.newPage();
    await fieldAppLogin(page, 'worker@demo.test');
    number = await fillAndSubmitServiceReport(page, 'Versionstest KG');
    await expect(page.getByRole('link', { name: /PDF öffnen|open PDF/i })).toBeVisible({ timeout: 90_000 });
    await context.close();
  });

  test.describe('designer', () => {
    test.use({ storageState: adminState('designer') });

    test('publishes v2 with a renamed label and a new field', async ({ page }) => {
      test.setTimeout(120_000);
      await gotoClassicBuilder(page, FORM_URL);
      // Add the new field first, then rename: block inputs are scoped to their builder item, which
      // may be collapsed (only expanded items render their inputs).
      await page.getByRole('button', { name: 'Elemente +' }).first().click();
      await page.getByText('Text', { exact: true }).filter({ visible: true }).first().click();
      await page.waitForLoadState('networkidle');
      const labels = page.getByRole('textbox', { name: /^Bezeichnung/ });
      const valuesOf = () => labels.evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
      await expect(async () => expect(await valuesOf()).toContain('')).toPass({ timeout: 10_000 });
      const fresh = labels.nth((await valuesOf()).indexOf(''));
      await fresh.fill('Auftragsnummer');
      await fresh.blur();
      await page.waitForLoadState('networkidle');
      await expect(page.getByRole('heading', { name: /Auftragsnummer/ })).toBeVisible();

      const customer = page
        .locator('.fi-fo-builder-item')
        .filter({ has: page.getByRole('heading', { name: /\(customer_name\)$/ }) })
        .first();
      const label = customer.getByRole('textbox', { name: /^Bezeichnung/ }).first();
      if (!(await label.isVisible())) {
        await customer.getByRole('heading', { name: /\(customer_name\)$/ }).click();
      }
      await expect(label).toHaveValue(/^Kunde/);
      await label.fill('Kundenname');
      await label.blur();
      await page.waitForLoadState('networkidle');
      await expect(page.getByRole('heading', { name: 'Text: Kundenname (customer_name)' })).toBeVisible();

      await page
        .getByRole('button', { name: /^(publish|veröffentlichen)$/i })
        .first()
        .click();
      const publish = page.getByRole('dialog').filter({ hasText: /Änderungsprotokoll|changelog/i });
      await publish.locator('textarea').fill('Kunde umbenannt, Auftragsnummer ergänzt');
      await publish.getByRole('button', { name: /^(absenden|submit)$/i }).click();
      await expect(page.getByRole('cell', { name: 'v2', exact: true })).toBeVisible({ timeout: 20_000 });
      await page.screenshot({ path: evidence('10-v2-published'), fullPage: true });
    });
  });

  test.describe('office', () => {
    test.use({ storageState: adminState('owner') });

    test('re-rendering the v1 submission keeps input_hash, text and page count', async ({ page }) => {
      test.setTimeout(180_000);
      await page.goto('/admin/demo/submissions');
      await page.getByRole('searchbox').first().fill(number);
      await page.getByText(number).first().click();
      await expect(page.locator('tr[data-rendition]')).toHaveCount(1);
      const [first] = await renditionRows(page);

      await page.getByRole('button', { name: /PDF neu erzeugen|re-render/i }).click();
      await page.getByRole('button', { name: /^(bestätigen|confirm)$/i }).filter({ visible: true }).click();
      await expect(async () => {
        await page.reload();
        await expect(page.locator('tr[data-rendition]')).toHaveCount(2, { timeout: 2_000 });
      }).toPass({ timeout: 90_000 });
      await page.screenshot({ path: evidence('11-rerendered'), fullPage: true });

      const rows = await renditionRows(page);
      expect(new Set(rows.map((r) => r.hash)).size).toBe(1);
      expect(rows[0]?.hash).toBe(first?.hash);
      expect(rows[0]?.pages).toBe(rows[1]?.pages);
      const [a, b] = await Promise.all(rows.map((r) => pdfFacts(page, r.href)));
      expect(a?.pages).toBe(b?.pages);
      expect(a?.text).toBe(b?.text);
      expect(a?.text).toContain('Kunde'); // the v1 label, not the renamed v2 label
      expect(a?.text).not.toContain('Kundenname');
    });
  });

  test.describe('designer rollback', () => {
    test.use({ storageState: adminState('designer') });

    test('rolling back to v1 creates v3', async ({ page }) => {
      test.setTimeout(150_000); // the classic builder may answer 503 behind another worker's render
      await gotoClassicBuilder(page, FORM_URL);
      const v1 = page.getByRole('row').filter({ has: page.getByRole('cell', { name: 'v1', exact: true }) });
      await v1.getByRole('button', { name: /auf diese version zurücksetzen|roll back/i }).click();
      const changelog = page.locator('textarea').filter({ visible: true });
      if (await changelog.count()) {
        await changelog.first().fill('Zurück auf v1');
      }
      await page
        .getByRole('button', { name: /^(bestätigen|confirm|absenden|submit|zurücksetzen)$/i })
        .filter({ visible: true })
        .last()
        .click();
      await expect(page.getByRole('cell', { name: 'v3', exact: true })).toBeVisible({ timeout: 20_000 });
      await page.screenshot({ path: evidence('12-rollback-v3'), fullPage: true });
    });
  });
});
