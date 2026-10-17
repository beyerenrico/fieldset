import { test, expect } from '@playwright/test';
import { adminState } from './support/auth';

// M2 demo: journey 2 with the live preview (the unsaved definition rendered by the Field App renderer)
// and journey 8 (import a fillable PDF as a draft form, processed by the queue worker).
const evidence = (name: string) => `docs/agent/milestones/evidence/M2/${name}.png`;

test.use({ storageState: adminState('designer') });

test('designer previews an unsaved change in the phone frame, then publishes', async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto('/admin/demo/forms');
  await page.getByRole('button', { name: /from template|aus vorlage|vorlage/i }).click();
  const dialog = page.getByRole('dialog');
  await dialog.locator('select').first().selectOption('service_report');
  await dialog.locator('input[type="text"]').last().fill('Servicebericht Vorschau');
  await dialog
    .getByRole('button', { name: /submit|absenden|erstellen|create/i })
    .first()
    .click();
  await expect(page).toHaveURL(/\/forms\/\d+\/edit/, { timeout: 30_000 }); // the builder page is heavy (QUESTIONS #8)

  // Change the title without saving; the preview must show the unsaved state.
  await page.getByRole('tab', { name: /formulareinstellungen|form settings/i }).click();
  const title = page.getByRole('textbox', { name: /^(name|formularname)/i }).first();
  await title.fill('Servicebericht Vorschau geändert');
  await title.blur();
  await page.getByRole('tab', { name: /vorschau|preview/i }).click();

  const frame = page.frameLocator('iframe[title="Vorschau"], iframe[title="Preview"]');
  await expect(frame.getByRole('heading', { name: 'Servicebericht Vorschau geändert' })).toBeVisible({
    timeout: 10_000,
  });
  await expect(frame.getByRole('textbox', { name: /^Kunde/ })).toBeVisible();
  await page.screenshot({ path: evidence('10-builder-preview'), fullPage: true });

  await page
    .getByRole('button', { name: /publish|veröffentlichen/i })
    .first()
    .click();
  await page.getByRole('dialog').locator('textarea').fill('Version nach Vorschau');
  await page
    .getByRole('dialog')
    .getByRole('button', { name: /submit|absenden|veröffentlichen|publish/i })
    .last()
    .click();
  await expect(page.getByText(/version 1|v1/i).first()).toBeVisible({ timeout: 30_000 }); // publishing round-trips the heavy builder state
});

test('designer imports a fillable PDF as a draft form', async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto('/admin/demo/forms');
  await page.getByRole('button', { name: /aus pdf importieren|import from pdf/i }).click();
  const dialog = page.getByRole('dialog');
  await dialog.locator('input[type="file"]').setInputFiles('tests/Fixtures/pdf/service-sheet.pdf');
  await dialog.getByRole('textbox').last().fill('PDF-Servicebogen E2E');
  await expect(dialog.getByText(/upload complete|hochgeladen|abgeschlossen/i).first()).toBeVisible({
    timeout: 20_000,
  });
  await page.screenshot({ path: evidence('20-pdf-import-dialog'), fullPage: true });
  await dialog
    .getByRole('button', { name: /submit|absenden|importieren|import/i })
    .last()
    .click();
  await expect(page.getByText(/PDF-Import gestartet|PDF import started/).first()).toBeVisible();

  // The queue worker creates the draft; poll the list.
  await expect(async () => {
    await page.reload();
    await expect(page.getByText('PDF-Servicebogen E2E').first()).toBeVisible({ timeout: 2_000 });
  }).toPass({ timeout: 60_000 });
  await page.getByText('PDF-Servicebogen E2E').first().click();
  await expect(page).toHaveURL(/\/forms\/\d+/);
  if (!/\/edit/.test(page.url())) {
    await page
      .getByRole('link', { name: /editor|bearbeiten|edit/i })
      .first()
      .click();
  }
  await expect(page.getByText(/PDF-Importbericht|PDF import report/).first()).toBeVisible({
    timeout: 20_000,
  });
  await page.screenshot({ path: evidence('21-pdf-import-draft'), fullPage: true });
});
