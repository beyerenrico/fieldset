/**
 * Email-first sign-in with home-realm discovery (M7, ADR 0036): the password step, the redirect to the
 * organization's identity provider, the return (`?sso=ok` / `?sso_error=<code>`) and `sso_required`.
 */
import { createMemoryHistory } from '@tanstack/react-router';
import { render, screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { StrictMode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { App, createAppQueryClient } from '../../src/App.tsx';
import type { DiscoveryResult } from '../../src/lib/api/types.ts';
import { getDeviceRecord } from '../../src/lib/device/credentials.ts';
import { hasPendingLogout, markPendingLogout } from '../../src/lib/session/pending-logout.ts';
import { loadSession } from '../../src/lib/session/session-cache.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { onSyncRequested, type SyncReason } from '../../src/lib/sync/triggers.ts';
import { leaveForSso } from '../../src/screens/login/leave.ts';
import { createBackend } from '../utils/backend.ts';
import { seedUnsynced } from '../utils/c-rows.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';
import { enterEmail, signIn } from '../utils/sign-in.ts';

const order = vi.hoisted((): string[] => []);

vi.mock('../../src/screens/login/leave.ts', () => ({
    leaveForSso: vi.fn((url: string) => order.push(`leave ${url}`)),
}));

vi.mock('../../src/lib/drafts/draft-session.ts', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../src/lib/drafts/draft-session.ts')>();
    return {
        ...actual,
        saveAndDisposeDraftSessions: vi.fn(async () => {
            order.push('drafts');
            await actual.saveAndDisposeDraftSessions();
        }),
    };
});

setupTestServer();

const REDIRECT =
    'http://localhost:3000/auth/sso/0b9f2d6e-5c1a-4e8b-9f3d-7a2c4e6b8d10/redirect?client=app';
const SSO: DiscoveryResult = {
    mode: 'sso',
    redirect_url: REDIRECT,
    organization: 'SSO Demo GmbH',
    enforced: true,
};

function signedOutBackend(overrides: Parameters<typeof createBackend>[0] = {}) {
    order.length = 0;
    vi.mocked(leaveForSso).mockClear();
    const backend = createBackend({ loggedIn: false, ...overrides });
    server.use(...backend.handlers);
    return backend;
}

describe('email step', () => {
    it('asks for the password when discovery says so; the email stays and can be changed', async () => {
        const backend = signedOutBackend();
        const { user } = renderApp('/login');
        await enterEmail(user, ' worker@demo.test ');
        const password = await screen.findByLabelText('Password');
        expect(password).toHaveFocus();
        expect(screen.getByLabelText('Email')).toHaveValue('worker@demo.test');
        expect(screen.getByLabelText('Email')).toHaveAttribute('readonly');
        expect(backend.state.discoveries).toEqual(['worker@demo.test']);

        await user.click(screen.getByRole('button', { name: 'Use a different email' }));
        const email = await screen.findByLabelText('Email');
        expect(email).toHaveFocus();
        expect(email).toHaveValue('worker@demo.test');
        expect(email).not.toHaveAttribute('readonly');
        expect(screen.queryByLabelText('Password')).toBeNull();
    });

    it('posts the email with the CSRF token after fetching the cookie', async () => {
        const calls: string[] = [];
        let body: unknown = null;
        let xsrf: string | null = null;
        server.use(
            http.get('*/sanctum/csrf-cookie', () => {
                calls.push('csrf');
                return new HttpResponse(null, { status: 204 });
            }),
            http.post('*/auth/sso/discover', async ({ request }) => {
                calls.push('discover');
                body = await request.json();
                xsrf = request.headers.get('X-XSRF-TOKEN');
                return HttpResponse.json({ mode: 'password' });
            }),
        );
        const { user } = renderApp('/login');
        await enterEmail(user);
        await screen.findByLabelText('Password');
        expect(calls).toEqual(['csrf', 'discover']);
        expect(body).toEqual({ email: 'worker@demo.test' });
        expect(xsrf).toBe('xsrf=token');
    });

    it.each([
        [HttpResponse.error(), 'You are offline. Signing in needs a connection.'],
        [
            HttpResponse.json({ message: 'x', errors: { email: ['bad'] } }, { status: 422 }),
            'Enter a valid email address.',
        ],
        [HttpResponse.json({ message: 'slow' }, { status: 429 }), 'Too many attempts'],
        [HttpResponse.json({ message: 'boom' }, { status: 500 }), 'Something went wrong'],
        [HttpResponse.json({ mode: 'sso', redirect_url: 'javascript:alert(1)' }), 'Something'],
    ])('explains a failed discovery and stays on the email step (%#)', async (response, text) => {
        server.use(http.post('*/auth/sso/discover', () => response));
        const { user } = renderApp('/login');
        await enterEmail(user);
        expect(await screen.findByRole('alert')).toHaveTextContent(text);
        expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
        expect(leaveForSso).not.toHaveBeenCalled();
    });
});

describe('single sign-on', () => {
    it('saves open drafts and sends a pending sign-out before leaving for the IdP', async () => {
        markPendingLogout();
        const backend = signedOutBackend({ discovery: SSO });
        let logouts = 0;
        server.use(
            // The app start tries first and fails: the sign-out is still pending at the redirect.
            http.post('*/auth/logout', () => {
                logouts += 1;
                order.push(logouts === 1 ? 'logout failed' : 'logout');
                return new HttpResponse(null, { status: logouts === 1 ? 503 : 204 });
            }),
        );
        const { user } = renderApp('/login');
        await enterEmail(user, 'sso.designer@sso-demo.test');
        expect(
            await screen.findByRole('heading', { name: 'Continue with SSO Demo GmbH' }),
        ).toHaveFocus();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Taking you to the sign-in page of your organization…',
        );
        expect(screen.getByRole('link', { name: 'Continue with SSO Demo GmbH' })).toHaveAttribute(
            'href',
            REDIRECT,
        );
        await waitFor(() => {
            expect(leaveForSso).toHaveBeenCalledWith(REDIRECT);
        });
        expect(order).toEqual(['logout failed', 'drafts', 'logout', `leave ${REDIRECT}`]);
        expect(hasPendingLogout()).toBe(false);
        expect(backend.state.discoveries).toEqual(['sso.designer@sso-demo.test']);
        expect(screen.queryByLabelText('Password')).toBeNull();

        // Back from the IdP (browser history): the email can be changed.
        await user.click(screen.getByRole('button', { name: 'Use a different email' }));
        expect(await screen.findByLabelText('Email')).toHaveFocus();
        expect(screen.getByLabelText('Email')).toHaveValue('sso.designer@sso-demo.test');
    });

    it('names no organization when the answer has none', async () => {
        signedOutBackend({ discovery: { ...SSO, organization: '' } });
        const { user } = renderApp('/login');
        await enterEmail(user);
        expect(
            await screen.findByRole('heading', { name: 'Continue with single sign-on' }),
        ).toBeInTheDocument();
        await waitFor(() => {
            expect(leaveForSso).toHaveBeenCalledWith(REDIRECT);
        });
    });

    it('offers SSO when the password is refused with sso_required', async () => {
        const backend = signedOutBackend();
        server.use(
            http.post('*/auth/login', () =>
                HttpResponse.json(
                    { message: 'SSO', errors: { sso_required: ['Use SSO.'] } },
                    { status: 422 },
                ),
            ),
        );
        const { user } = renderApp('/login');
        await signIn(user, 'legacy@sso-demo.test', 'password');
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Your organization requires single sign-on.',
        );
        expect(screen.queryByText('The email or password is not correct.')).toBeNull();
        backend.state.discovery = SSO;
        await user.click(screen.getByRole('button', { name: 'Continue with single sign-on' }));
        await waitFor(() => {
            expect(leaveForSso).toHaveBeenCalledWith(REDIRECT);
        });
        expect(backend.state.discoveries).toEqual(['legacy@sso-demo.test', 'legacy@sso-demo.test']);
    });
});

