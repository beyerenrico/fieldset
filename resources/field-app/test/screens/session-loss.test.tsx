/** Lost sessions: notices that stick, no saves afterwards, no cached data across users. */
import { screen, waitFor } from '@testing-library/react';
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ReauthRequiredError } from '../../src/lib/api/errors.ts';
import { db } from '../../src/lib/db/db.ts';
import { saveDeviceRecord } from '../../src/lib/device/credentials.ts';
import { reportAuthError } from '../../src/lib/session/auth-events.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { hardReload } from '../../src/lib/session/reload.ts';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';
import { FAKE_TIMERS } from '../utils/a-store.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

vi.mock('../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

setupTestServer();
preloadLazyScreens();

const UUID = '0192f1c4-0000-7000-8000-0000000000f2';

beforeEach(() => {
    // setImmediate stays real: fake-indexeddb needs it, or Dexie commits too early (a-store.ts).
    vi.useFakeTimers(FAKE_TIMERS);
    setOrganizationId(1);
});
afterEach(() => {
    vi.useRealTimers();
});

describe('lost sessions', () => {
    it('keeps the "wiped" notice and saves nothing after the wipe', async () => {
        await saveDeviceRecord({ deviceUuid: 'd1', secret: 's1' });
        const backend = createBackend();
        backend.state.submissions.set(UUID, makeSubmission({ uuid: UUID, revision: 1 }));
        server.use(...backend.handlers);
        let puts = 0;
        server.use(
            http.put('*/api/v1/submissions/:uuid', () => {
                puts++;
                return fail(401, 'unauthenticated');
            }),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'wipe' })),
        );
        const { user, history } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        await vi.advanceTimersByTimeAsync(1600);
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        await vi.advanceTimersByTimeAsync(10_000);
        expect(screen.getByRole('status')).toHaveTextContent('removed its local data');
        expect(history.location.search).toContain('notice=wiped');
        expect(hardReload).toHaveBeenCalledWith('/app/login?notice=wiped');
        expect(puts).toBe(1);
    });

    it('writes pending edits on this device when the session expires (reauth)', async () => {
        const backend = createBackend();
        backend.state.submissions.set(UUID, makeSubmission({ uuid: UUID, revision: 1 }));
        server.use(...backend.handlers);
        const { user } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        // Within the autosave debounce: only App's reauth handling can write the edit now.
        backend.state.loggedIn = false;
        reportAuthError(new ReauthRequiredError('expired'));
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        await vi.waitFor(async () => {
            expect((await db.drafts.get(UUID))?.data).toMatchObject({ customer: 'Edited' });
        });
        expect(hardReload).not.toHaveBeenCalled(); // nothing is cleared on reauth
    });

    it('drops every cached query when the session expires', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        const { queryClient } = renderApp('/');
        await screen.findByRole('heading', { name: /Hello/ });
        expect(queryClient.getQueryCache().findAll({ queryKey: ['submissions'] })).not.toEqual([]);
        backend.state.loggedIn = false;
        reportAuthError(new ReauthRequiredError('expired'));
        await screen.findByRole('heading', { name: 'Sign in' });
        await waitFor(() => {
            expect(queryClient.getQueryCache().getAll()).toEqual([]);
        });
    });

    it('starts the next login with an empty cache', async () => {
        const backend = createBackend({ loggedIn: false });
        server.use(...backend.handlers);
        const { user, queryClient } = renderApp('/login');
        queryClient.setQueryData(['submission', 'someone-elses'], makeSubmission());
        await user.type(await screen.findByLabelText('Email'), 'worker@demo.test');
        await user.type(screen.getByLabelText('Password'), 'secret');
        await user.click(screen.getByRole('button', { name: 'Sign in' }));
        await screen.findByRole('heading', { name: /Hello/ });
        expect(queryClient.getQueryData(['submission', 'someone-elses'])).toBeUndefined();
    });
});
