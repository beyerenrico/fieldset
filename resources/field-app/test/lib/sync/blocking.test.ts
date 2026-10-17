/**
 * Blocked drafts (M6 gate F1 items 2, 5, 6 and the background-refusal contract): queued submits and
 * sends carry the blocking answer, blocked uploads have a way out, background refusals are visible,
 * and a changes_requested submission on a retired version is not blocked before its resubmit.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../../src/lib/db/db.ts';
import {
    enqueueAttachmentUpload,
    enqueueDraftSave,
    enqueueSend,
    enqueueSubmit,
    opId,
} from '../../../src/lib/outbox/outbox.ts';
import { retryBlocked } from '../../../src/lib/sync/conflicts.ts';
import { runSync } from '../../../src/lib/sync/engine.ts';
import { failWith, summarize } from '../../utils/a-backend.ts';
import { SCOPE, attachmentRow, draftRow, setupSyncScope } from '../../utils/a-store.ts';
import { PHOTO, SUBMITTED_AT, UUID, serverDraft, setupBackend } from '../../utils/f1-sync.ts';
import { makeVersion } from '../../utils/fixtures.ts';
import { setupTestServer } from '../../utils/server.ts';

setupTestServer();
setupSyncScope();

afterEach(() => {
    vi.restoreAllMocks();
});

const SUBMIT_ID = opId('submit', SCOPE.userId, UUID);
const SEND_ID = opId('send', SCOPE.userId, UUID);
const UPLOAD_ID = opId('attachment.upload', SCOPE.userId, UUID, PHOTO);

async function retire(versionId: number): Promise<void> {
    await db.formVersions.put({
        ...makeVersion(versionId, 3),
        retired: true,
        orgId: 1,
        cachedAt: 1,
    });
}

describe('retired versions (item 2)', () => {
    it('lets a changes_requested submission on a retired version resubmit', async () => {
        const backend = setupBackend();
        await retire(7);
        const base = { status: 'changes_requested' as const, number: 'SR-2026-00004', revision: 4 };
        await serverDraft(backend, { customer: 'A' }, base, { localVersion: 2 });
        await enqueueDraftSave(SCOPE, UUID);
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);

        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['PUT update', 'SUBMIT']);
        expect(backend.state.submissions.get(UUID)).toMatchObject({
            status: 'submitted',
            number: 'SR-2026-00004',
        });
    });

    it('still blocks a draft (status draft) on a retired version before any request', async () => {
        const backend = setupBackend();
        await retire(7);
        await serverDraft(backend, { customer: 'A' }, {}, { localVersion: 2 });
        await enqueueDraftSave(SCOPE, UUID);

        await runSync({ manual: true });

        expect(backend.state.log).toEqual([]);
        expect(await db.drafts.get(UUID)).toMatchObject({ blockedBy: 'version_retired' });
    });
});

describe('queued submits and sends of a paused draft (item 5)', () => {
    it('carry the blocking answer and go back to pending on "Try again"', async () => {
        const backend = setupBackend();
        backend.state.inject.set('PUT put', failWith(403, 'form_access_revoked'));
        await db.drafts.put(draftRow({ uuid: UUID, data: { customer: 'A' } }));
        await enqueueDraftSave(SCOPE, UUID);
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);
        await enqueueSend(SCOPE, UUID, { email: 'kunde@example.test' });

        await runSync({ manual: true });

        const lastError = { status: 403, code: 'form_access_revoked' };
        expect(await db.outbox.get(SUBMIT_ID)).toMatchObject({ state: 'blocked', lastError });
        expect(await db.outbox.get(SEND_ID)).toMatchObject({ state: 'blocked', lastError });

        backend.state.inject.clear();
        await retryBlocked(UUID);
        expect(await db.outbox.get(SUBMIT_ID)).toMatchObject({ state: 'pending', lastError: null });
        expect(await db.outbox.get(SEND_ID)).toMatchObject({ state: 'pending', lastError: null });
        await runSync({ manual: true });
        expect(summarize(backend.state.log)).toEqual([
            'PUT create',
            'PUT create',
            'SUBMIT',
            'SEND',
        ]);
    });

    it('a send queued on a paused draft is queued on hold', async () => {
        setupBackend();
        await db.drafts.put(draftRow({ uuid: UUID, sync: 'conflict' }));
        await enqueueSend(SCOPE, UUID, { email: 'kunde@example.test' });
        expect(await db.outbox.get(SEND_ID)).toMatchObject({
            state: 'blocked',
            lastError: { code: 'revision_conflict' },
        });
    });
});

describe('blocked uploads (item 6)', () => {
    async function seedPhoto(): Promise<void> {
        await db.drafts.put(draftRow({ uuid: UUID, data: { photos: [PHOTO] } }));
        await db.attachments.put(attachmentRow({ uuid: PHOTO, submissionUuid: UUID }));
        await enqueueDraftSave(SCOPE, UUID);
        await enqueueAttachmentUpload(SCOPE, UUID, PHOTO);
    }

    it('block the draft (attachment_blocked); "Try again" uploads again', async () => {
        const backend = setupBackend();
        backend.state.inject.set('POST upload', failWith(413, 'file_too_large'));
        await seedPhoto();
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);

        expect(await runSync({ manual: true })).toMatchObject({ blocked: 1 });

        expect(await db.drafts.get(UUID)).toMatchObject({
            sync: 'blocked',
            blockedBy: 'attachment_blocked',
        });
        expect(await db.attachments.get(PHOTO)).toMatchObject({
            state: 'pending',
            error: { status: 413, code: 'file_too_large' },
        });
        expect(await db.outbox.get(UPLOAD_ID)).toMatchObject({ state: 'blocked' });
        expect(await db.outbox.get(SUBMIT_ID)).toMatchObject({
            state: 'blocked',
            lastError: { code: 'file_too_large' },
        });

        backend.state.inject.clear();
        await retryBlocked(UUID);
        await runSync({ manual: true });
        expect(summarize(backend.state.log)).toEqual(['PUT create', 'UPLOAD', 'UPLOAD', 'SUBMIT']);
        expect(backend.state.submissions.get(UUID)?.status).toBe('submitted');
    });

    it('a new submit resets blocked uploads and lifts the block', async () => {
        const backend = setupBackend();
        backend.state.inject.set('POST upload', failWith(409, 'attachment_conflict'));
        await seedPhoto();
        await runSync({ manual: true });
        expect(await db.drafts.get(UUID)).toMatchObject({ blockedBy: 'attachment_blocked' });

        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);

        expect(await db.drafts.get(UUID)).toMatchObject({ sync: 'queued', blockedBy: null });
        expect(await db.outbox.get(UPLOAD_ID)).toMatchObject({
            state: 'pending',
            nextAttemptAt: 0,
        });
        expect(await db.outbox.get(SUBMIT_ID)).toMatchObject({ state: 'pending' });
    });
});

describe('background submit refusals (contract)', () => {
    it('422 validation_failed blocks the draft with submit_refused and the pointer errors', async () => {
        const backend = setupBackend();
        const errors = [{ pointer: '/customer', code: 'required' }];
        backend.state.inject.set('POST submit', failWith(422, 'validation_failed', errors));
        await serverDraft(backend, {});
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);
        await enqueueSend(SCOPE, UUID, { email: 'kunde@example.test' });

        await runSync({ manual: true });

        expect(await db.drafts.get(UUID)).toMatchObject({
            sync: 'blocked',
            blockedBy: 'submit_refused',
            submitErrors: errors,
        });
        expect(await db.outbox.get(SUBMIT_ID)).toBeUndefined();
        expect(await db.outbox.get(SEND_ID)).toMatchObject({
            state: 'blocked',
            lastError: { status: 422, code: 'validation_failed' },
        });

        backend.state.inject.clear();
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT); // fixed and submitted again
        expect(await db.drafts.get(UUID)).toMatchObject({ sync: 'queued', blockedBy: null });
    });

    it('422 attachments_missing uploads the local copies again once, then submits', async () => {
        const backend = setupBackend();
        await serverDraft(backend, { photos: [PHOTO] });
        await db.attachments.put(
            attachmentRow({ uuid: PHOTO, submissionUuid: UUID, state: 'uploaded' }),
        );
        backend.state.inject.set('POST submit', () => {
            backend.state.inject.delete('POST submit');
            return failWith(422, 'attachments_missing', [PHOTO])();
        });
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);

        await runSync({ manual: true });
        expect(await db.outbox.get(SUBMIT_ID)).toMatchObject({
            state: 'pending',
            reuploaded: true,
        });
        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['SUBMIT', 'UPLOAD', 'SUBMIT']);
        expect(backend.state.submissions.get(UUID)?.status).toBe('submitted');
    });

    it('422 attachments_missing again blocks the draft with the missing uuids', async () => {
        const backend = setupBackend();
        await serverDraft(backend, { photos: [PHOTO, 'gone'] });
        await db.attachments.put(attachmentRow({ uuid: PHOTO, submissionUuid: UUID }));
        backend.state.inject.set(
            'POST submit',
            failWith(422, 'attachments_missing', [PHOTO, 'gone']),
        );
        await enqueueSubmit(SCOPE, UUID, SUBMITTED_AT);

        await runSync({ manual: true });
        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['SUBMIT', 'UPLOAD', 'SUBMIT']);
        expect(await db.drafts.get(UUID)).toMatchObject({
            sync: 'blocked',
            blockedBy: 'attachments_missing',
            missingAttachments: [PHOTO, 'gone'],
        });
        expect(await db.outbox.get(SUBMIT_ID)).toBeUndefined();
    });
});
