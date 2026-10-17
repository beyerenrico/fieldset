/**
 * Submission detail of a paused submission (UX review, ADR 0035): a refused submit says so and
 * leads to the form, a decision leads to the review screen (no dead end), a queued submit names a
 * localized reason, and a refused send offers "Try again" / "Cancel sending".
 */
import { screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { attachmentRow, draftRow, setupSyncScope } from '../utils/a-store.ts';
import { createBackend } from '../utils/backend.ts';
import { outboxError, putOps, sendOp, submitOp, writesOffline } from '../utils/f2-rows.ts';
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
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-0000000000d1';
const REVIEW_HREF = `/app/fill/${UUID}/review`;
const blocked = outboxError(422, 'validation_failed', 'The given data was invalid.');
const errors = (count: number) =>
    Array.from({ length: count }, (_, index) => ({
        pointer: `/f${String(index)}`,
        code: 'required',
    }));

beforeEach(() => {
    setOrganizationId(1);
    server.use(...createBackend().handlers);
    writesOffline();
});

async function openDetail() {
    const app = renderApp(`/submissions/${UUID}`);
    await screen.findByRole('heading', { name: 'Service report', level: 1 });
    return app;
}

describe('a submit refused in the background', () => {
    it.each([
        [
            2,
            'Not submitted: 2 fields need fixing. Open the form, correct the marked fields and submit again.',
        ],
        [
            1,
            'Not submitted: 1 field needs fixing. Open the form, correct the marked field and submit again.',
        ],
    ])('names %i field(s) to fix and links to the form', async (count, text) => {
        await db.drafts.put(
            draftRow({
                uuid: UUID,
                sync: 'blocked',
                blockedBy: 'submit_refused',
                submitErrors: errors(count),
            }),
        );
        await putOps(submitOp(UUID, { state: 'blocked', lastError: blocked }));
        await openDetail();
        const notice = await screen.findByText(text);
        expect(notice.closest('[role="status"]')).not.toBeNull();
        expect(screen.getByRole('link', { name: 'Open the form' })).toHaveAttribute(
            'href',
            REVIEW_HREF,
        );
        expect(screen.queryByText('Ready to send')).not.toBeInTheDocument();
        expect(screen.queryByText(/The given data was invalid/)).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Continue editing' })).not.toBeInTheDocument();
    });

    it('says an attachment is missing (also when the submit op was dropped)', async () => {
        await db.drafts.put(
            draftRow({ uuid: UUID, sync: 'blocked', blockedBy: 'attachments_missing' }),
        );
        await openDetail();
        expect(
            await screen.findByText(
                'Not submitted: an attachment is missing. Open the form, add it again and submit.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open the form' })).toHaveAttribute(
            'href',
            REVIEW_HREF,
        );
    });

    it('names a refused attachment and its reason', async () => {
        await db.drafts.put(
            draftRow({ uuid: UUID, sync: 'blocked', blockedBy: 'attachment_blocked' }),
        );
        await db.attachments.put(
            attachmentRow({
                submissionUuid: UUID,
                fileName: 'scan.heic',
                state: 'failed',
                error: outboxError(422, 'file_type_not_allowed', 'mimes:jpg,png'),
            }),
        );
        await openDetail();
        expect(
            await screen.findByText(
                'The attachment “scan.heic” was not accepted. This file type is not allowed. Open the form and replace it.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/mimes:/)).not.toBeInTheDocument();
    });

    it('says it in German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        await db.drafts.put(
            draftRow({
                uuid: UUID,
                sync: 'blocked',
                blockedBy: 'submit_refused',
                submitErrors: errors(3),
            }),
        );
        renderApp(`/submissions/${UUID}`);
        expect(
            await screen.findByText(
                'Nicht eingereicht: 3 Felder müssen korrigiert werden. Öffnen Sie das Formular, korrigieren Sie die markierten Felder und reichen Sie erneut ein.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Formular öffnen' })).toBeInTheDocument();
    });
});