describe('return from the identity provider', () => {
    it('completes the sign-in on ?sso=ok (device, me, sync)', async () => {
        const reasons: SyncReason[] = [];
        const stop = onSyncRequested((reason) => reasons.push(reason));
        let registrations = 0;
        const backend = createBackend({ deviceSecret: 'sso-secret' });
        server.use(...backend.handlers);
        server.use(
            http.post('*/api/v1/devices', async ({ request }) => {
                registrations += 1;
                const input = (await request.json()) as Record<string, string>;
                return HttpResponse.json(
                    {
                        success: true,
                        data: { device_uuid: input.device_uuid, secret: 'sso-secret' },
                    },
                    { status: 201 },
                );
            }),
        );
        const { history } = renderApp('/login?sso=ok');
        expect(
            await screen.findByRole('heading', { name: /Hello, Willi Worker/ }),
        ).toBeInTheDocument();
        stop();
        expect(history.location.pathname).toBe('/app/');
        expect(history.location.search).toBe('');
        expect(reasons).toContain('login');
        expect(registrations).toBe(1);
        expect((await getDeviceRecord())?.secret).toBe('sso-secret');
        expect((await loadSession())?.userId).toBe(7);
    });

    it('completes the SSO return once under StrictMode', async () => {
        let registrations = 0;
        server.use(...createBackend().handlers);
        server.use(
            http.post('*/api/v1/devices', async ({ request }) => {
                registrations += 1;
                const input = (await request.json()) as Record<string, string>;
                return HttpResponse.json(
                    { success: true, data: { device_uuid: input.device_uuid, secret: 's' } },
                    { status: 201 },
                );
            }),
        );
        const history = createMemoryHistory({ initialEntries: ['/app/login?sso=ok'] });
        render(
            <StrictMode>
                <App queryClient={createAppQueryClient()} history={history} />
            </StrictMode>,
        );
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
        expect(registrations).toBe(1);
    });

    it("asks about another user's unsynced data after the SSO return", async () => {
        await seedUnsynced({ orgId: 1, userId: 99 }, 'theirs');
        server.use(...createBackend().handlers);
        renderApp('/login?sso=ok');
        expect(await screen.findByRole('heading', { name: 'Data of another user' })).toHaveFocus();
    });

    it.each([
        ['sso_failed', 'Single sign-on did not work. Please try again.'],
        ['sso_denied', 'The sign-in was cancelled or refused by your organization.'],
        ['account_inactive', 'Your account is deactivated.'],
        ['not_member', 'Your account does not belong to this organization.'],
        ['domain_not_verified', 'is not verified for single sign-on'],
        ['email_unverified', 'Your organization has not confirmed your email address.'],
        ['provider_unavailable', 'The sign-in service of your organization cannot be reached.'],
        ['something_new', 'Single sign-on did not work. Please try again.'],
    ])('explains ?sso_error=%s and starts over with the email', async (code, text) => {
        signedOutBackend();
        const { user } = renderApp(`/login?sso_error=${code}`);
        expect(await screen.findByRole('alert')).toHaveTextContent(text);
        const email = screen.getByLabelText('Email');
        expect(email).toHaveAccessibleDescription(expect.stringContaining(text));
        await enterEmail(user);
        expect(await screen.findByLabelText('Password')).toBeInTheDocument();
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('explains SSO errors in formal German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        signedOutBackend();
        renderApp('/login?sso_error=not_member');
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Ihr Konto gehört nicht zu dieser Organisation. Bitte fragen Sie Ihre Administration nach einer Einladung.',
        );
        expect(screen.getByLabelText('E-Mail')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Weiter' })).toBeDisabled();
    });
});
