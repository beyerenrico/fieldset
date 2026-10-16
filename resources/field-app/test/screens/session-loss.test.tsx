/** Lost sessions: notices that stick, no saves afterwards, no cached data across users. */
import { screen, waitFor } from '@testing-library/react';
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ReauthRequiredError } from '../../src/lib/api/errors.ts';
import { saveDeviceRecord } from '../../src/lib/device/credentials.ts';
import { reportAuthError } from '../../src/lib/session/auth-events.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();

const UUID = '0192f1c4-0000-7000-8000-0000000000f2';

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
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
        expect(screen.getByRole('status')).toHaveTextContent('local data was removed');
        expect(history.location.search).toContain('notice=wiped');
        expect(puts).toBe(1);
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
