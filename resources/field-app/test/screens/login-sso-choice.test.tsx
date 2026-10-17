/**
 * UX review of the email-first sign-in (M7, ADR 0036): an organization that does not enforce SSO offers
 * its central login next to a password; an enforcing one is left for at once with a visible fallback; a
 * failed SSO return offers a password; a refusal is read out on the focused email field; "Use a different
 * email" cancels a redirect that has not left yet.
 */
import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { DiscoveryResult } from '../../src/lib/api/types.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { leaveForSso } from '../../src/screens/login/leave.ts';
import { continueToSso } from '../../src/screens/login/sso-redirect.ts';
import { createBackend } from '../utils/backend.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';
import { enterEmail } from '../utils/sign-in.ts';

/** Holds the draft save that runs before the redirect, so the user can act while it is pending. */
const drafts = vi.hoisted(() => ({ hold: null as Promise<void> | null }));

vi.mock('../../src/screens/login/leave.ts', () => ({ leaveForSso: vi.fn() }));

// Observed, not replaced: its promise says whether the browser was sent away (true) or not (false).
vi.mock('../../src/screens/login/sso-redirect.ts', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../src/screens/login/sso-redirect.ts')>();
    return { continueToSso: vi.fn(actual.continueToSso) };
});

vi.mock('../../src/lib/drafts/draft-session.ts', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../src/lib/drafts/draft-session.ts')>();
    return {
        ...actual,
        saveAndDisposeDraftSessions: vi.fn(async () => {
            await drafts.hold;
            await actual.saveAndDisposeDraftSessions();
        }),
    };
});

setupTestServer();

afterEach(() => {
    drafts.hold = null;
});

const REDIRECT = 'http://localhost:3000/auth/sso/5d1c/redirect?client=app';
const OPTIONAL: DiscoveryResult = {
    mode: 'sso',
    redirect_url: REDIRECT,
    organization: 'SAML Demo GmbH',
    enforced: false,
};
const ENFORCED: DiscoveryResult = { ...OPTIONAL, organization: 'SSO Demo GmbH', enforced: true };
const ADMIN = 'If this keeps happening, contact your administrator.';

function signedOutBackend(discovery: DiscoveryResult = { mode: 'password' }) {
    vi.mocked(leaveForSso).mockClear();
    vi.mocked(continueToSso).mockClear();
    const backend = createBackend({ loggedIn: false, discovery });
    server.use(...backend.handlers);
    return backend;
}

/** Holds the draft save until the returned function is called. */
function holdDrafts(): () => void {
    let release = (): void => undefined;
    drafts.hold = new Promise<void>((resolve) => {
        release = resolve;
    });
    return () => {
        release();
    };
}

/** The outcome of the one redirect this test started. */
async function redirectOutcome(): Promise<boolean> {
    expect(continueToSso).toHaveBeenCalledTimes(1);
    return (await vi.mocked(continueToSso).mock.results[0]?.value) as boolean;
}

async function passwordSignIn(user: ReturnType<typeof renderApp>['user']): Promise<void> {
    const password = await screen.findByLabelText('Password');
    expect(password).toHaveFocus();
    await user.type(password, 'secret');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('heading', { name: /Hello, Willi Worker/ })).toBeInTheDocument();
}

