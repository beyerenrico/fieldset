#!/usr/bin/env node
// Home page screenshots (plan U9, R11, R12): a manual Playwright script, no test. It signs in with the demo users of a
// freshly seeded instance, switches the demo organization to English and captures, at 1440×900:
//   forms.png      the admin forms list (the seeded service report plus three forms it creates from templates)
//   builder.png    the canvas builder with the service report open
//   field-app.png  the Field App on a Pixel 7: the form list and the service report being filled, framed side by side
//   pdf.png        the PDF of the service report the field worker submits, in the browser's PDF viewer
//
// Usage, from the repository root after `npm ci` (Playwright is a root devDependency, browsers in storage/playwright):
//   PLAYWRIGHT_BROWSERS_PATH=storage/playwright node docs/site/scripts/screenshots.mjs <base-url> [--out <dir>]
//
// <base-url> must be a fresh demo instance and never one with real data (the dev database holds E2E leftovers,
// LEARNINGS 127): a local `fieldset install --demo` instance, or a checkout on a throwaway database
// (`php artisan migrate --seed`) with a queue worker and Gotenberg for the PDF, behind a web server that routes like
// docker/app/Caddyfile. `php artisan serve` does not: PHP's built-in server passes /app/<route> to Laravel with the
// base path /app, because public/app/ has an index.html, and serves the builder preview's assets without CORS.
// The script changes that instance: the organization's language becomes English (the UI follows the organization's
// language unless a user has their own, LocaleResolver), it creates three draft forms from templates (skipped when the
// list has them) and submits one service report. FIELDSET_DEMO_PASSWORD overrides the demo password ("password").
// --out defaults to docs/site/root/public/screenshots/, resolved from this file, never from the working directory
// (LEARNINGS 160), and never milestone evidence (LEARNINGS 159). All four files are written only after every capture
// succeeded. The full Chromium build (not the headless shell) is used, because only it has the PDF viewer.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { chromium, devices } from 'playwright';

const DEFAULT_OUT = fileURLToPath(new URL('../root/public/screenshots/', import.meta.url));
const DESKTOP = Object.freeze({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, locale: 'en-US' });
const PHONE = Object.freeze({ ...devices['Pixel 7'], locale: 'en-US' });
const TENANT = '/admin/demo';
const OWNER = 'owner@demo.test';
const WORKER = 'worker@demo.test';
const PASSWORD = process.env.FIELDSET_DEMO_PASSWORD || 'password';
// Template keys (spec/templates/index.json) of the forms created for a fuller forms list.
const EXTRA_TEMPLATES = Object.freeze(['inspection_checklist', 'delivery_note', 'timesheet']);
const SERVICE_REPORT = 'Servicebericht';
const NUMBER = /SR-\d{4}-\d{5}/;
// The form renderer's own buttons follow the form's locale (de-DE), not the UI's: matched in both languages.
const NEXT = /^(next|weiter)$/i;
const ADD_ROW = /add (line|entry)|zeile hinzufügen|eintrag hinzufügen/i;

function usage(message) {
  process.stderr.write(`${message}\nUsage: node docs/site/scripts/screenshots.mjs <base-url> [--out <dir>]\n`);
  process.exit(2);
}

function parseCli(argv) {
  let parsed;
  try {
    parsed = parseArgs({ args: argv, allowPositionals: true, options: { out: { type: 'string' } } });
  } catch (error) {
    usage(error.message);
  }
  if (parsed.positionals.length !== 1) usage('Exactly one base URL is required.');
  let url;
  try {
    url = new URL(parsed.positionals[0]);
  } catch {
    usage(`Not a URL: ${parsed.positionals[0]}`);
  }
  if (!['http:', 'https:'].includes(url.protocol)) usage('The base URL must be http or https.');
  return { base: url.origin, out: resolve(parsed.values.out ?? DEFAULT_OUT) };
}

async function settle(page) {
  await page.waitForLoadState('networkidle');
}

// Email-first password sign-in on /admin/login (ADR 0036), as tests/e2e/support/auth.ts.
async function adminLogin(page, email) {
  await page.goto('/admin/login');
  await page.waitForFunction(() => 'Livewire' in window);
  await settle(page);
  await page.locator('input[type="email"]').fill(email);
  const next = page.getByRole('button', { name: /^\s*(continue|weiter)\s*$/i });
  if ((await next.count()) > 0) await next.click();
  const password = page.locator('input[autocomplete="current-password"]');
  await password.fill(PASSWORD);
  await page.getByRole('button', { name: /^\s*(sign in|anmelden)\s*$/i }).click();
  await page.waitForURL((url) => url.pathname.startsWith(TENANT));
}

