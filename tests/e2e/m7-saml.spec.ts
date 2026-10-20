import { expect, test } from './support/test';
import { fieldAppSsoLogin, freshPage, rolesIn } from './support/keycloak';
import { devStackOnly } from './support/target';

// M7 SAML 2.0 (BUILD_PROMPT §8.7, ADR 0036 "SAML"): the Field App sends saml.reviewer to Keycloak's SAML
// endpoint; the signed response comes back through the ACS (no session, one-time ticket) and
// /auth/sso/saml/complete. JIT applies groups=form-reviewers → Reviewer. Needs COMPOSE_PROFILES=sso and
// the SsoDemoSeeder data.
devStackOnly('needs the dev Keycloak (COMPOSE_PROFILES=sso), SSO_ALLOW_INSECURE_IDP and the SsoDemoSeeder data');
const evidence = (name: string) => `docs/agent/milestones/evidence/M7/${name}.png`;

test.describe('single sign-on through Keycloak (SAML)', () => {
  test.use({ actionTimeout: 30_000 });

  test('saml.reviewer signs in from the Field App and gets the Reviewer role', async ({ browser }) => {
    const { context, page } = await freshPage(browser);
    await fieldAppSsoLogin(page, 'saml.reviewer@saml-demo.test');
    await page.screenshot({ path: evidence('saml-01-field-app-home') });
    expect(await rolesIn(page, 'saml-demo')).toEqual(expect.arrayContaining(['reviewer', 'field_worker']));
    await context.close();
  });
});
