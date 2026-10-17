/** Starting the app without a network from the cached session (ADR 0035, journey 7). */
import { screen } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getCurrentUserId, setCurrentUserId } from '../../../src/lib/db/scope.ts';
import { saveDeviceRecord } from '../../../src/lib/device/credentials.ts';
import { setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { saveSession } from '../../../src/lib/session/session-cache.ts';
import { createBackend, fail, ok } from '../../utils/backend.ts';
import { ORG_A, ORG_B, makeMe } from '../../utils/fixtures.ts';
import { renderApp } from '../../utils/render-app.tsx';
import { server, setupTestServer } from '../../utils/server.ts';

vi.mock('../../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

vi.mock('../../../src/lib/sync/engine.ts', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../../../src/lib/sync/engine.ts')>()),
    startSyncEngine: () => () => undefined,
}));

setupTestServer();

/** Every API call fails like a phone without signal. */
const offline = () => [
    http.get('*/api/v1/*', () => HttpResponse.error()),
    http.post('*/api/v1/*', () => HttpResponse.error()),
];

beforeEach(() => {
    setOrganizationId(2);
});

afterEach(() => {
    setCurrentUserId(null);
});

describe('offline start', () => {
    it('opens the app from the cached session when the network fails', async () => {
        await saveSession(makeMe([ORG_A, ORG_B]));
        server.use(...offline());
        renderApp('/settings');
        expect(await screen.findByRole('heading', { name: 'Settings' })).toBeInTheDocument();
        expect(screen.getByText('Second AG', { selector: 'header span' })).toBeInTheDocument();
        expect(screen.getByText('Signed in as worker@demo.test')).toBeInTheDocument();
        expect(getCurrentUserId()).toBe(7);
    });

    it('refreshes the cached session once the server answers again', async () => {
        await saveSession(makeMe([ORG_B]));
        server.use(...offline());
        const { queryClient } = renderApp('/settings');
        await screen.findByRole('heading', { name: 'Settings' });
        server.resetHandlers();
        server.use(...createBackend({ me: makeMe([ORG_A, ORG_B]) }).handlers);
        await queryClient.refetchQueries({ queryKey: ['me'] });
        expect(await screen.findByLabelText('Organization')).toBeInTheDocument();
    });

    it('still goes to the login page when the server says the session is gone', async () => {
        await saveDeviceRecord({ deviceUuid: 'd1', secret: 's1' });
        await saveSession(makeMe());
        server.use(
            http.get('*/api/v1/me', () => fail(401, 'unauthenticated')),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'reauth' })),
        );
        renderApp('/');
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('Your session has ended');
    });

    it('shows the offline error without a cached session', async () => {
        server.use(...offline());
        renderApp('/settings');
        expect(await screen.findByRole('alert')).toHaveTextContent('You are offline');
    });
});
