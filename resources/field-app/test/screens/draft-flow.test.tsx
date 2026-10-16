/** Fill → review with uploads in flight, and reopening drafts (shared draft session). */
import { screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as endpoints from '../../src/lib/api/endpoints.ts';
import type { AttachmentRecord } from '../../src/lib/api/types.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { createBackend } from '../utils/backend.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();

const UUID = '0192f1c4-0000-7000-8000-0000000000f1';

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    setOrganizationId(1);
});
afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

function setup() {
    const backend = createBackend();
    backend.state.submissions.set(
        UUID,
        makeSubmission({ uuid: UUID, revision: 1, data: { customer: 'ACME' } }),
    );
    server.use(...backend.handlers);
    return backend;
}

function deferredUpload() {
    let finish: () => void = () => undefined;
    const spy = vi.spyOn(endpoints, 'uploadAttachment').mockImplementation(
        (_uuid, input) =>
            new Promise<AttachmentRecord>((resolve) => {
                finish = () => {
                    resolve({
                        uuid: input.attachmentUuid,
                        kind: 'photo',
                        element_key: 'photos',
                        mime: 'image/jpeg',
                        size: 1,
                        url: '/x',
                    });
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

describe('uploads in flight', () => {
    it('disables "Review" with a hint until the upload is done, then saves it', async () => {
        const backend = setup();
        const upload = deferredUpload();
        const { user, history } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-upload' }));
        await waitFor(() => {
            expect(upload.spy).toHaveBeenCalled();
        });
        expect(screen.getByRole('button', { name: 'Review' })).toBeDisabled();
        expect(screen.getByText(/Uploads in progress/)).toBeInTheDocument();
        upload.finish();
        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Review' })).toBeEnabled();
        });
        expect(screen.queryByText(/Uploads in progress/)).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Review' }));
        await screen.findByRole('heading', { name: 'Review and sign' });
        expect(history.location.pathname).toBe(`/app/fill/${UUID}/review`);
        expect(backend.state.puts).toHaveLength(1);
        expect(backend.state.puts[0]?.body).toMatchObject({
            revision: 1,
            data: { customer: 'ACME', photos: anyId },
        });
    });

    it('lets an upload started on the fill screen finish into the review screen', async () => {
        const backend = setup();
        const upload = deferredUpload();
        const { user, history } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'fake-upload' }));
        await waitFor(() => {
            expect(upload.spy).toHaveBeenCalled();
        });
        history.push(`/app/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled();
        expect(screen.getByText(/Uploads in progress/)).toBeInTheDocument();
        upload.finish();
        await waitFor(() => {
            expect(data()).toMatchObject({ photos: anyId });
        });
        await vi.advanceTimersByTimeAsync(1600);
        await waitFor(() => {
            expect(backend.state.puts).toHaveLength(1);
        });
        expect(backend.state.puts[0]?.body).toMatchObject({ revision: 1 });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        await screen.findByText('Number SR-2026-00012', { selector: 'p' });
        expect(backend.state.submits[0]?.body).toEqual({ revision: 2 });
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
        await waitFor(() => {
            expect(backend.state.puts).toHaveLength(1);
        });
        await vi.advanceTimersByTimeAsync(50);
        history.push(`/app/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        expect(data()).toEqual({ customer: 'Edited' });
        await user.click(screen.getByRole('button', { name: 'fake-edit' }));
        await vi.advanceTimersByTimeAsync(1600);
        await waitFor(() => {
            expect(backend.state.puts.length).toBeGreaterThan(1);
        });
        expect(backend.state.puts.map((put) => put.body.revision)).toEqual([1, 2]);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });
});
