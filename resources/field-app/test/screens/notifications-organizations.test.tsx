/** Notification centre across organizations: names them, switches before opening, explains left ones. */
import { screen, waitFor, within } from '@testing-library/react';
import { http } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AppNotification } from '../../src/lib/api/types.ts';
import { getOrganizationId, setOrganizationId } from '../../src/lib/session/org-store.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { createBackend, fail } from '../utils/backend.ts';
import { ORG_A, ORG_B, makeMe, makeNotification, makeSubmission } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();

beforeEach(() => {
    setOrganizationId(ORG_A.id);
});

describe('notifications of several organizations', () => {
    const OTHER_UUID = '0192f1c4-0000-7000-8000-0000000000b2';
    const OTHER: AppNotification = makeNotification({
        id: 'n5',
        event: 'submission_approved',
        title: 'Approved in Second AG',
        body: null,
        url: `/app/submissions/${OTHER_UUID}`,
        organization_id: ORG_B.id,
    });

    /** Org B's submission only answers with `X-Organization-Id: 2`. */
    function setupOrgs(notifications: AppNotification[], organizations = [ORG_A, ORG_B]) {
        const backend = createBackend({ me: makeMe(organizations), notifications });
        backend.state.submissions.set(
            OTHER_UUID,
            makeSubmission({ uuid: OTHER_UUID, status: 'submitted', number: 'SA-2026-00001' }),
        );
        const orgHeaders: (string | null)[] = [];
        server.use(...backend.handlers);
        server.use(
            http.get('*/api/v1/submissions/:uuid', ({ params, request }) => {
                const org = request.headers.get('X-Organization-Id');
                orgHeaders.push(org);
                return params.uuid === OTHER_UUID && org !== String(ORG_B.id)
                    ? fail(404, 'not_found')
                    : undefined;
            }),
        );
        return { backend, orgHeaders };
    }

    it('names the organization of each item when the user belongs to several', async () => {
        setupOrgs([makeNotification(), OTHER]);
        renderApp('/notifications');
        const list = await screen.findByRole('list', { name: 'Notifications' });
        const items = within(list).getAllByRole('listitem');
        expect(items[0]).toHaveTextContent('Organization: Demo GmbH');
        expect(items[1]).toHaveTextContent('Organization: Second AG');
    });

    it("switches to the item's organization before opening its link", async () => {
        const { backend, orgHeaders } = setupOrgs([makeNotification(), OTHER]);
        const { user, history } = renderApp('/notifications');
        const link = await screen.findByRole('link', { name: 'Approved in Second AG' });
        expect(link).toHaveAttribute('href', `/app/submissions/${OTHER_UUID}`);
        await user.click(link);
        expect(await screen.findByText('Number SA-2026-00001')).toBeInTheDocument();
        expect(history.location.pathname).toBe(`/app/submissions/${OTHER_UUID}`);
        expect(getOrganizationId()).toBe(ORG_B.id);
        expect(orgHeaders.length).toBeGreaterThan(0);
        expect(new Set(orgHeaders)).toEqual(new Set([String(ORG_B.id)]));
        await waitFor(() => {
            expect(backend.state.reads).toEqual([{ ids: ['n5'] }]);
        });
    });

    it('keeps the organization for items of the current one', async () => {
        setupOrgs([makeNotification(), OTHER]);
        const { user, history } = renderApp('/notifications');
        await user.click(await screen.findByRole('link', { name: 'Review requested' }));
        await waitFor(() => {
            expect(history.location.pathname).toBe(
                '/app/submissions/0192f1c4-0000-7000-8000-0000000000a1',
            );
        });
        expect(getOrganizationId()).toBe(ORG_A.id);
    });

    it('opens items without an organization as they are', async () => {
        const plain = { ...OTHER, id: 'n6', title: 'Platform notice', organization_id: null };
        setupOrgs([plain]);
        const { user, history } = renderApp('/notifications');
        const link = await screen.findByRole('link', { name: 'Platform notice' });
        expect(link.closest('li')).not.toHaveTextContent('Organization:');
        await user.click(link);
        await waitFor(() => {
            expect(history.location.pathname).toBe(`/app/submissions/${OTHER_UUID}`);
        });
        expect(getOrganizationId()).toBe(ORG_A.id);
    });

    it('explains an item of an organization the user no longer belongs to', async () => {
        const gone = { ...OTHER, id: 'n7', title: 'From a former employer', organization_id: 9 };
        const { backend } = setupOrgs([gone], [ORG_A]);
        const { user } = renderApp('/notifications');
        const heading = await screen.findByRole('heading', { name: 'From a former employer' });
        expect(within(heading).queryByRole('link')).not.toBeInTheDocument();
        const item = heading.closest('li')!;
        expect(item).toHaveTextContent(
            'You no longer belong to the organization of this notification, so it cannot be opened.',
        );
        await user.click(within(item).getByRole('button', { name: 'Mark as read' }));
        await waitFor(() => {
            expect(backend.state.reads).toEqual([{ ids: ['n7'] }]);
        });
        expect(getOrganizationId()).toBe(ORG_A.id);
    });

    it('speaks German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        setupOrgs([OTHER, { ...OTHER, id: 'n8', title: 'Alt', organization_id: 9 }]);
        renderApp('/notifications');
        const list = await screen.findByRole('list', { name: 'Benachrichtigungen' });
        expect(list).toHaveTextContent('Organisation: Second AG');
        expect(list).toHaveTextContent(
            'Sie gehören nicht mehr zur Organisation dieser Benachrichtigung, daher lässt sie sich nicht öffnen.',
        );
    });
});
