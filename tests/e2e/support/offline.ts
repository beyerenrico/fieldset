import { expect, type Page } from '@playwright/test';
import { signOnCanvas } from './field-app';

// Helpers for the offline journeys (M6, ADR 0035): service worker, the Dexie store `fieldset-offline` and
// direct API checks with the browser's session.

/** A 1×1 PNG: enough for the photo element (the app re-encodes it). */
export const TINY_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64',
);

/** Rows in a table of the offline store (−1 when it cannot be read). */
export function countRows(page: Page, table: string): Promise<number> {
  return page.evaluate(
    (name) =>
      new Promise<number>((resolve) => {
        const open = indexedDB.open('fieldset-offline');
        open.onerror = () => resolve(-1);
        open.onsuccess = () => {
          const db = open.result;
          if (!db.objectStoreNames.contains(name)) {
            db.close();
            resolve(0);
            return;
          }
          const request = db.transaction(name).objectStore(name).count();
          request.onsuccess = () => {
            db.close();
            resolve(request.result);
          };
          request.onerror = () => {
            db.close();
            resolve(-1);
          };
        };
      }),
    table,
  );
}

/** Primary keys of a table of the offline store. */
export function tableKeys(page: Page, table: string): Promise<string[]> {
  return page.evaluate(
    (name) =>
      new Promise<string[]>((resolve) => {
        const open = indexedDB.open('fieldset-offline');
        open.onerror = () => resolve([]);
        open.onsuccess = () => {
          const db = open.result;
          if (!db.objectStoreNames.contains(name)) {
            db.close();
            resolve([]);
            return;
          }
          const request = db.transaction(name).objectStore(name).getAllKeys();
          request.onsuccess = () => {
            db.close();
            resolve(request.result.map((key) => String(key)));
          };
          request.onerror = () => {
            db.close();
            resolve([]);
          };
        };
      }),
    table,
  );
}

/**
 * Waits until the page is controlled by the service worker (a reload after the first install) and the
 * catalog cache holds at least one form version, i.e. the app can start and fill forms offline.
 */
export async function waitForOfflineReady(page: Page): Promise<void> {
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready;
  });
  await page.reload();
  await expect(page.getByText(/^(hello|hallo), /i)).toBeVisible({ timeout: 20_000 });
  await expect
    .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null), { timeout: 20_000 })
    .toBe(true);
  await expect.poll(() => countRows(page, 'formVersions'), { timeout: 30_000 }).toBeGreaterThan(0);
}

export interface ReportInput {
  readonly customer: string;
  readonly lines: number;
  readonly photo: boolean;
}

/** Opens a new service report and fills page 1 (customer and job). */
export async function startServiceReport(page: Page, customer: string): Promise<void> {
  await page.goto('/app/forms');
  await page.getByText('Servicebericht', { exact: true }).first().click();
  await expect(page).toHaveURL(/\/app\/fill\//);
  await page.getByRole('textbox', { name: /^Kunde/ }).fill(customer);
  await page.getByRole('combobox', { name: /Art des Einsatzes/ }).selectOption('maintenance');
  await page.getByRole('textbox', { name: /Durchgeführte Arbeiten/ }).fill('Offline gewartet');
}

/** Material lines: each 1.5 × 12.34 = 18.51 EUR. */
async function fillLines(page: Page, count: number): Promise<void> {
  const addRow = page
    .getByRole('button', { name: /zeile hinzufügen|add line|add entry|eintrag hinzufügen/i })
    .first();
  // The list is virtualized against the window: address each row by its header, not by position.
  const row = (line: number) =>
    page.getByRole('row').filter({
      has: page.getByRole('rowheader', { name: new RegExp(`^(eintrag|entry) ${line}$`, 'i') }),
    });
  for (let line = 1; line <= count; line++) {
    if (line > 1 || (await row(1).count()) === 0) {
      await addRow.click();
    }
    await row(line).getByRole('textbox', { name: /^Beschreibung/ }).fill(`Position ${line}`);
    await row(line).getByRole('textbox', { name: /^Menge/ }).fill('1.5');
    await row(line).getByRole('textbox', { name: /^Einzelpreis/ }).fill('12.34');
  }
}

/** Fills the whole service report and opens the review screen. */
export async function fillServiceReport(page: Page, input: ReportInput): Promise<void> {
  await startServiceReport(page, input.customer);
  await page.getByRole('button', { name: /^(next|weiter)$/i }).click();
  await fillLines(page, input.lines);
  await page.getByRole('button', { name: /^(next|weiter)$/i }).click();

  if (input.photo) {
    await page
      .locator('input[type=file]')
      .first()
      .setInputFiles({ name: 'baustelle.png', mimeType: 'image/png', buffer: TINY_PNG });
    await expect.poll(() => countRows(page, 'attachments'), { timeout: 20_000 }).toBeGreaterThan(0);
  }
  await page.getByRole('checkbox', { name: /ordnungsgemäß ausgeführt/ }).check();
  await signOnCanvas(page);
  await page.getByRole('button', { name: /^(review|prüfen|überprüfen)$/i }).click();
  await expect(page).toHaveURL(/\/review$/);
}

export async function submitFromReview(page: Page): Promise<void> {
  await page.getByRole('button', { name: /^(submit|einreichen|absenden)$/i }).click();
}

interface Envelope<T> {
  readonly data: T;
  readonly meta?: { readonly last_page?: number };
}

export interface ApiSubmission {
  readonly uuid: string;
  readonly number: string | null;
  readonly status: string;
  readonly data: Record<string, unknown>;
  readonly attachments?: readonly unknown[];
}

/** GET on /api/v1 with the browser's session (Sanctum needs a same-origin Referer) and organization. */
export async function apiGet<T>(page: Page, path: string): Promise<T> {
  return (await apiEnvelope<T>(page, path)).data;
}

async function apiEnvelope<T>(page: Page, path: string): Promise<Envelope<T>> {
  const headers = { Accept: 'application/json', Referer: new URL('/app/', page.url()).toString() };
  const me = await page.request.get('/api/v1/me', { headers });
  expect(me.ok()).toBeTruthy();
  const orgId = ((await me.json()) as Envelope<{ organizations: { id: number }[] }>).data
    .organizations[0].id;
  const response = await page.request.get(`/api/v1${path}`, {
    headers: { ...headers, 'X-Organization-Id': String(orgId) },
  });
  expect(response.ok(), `${path} → ${response.status()}`).toBeTruthy();
  return (await response.json()) as Envelope<T>;
}

/** The user's submissions whose customer field equals `customer` (all pages). */
export async function submissionsFor(page: Page, customer: string): Promise<ApiSubmission[]> {
  const found: ApiSubmission[] = [];
  for (let current = 1, last = 1; current <= last; current++) {
    const envelope = await apiEnvelope<ApiSubmission[]>(page, `/submissions?page=${current}`);
    last = envelope.meta?.last_page ?? 1;
    found.push(...envelope.data.filter((item) => item.data?.customer_name === customer));
  }
  return found;
}