// Organization settings → Language: English. Every demo user without a language of their own follows it, in the
// admin dashboard and in the Field App.
async function switchToEnglish(page) {
  await page.goto(`${TENANT}/profile`);
  await settle(page);
  const locale = page.locator('select[id$="locale"]');
  await locale.selectOption('en');
  const saved = page.waitForResponse((response) => response.request().method() === 'POST'
    && /\/livewire(-[^/]+)?\/update/.test(response.url()) && response.request().postData()?.includes('"save"'));
  await page.locator('form').filter({ has: locale }).locator('button[type="submit"]').click();
  await saved;
  await page.goto(`${TENANT}/forms`);
  const lang = await page.locator('html').getAttribute('lang');
  if (lang !== 'en') throw new Error(`The admin dashboard is still in "${lang}" after switching to English`);
}

async function createFromTemplate(page, key) {
  await page.goto(`${TENANT}/forms`);
  await settle(page);
  if ((await page.getByRole('cell', { name: key, exact: true }).count()) > 0) return;
  await page.getByRole('button', { name: /^\s*from template\s*$/i }).click();
  const dialog = page.getByRole('dialog');
  await dialog.locator('select').first().selectOption(key);
  await dialog.getByRole('button', { name: /^\s*(create|submit)\s*$/i }).first().click();
  await page.waitForURL(/\/forms\/\d+\/canvas/);
}

async function expectBrand(page) {
  if (!(await page.getByText('Fieldset', { exact: true }).first().isVisible())) {
    throw new Error(`The product name "Fieldset" is not visible on ${page.url()}`);
  }
}

async function captureForms(page) {
  await page.goto(`${TENANT}/forms`);
  await settle(page);
  await page.getByRole('cell', { name: SERVICE_REPORT }).first().waitFor();
  await expectBrand(page);
  return page.screenshot();
}

async function captureBuilder(page) {
  await page.goto(`${TENANT}/forms`);
  await settle(page);
  const href = await page.getByRole('link', { name: SERVICE_REPORT, exact: true }).first().getAttribute('href');
  if (!href?.endsWith('/canvas')) throw new Error(`The service report does not open in the canvas builder: ${href}`);
  await page.goto(href);
  await page.locator('[data-builder-root][data-builder-ready]').waitFor();
  await settle(page);
  // Select the first field; the inspector loads it over Livewire and names it in data-builder-inspected.
  const node = page.locator('[data-builder-node][data-builder-key="customer_name"]').first();
  await node.click();
  const id = await node.getAttribute('data-builder-node');
  await page.locator(`[data-builder-inspector][data-builder-inspected="${id}"]`).waitFor();
  await settle(page);
  return page.screenshot();
}

// Email-first sign-in of the Field App, as tests/e2e/support/field-app.ts.
async function fieldAppLogin(page, email) {
  await page.goto('/app/');
  await page.getByLabel(/^email$/i).fill(email);
  const password = page.getByLabel(/^password$/i);
  if (!(await password.isVisible())) {
    await page.getByRole('button', { name: /^continue$/i }).click();
    await password.waitFor();
  }
  await password.fill(PASSWORD);
  await page.getByRole('button', { name: /^sign in$/i }).click();
  await page.getByText(/^hello, /i).waitFor({ timeout: 20_000 });
}

async function signOnCanvas(page) {
  const canvas = page.locator('canvas').first();
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (box === null) throw new Error('The signature pad is not visible');
  await page.mouse.move(box.x + 20, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + 15, { steps: 8 });
  await page.mouse.move(box.x + box.width - 20, box.y + box.height - 15, { steps: 8 });
  await page.mouse.up();
  await page.getByRole('button', { name: /confirm signature|unterschrift bestätigen/i }).click();
}

// Page 1 of the demo service report; its labels are the bundled template's, in German.
async function fillJob(page) {
  await page.getByRole('textbox', { name: /^Kunde/ }).fill('Beispiel GmbH');
  await page.getByRole('textbox', { name: /^Einsatzort/ }).fill('Musterweg 1\n10115 Berlin');
  await page.getByRole('combobox', { name: /Art des Einsatzes/ }).selectOption('maintenance');
  await page.getByRole('textbox', { name: /Durchgeführte Arbeiten/ }).fill('Wartung der Anlage, Filter getauscht');
  await page.evaluate(() => window.scrollTo(0, 0));
}

