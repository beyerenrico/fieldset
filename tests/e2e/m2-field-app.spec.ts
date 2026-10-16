import { test, expect, devices, type Page } from '@playwright/test';

// M2 demo (journey 3 online part): a field worker on a phone fills the demo service report, signs it
// and submits; the server assigns the number. Requires the demo seed (published service report).
test.use({ ...devices['Pixel 7'] });

const evidence = (name: string) => `docs/agent/milestones/evidence/M2/${name}.png`;

// WCAG 1.4.10: nothing may widen the page beyond the phone's width.
async function expectNoHorizontalScroll(page: Page) {
  const [scrollWidth, width] = await page.evaluate(() => [
    document.documentElement.scrollWidth,
    window.innerWidth,
  ]);
  expect(scrollWidth).toBeLessThanOrEqual(width);
  expect(width).toBe(page.viewportSize()?.width);
}

async function login(page: Page, email: string) {
  await page.goto('/app/');
  await page.getByLabel(/^(email|e-mail)$/i).fill(email);
  await page.getByLabel(/^(password|passwort)$/i).fill('password');
  await page.getByRole('button', { name: /^(sign in|anmelden)$/i }).click();
}

test('field worker fills, signs and submits the service report', async ({ page }) => {
  test.setTimeout(120_000);
  await login(page, 'worker@demo.test');
  await expect(page.getByText(/^(hello|hallo), willi/i)).toBeVisible();
  await page.screenshot({ path: evidence('01-home'), fullPage: true });

  await page.goto('/app/forms');
  await page.getByText('Servicebericht', { exact: true }).first().click();
  await expect(page).toHaveURL(/\/app\/fill\//);

  // Page 1: customer & job
  await page.getByRole('textbox', { name: /^Kunde/ }).fill('Beispiel GmbH');
  await page.getByRole('combobox', { name: /Art des Einsatzes/ }).selectOption('maintenance');
  await page
    .getByRole('textbox', { name: /Durchgeführte Arbeiten/ })
    .fill('Wartung der Anlage, Filter getauscht');
  await page.screenshot({ path: evidence('02-fill-page1'), fullPage: true });
  await page.getByRole('button', { name: /^(next|weiter)$/i }).click();

  // Page 2: material & labour (one row is required)
  const description = page.getByRole('textbox', { name: /^Beschreibung/ }).first();
  if (!(await description.isVisible())) {
    await page
      .getByRole('button', { name: /zeile hinzufügen|add line|add entry|eintrag hinzufügen/i })
      .first()
      .click();
  }
  await page
    .getByRole('textbox', { name: /^Beschreibung/ })
    .first()
    .fill('Arbeitszeit');
  await page
    .getByRole('textbox', { name: /^Menge/ })
    .first()
    .fill('1.5');
  await page
    .getByRole('textbox', { name: /^Einzelpreis/ })
    .first()
    .fill('65.00');
  await expect(page.getByText(/97,50/).first()).toBeVisible();
  await expectNoHorizontalScroll(page);
  await page.screenshot({ path: evidence('03-fill-items'), fullPage: true });
  await page.getByRole('button', { name: /^(next|weiter)$/i }).click();

  // Page 3: acceptance and signature
  await page.getByRole('checkbox', { name: /ordnungsgemäß ausgeführt/ }).check();
  const canvas = page.locator('canvas').first();
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (box === null) throw new Error('signature canvas not visible');
  await page.mouse.move(box.x + 20, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + 15, { steps: 8 });
  await page.mouse.move(box.x + box.width - 20, box.y + box.height - 15, { steps: 8 });
  await page.mouse.up();
  await page.getByRole('button', { name: /confirm signature|unterschrift bestätigen/i }).click();
  await expect(page.getByText(/signed by|unterschrieben von/i).first()).toBeVisible();
  await page.screenshot({ path: evidence('04-signed'), fullPage: true });

  await page.getByRole('button', { name: /^(review|prüfen|überprüfen)$/i }).click();
  await expect(page).toHaveURL(/\/review$/);
  const submitButton = page.getByRole('button', { name: /^(submit|einreichen|absenden)$/i });
  await expectNoHorizontalScroll(page);
  await expect(submitButton).toBeInViewport();
  await page.screenshot({ path: evidence('05-review-sticky-footer') });
  await submitButton.click();
  await expect(page.getByText(/SR-\d{4}-\d{5}/).first()).toBeVisible({ timeout: 20_000 });
  await expectNoHorizontalScroll(page);
  await page.screenshot({ path: evidence('06-submitted'), fullPage: true });
});