describe('a queued submit that waits for a decision', () => {
    it('leads a conflict to the review screen instead of "Ready to send"', async () => {
        await db.drafts.put(
            draftRow({
                uuid: UUID,
                sync: 'conflict',
                conflict: makeSubmission({ uuid: UUID, revision: 4 }),
            }),
        );
        await putOps(
            submitOp(UUID, { state: 'blocked', lastError: outboxError(409, 'revision_conflict') }),
        );
        await openDetail();
        const notice = (await screen.findByText(/Someone else changed this submission/)).closest(
            '[role="status"]',
        ) as HTMLElement;
        expect(notice).toHaveTextContent('Needs your decision');
        expect(within(notice).getByRole('link', { name: 'Resolve' })).toHaveAttribute(
            'href',
            REVIEW_HREF,
        );
        expect(screen.queryByText('Ready to send')).not.toBeInTheDocument();
        expect(screen.getByText('Conflict', { selector: '[data-sync]' })).toBeInTheDocument();
    });

    it('explains a blocking answer with the next step and links to the review screen', async () => {
        await db.drafts.put(
            draftRow({ uuid: UUID, sync: 'blocked', blockedBy: 'form_access_revoked' }),
        );
        await putOps(
            submitOp(UUID, {
                state: 'blocked',
                lastError: outboxError(403, 'form_access_revoked'),
            }),
        );
        await openDetail();
        expect(
            await screen.findByText(/ask your office to restore access, then tap Try again/),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Resolve' })).toHaveAttribute('href', REVIEW_HREF);
        expect(screen.queryByText('Ready to send')).not.toBeInTheDocument();
    });
});

describe('a submit ready to send', () => {
    it.each([
        [503, 'service_unavailable', 'The last attempt failed because of a temporary problem.'],
        [
            429,
            'too_many_requests',
            'The last attempt was turned away because of too many requests.',
        ],
        [408, 'request_timeout', 'The last attempt got no answer in time.'],
    ])('names a localized reason for a %i, never the raw message', async (status, code, text) => {
        await db.drafts.put(draftRow({ uuid: UUID }));
        await putOps(
            submitOp(UUID, {
                attempts: 1,
                lastError: outboxError(status, code, 'Raw server text'),
            }),
        );
        await openDetail();
        expect(await screen.findByText('Ready to send')).toBeInTheDocument();
        expect(screen.getByText(new RegExp(`^${text}`))).toBeInTheDocument();
        expect(screen.queryByText(/Raw server text/)).not.toBeInTheDocument();
        expect(screen.queryByText(new RegExp(code))).not.toBeInTheDocument();
        expect(screen.getAllByText('Number pending')).toHaveLength(1);
    });
});

describe('a refused "send to customer"', () => {
    function submitted() {
        const backend = createBackend();
        backend.state.submissions.set(
            UUID,
            makeSubmission({
                uuid: UUID,
                status: 'submitted',
                number: 'SR-2026-00031',
                can_edit: false,
                can_send: true,
            }),
        );
        server.use(...backend.handlers);
        writesOffline();
    }

    it('offers "Try again" and "Cancel sending" with a localized reason', async () => {
        submitted();
        await putOps(
            sendOp(UUID, {
                state: 'blocked',
                lastError: outboxError(422, 'send_not_allowed', 'Sending failed'),
            }),
        );
        const { user } = await openDetail();
        const title = await screen.findByText('Not sent to erika@example.test.');
        const notice = title.parentElement as HTMLElement;
        expect(notice).toHaveTextContent('Sending is not allowed for this submission.');
        expect(notice).not.toHaveTextContent('Sending failed');
        await user.click(within(notice).getByRole('button', { name: 'Try again' }));
        // Offline: the same request waits in the outbox again.
        expect(await screen.findByText(/Sending to erika@example.test is queued/)).toBeVisible();
        expect(await db.outbox.get(sendOp(UUID).id)).toMatchObject({ state: 'pending' });
    });

    it('offers only "Cancel sending" when a retry cannot help (address refused)', async () => {
        submitted();
        await putOps(
            sendOp(UUID, { state: 'blocked', lastError: outboxError(422, 'validation_failed') }),
        );
        const { user } = await openDetail();
        const notice = (await screen.findByText('Not sent to erika@example.test.'))
            .parentElement as HTMLElement;
        expect(notice).toHaveTextContent('The email address was not accepted.');
        expect(within(notice).queryByRole('button', { name: 'Try again' })).toBeNull();
        await user.click(within(notice).getByRole('button', { name: 'Cancel sending' }));
        await vi.waitFor(async () => {
            expect(await db.outbox.count()).toBe(0);
        });
        // The notice follows the store (live query) a moment later.
        await vi.waitFor(() => {
            expect(screen.queryByText('Not sent to erika@example.test.')).not.toBeInTheDocument();
        });
    });
});
