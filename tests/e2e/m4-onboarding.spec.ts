import { test, expect, request as apiRequest, type APIRequestContext, type Page } from './support/test';
import { devStackOnly, mailpitOptions } from './support/target';

// Journey 1 (§12): an owner registers an organization (email verified via Mailpit) and invites a
// designer and a field worker, who accept their invitations. Requires APP_ALLOW_REGISTRATION=true
// (set in CI and in .env for the dev stack).
devStackOnly('organization sign-up needs APP_ALLOW_REGISTRATION=true, which an fsa instance never sets');
const evidence = (name: string) => `docs/agent/milestones/evidence/M4/${name}.png`;
const RUN = Date.now();
const OWNER = `owner-${RUN}@example.test`;
const ORG = `Onboarding ${RUN} GmbH`;

async function linkFromMail(mailpit: APIRequestContext, to: string, pattern: RegExp): Promise<string> {
  let link = '';
  await expect(async () => {
    const found = (await (
      await mailpit.get(`/api/v1/search?query=${encodeURIComponent(`to:${to}`)}`)
    ).json()) as {
      messages: { ID: string }[];
    };
    expect(found.messages.length).toBeGreaterThan(0);
    const message = (await (await mailpit.get(`/api/v1/message/${found.messages[0]?.ID}`)).json()) as {
      HTML: string;
    };
    const match = message.HTML.replaceAll('&amp;', '&').match(pattern);
    expect(match).not.toBeNull();
    link = match?.[0] ?? '';
  }).toPass({ timeout: 60_000, intervals: [1_000, 2_000, 5_000] });
  return link;
}

async function invite(page: Page, email: string, name: string, role: RegExp): Promise<void> {
  await page.getByRole('button', { name: /mitglied einladen|invite member/i }).click();
  const dialog = page.getByRole('dialog').filter({ hasText: /mitglied einladen|invite member/i });
  await dialog.getByRole('textbox', { name: /e-mail|email/i }).fill(email);
  await dialog.getByRole('textbox', { name: /^name/i }).fill(name);
  await dialog.getByRole('checkbox', { name: role }).check();
  await dialog
    .getByRole('button', { name: /absenden|submit|einladen|invite/i })
    .last()
    .click();
  await expect(page.getByText(/einladung versendet|invitation sent/i).first()).toBeVisible();
}

test('journey 1: register an organization and onboard a designer and a field worker', async ({
  page,
  browser,
}) => {
  test.setTimeout(240_000);
  const mailpit = await apiRequest.newContext(mailpitOptions());

  await page.goto('/admin/register');
  await page.waitForFunction(() => 'Livewire' in window);
  await page.waitForLoadState('networkidle');
  // Filament's auth forms are addressed by their input ids (labels are not associated).
  await page.locator('#form\\.name').fill('Otto Onboarding');
  await page.locator('#form\\.email').fill(OWNER);
  await page.locator('#form\\.password').fill('Sicher-Passwort-2026!');
  await page.locator('#form\\.passwordConfirmation').fill('Sicher-Passwort-2026!');
  await page.getByRole('button', { name: /sign up|registrieren/i }).click();

  await page.goto(
    await linkFromMail(mailpit, OWNER, /https?:\/\/[^"'\s<>]*\/email-verification\/verify[^"'\s<>]*/),
  );
  await expect(page.locator('#form\\.name')).toBeVisible();
  await page.locator('#form\\.name').fill(ORG);
  await page
    .getByRole('button', { name: /register|registrieren|anlegen|create/i })
    .last()
    .click();
  await expect(page).toHaveURL(/\/admin\/[a-z0-9-]+$/);
  await page.screenshot({ path: evidence('01-org-registered'), fullPage: true });

  await page.getByRole('link', { name: /^(mitglieder|members)$/i }).click();
  await expect(page).toHaveURL(/\/members/);
  const designer = `designer-${RUN}@example.test`;
  const worker = `worker-${RUN}@example.test`;
  await invite(page, designer, 'Dora Designer', /formulargestalter|designer/i);
  await invite(page, worker, 'Wim Worker', /außendienst|field worker/i);
  await page.screenshot({ path: evidence('02-invited'), fullPage: true });

  for (const [email, target] of [
    [designer, /\/admin\//],
    [worker, /\/app/],
  ] as const) {
    const context = await browser.newContext();
    const invitee = await context.newPage();
    await invitee.goto(await linkFromMail(mailpit, email, /https?:\/\/[^"'\s<>]*\/invitations\/[^"'\s<>]+/));
    await invitee.locator('#password').fill('Neues-Passwort-2026!');
    await invitee.locator('#password_confirmation').fill('Neues-Passwort-2026!');
    await invitee.getByRole('button').last().click();
    await expect(invitee).toHaveURL(target);
    await context.close();
  }
  await page.reload();
  for (const email of [designer, worker]) {
    await expect(page.getByRole('row').filter({ hasText: email })).toContainText(/aktiv|active/i);
  }
  await page.screenshot({ path: evidence('03-members-active'), fullPage: true });
  await mailpit.dispose();
});
