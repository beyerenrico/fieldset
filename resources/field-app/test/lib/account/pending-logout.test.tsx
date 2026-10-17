/** A sign-out the server has not confirmed (offline or failed POST /auth/logout), F3 gate fix 1. */
import { screen } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../../src/lib/db/db.ts';
import { saveDeviceRecord } from '../../../src/lib/device/credentials.ts';
import {
    clearPendingLogout,
    flushPendingLogout,
    hasPendingLogout,
    markPendingLogout,
} from '../../../src/lib/session/pending-logout.ts';
import { setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { hardReload } from '../../../src/lib/session/reload.ts';
import { saveSession } from '../../../src/lib/session/session-cache.ts';
import { signOut } from '../../../src/lib/session/sign-out.ts';
import { startSyncEngine, stopSyncEngine } from '../../../src/lib/sync/engine.ts';
import { createBackend, fail } from '../../utils/backend.ts';
import { seedUnsynced, setOnline, stubBrowserCaches } from '../../utils/c-rows.ts';
import { makeMe } from '../../utils/fixtures.ts';
import { renderApp } from '../../utils/render-app.tsx';
import { server, setupTestServer } from '../../utils/server.ts';
import { signIn } from '../../utils/sign-in.ts';

vi.mock('../../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

vi.mock('../../../src/lib/sync/engine.ts', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../../../src/lib/sync/engine.ts')>()),
    startSyncEngine: vi.fn(() => () => undefined),
    stopSyncEngine: vi.fn(() => Promise.resolve()),
}));

setupTestServer();

let browser: ReturnType<typeof stubBrowserCaches>;

beforeEach(() => {
    vi.mocked(hardReload).mockClear();
    browser = stubBrowserCaches();
    setOrganizationId(1);
});

afterEach(() => {
    browser.restore();
    vi.restoreAllMocks();
});

/** Counts the requests that reached the fake server. */
function track(backend: ReturnType<typeof createBackend>) {
    const calls = { me: 0, logout: 0 };
    server.use(...backend.handlers);
    server.use(
        http.get('*/api/v1/me', () => {
            calls.me++;
            return backend.state.loggedIn
                ? HttpResponse.json({ success: true, data: backend.state.me })
                : fail(401, 'unauthenticated');
        }),
        http.post('*/auth/logout', () => {
            calls.logout++;
            backend.state.loggedIn = false;
            return new HttpResponse(null, { status: 204 });
        }),
    );
    return calls;
}

describe('pending server sign-out', () => {
    it('survives the purge of an offline sign-out and keeps the server session marked', async () => {
        await seedUnsynced();
        setOnline(false);
        server.use(http.post('*/auth/logout', () => HttpResponse.error()));
        expect(await signOut()).toEqual({ reloading: false });
        expect(await db.drafts.count()).toBe(0);
        expect(hasPendingLogout()).toBe(true);
        expect(localStorage.getItem('fsa.pendingLogout')).toBe('true');
    });

    it('stays pending after a server error and ends on success or when no session is left', async () => {
        markPendingLogout();
        server.use(http.post('*/auth/logout', () => fail(503, 'down')));
        expect(await flushPendingLogout()).toBe(false);
        expect(hasPendingLogout()).toBe(true);

        server.use(http.post('*/auth/logout', () => fail(401, 'unauthenticated')));
        expect(await flushPendingLogout()).toBe(true);
        expect(hasPendingLogout()).toBe(false);

        markPendingLogout();
        setOnline(false);
        expect(await flushPendingLogout()).toBe(false); // offline: not even tried
        expect(hasPendingLogout()).toBe(true);
        clearPendingLogout();
    });

    it('sends the app to the login page without asking /me, then signs out on the server', async () => {
        await saveDeviceRecord({ deviceUuid: 'd1', secret: 's1' });
        await saveSession(makeMe()); // the old session must not open the app offline either
        markPendingLogout();
        const backend = createBackend();
        const calls = track(backend);
        const { history } = renderApp('/settings');
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(history.location.search).toContain('notice=logout');
        await vi.waitFor(() => {
            expect(hasPendingLogout()).toBe(false);
        });
        expect(calls.logout).toBe(1);
        expect(backend.state.loggedIn).toBe(false);
        expect(calls.me).toBe(0);
    });

    it('tells the user while the server sign-out is pending, until it went through', async () => {
        markPendingLogout();
        const offline = setOnline(false);
        const calls = track(createBackend());
        renderApp('/');
        const notice = await screen.findByRole('status');
        expect(notice).toHaveTextContent('You have been signed out.');
        expect(notice).toHaveTextContent('The sign-out on the server is completed');
        expect(calls.me).toBe(0);
        expect(calls.logout).toBe(0);

        offline.mockReturnValue(true);
        window.dispatchEvent(new Event('online'));
        await vi.waitFor(() => {
            expect(screen.getByRole('status')).not.toHaveTextContent('on the server');
        });
        expect(calls.logout).toBe(1);
    });

    it('signs out offline from Settings and says the server sign-out is still pending', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        await seedUnsynced();
        const engine: string[] = [];
        vi.mocked(startSyncEngine).mockImplementation(() => {
            engine.push('start');
            return () => undefined;
        });
        vi.mocked(stopSyncEngine).mockImplementation(async () => {
            engine.push(`stop:drafts=${String(await db.drafts.count())}`);
        });
        const { user } = renderApp('/settings');
        await user.click(await screen.findByRole('button', { name: 'Sign out' }));
        setOnline(false);
        server.use(http.post('*/auth/logout', () => HttpResponse.error()));
        await user.click(screen.getByRole('button', { name: 'Sign out and remove data' }));
        // Busy: aria-disabled keeps focus on the button instead of dropping it to the page.
        expect(screen.getByRole('button', { name: 'Sign out and remove data' })).toHaveAttribute(
            'aria-disabled',
            'true',
        );
        expect(screen.getByRole('button', { name: 'Cancel' })).toHaveAttribute(
            'aria-disabled',
            'true',
        );
        const notice = await screen.findByRole('status');
        expect(notice).toHaveTextContent('You have been signed out.');
        expect(notice).toHaveTextContent('The sign-out on the server is completed');
        expect(backend.state.loggedIn).toBe(true);
        expect(hardReload).not.toHaveBeenCalled();
        // The engine stops before anything is removed and is ready again for the next sign-in.
        expect(engine).toEqual(['start', 'stop:drafts=1', 'start']);
    });

    it('ends the old session before a new sign-in, and the sign-in clears the marker', async () => {
        markPendingLogout();
        const backend = createBackend();
        const order: string[] = [];
        server.use(...backend.handlers);
        server.use(
            http.post('*/auth/logout', () => {
                order.push('logout');
                return fail(503, 'down'); // still pending: the sign-in replaces the session
            }),
            http.post('*/auth/login', () => {
                order.push('login');
                backend.state.loggedIn = true;
                return HttpResponse.json({ two_factor: false });
            }),
        );
        const { user } = renderApp('/login');
        await signIn(user);
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
        expect(order.slice(-2)).toEqual(['logout', 'login']);
        expect(hasPendingLogout()).toBe(false);
    });
});
