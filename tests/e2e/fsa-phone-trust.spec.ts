import { expectAccessible } from './support/axe';
import { test, expect, type Page } from './support/test';

// Phone trust page of a running `fsa` instance in local-CA mode (plan U14, R19, ADR 0057/0082), project
// `fsa-instance` only (never part of the desktop run). Everything comes from the environment:
// - FSA_PHONE_TRUST_URL: the page on plain HTTP, e.g. http://192.168.178.24/phone-trust (unset: all tests skip)
// - FSA_LOCAL_CA_FINGERPRINT: the root's SHA-256 fingerprint as `fsa` printed it (optional)
// - FSA_FIELD_APP_URL: the Field App over HTTPS, e.g. https://192.168.178.24/app/ (optional; the trailing slash
//   matters: the service worker's scope is /app/)
// - FSA_LOCAL_CA_SPKI: comma-separated base64 SHA-256 SPKI hashes Chromium accepts without a certificate error
//   (--ignore-certificate-errors-spki-list), standing in for a phone that trusts the local CA (optional)
// Never `ignoreHTTPSErrors`: the trust check must see the certificate exactly as the browser judges it.

const TRUST_URL = process.env.FSA_PHONE_TRUST_URL ?? '';
const FINGERPRINT = (process.env.FSA_LOCAL_CA_FINGERPRINT ?? '').trim().toUpperCase();
const FIELD_APP_ENV = process.env.FSA_FIELD_APP_URL ?? '';
const FIELD_APP_URL = FIELD_APP_ENV === '' ? '' : FIELD_APP_ENV.replace(/\/*$/, '/');
const SPKI = (process.env.FSA_LOCAL_CA_SPKI ?? '').trim();
const FINGERPRINT_FORMAT = /^[0-9A-F]{2}(:[0-9A-F]{2}){31}$/;
const CHECK_TIMEOUT = 15_000;

test.skip(TRUST_URL === '', 'FSA_PHONE_TRUST_URL is not set: these tests need an fsa instance in local-CA mode.');

if (SPKI !== '') {
  test.use({ launchOptions: { args: [`--ignore-certificate-errors-spki-list=${SPKI}`] } });
}

function trustPage(lang?: 'de' | 'en'): string {
  const url = new URL(TRUST_URL);
  if (lang !== undefined) url.searchParams.set('lang', lang);
  return url.toString();
}

/** Waits until the trust check has settled (trusted or not yet), so nothing changes under axe. */
async function settledCheck(page: Page): Promise<string | null> {
  const status = page.locator('#trust-status');
  await expect(status).not.toHaveAttribute('data-trust-state', 'checking', { timeout: CHECK_TIMEOUT });
  return status.getAttribute('data-trust-state');
}

test.describe('trust page at phone width', () => {
  test.use({ viewport: { width: 375, height: 812 } });

  for (const lang of ['de', 'en'] as const) {
    test(`fits the screen, shows the fingerprint and is accessible (${lang})`, async ({ page }) => {
      await page.goto(trustPage(lang));
      await expect(page.locator('html')).toHaveAttribute('lang', lang);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

      const fingerprint = page.locator('[data-fingerprint]');
      await expect(fingerprint).toHaveText(FINGERPRINT_FORMAT);
      if (FINGERPRINT !== '') await expect(fingerprint).toHaveText(FINGERPRINT);

      expect(await settledCheck(page)).toMatch(/^(trusted|untrusted)$/);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow, 'horizontal overflow in px').toBeLessThanOrEqual(0);
      await expectAccessible(page, `phone trust page (${lang})`);
    });
  }

  test('offers the public root certificate and nothing else', async ({ page }) => {
    await page.goto(trustPage());
    const href = await page.locator('[data-root-download]').getAttribute('href');
    expect(href).toBe('/phone-trust/root.crt');

    const response = await page.request.get(new URL(href ?? '', TRUST_URL).toString());
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('application/x-x509-ca-cert');
    const body = await response.text();
    expect(body).toMatch(/^-----BEGIN CERTIFICATE-----\r?\n[A-Za-z0-9+/=\r\n]+-----END CERTIFICATE-----\r?\n?$/);
    expect(body).not.toContain('PRIVATE KEY');
  });
});

test.describe('in a browser that trusts the local CA', () => {
  test.skip(FIELD_APP_URL === '', 'FSA_FIELD_APP_URL is not set.');

  test('the Field App registers its service worker over HTTPS', async ({ page }) => {
    await page.goto(FIELD_APP_URL);
    const registration = await page.evaluate(async (timeout) => {
      const ready = await Promise.race([
        navigator.serviceWorker.ready,
        new Promise<null>((resolve) => window.setTimeout(() => resolve(null), timeout)),
      ]);
      return ready === null ? null : { scope: ready.scope, active: ready.active !== null };
    }, 30_000);

    expect(registration, 'navigator.serviceWorker.ready within 30 s').not.toBeNull();
    expect(registration?.active).toBe(true);
    expect(registration?.scope).toMatch(/\/app\/$/);
  });

  test('the trust check reports trust and links to the Field App', async ({ page }) => {
    await page.goto(trustPage());
    expect(await settledCheck(page)).toBe('trusted');

    const open = page.locator('#trust-open a');
    await expect(open).toBeVisible();
    await expect(open).toHaveAttribute('href', FIELD_APP_URL);
    await open.click();
    await expect(page).toHaveURL(new RegExp(`^${FIELD_APP_URL.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
  });
});
