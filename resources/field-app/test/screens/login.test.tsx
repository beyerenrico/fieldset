import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it, vi } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import { getCurrentUserId } from '../../src/lib/db/scope.ts';
import { getDeviceRecord, saveDeviceRecord } from '../../src/lib/device/credentials.ts';
import { hardReload } from '../../src/lib/session/reload.ts';
import { loadSession, saveSession } from '../../src/lib/session/session-cache.ts';
import { countForeignRows } from '../../src/lib/session/unsynced.ts';
import { onSyncRequested, type SyncReason } from '../../src/lib/sync/triggers.ts';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { seedUnsynced, stubStorageManager } from '../utils/c-rows.ts';
import { ORG_A, ORG_B, makeMe } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

setupTestServer();

async function signIn(user: ReturnType<typeof renderApp>['user']) {
    await user.type(await screen.findByLabelText('Email'), 'worker@demo.test');
    await user.type(screen.getByLabelText('Password'), 'secret');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
}

describe('login', () => {
    it('redirects to login without a session and signs in (device registered)', async () => {
        const backend = createBackend({ loggedIn: false, deviceSecret: 'dev-secret' });
        server.use(...backend.handlers);
        const { user, history } = renderApp('/');
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        await signIn(user);
        expect(
            await screen.findByRole('heading', { name: /Hello, Willi Worker/ }),
        ).toBeInTheDocument();
        expect(history.location.pathname).toBe('/app/');
        expect((await getDeviceRecord())?.secret).toBe('dev-secret');
    });

    it('resumes the sync after a reauth and asks for persistent storage (nothing cleared)', async () => {
        await saveDeviceRecord({ deviceUuid: 'd1', secret: 's1' });
        await seedUnsynced(); // the outbox of the same user survives the reauth
        const storage = stubStorageManager({ persisted: false });
        const reasons: SyncReason[] = [];
        const stop = onSyncRequested((reason) => reasons.push(reason));
        server.use(...createBackend({ loggedIn: false }).handlers);
        const { user } = renderApp('/');
        expect(await screen.findByRole('status')).toHaveTextContent('Your session has ended');
        await signIn(user);
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
        stop();
        storage.restore();
        expect(reasons).toContain('login');
        expect(storage.persist).toHaveBeenCalledTimes(1);
        expect(await db.drafts.count()).toBe(1);
        expect(getCurrentUserId()).toBe(7);
        expect((await loadSession())?.userId).toBe(7);
        expect(screen.queryByRole('heading', { name: 'Data of another user' })).toBeNull();
    });

    it('offers to delete unsynced items of another user after sign-in', async () => {
        await seedUnsynced({ orgId: 1, userId: 99 }, 'theirs');
        await seedUnsynced({ orgId: 1, userId: 7 }, 'mine');
        server.use(...createBackend({ loggedIn: false }).handlers);
        const { user } = renderApp('/login');
        await signIn(user);
        expect(
            await screen.findByRole('heading', { name: 'Data of another user' }),
        ).toBeInTheDocument();
        expect(screen.getByText(/another user \(3\)/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Delete them' }));
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
        expect(await countForeignRows(7)).toBe(0);
        expect(await db.drafts.get('mine')).toBeDefined(); // own work stays (and syncs)
    });

    it("keeps the other user's items hidden when asked to", async () => {
        await seedUnsynced({ orgId: 1, userId: 99 }, 'theirs');
        server.use(...createBackend({ loggedIn: false }).handlers);
        const { user } = renderApp('/login');
        await signIn(user);
        await user.click(await screen.findByRole('button', { name: 'Keep them' }));
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
        expect(await db.drafts.count()).toBe(1);
    });

    it("never opens another user's cached session after a sign-in it cannot confirm", async () => {
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        await saveSession({ ...makeMe(), user: { ...makeMe().user, id: 99, name: 'Previous' } });
        server.use(...createBackend({ loggedIn: false }).handlers);
        server.use(http.get('*/api/v1/me', () => HttpResponse.error()));
        const { user } = renderApp('/login');
        await signIn(user);
        expect(await screen.findByRole('alert')).toHaveTextContent('You are offline');
        expect(await loadSession()).toBeUndefined();
        expect(screen.queryByText(/Previous/)).toBeNull();
        expect(hardReload).not.toHaveBeenCalled();
    });

    it('asks for the 2FA code, accepts a recovery code, and rejects wrong codes', async () => {
        const backend = createBackend({ loggedIn: false, twoFactor: true });
        server.use(...backend.handlers);
        const { user } = renderApp('/login');
        await signIn(user);
        expect(
            await screen.findByRole('heading', { name: 'Two-factor authentication' }),
        ).toBeInTheDocument();
        await user.type(screen.getByLabelText('Authentication code'), '000000');
        await user.click(screen.getByRole('button', { name: 'Verify' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('The code is not valid.');
        await user.click(screen.getByRole('button', { name: 'Use a recovery code' }));
        await user.type(screen.getByLabelText('Recovery code'), 'abc-def');
        await user.click(screen.getByRole('button', { name: 'Verify' }));
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
    });

    it.each([
        [
            HttpResponse.json({ message: 'x', errors: { email: ['bad'] } }, { status: 422 }),
            'The email or password is not correct.',
        ],
        [HttpResponse.json({ message: 'slow' }, { status: 429 }), 'Too many attempts'],
        [HttpResponse.error(), 'You are offline'],
        [HttpResponse.json({ message: 'boom' }, { status: 500 }), 'Something went wrong'],
    ])('shows login errors (%#)', async (response, text) => {
        server.use(http.post('*/auth/login', () => response));
        const { user } = renderApp('/login');
        await signIn(user);
        expect(await screen.findByRole('alert')).toHaveTextContent(text);
    });

    it('still signs in when device registration fails', async () => {
        const backend = createBackend({ loggedIn: false });
        server.use(...backend.handlers);
        server.use(http.post('*/api/v1/devices', () => fail(500, 'boom')));
        const { user } = renderApp('/login');
        await signIn(user);
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
    });

    it('shows the session-expired notice and keeps local data', async () => {
        await saveDeviceRecord({ deviceUuid: 'd1', secret: 's1' });
        server.use(...createBackend({ loggedIn: false }).handlers);
        renderApp('/');
        expect(await screen.findByRole('status')).toHaveTextContent('Your session has ended');
        expect(await getDeviceRecord()).toEqual({ deviceUuid: 'd1', secret: 's1' });
    });

    it('wipes the device and explains it', async () => {
        await saveDeviceRecord({ deviceUuid: 'd1', secret: 's1' });
        server.use(...createBackend({ loggedIn: false }).handlers);
        server.use(http.post('*/api/v1/auth/device-status', () => ok({ status: 'wipe' })));
        renderApp('/forms');
        expect(await screen.findByRole('status')).toHaveTextContent('local data was removed');
        await waitFor(async () => {
            expect(await getDeviceRecord()).toBeUndefined();
        });
        expect(hardReload).toHaveBeenCalledWith('/app/login?notice=wiped');
    });

    it('asks to pick an organization when the user has several', async () => {
        const backend = createBackend({ me: makeMe([ORG_A, ORG_B]) });
        let orgHeader: string | null = null;
        server.use(...backend.handlers);
        server.use(
            http.get('*/api/v1/forms', ({ request }) => {
                orgHeader = request.headers.get('X-Organization-Id');
                return ok([]);
            }),
            // A catalog refresh that wins the race must not bring back the default backend's forms.
            http.get('*/api/v1/sync/manifest', () =>
                ok({
                    cursor: 'c1',
                    forms: [],
                    fillable_form_ids: [],
                    revoked_form_ids: [],
                    retired_version_ids: [],
                    tasks: [],
                }),
            ),
        );
        const { user } = renderApp('/forms');
        expect(
            await screen.findByRole('heading', { name: 'Choose an organization' }),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Second AG' }));
        expect(await screen.findByRole('heading', { name: /Hello/ })).toBeInTheDocument();
        await user.click(screen.getByRole('link', { name: 'Forms' }));
        await screen.findByText('No forms found.');
        expect(orgHeader).toBe('2');
    });

    it('renders a not-found page', async () => {
        server.use(...createBackend().handlers);
        renderApp('/nope/nothing');
        expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument();
    });
});
