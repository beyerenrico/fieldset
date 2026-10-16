import { test, expect, devices, request as apiRequest, type Browser, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fieldAppLogin, fillAndSubmitServiceReport } from './support/field-app';

// Journey 4 (§12) on the demo workflow "Prüfen und an Kunden senden": a reviewer rejects the
// submission with a comment, the worker fixes it and resubmits (number kept), the reviewer approves,
// and the PDF with the corrected totals arrives in Mailpit. Needs pdftotext (poppler-utils).
const evidence = (name: string) => `docs/agent/milestones/evidence/M4/${name}.png`;
const MAILPIT = process.env.MAILPIT_URL ?? 'http://127.0.0.1:18025';
const CUSTOMER_EMAIL = `j4-${Date.now()}@example.test`;
const COMMENT = 'Bitte die Arbeitszeit auf 2 Stunden korrigieren.';

async function phone(browser: Browser, email: string): Promise<Page> {
  const context = await browser.newContext({ ...devices['Pixel 7'] });
  const page = await context.newPage();
  await fieldAppLogin(page, email);
  return page;
}

/** Opens the reviewer's open task for the submission once the workflow has created it. */
async function openTask(page: Page, number: string): Promise<void> {
  await expect(async () => {
    await page.goto('/app/');
    await page.getByText(`Nr. ${number}`).click({ timeout: 2_000 });
  }).toPass({ timeout: 60_000 });
  await expect(page.getByRole('heading', { name: 'Ihre Prüfung' })).toBeVisible();
}

test('journey 4: reject, resubmit, approve, PDF to the customer', async ({ browser }) => {
  test.setTimeout(300_000);
  const worker = await phone(browser, 'worker@demo.test');
  const number = await fillAndSubmitServiceReport(worker, 'Prüfung & Co. KG', undefined, CUSTOMER_EMAIL);

  // The reviewer rejects with a comment.
  const reviewer = await phone(browser, 'reviewer@demo.test');
  await openTask(reviewer, number);
  await reviewer.getByRole('button', { name: 'Ablehnen' }).click();
  await reviewer.getByRole('textbox', { name: 'Was soll geändert werden?' }).fill(COMMENT);
  await reviewer.getByRole('dialog').getByRole('button', { name: 'Ablehnen' }).click();
  await expect(reviewer.getByText(/Sie haben Änderungen angefordert/)).toBeVisible();
  await reviewer.screenshot({ path: evidence('20-rejected'), fullPage: true });

  // The worker sees the comment, fixes the hours and resubmits; the number stays.
  await expect(async () => {
    await worker.goto('/app/submissions');
    await worker.getByText(number).first().click({ timeout: 2_000 });
    await expect(worker.getByText(COMMENT)).toBeVisible({ timeout: 2_000 });
  }).toPass({ timeout: 30_000 });
  await worker.screenshot({ path: evidence('21-changes-requested'), fullPage: true });
  // The list opens a changes-requested submission in the fill flow; the detail screen has an edit button.
  const edit = worker.getByRole('link', { name: 'Bearbeiten und erneut einreichen' });
  if (await edit.isVisible()) {
    await edit.click();
  }
  await expect(worker).toHaveURL(/\/app\/fill\//);
  await worker.getByRole('button', { name: /^(next|weiter)$/i }).click();
  await worker
    .getByRole('textbox', { name: /^Menge/ })
    .first()
    .fill('2');
  await expect(worker.getByText(/154,70/).first()).toBeVisible();
  await worker.getByRole('button', { name: /^(next|weiter)$/i }).click();
  await worker.getByRole('button', { name: /^(review|prüfen|überprüfen)$/i }).click();
  await worker.getByRole('button', { name: 'Erneut einreichen' }).click();
  await expect(worker.getByText('Erneut eingereicht').first()).toBeVisible({ timeout: 20_000 });
  await expect(worker.getByText(number).first()).toBeVisible();

  // The resubmit starts a new run with a new review task; the reviewer approves.
  await openTask(reviewer, number);
  await reviewer.getByRole('button', { name: 'Freigeben' }).click();
  await reviewer.getByRole('dialog').getByRole('button', { name: 'Freigeben' }).click();
  await expect(reviewer.getByText('Sie haben diese Einreichung freigegeben.')).toBeVisible();
  await reviewer.screenshot({ path: evidence('22-approved'), fullPage: true });

  // The workflow mails the PDF with the corrected totals to the customer's address.
  const mailpit = await apiRequest.newContext({ baseURL: MAILPIT });
  let id = '';
  await expect(async () => {
    const found = (await (
      await mailpit.get(`/api/v1/search?query=${encodeURIComponent(`to:${CUSTOMER_EMAIL}`)}`)
    ).json()) as {
      messages: { ID: string; Subject: string }[];
    };
    expect(found.messages.length).toBeGreaterThan(0);
    expect(found.messages[0]?.Subject).toContain(number);
    id = found.messages[0]?.ID ?? '';
  }).toPass({ timeout: 120_000, intervals: [2_000, 5_000] });
  const message = (await (await mailpit.get(`/api/v1/message/${id}`)).json()) as {
    Attachments: { ContentType: string; PartID: string }[];
  };
  const pdf = message.Attachments.find((a) => a.ContentType === 'application/pdf');
  expect(pdf).toBeDefined();
  const file = join(mkdtempSync(join(tmpdir(), 'j4-')), 'doc.pdf');
  writeFileSync(file, await (await mailpit.get(`/api/v1/message/${id}/part/${pdf?.PartID}`)).body());
  const text = execFileSync('pdftotext', ['-layout', file, '-']).toString();
  expect(text).toContain(number);
  expect(text).toContain('130,00 €');
  expect(text).toContain('154,70 €');
  await mailpit.dispose();
});
