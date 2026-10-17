/**
 * Fill → review with attachments being stored, and reopening drafts (shared draft session). Files
 * are kept on this device first; the sync engine uploads them after the draft (ADR 0035).
 */
import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AttachmentRow } from '../../src/lib/db/schema.ts';
import * as localStore from '../../src/lib/attachments/local-store.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { createSyncBackend, fakeUploads, summarize } from '../utils/a-backend.ts';
import { FAKE_TIMERS, setupSyncScope } from '../utils/a-store.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { createBackend } from '../utils/backend.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-0000000000f1';

beforeEach(() => {
    vi.useFakeTimers(FAKE_TIMERS);
    setOrganizationId(1);
});
afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

/** The workflow/catalog fake for reads, the sync fake for writes (shared submissions). */
function setup() {
    const reads = createBackend();
    const writes = createSyncBackend({ submissions: reads.state.submissions });
    reads.state.submissions.set(
        UUID,
        makeSubmission({ uuid: UUID, revision: 1, data: { customer: 'ACME' } }),
    );
    server.use(...writes.handlers, ...reads.handlers);
    fakeUploads(writes.state);
    return writes;
}

/** Holds the file in "being stored on this device" until finish() (photo compression, IDB). */
function slowStore() {
    let finish: () => void = () => undefined;
    const real = localStore.storeAttachment;
    const spy = vi.spyOn(localStore, 'storeAttachment').mockImplementation(
        (...args) =>
            new Promise<AttachmentRow>((resolve, reject) => {
                finish = () => {
                    real(...args).then(resolve, reject);
                };
            }),
    );
    return {
        spy,
        finish: () => {
            finish();
        },
    };
}

const data = () => JSON.parse(screen.getByTestId('renderer-data').textContent) as object;
const anyId: unknown = expect.any(String);

describe('attachments being stored', () => {
    it('disables "Review" with a hint until the file is stored, then syncs it after the draft', async () => {
        const backend = setup();
        const store = slowStore();
        const { user, history } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-upload' }));
        await waitFor(() => {
            expect(store.spy).toHaveBeenCalled();
        });
        expect(screen.getByRole('button', { name: 'Review' })).toBeDisabled();
        expect(screen.getByText(/Uploads in progress/)).toBeInTheDocument();
        store.finish();
        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Review' })).toBeEnabled();
        });
        expect(screen.queryByText(/Uploads in progress/)).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Review' }));
        await screen.findByRole('heading', { name: 'Review and sign' });
        expect(history.location.pathname).toBe(`/app/fill/${UUID}/review`);
        await vi.advanceTimersByTimeAsync(1600);
        await waitFor(() => {
            expect(summarize(backend.state.log)).toEqual(['UPLOAD', 'PUT update']);
        });
        expect(backend.state.log[1]?.body).toMatchObject({
            revision: 1,
            data: { customer: 'ACME', photos: anyId },
        });
    });

    it('lets a file stored on the fill screen finish into the review screen and submits once', async () => {
        const backend = setup();
        const store = slowStore();
        const { user, history } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-upload' }));
        await waitFor(() => {
            expect(store.spy).toHaveBeenCalled();
        });
        history.push(`/app/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled();
        expect(screen.getByText(/Uploads in progress/)).toBeInTheDocument();
        store.finish();
        await waitFor(() => {
            expect(data()).toMatchObject({ photos: anyId });
        });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        await screen.findByText('Number SR-2026-00001', { selector: 'p' });
        expect(summarize(backend.state.log)).toEqual(['UPLOAD', 'PUT update', 'SUBMIT']);
        expect(backend.state.log[2]?.body).toMatchObject({ revision: 2 });
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });
});

describe('reopening a draft', () => {
    it('starts from the data saved after the screen was left', async () => {
        const backend = setup();
        const { user, history } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        history.push('/app/');
        await screen.findByRole('heading', { name: /Hello/ });
        await vi.advanceTimersByTimeAsync(1600);
        await waitFor(() => {
            expect(backend.state.log).toHaveLength(1);
        });
        history.push(`/app/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        expect(data()).toEqual({ customer: 'Edited' });
        await user.click(screen.getByRole('button', { name: 'fake-upload' }));
        await vi.advanceTimersByTimeAsync(1600);
        await waitFor(() => {
            expect(summarize(backend.state.log)).toEqual(['PUT update', 'UPLOAD', 'PUT update']);
        });
        expect(
            backend.state.log.map((entry) => (entry.body as { revision?: number }).revision),
        ).toEqual([1, undefined, 2]);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });

    it('starts from the copy on this device while offline', async () => {
        const backend = setup();
        backend.state.offline = true;
        const { user, history } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        history.push('/app/');
        await screen.findByRole('heading', { name: /Hello/ });
        await vi.advanceTimersByTimeAsync(1600);
        // Neither the PUT nor GET /submissions/{uuid} reach the server any more.
        server.use(http.get(`*/api/v1/submissions/${UUID}`, () => HttpResponse.error()));
        history.push(`/app/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        expect(data()).toEqual({ customer: 'Edited' });
        expect(document.querySelector('[data-state]')).toHaveTextContent('No changes yet');
    });
});
