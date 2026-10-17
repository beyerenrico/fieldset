import { expect, test } from './support/test';
import { fieldAppSignIn } from './support/field-app';
import {
  KEYCLOAK_REALM,
  fieldAppSsoLogin,
  fortifyPost,
  freshPage,
  keycloakLogin,
  rolesIn,
} from './support/keycloak';
import { BREAK_GLASS, freshTotp } from './support/totp';

// M7 SSO (BUILD_PROMPT §8.7 AC, ADR 0036): login through Keycloak creates the user with the mapped role;
// with enforcement on, password login is refused for the SSO domain. Needs the Docker stack with
// COMPOSE_PROFILES=sso (Keycloak) and the SsoDemoSeeder data. Every test uses a fresh context, so no
// Keycloak session carries over.
const evidence = (name: string) => `docs/agent/milestones/evidence/M7/${name}.png`;
const HELLO = /^(hello|hallo), /i;

test.describe('single sign-on through Keycloak (OIDC)', () => {
  test.use({ actionTimeout: 30_000 });

  test('Field App: email → Keycloak → home; JIT applies the groups mapping (Designer)', async ({ browser }) => {
    const { context, page } = await freshPage(browser);
    await fieldAppSsoLogin(page, 'sso.designer@sso-demo.test');
    await page.screenshot({ path: evidence('sso-01-field-app-home') });
    expect(await rolesIn(page, 'sso-demo')).toEqual(expect.arrayContaining(['designer', 'field_worker']));
    await context.close();
  });

  test('Field App: a JIT user without groups gets the default role only', async ({ browser }) => {
    const { context, page } = await freshPage(browser);
    await fieldAppSsoLogin(page, 'sso.worker@sso-demo.test');
    expect(await rolesIn(page, 'sso-demo')).toEqual(['field_worker']);
    await context.close();
  });

  test('admin: the email-first login continues at Keycloak and lands in the panel', async ({ browser }) => {
    const { context, page } = await freshPage(browser);
    await page.goto('/admin/login');
    await page.waitForFunction(() => 'Livewire' in window); // typing before hydration loses the input
    await page.locator('input[type="email"]').fill('sso.designer@sso-demo.test');
    await page.getByRole('button', { name: /^(continue|weiter|sign in|anmelden)$/i }).first().click();
    await keycloakLogin(page, 'sso.designer@sso-demo.test');
    await expect(page).toHaveURL(/\/admin\/sso-demo/, { timeout: 30_000 });
    await page.screenshot({ path: evidence('sso-02-admin-panel') });
    await context.close();
  });

  test('enforcement: no password step for the SSO domain; password login and reset are refused', async ({ browser }) => {
    const { context, page } = await freshPage(browser);
    await page.goto('/app/login');
    await page.getByLabel(/^(email|e-mail)$/i).fill('legacy@sso-demo.test');
    // The app leaves for the IdP as soon as discovery answers, so read the body on the way through.
    let discovery: unknown = null;
    await page.route('**/auth/sso/discover', async (route) => {
      const response = await route.fetch();
      discovery = await response.json();
      await route.fulfill({ response });
    });
    await page.getByRole('button', { name: /^(continue|weiter)$/i }).click();
    // Straight on to the identity provider: the app never shows its own password field.
    await page.waitForURL((url) => url.href.startsWith(KEYCLOAK_REALM), { timeout: 30_000 });
    expect(discovery).toMatchObject({ mode: 'sso', organization: 'SSO Demo GmbH', enforced: true });
    await expect(page.locator('#username')).toBeVisible();
    await page.screenshot({ path: evidence('sso-03-enforced-keycloak') });

    const login = await fortifyPost(page, '/auth/login', {
      email: 'legacy@sso-demo.test',
      password: 'password',
      remember: true,
    });
    expect(login.status).toBe(422);
    expect(login.body.errors?.sso_required?.length).toBeGreaterThan(0);

    const reset = await fortifyPost(page, '/auth/forgot-password', { email: 'legacy@sso-demo.test' });
    expect(reset.status).toBe(422);
    expect(reset.body.errors?.sso_required?.length).toBeGreaterThan(0);
    await context.close();
  });

  test('break-glass Owner: password and TOTP still work', async ({ browser }) => {
    const { context, page } = await freshPage(browser);
    await page.goto('/app/login');
    await fieldAppSignIn(page, BREAK_GLASS.email);
    const code = page.getByLabel(/^(authentication code|authentifizierungscode)$/i);
    await expect(code).toBeVisible({ timeout: 20_000 });
    await code.fill(await freshTotp(BREAK_GLASS.secret));
    await page.getByRole('button', { name: /^(verify|bestätigen)$/i }).click();
    await expect(page.getByText(HELLO)).toBeVisible({ timeout: 20_000 });
    expect(await rolesIn(page, 'sso-demo')).toContain('owner');
    await context.close();
  });
});
