import { test, expect, devices, request as apiRequest, type APIRequestContext } from './support/test';
import { adminState } from './support/auth';
import { fieldAppLogin, fillAndSubmitServiceReport } from './support/field-app';
import { mailpitOptions } from './support/target';

// M3 gate journey: a field worker submits the service report on a phone, the queue renders the PDF
// with Gotenberg, the worker sends it to the customer, the mail arrives in Mailpit with the PDF, and
// the office sees the rendition in the admin inbox. Requires the Docker stack and the demo seed.
const evidence = (name: string) => `docs/agent/milestones/evidence/M3/${name}.png`;
const CUSTOMER_EMAIL = `kunde-${Date.now()}@example.test`;

interface MailpitSummary {
  ID: string;
  Subject: string;
  Attachments: number;
}

async function waitForMail(mailpit: APIRequestContext, to: string): Promise<MailpitSummary> {
  let found: MailpitSummary | undefined;
  await expect(async () => {
    const response = await mailpit.get(`/api/v1/search?query=${encodeURIComponent(`to:${to}`)}`);
    expect(response.ok()).toBeTruthy();
    const body = (await response.json()) as { messages: MailpitSummary[] };
    found = body.messages[0];
    expect(found).toBeDefined();
  }).toPass({ timeout: 90_000, intervals: [1_000, 2_000, 5_000] });
  if (found === undefined) throw new Error(`no mail to ${to}`);
  return found;
}

test.describe.serial('documents and email', () => {
  let number = '';

  test('field worker submits, opens the PDF and sends it to the customer', async ({ browser }) => {
    test.setTimeout(180_000);
    const context = await browser.newContext({ ...devices['Pixel 7'] });
    const page = await context.newPage();
    await fieldAppLogin(page, 'worker@demo.test');
    await expect(page.getByText(/^(hello|hallo), willi/i)).toBeVisible();

    number = await fillAndSubmitServiceReport(page, 'Muster AG', undefined, CUSTOMER_EMAIL);

    // The queue renders the PDF; the detail screen polls until the signed link is there.
    const openPdf = page.getByRole('link', { name: /PDF öffnen|open PDF/i });
    await expect(openPdf).toBeVisible({ timeout: 90_000 });
    await page.screenshot({ path: evidence('01-field-app-pdf-ready'), fullPage: true });
    const href = await openPdf.getAttribute('href');
    expect(href).toBeTruthy();
    const pdf = await page.request.get(href ?? '');
    expect(pdf.ok()).toBeTruthy();
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');

    await page.getByRole('button', { name: /an kunden senden|send to customer/i }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('textbox', { name: /e-mail/i })).toHaveValue(CUSTOMER_EMAIL);
    await page.screenshot({ path: evidence('02-send-dialog') });
    await dialog.getByRole('button', { name: /^(senden|send)$/i }).click();
    await expect(page.getByText(/(versand an|sending to) .* (ist eingeplant|is scheduled)|sobald das pdf fertig|as soon as the pdf/i).first()).toBeVisible();
    await expect(page.getByText(CUSTOMER_EMAIL).first()).toBeVisible();
    await page.screenshot({ path: evidence('03-sent'), fullPage: true });
    await context.close();
  });

  test('the customer mail arrives in Mailpit with the PDF', async () => {
    test.setTimeout(120_000);
    const mailpit = await apiRequest.newContext(mailpitOptions());
    const summary = await waitForMail(mailpit, CUSTOMER_EMAIL);
    expect(summary.Subject).toContain(number);

    const message = (await (await mailpit.get(`/api/v1/message/${summary.ID}`)).json()) as {
      Attachments: { ContentType: string; FileName: string }[];
      HTML: string;
    };
    expect(message.Attachments.some((a) => a.ContentType === 'application/pdf')).toBeTruthy();
    await mailpit.dispose();
  });

  test.describe('office', () => {
    test.use({ storageState: adminState('owner') });

    test('owner sees the rendition and the send in the submissions inbox', async ({ page }) => {
      await page.goto('/admin/demo/submissions');
      await page.getByRole('searchbox').first().fill(number);
      await page.getByText(number).first().click();
      await expect(page.getByText(/PDF-Fassungen|renditions/i).first()).toBeVisible();
      await expect(page.getByText(CUSTOMER_EMAIL).first()).toBeVisible();
      await page.screenshot({ path: evidence('04-admin-documents'), fullPage: true });

      const download = page.getByRole('link', { name: /herunterladen|download/i }).first();
      const pdf = await page.request.get((await download.getAttribute('href')) ?? '');
      expect(pdf.headers()['content-type']).toContain('application/pdf');
    });
  });
});
