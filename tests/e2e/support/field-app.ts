import { expect, type Page } from '@playwright/test';

/** Optional screenshot hook: called with a step name at the evidence points of the journey. */
export type Snap = (name: string, options?: { fullPage?: boolean }) => Promise<unknown>;

const noSnap: Snap = async () => undefined;

// WCAG 1.4.10: nothing may widen the page beyond the phone's width.
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
  const [scrollWidth, width] = await page.evaluate(() => [
    document.documentElement.scrollWidth,
    window.innerWidth,
  ]);
  expect(scrollWidth).toBeLessThanOrEqual(width);
  expect(width).toBe(page.viewportSize()?.width);
}

export async function fieldAppLogin(page: Page, email: string): Promise<void> {
  await page.goto('/app/');
  await page.getByLabel(/^(email|e-mail)$/i).fill(email);
  await page.getByLabel(/^(password|passwort)$/i).fill('password');
  await page.getByRole('button', { name: /^(sign in|anmelden)$/i }).click();
}

/**
 * Fills the demo service report (three wizard steps), signs it and submits it from the review screen.
 * Returns the number the server assigned.
 */
export async function fillAndSubmitServiceReport(
  page: Page,
  customer: string,
  snap: Snap = noSnap,
): Promise<string> {
  await page.goto('/app/forms');
  await page.getByText('Servicebericht', { exact: true }).first().click();
  await expect(page).toHaveURL(/\/app\/fill\//);

  // Page 1: customer & job
  await page.getByRole('textbox', { name: /^Kunde/ }).fill(customer);
  await page.getByRole('combobox', { name: /Art des Einsatzes/ }).selectOption('maintenance');
  await page
    .getByRole('textbox', { name: /Durchgeführte Arbeiten/ })
    .fill('Wartung der Anlage, Filter getauscht');
  await snap('02-fill-page1', { fullPage: true });
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
  await snap('03-fill-items', { fullPage: true });
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
  await snap('04-signed', { fullPage: true });

  await page.getByRole('button', { name: /^(review|prüfen|überprüfen)$/i }).click();
  await expect(page).toHaveURL(/\/review$/);
  const submitButton = page.getByRole('button', { name: /^(submit|einreichen|absenden)$/i });
  await expectNoHorizontalScroll(page);
  await expect(submitButton).toBeInViewport();
  await snap('05-review-sticky-footer');
  await submitButton.click();
  const number = page.getByText(/SR-\d{4}-\d{5}/).first();
  await expect(number).toBeVisible({ timeout: 20_000 });
  await expectNoHorizontalScroll(page);
  await snap('06-submitted', { fullPage: true });

  const match = /SR-\d{4}-\d{5}/.exec((await number.textContent()) ?? '');
  if (match === null) throw new Error('no submission number shown');
  return match[0];
}
