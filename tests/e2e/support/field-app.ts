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

/**
 * Signs in with a password on the login page that is already open. Email-first (M7, ADR 0036): email →
 * Continue → discovery answers "password" → password → Sign in. The one-page form of M2–M6 (email and
 * password together) still works, so the helper is safe on either build.
 */
export async function fieldAppSignIn(page: Page, email: string, password = 'password'): Promise<void> {
  await page.getByLabel(/^(email|e-mail)$/i).fill(email);
  const passwordField = page.getByLabel(/^(password|passwort)$/i);
  if (!(await passwordField.isVisible())) {
    await page.getByRole('button', { name: /^(continue|weiter)$/i }).click();
    await expect(passwordField).toBeVisible({ timeout: 20_000 });
  }
  await passwordField.fill(password);
  await page.getByRole('button', { name: /^(sign in|anmelden)$/i }).click();
}

export async function fieldAppLogin(page: Page, email: string): Promise<void> {
  await page.goto('/app/');
  await fieldAppSignIn(page, email);
  // Navigating before the session and device registration finished would land back on the login.
  await expect(page.getByText(/^(hello|hallo), /i)).toBeVisible({ timeout: 20_000 });
}

/** Draws a signature on the first signature pad and confirms it. */
export async function signOnCanvas(page: Page): Promise<void> {
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
}

/**
 * Fills the demo service report (three wizard steps), signs it and submits it from the review screen.
 * Returns the number the server assigned.
 */
export async function fillAndSubmitServiceReport(
  page: Page,
  customer: string,
  snap: Snap = noSnap,
  customerEmail?: string,
): Promise<string> {
  await page.goto('/app/forms');
  await page.getByText('Servicebericht', { exact: true }).first().click();
  await expect(page).toHaveURL(/\/app\/fill\//);

  // Page 1: customer & job
  await page.getByRole('textbox', { name: /^Kunde/ }).fill(customer);
  if (customerEmail !== undefined) {
    await page.getByRole('textbox', { name: /^E-Mail Kunde/ }).fill(customerEmail);
  }
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
  await signOnCanvas(page);
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
