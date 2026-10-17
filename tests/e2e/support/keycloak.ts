import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { fieldAppContinueToSso } from './field-app';

// The dev Keycloak (docker/keycloak, COMPOSE_PROFILES=sso). Its issuer is fixed by KC_HOSTNAME; Chromium
// resolves *.localhost to loopback by itself and keeps Keycloak's Secure cookies over http there.
export const KEYCLOAK_URL = process.env.E2E_KEYCLOAK_URL ?? 'http://keycloak.localhost:8180';
export const KEYCLOAK_REALM = `${KEYCLOAK_URL}/realms/form-schema`;
export const SSO_PASSWORD = 'password';

const HELLO = /^(hello|hallo), /i;

/** A fresh browser context: no app session and no Keycloak SSO session from an earlier test. */
export async function freshPage(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  return { context, page: await context.newPage() };
}

/** Waits for the Keycloak login page (OIDC or SAML) and signs in there (theme keycloak.v2). */
export async function keycloakLogin(page: Page, email: string, password = SSO_PASSWORD): Promise<void> {
  await page.waitForURL((url) => url.href.startsWith(KEYCLOAK_REALM), { timeout: 30_000 });
  const username = page.locator('#username');
  await expect(username).toBeVisible();
  await username.fill(email); // login_hint usually prefills it already
  await page.locator('#password').fill(password);
  await page.locator('#kc-login').click();
}

/** Field App: email → Continue (discovery says SSO) → Keycloak → back to the app's home. */
export async function fieldAppSsoLogin(page: Page, email: string): Promise<void> {
  await page.goto('/app/login');
  // Enforced domains leave at once; others first offer "Continue with <organization>" or a password.
  await fieldAppContinueToSso(page, email);
  await keycloakLogin(page, email);
  await expect(page.getByText(HELLO)).toBeVisible({ timeout: 30_000 });
  await expect(page).toHaveURL(/\/app\/$/); // `?sso=ok` is consumed
}

interface MeOrganization {
  readonly slug: string;
  readonly roles: readonly string[];
}

/** `GET /api/v1/me` with the page's session (Sanctum wants a same-origin Referer): roles in one organization. */
export async function rolesIn(page: Page, slug: string): Promise<readonly string[]> {
  const response = await page.request.get('/api/v1/me', {
    headers: { Accept: 'application/json', Referer: new URL('/app/', page.url()).toString() },
  });
  expect(response.ok(), `GET /api/v1/me → ${response.status()}`).toBeTruthy();
  const body = (await response.json()) as { data: { organizations: MeOrganization[] } };
  const organization = body.data.organizations.find((candidate) => candidate.slug === slug);
  expect(organization, `member of ${slug}`).toBeDefined();
  return organization?.roles ?? [];
}

/** A Fortify (web) POST with the CSRF cookie of the page's context; returns status and JSON body. */
export async function fortifyPost(
  page: Page,
  path: string,
  data: Record<string, unknown>,
): Promise<{ status: number; body: { message?: string; errors?: Record<string, string[]> } }> {
  await page.request.get('/sanctum/csrf-cookie');
  const cookies = await page.context().cookies();
  const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';
  const response = await page.request.post(path, {
    data,
    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf) },
  });
  const text = await response.text();
  return { status: response.status(), body: text === '' ? {} : (JSON.parse(text) as object) };
}
