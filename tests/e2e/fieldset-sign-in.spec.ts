import { expectAccessible } from './support/axe';
import { artisan, deleteUsers } from './support/platform';
import type { Request } from '@playwright/test';
import { test, expect, type Page } from './support/test';
import { freshTotp } from './support/totp';

// One-time sign-in links (BUILD_PROMPT_V2 R27/R28, KTD21, ADR 0056/0068), driven through the app-side commands
// that `fieldset login-link` runs as socket-less one-offs. The token travels in the URL fragment: no request line
// carries it, only the landing page's POST body does. Each test creates its own demo-organization admin in the
// app container, so reruns need no reseed; afterAll deletes them again (with their membership, operator grant
// and links), or they pile up as demo members.

const MFA_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const PASSWORD = 'Link-Passwort-2026!';
const created: string[] = [];

interface Account {
    readonly email: string;
    readonly name: string;
}

interface IssuedLink {
    readonly url: string;
    readonly token: string;
}

function newAccount(prefix: string): Account {
    return { email: `${prefix}-${Date.now()}@demo.test`, name: 'Lina Link' };
}

/** A verified admin of the demo organization; optionally with confirmed TOTP and the operator role. */
function createAdmin(account: Account, options: { mfa?: boolean; operator?: boolean } = {}): void {
    const attributes = JSON.stringify({
        name: account.name,
        email: account.email,
        password: PASSWORD,
    });
    created.push(account.email);
    const mfa = options.mfa
        ? `App\\Domain\\Identity\\Auth\\SharedAppAuthentication::make()->recoverable()->saveSecret($u, '${MFA_SECRET}');`
        : '';
    artisan(
        'tinker',
        '--execute',
        `$u = App\\Domain\\Identity\\Models\\User::query()->create(json_decode('${attributes}', true));` +
            `$u->forceFill(['email_verified_at' => now()])->save();` +
            `$o = App\\Domain\\Identity\\Models\\Organization::query()->where('slug', 'demo')->sole();` +
            `app(App\\Domain\\Identity\\Tenancy\\TenantContext::class)->run($o, fn () => ` +
            `app(App\\Domain\\Identity\\Actions\\AddMember::class)->handle($o, $u, ['admin']));` +
            mfa,
    );
    if (options.operator) {
        expect(artisan('app:platform-admin', account.email)).toMatch(/granted/i);
    }
}

