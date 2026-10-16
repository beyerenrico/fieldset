import { screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as endpoints from '../../src/lib/api/endpoints.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { createBackend, fail } from '../utils/backend.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';
import { http } from 'msw';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();

const UUID = '0192f1c4-0000-7000-8000-0000000000aa';

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    setOrganizationId(1);
});
afterEach(() => {
    vi.useRealTimers();
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
    it('creates the draft on the first autosave and updates with the revision', async () => {
        const backend = setup();
        const { user } = renderApp(`/fill/${UUID}?formId=3&versionId=7`);
        expect(await screen.findByRole('heading', { name: 'Service report' })).toBeInTheDocument();
        expect(saveState()).toHaveTextContent('No changes yet');
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        expect(saveState()).toHaveTextContent('Unsaved changes');
        await vi.advanceTimersByTimeAsync(1600);
        await waitFor(() => {
            expect(saveState()).toHaveTextContent('Saved');
        });
        expect(backend.state.puts).toEqual([
            { uuid: UUID, body: { form_version_id: 7, data: { customer: 'Edited' } } },
        ]);
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
        const panel = await screen.findByRole('alertdialog', {
            name: 'Someone else changed this draft',
        });
        const row = within(panel).getByRole('row', { name: /Customer/ });
        expect(row).toHaveTextContent('Edited');
        expect(row).toHaveTextContent('Server');
        await user.click(within(panel).getByRole('button', { name: 'Keep mine' }));
        await waitFor(() => {
            expect(saveState()).toHaveTextContent('Saved');
        });
        expect(backend.state.puts.at(-1)?.body).toEqual({
            form_version_id: 7,
            data: { customer: 'Edited' },
            revision: 2,
        });
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
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
        await user.click(await screen.findByRole('button', { name: 'Use server version' }));
        expect(screen.getByTestId('renderer-data')).toHaveTextContent('{"customer":"Server"}');
        expect(saveState()).toHaveTextContent('Saved');
    });

    it('locks the form when the server says not_editable', async () => {
        setup({ revision: 1 });
        server.use(http.put('*/api/v1/submissions/:uuid', () => fail(409, 'not_editable')));
        const { user } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        await vi.advanceTimersByTimeAsync(1600);
        expect(await screen.findByRole('link', { name: 'Open submission' })).toBeInTheDocument();
        expect(screen.getByTestId('renderer')).toHaveAttribute('data-readonly', 'true');
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

    it('forces the draft PUT before an attachment upload', async () => {
        const backend = setup();
        const order: string[] = [];
        const upload = vi
            .spyOn(endpoints, 'uploadAttachment')
            .mockImplementation((_uuid, input) => {
                order.push(`upload:${String(backend.state.puts.length)}`);
                return Promise.resolve({
                    uuid: input.attachmentUuid,
                    kind: 'photo',
                    element_key: 'photos',
                    mime: 'image/jpeg',
                    size: 1,
                    url: '/x',
                });
            });
        const { user } = renderApp(`/fill/${UUID}?formId=3&versionId=7`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-upload' }));
        await waitFor(() => {
            expect(upload).toHaveBeenCalled();
        });
        expect(order).toEqual(['upload:1']);
        expect(backend.state.puts[0]?.body).toEqual({ form_version_id: 7, data: {} });
        upload.mockRestore();
    });

    it('stays on the form when the draft cannot be saved before review', async () => {
        setup();
        server.use(http.put('*/api/v1/submissions/:uuid', () => fail(500, 'boom')));
        const { user, history } = renderApp(`/fill/${UUID}?formId=3&versionId=7`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'Review' }));
        expect(await screen.findByText(/could not be saved/)).toBeInTheDocument();
        expect(history.location.pathname).toBe(`/app/fill/${UUID}`);
    });
});