describe('SSO that is not enforced', () => {
    it('offers the central login and a password; the password signs in', async () => {
        const backend = signedOutBackend(OPTIONAL);
        const { user } = renderApp('/login');
        await enterEmail(user, 'saml.reviewer@saml-demo.test');
        expect(
            await screen.findByRole('heading', { name: 'How would you like to sign in?' }),
        ).toHaveFocus();
        expect(screen.getByText('saml.reviewer@saml-demo.test')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Continue with SAML Demo GmbH' })).toBeVisible();
        expect(continueToSso).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Sign in with a password' }));
        expect(screen.getByLabelText('Email')).toHaveValue('saml.reviewer@saml-demo.test');
        await passwordSignIn(user);
        expect(backend.state.discoveries).toEqual(['saml.reviewer@saml-demo.test']);
        expect(continueToSso).not.toHaveBeenCalled();
        expect(leaveForSso).not.toHaveBeenCalled();
    });

    it('continues to the identity provider on request', async () => {
        signedOutBackend(OPTIONAL);
        const { user } = renderApp('/login');
        await enterEmail(user);
        await user.click(
            await screen.findByRole('button', { name: 'Continue with SAML Demo GmbH' }),
        );
        expect(
            await screen.findByRole('heading', { name: 'Continue with SAML Demo GmbH' }),
        ).toHaveFocus();
        await waitFor(() => {
            expect(leaveForSso).toHaveBeenCalledWith(REDIRECT);
        });
        expect(leaveForSso).toHaveBeenCalledTimes(1);
    });

    it('offers the choice in formal German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        signedOutBackend(OPTIONAL);
        const { user } = renderApp('/login');
        await user.type(await screen.findByLabelText('E-Mail'), 'worker@saml-demo.test');
        await user.click(screen.getByRole('button', { name: 'Weiter' }));
        expect(
            await screen.findByRole('heading', { name: 'Wie möchten Sie sich anmelden?' }),
        ).toHaveFocus();
        expect(screen.getByRole('button', { name: 'Weiter mit SAML Demo GmbH' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Mit Passwort anmelden' })).toBeVisible();
    });
});

describe('enforced SSO', () => {
    it('leaves at once and shows a visible fallback, but no password option', async () => {
        signedOutBackend(ENFORCED);
        const { user } = renderApp('/login');
        await enterEmail(user, 'legacy@sso-demo.test');
        await waitFor(() => {
            expect(leaveForSso).toHaveBeenCalledWith(REDIRECT);
        });
        const fallback = screen.getByRole('link', { name: 'Continue with SSO Demo GmbH' });
        expect(fallback).toBeVisible();
        expect(fallback).toHaveAttribute('href', REDIRECT);
        expect(screen.queryByRole('button', { name: 'Sign in with a password' })).toBeNull();
        expect(screen.queryByLabelText('Password')).toBeNull();
    });

    it('"Use a different email" during the redirect cancels it', async () => {
        const backend = signedOutBackend(ENFORCED);
        const release = holdDrafts();
        const { user } = renderApp('/login');
        await enterEmail(user, 'legacy@sso-demo.test');
        expect(
            await screen.findByRole('heading', { name: 'Continue with SSO Demo GmbH' }),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Use a different email' }));
        const email = await screen.findByLabelText('Email');
        expect(email).toHaveFocus();
        expect(leaveForSso).not.toHaveBeenCalled();
        release();
        expect(await redirectOutcome()).toBe(false);
        expect(leaveForSso).not.toHaveBeenCalled();

        // Another email continues normally.
        backend.state.discovery = { mode: 'password' };
        await user.clear(email);
        await enterEmail(user);
        expect(await screen.findByLabelText('Password')).toBeInTheDocument();
        expect(leaveForSso).not.toHaveBeenCalled();
    });

    it('leaves once the pending draft save is done (control for the cancel test)', async () => {
        signedOutBackend(ENFORCED);
        const release = holdDrafts();
        const { user } = renderApp('/login');
        await enterEmail(user, 'legacy@sso-demo.test');
        expect(
            await screen.findByRole('heading', { name: 'Continue with SSO Demo GmbH' }),
        ).toBeInTheDocument();
        expect(leaveForSso).not.toHaveBeenCalled();
        release();
        expect(await redirectOutcome()).toBe(true);
        expect(leaveForSso).toHaveBeenCalledWith(REDIRECT);
    });
});

describe('return after a failed SSO login', () => {
    it.each([
        ['sso_failed', 'The central login did not work. Please try again.'],
        ['provider_unavailable', `cannot be reached. Please try again later. ${ADMIN}`],
        ['something_new', 'The central login did not work. Please try again.'],
    ])('offers a password after ?sso_error=%s', async (code, text) => {
        const backend = signedOutBackend();
        const { user } = renderApp(`/login?sso_error=${code}`);
        expect(await screen.findByRole('alert')).toHaveTextContent(text);
        const option = screen.getByRole('button', { name: 'Sign in with a password' });
        expect(option).toBeDisabled();
        await user.type(screen.getByLabelText('Email'), ' worker@demo.test ');
        await user.click(option);
        expect(screen.getByLabelText('Email')).toHaveValue('worker@demo.test');
        expect(screen.queryByRole('alert')).toBeNull();
        await passwordSignIn(user);
        expect(backend.state.discoveries).toEqual([]);
    });

    it.each(['sso_denied', 'not_member', 'account_inactive', 'domain_not_verified'])(
        'offers no password after ?sso_error=%s',
        async (code) => {
            signedOutBackend();
            renderApp(`/login?sso_error=${code}`);
            expect(await screen.findByRole('alert')).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Sign in with a password' })).toBeNull();
        },
    );

    it('offers the password in formal German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        signedOutBackend();
        renderApp('/login?sso_error=provider_unavailable');
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Falls das weiterhin passiert, wenden Sie sich an Ihre Administration.',
        );
        expect(screen.getByRole('button', { name: 'Mit Passwort anmelden' })).toBeDisabled();
    });
});

describe('announcing errors on the email field', () => {
    it('focuses the email field on return and describes it with the refusal first', async () => {
        signedOutBackend();
        renderApp('/login?sso_error=sso_denied');
        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent(
            `The sign-in was cancelled or refused by your organization. ${ADMIN}`,
        );
        const email = screen.getByLabelText('Email');
        expect(email).toHaveFocus();
        expect(email.getAttribute('aria-describedby')?.split(' ')[0]).toBe(alert.id);
        expect(email).toHaveAccessibleDescription(
            expect.stringMatching(/^The sign-in was cancelled.+Enter your work email\./),
        );
    });

    it('moves the focus to the field when discovery fails offline, with the next step', async () => {
        server.use(http.post('*/auth/sso/discover', () => HttpResponse.error()));
        const { user } = renderApp('/login');
        await enterEmail(user);
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'No connection. Connect to mobile data or Wi-Fi, then tap Continue again.',
        );
        expect(screen.getByLabelText('Email')).toHaveFocus();
    });

    it('explains the email step without jargon', async () => {
        signedOutBackend();
        renderApp('/login');
        expect(await screen.findByLabelText('Email')).toHaveAccessibleDescription(
            'Enter your work email. If your company uses a central login, we will take you there.',
        );
        expect(screen.getByLabelText('Email')).not.toHaveFocus();
    });

    it('explains the email step and the offline case in formal German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        server.use(http.post('*/auth/sso/discover', () => HttpResponse.error()));
        const { user } = renderApp('/login');
        const email = await screen.findByLabelText('E-Mail');
        expect(email).toHaveAccessibleDescription(
            'Geben Sie Ihre geschäftliche E-Mail-Adresse ein. Nutzt Ihr Unternehmen eine zentrale Anmeldung, leiten wir Sie dorthin weiter.',
        );
        await user.type(email, 'worker@demo.test');
        await user.click(screen.getByRole('button', { name: 'Weiter' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Keine Verbindung. Bitte verbinden Sie sich mit mobilen Daten oder WLAN und tippen Sie dann erneut auf „Weiter“.',
        );
        expect(email).toHaveFocus();
    });
});