function linkOf(url: string): IssuedLink {
    expect(url).toMatch(/\/login-link#[A-Za-z0-9]{64}$/);
    return { url, token: url.split('#')[1] };
}

/** `identity:login-link --json`: the link is only under `secrets`. */
function issueAdminLink(email: string): IssuedLink {
    const json = JSON.parse(
        artisan('identity:login-link', email, '--json', '--actor-uid=501').trim(),
    );
    expect(json.status).toBe('ok');
    expect(JSON.stringify(json.data)).not.toContain('#');
    return linkOf(json.secrets[0].value);
}

/** The owner's first link (reset broker) for an existing account: what `app:install --owner-link` issues. */
function issueOwnerLink(email: string): IssuedLink {
    const output = artisan(
        'tinker',
        '--execute',
        `echo app(App\\Domain\\Identity\\Actions\\IssueOwnerLink::class)->handle(` +
            `App\\Domain\\Identity\\Models\\User::query()->where('email', '${email}')->sole(), 501)->url;`,
    );
    const url = output.match(/https?:\/\/\S+\/login-link#[A-Za-z0-9]+/)?.[0] ?? '';
    return linkOf(url);
}

/** Records every request line and the body of every POST, to prove where the token goes. */
function recordRequests(page: Page): { lines: string[]; posts: string[] } {
    const seen = { lines: [] as string[], posts: [] as string[] };
    page.on('request', (request: Request) => {
        seen.lines.push(`${request.method()} ${request.url()}`);
        if (request.method() === 'POST') seen.posts.push(request.postData() ?? '');
    });
    return seen;
}

test.describe.serial('one-time sign-in links', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test.afterAll(() => {
        deleteUsers(created.splice(0));
    });

    test('an admin link signs in through the fragment, never in a request line, and works only once', async ({
        page,
        browser,
    }) => {
        const admin = newAccount('link-admin');
        createAdmin(admin);
        const link = issueAdminLink(admin.email);
        const seen = recordRequests(page);

        await page.goto(link.url);
        await expect(page).toHaveURL(/\/admin\/demo/, { timeout: 20_000 });

        expect(seen.lines.filter((line) => line.includes(link.token))).toEqual([]);
        expect(seen.lines.filter((line) => /^GET \S+\/login-link(\?|$)/.test(line))).toHaveLength(
            1,
        );
        expect(seen.posts.filter((body) => body.includes(`token=${link.token}`))).toHaveLength(1);

        const again = await (
            await browser.newContext({ storageState: { cookies: [], origins: [] } })
        ).newPage();
        await again.goto(link.url);
        await expect(again.locator('main[data-login-link-state="used"]')).toBeVisible();
        await expect(again.getByText('fieldset login-link <email>')).toBeVisible();
        await expect(again).not.toHaveURL(/#/);
        await expectAccessible(again, 'sign-in link: used');
    });

    test('an admin with MFA still passes the MFA challenge, and the link never opens /platform', async ({
        page,
    }) => {
        const admin = newAccount('link-mfa');
        createAdmin(admin, { mfa: true, operator: true });

        await page.goto(issueAdminLink(admin.email).url);
        await expect(page).toHaveURL(/\/admin\/login/, { timeout: 20_000 });
        const code = page.locator('input[autocomplete="one-time-code"]');
        await expect(code).toBeVisible();
        await expect(page.locator('input[type="password"]')).toHaveCount(0);

        await code.fill(await freshTotp(MFA_SECRET)); // Filament submits a complete code by itself
        await expect(page).toHaveURL(/\/admin\/demo/, { timeout: 20_000 });

        await page.goto('/platform/organizations');
        await expect(page).toHaveURL(/\/platform\/login/);
    });

    test('the owner link leads to setting a password, offers MFA, and shows "used" afterwards', async ({
        page,
        browser,
    }) => {
        const owner = newAccount('link-owner');
        createAdmin(owner);
        const link = issueOwnerLink(owner.email);
        const seen = recordRequests(page);

        await page.goto(link.url);
        await expect(page).toHaveURL(/\/admin\/password-reset\/reset\?/, { timeout: 20_000 });
        expect(page.url()).not.toContain(link.token);
        await page.waitForFunction(() => 'Livewire' in window);
        await page.waitForLoadState('networkidle');
        const passwords = page.locator('input[type="password"]');
        await passwords.nth(0).fill('N3ues-Owner-Passwort!');
        await passwords.nth(1).fill('N3ues-Owner-Passwort!');
        await page.getByRole('button', { name: /(reset password|passwort zurücksetzen)/i }).click();

        await expect(page).toHaveURL(/\/admin\/profile/, { timeout: 20_000 });
        await expect(
            page.getByText(
                /(two-factor authentication \(MFA\)|Zwei-Faktor-Authentifizierung \(MFA\))/,
            ),
        ).toBeVisible();
        expect(seen.lines.filter((line) => line.includes(link.token))).toEqual([]);

        const again = await (
            await browser.newContext({ storageState: { cookies: [], origins: [] } })
        ).newPage();
        await again.goto(link.url);
        await expect(again.locator('main[data-login-link-state="used"]')).toBeVisible();
        await expect(again.getByText('fieldset login-link <email>')).toBeVisible();
    });

    test('expired and invalid links have their own German and English states', async ({
        browser,
    }) => {
        const admin = newAccount('link-expired');
        createAdmin(admin);
        const expired = issueAdminLink(admin.email);
        artisan(
            'tinker',
            '--execute',
            `App\\Domain\\Identity\\Models\\LoginLink::query()->where('token_hash', hash('sha256', '${expired.token}'))` +
                `->update(['expires_at' => now()->subMinute()]);`,
        );

        for (const [locale, texts] of [
            [
                'en-US',
                {
                    expired: 'This sign-in link has expired',
                    invalid: 'This sign-in link is not valid',
                },
            ],
            [
                'de-DE',
                {
                    expired: 'Dieser Anmeldelink ist abgelaufen',
                    invalid: 'Dieser Anmeldelink ist ungültig',
                },
            ],
        ] as const) {
            const page = await (
                await browser.newContext({ locale, storageState: { cookies: [], origins: [] } })
            ).newPage();
            await page.goto(expired.url);
            await expect(page.locator('main[data-login-link-state="expired"]')).toBeVisible();
            await expect(page.getByRole('heading', { name: texts.expired })).toBeVisible();
            await expect(page.getByText('fieldset login-link <email>')).toBeVisible();

            await page.goto(`/login-link#${'x'.repeat(64)}`);
            await expect(page.locator('main[data-login-link-state="invalid"]')).toBeVisible();
            await expect(page.getByRole('heading', { name: texts.invalid })).toBeVisible();

            await page.goto('/login-link'); // no fragment at all: the page says so without a request
            await expect(page.locator('#login-link-missing')).toBeVisible();
            await expect(page.getByRole('heading', { name: texts.invalid })).toBeVisible();
            await expectAccessible(page, `sign-in link: invalid (${locale})`);
        }
    });
});