// Pages 2 and 3, the review and the submit; returns the number the server assigned.
async function finishAndSubmit(page) {
  await page.getByRole('button', { name: NEXT }).click();
  const description = page.getByRole('textbox', { name: /^Beschreibung/ }).first();
  if (!(await description.isVisible())) await page.getByRole('button', { name: ADD_ROW }).first().click();
  await description.fill('Arbeitszeit');
  await page.getByRole('textbox', { name: /^Menge/ }).first().fill('1.5');
  await page.getByRole('textbox', { name: /^Einzelpreis/ }).first().fill('65.00');
  await page.getByRole('button', { name: NEXT }).click();
  await page.getByRole('checkbox', { name: /ordnungsgemäß ausgeführt/ }).check();
  await signOnCanvas(page);
  await page.getByRole('button', { name: /^(review|prüfen|überprüfen)$/i }).click();
  await page.waitForURL(/\/review$/);
  await page.getByRole('button', { name: /^(submit|einreichen|absenden)$/i }).click();
  const shown = page.getByText(NUMBER).first();
  await shown.waitFor({ timeout: 20_000 });
  return NUMBER.exec((await shown.textContent()) ?? '')?.[0] ?? '';
}

// The field worker's screens: [form list, service report page 1 filled], the submission number and the PDF's bytes.
async function fieldApp(page) {
  await fieldAppLogin(page, WORKER);
  await page.goto('/app/forms');
  await page.getByText(SERVICE_REPORT, { exact: true }).first().waitFor();
  await settle(page);
  const list = await page.screenshot();
  await page.getByText(SERVICE_REPORT, { exact: true }).first().click();
  await page.waitForURL(/\/app\/fill\//);
  await fillJob(page);
  const filling = await page.screenshot();
  const number = await finishAndSubmit(page);
  if (number === '') throw new Error('The Field App showed no submission number');
  const open = page.getByRole('link', { name: /^open pdf/i });
  await open.waitFor({ timeout: 120_000 });
  const response = await page.request.get((await open.getAttribute('href')) ?? '');
  const pdf = await response.body();
  if (!response.ok() || pdf.subarray(0, 5).toString() !== '%PDF-') throw new Error(`No PDF for ${number}`);
  return { phones: [list, filling], number, pdf };
}

// The PDF in the browser's own viewer, fitted to the width. Playwright serves the fetched bytes inline under the
// instance's origin, so neither the worker's session nor the link's disposition matters. The viewer renders inside the
// browser's own extension frame, which Playwright cannot observe: a fixed pause lets it draw.
async function capturePdf(page, base, number, pdf) {
  const url = `${base}/screenshots/${number}.pdf`;
  await page.route(url, (route) => route.fulfill({ body: pdf, contentType: 'application/pdf' }));
  await page.goto(`${url}#navpanes=0&view=FitH`);
  await page.waitForTimeout(3_000);
  return page.screenshot();
}

const dataUrl = (png) => `data:image/png;base64,${png.toString('base64')}`;

// Phone screenshots side by side on a plain background, at the desktop size of the other screenshots.
async function framePhones(page, phones) {
  const images = phones.map((png) => `<img src="${dataUrl(png)}" alt="">`).join('');
  await page.setContent(`<!doctype html><html><head><style>
    html, body { margin: 0; height: 100%; }
    body { display: flex; align-items: center; justify-content: center; gap: 96px;
      background: linear-gradient(135deg, #f8fafc, #e2e8f0); }
    img { height: 780px; border: 12px solid #0f172a; border-radius: 44px; box-shadow: 0 24px 60px rgb(15 23 42 / 25%); }
  </style></head><body>${images}</body></html>`);
  await page.waitForFunction(() => [...document.images].every((image) => image.complete && image.naturalWidth > 0));
  return page.screenshot();
}

async function capture(browser, base) {
  const desktop = await browser.newContext({ ...DESKTOP, baseURL: base });
  const phone = await browser.newContext({ ...PHONE, baseURL: base });
  for (const context of [desktop, phone]) context.setDefaultTimeout(30_000);
  const admin = await desktop.newPage();
  await adminLogin(admin, OWNER);
  await switchToEnglish(admin);
  for (const key of EXTRA_TEMPLATES) await createFromTemplate(admin, key);
  const forms = await captureForms(admin);
  const builder = await captureBuilder(admin);
  const worker = await fieldApp(await phone.newPage());
  const pdf = await capturePdf(await desktop.newPage(), base, worker.number, worker.pdf);
  const fieldAppPng = await framePhones(await desktop.newPage(), worker.phones);
  return { 'forms.png': forms, 'builder.png': builder, 'pdf.png': pdf, 'field-app.png': fieldAppPng };
}

async function main() {
  const { base, out } = parseCli(process.argv.slice(2));
  const browser = await chromium.launch({ channel: 'chromium' });
  try {
    const files = await capture(browser, base);
    mkdirSync(out, { recursive: true });
    for (const [name, png] of Object.entries(files)) {
      writeFileSync(join(out, name), png);
      process.stdout.write(`${join(out, name)} (${Math.round(png.length / 1024)} KiB)\n`);
    }
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  process.stderr.write(`Screenshots failed: ${error.message}\n`);
  process.exit(1);
});
