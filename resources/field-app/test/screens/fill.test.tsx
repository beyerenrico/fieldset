/**
 * Fill screen, local-first (ADR 0035): every edit is saved on this device, the sync engine creates
 * and updates the server draft; conflicts and blocking answers come from the draft's row.
 */
import { screen, waitFor, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { fakeUploads, createSyncBackend } from '../utils/a-backend.ts';
import { FAKE_TIMERS, setupSyncScope } from '../utils/a-store.ts';
import { createBackend, fail } from '../utils/backend.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@fieldset/fieldset-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@fieldset/fieldset-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-0000000000aa';

beforeEach(() => {
    vi.useFakeTimers(FAKE_TIMERS);
    setOrganizationId(1);
});
afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

function setup(submission?: Parameters<typeof makeSubmission>[0]) {
    const backend = createBackend();
    if (submission !== undefined) {
        backend.state.submissions.set(UUID, makeSubmission({ uuid: UUID, ...submission }));
    }
    server.use(...backend.handlers);
    return backend;
}

const saveState = () => document.querySelector<HTMLElement>('[data-state]');

describe('fill screen', () => {
    it('saves on this device at once and creates the server draft in the background', async () => {
        const backend = setup();
        const { user } = renderApp(`/fill/${UUID}?formId=3&versionId=7`);
        expect(await screen.findByRole('heading', { name: 'Service report' })).toBeInTheDocument();
        expect(saveState()).toHaveTextContent('No changes yet');
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        expect(saveState()).toHaveTextContent('Unsaved changes');
        await vi.advanceTimersByTimeAsync(400);
        await waitFor(() => {
            expect(saveState()).toHaveTextContent('Saved on this device');
        });
        expect(await db.drafts.get(UUID)).toMatchObject({
            data: { customer: 'Edited' },
            localVersion: 1,
            serverRevision: 0,
        });
        await vi.advanceTimersByTimeAsync(1200);
        await waitFor(() => {
            expect(backend.state.puts).toHaveLength(1);
        });
        expect(backend.state.puts).toEqual([
            { uuid: UUID, body: { form_version_id: 7, data: { customer: 'Edited' } } },
        ]);
        await waitFor(() => {
            expect(screen.getByTestId('sync-state')).toHaveTextContent('Synced');
        });
        expect(screen.getByTestId('renderer')).toHaveAttribute('data-ui-locale', 'en');
    });

    it('shows the conflict panel and keeps my version', async () => {
        const backend = setup({ revision: 1, data: { customer: 'Old' } });
        const { user } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        backend.state.submissions.set(
            UUID,
            makeSubmission({ uuid: UUID, revision: 2, data: { customer: 'Server' } }),
        );
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        await vi.advanceTimersByTimeAsync(1600);
        const panel = await screen.findByRole('region', {
            name: 'Someone else changed this draft',
        });
        // It appeared on its own: announced once, focus stays where the user is.
        expect(screen.getByRole('button', { name: 'fake-edit' })).toHaveFocus();
        expect(screen.getAllByRole('status').map((status) => status.textContent)).toContain(
            'Someone else changed this draft',
        );
        expect(screen.getByRole('button', { name: 'Review' })).toBeDisabled();
        expect(saveState()).toHaveTextContent('Conflict');
        const row = within(panel).getByRole('row', { name: /Customer/ });
        expect(row).toHaveTextContent('Edited');
        expect(row).toHaveTextContent('Server');
        await user.click(within(panel).getByRole('button', { name: 'Keep mine' }));
        await waitFor(() => {
            expect(backend.state.puts.at(-1)?.body).toEqual({
                form_version_id: 7,
                data: { customer: 'Edited' },
                revision: 2,
            });
        });
        await waitFor(() => {
            expect(
                screen.queryByRole('region', { name: 'Someone else changed this draft' }),
            ).not.toBeInTheDocument();
        });
        expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
        expect(saveState()).toHaveTextContent('Saved on this device');
    });

    it('adopts the server version on request', async () => {
        const backend = setup({ revision: 1, data: { customer: 'Old' } });
        const { user } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        backend.state.submissions.set(
            UUID,
            makeSubmission({ uuid: UUID, revision: 4, data: { customer: 'Server' } }),
        );
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        await vi.advanceTimersByTimeAsync(1600);
        await user.click(await screen.findByRole('button', { name: 'Use office version' }));
        await waitFor(() => {
            expect(screen.getByTestId('renderer-data')).toHaveTextContent('{"customer":"Server"}');
        });
        // The save state follows the stored row (live query), a moment after the editor's data.
        await waitFor(() => {
            expect(saveState()).toHaveTextContent('Saved on this device');
        });
        expect(await db.drafts.get(UUID)).toMatchObject({ sync: 'synced', serverRevision: 4 });
        await vi.advanceTimersByTimeAsync(2000);
        expect(backend.state.puts).toHaveLength(1);
    });

    it('blocks the form when the server says not_editable and offers the ways out', async () => {
        setup({ revision: 1 });
        server.use(http.put('*/api/v1/submissions/:uuid', () => fail(409, 'not_editable')));
        const { user } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        await vi.advanceTimersByTimeAsync(1600);
        const panel = await screen.findByRole('region', { name: 'Needs your decision' });
        expect(panel).toHaveTextContent('This submission can no longer be changed');
        expect(panel).toHaveTextContent('save them as a new draft or discard them');
        // A retry cannot help here: only the ways out are offered.
        expect(within(panel).queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
        expect(within(panel).getByRole('link', { name: 'Open submission' })).toBeInTheDocument();
        expect(
            within(panel).getByRole('button', { name: 'Save as a new draft' }),
        ).toBeInTheDocument();
        expect(screen.getByTestId('renderer')).toHaveAttribute('data-readonly', 'true');
        expect(screen.getByRole('button', { name: 'Review' })).toBeDisabled();
    });

    it('shows a locked notice for submissions that cannot be edited', async () => {
        setup({ status: 'submitted', can_edit: false });
        renderApp(`/fill/${UUID}`);
        expect(
            await screen.findByText('This submission can no longer be edited.'),
        ).toBeInTheDocument();
    });

    it('explains a missing draft without form parameters', async () => {
        setup();
        renderApp(`/fill/${UUID}`);
        expect(await screen.findByText(/This draft does not exist/)).toBeInTheDocument();
    });

    it('queues an attachment behind the draft: create PUT first, then the upload', async () => {
        const backend = setup();
        const uploads = createSyncBackend({ submissions: backend.state.submissions });
        fakeUploads(uploads.state);
        const { user } = renderApp(`/fill/${UUID}?formId=3&versionId=7`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-upload' }));
        await waitFor(() => {
            expect(screen.getByTestId('renderer-data').textContent).toContain('photos');
        });
        expect(backend.state.puts).toHaveLength(0);
        expect(uploads.state.log).toHaveLength(0);
        await vi.advanceTimersByTimeAsync(1600);
        await waitFor(() => {
            expect(uploads.state.log).toHaveLength(1);
        });
        expect(backend.state.puts).toHaveLength(1);
        expect(backend.state.puts[0]?.body).toMatchObject({ form_version_id: 7 });
        expect(backend.state.puts[0]?.body).not.toHaveProperty('revision');
        const photo = (
            JSON.parse(screen.getByTestId('renderer-data').textContent) as { photos: string }
        ).photos;
        expect(uploads.state.log[0]?.body).toMatchObject({ attachment_uuid: photo });
    });

    it('goes to review offline: the copy on this device is enough', async () => {
        setup();
        server.use(http.put('*/api/v1/submissions/:uuid', () => HttpResponse.error()));
        const { user, history } = renderApp(`/fill/${UUID}?formId=3&versionId=7`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        await user.click(screen.getByRole('button', { name: 'Review' }));
        await screen.findByRole('heading', { name: 'Review and sign' });
        expect(history.location.pathname).toBe(`/app/fill/${UUID}/review`);
        expect(screen.getByTestId('renderer-data')).toHaveTextContent('{"customer":"Edited"}');
    });
});
