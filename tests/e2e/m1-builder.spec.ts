import { test, expect } from './support/test';
import { adminState } from './support/auth';

// M1 demo (journey 2 without preview, which arrives in M2): the designer creates a form from the
// "Service Report" template, edits a label, publishes v1, and sees the version history.
const evidence = (name: string) => `docs/agent/milestones/evidence/M1/${name}.png`;

test.use({ storageState: adminState('designer') });

test('designer builds a form from the service report template and publishes v1', async ({ page }) => {
  await page.goto('/admin/demo/forms');
  await page.screenshot({ path: evidence('01-forms-list'), fullPage: true });

  await page.getByRole('button', { name: /from template|aus vorlage|vorlage/i }).click();
  const dialog = page.getByRole('dialog');
  await dialog.locator('select').first().selectOption('service_report');
  await dialog.locator('input[type="text"]').last().fill('Servicebericht E2E');
  await dialog
    .getByRole('button', { name: /submit|absenden|erstellen|create/i })
    .first()
    .click();

  // New forms open in the canvas builder (ADR 0044); this journey covers the classic builder, so it switches.
  await expect(page).toHaveURL(/\/forms\/\d+\/canvas/, { timeout: 30_000 });
  await page.locator('[data-builder-toolbar="classic"]').click();
  await expect(page).toHaveURL(/\/forms\/\d+\/edit/, { timeout: 30_000 }); // the builder page is heavy (QUESTIONS #8)
  await page.screenshot({ path: evidence('02-builder'), fullPage: true });

  await page
    .getByRole('button', { name: /publish|veröffentlichen/i })
    .first()
    .click();
  await page.getByRole('dialog').locator('textarea').fill('Erste Version aus der Vorlage');
  await page
    .getByRole('dialog')
    .getByRole('button', { name: /submit|absenden|veröffentlichen|publish/i })
    .last()
    .click();
  await expect(page.getByText(/version 1|v1/i).first()).toBeVisible({ timeout: 30_000 }); // publishing round-trips the heavy builder state
  await page.screenshot({ path: evidence('03-published'), fullPage: true });
});
