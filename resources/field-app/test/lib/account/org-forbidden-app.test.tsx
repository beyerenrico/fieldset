/** A query answered 403 organization_forbidden: the app re-reads the session and moves on (ADR 0035). */
import { screen, waitFor } from '@testing-library/react';
import { http } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../../src/lib/api/errors.ts';
import { db } from '../../../src/lib/db/db.ts';
import { getOrganizationId, setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { createBackend, ok } from '../../utils/backend.ts';
import { seedUnsynced } from '../../utils/c-rows.ts';
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

beforeEach(() => {
    setOrganizationId(1);
});

/** Any query of the current organization failing with 403 organization_forbidden. */
function forbiddenQuery(queryClient: ReturnType<typeof renderApp>['queryClient']): void {
    void queryClient
        .query({
            queryKey: ['probe', 1],
            queryFn: () => Promise.reject(new ApiError(403, 'organization_forbidden', 'forbidden')),
            retry: false,
        })
        .catch(() => undefined);
}

describe('organization_forbidden in the app', () => {
    it('switches to the remaining organization and drops the lost one', async () => {
        const backend = createBackend({ me: makeMe([ORG_A, ORG_B]) });
        server.use(...backend.handlers);
        await seedUnsynced({ orgId: 1, userId: 7 }, 'lost');
        const { queryClient, history } = renderApp('/settings');
        await screen.findByRole('heading', { name: 'Settings' });
        backend.state.me = makeMe([ORG_B]);
        forbiddenQuery(queryClient);
        await waitFor(() => {
            expect(getOrganizationId()).toBe(2);
        });
        expect(await screen.findByText('Second AG', { selector: 'header span' })).toBeVisible();
        expect(history.location.pathname).toBe('/app/');
        expect(await db.drafts.count()).toBe(0);
    });

    it('explains an account without organizations when the device is not wiped', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        const { queryClient, history } = renderApp('/settings');
        await screen.findByRole('heading', { name: 'Settings' });
        backend.state.me = makeMe([]);
        server.use(http.post('*/api/v1/auth/device-status', () => ok({ status: 'ok' })));
        forbiddenQuery(queryClient);
        expect(
            await screen.findByText(/does not belong to an active organization/),
        ).toBeInTheDocument();
        expect(history.location.pathname).toBe('/app/org');
    });
});
