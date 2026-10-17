/** Conflicts and blocking answers (ADR 0035): the user's choices, refused submits and uploads. */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../../src/lib/db/db.ts';
import {
    enqueueAttachmentUpload,
    enqueueDraftSave,
    enqueueSend,
    enqueueSubmit,
    opId,
} from '../../../src/lib/outbox/outbox.ts';
import {
    copyToNewDraft,
    discardLocalSubmission,
    resolveConflict,
    retryBlocked,
} from '../../../src/lib/sync/conflicts.ts';
import { runSync } from '../../../src/lib/sync/engine.ts';
import { onOutboxEvent, type OutboxEvent } from '../../../src/lib/sync/events.ts';
import { createSyncBackend, fakeUploads, failWith, summarize } from '../../utils/a-backend.ts';
import { SCOPE, attachmentRow, draftRow, setupSyncScope } from '../../utils/a-store.ts';
import { makeSubmission } from '../../utils/fixtures.ts';
import { server, setupTestServer } from '../../utils/server.ts';

setupTestServer();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-00000000d001';
const PHOTO = '0192f1c4-0000-7000-8000-00000000d0a1';

afterEach(() => {
    vi.restoreAllMocks();
});

function setup() {
    const backend = createSyncBackend();
    server.use(...backend.handlers);
    fakeUploads(backend.state);
    return backend;
}

/** On the server at revision 1, edited locally since ("Mine"), changed on the server ("Server"). */
async function conflicted(backend: ReturnType<typeof setup>): Promise<void> {
    const base = makeSubmission({ uuid: UUID, revision: 1, data: { customer: 'Base' } });
    backend.state.submissions.set(UUID, { ...base, revision: 2, data: { customer: 'Server' } });
    await db.drafts.put(
        draftRow({
            uuid: UUID,
            data: { customer: 'Mine' },
            localVersion: 3,
            syncedVersion: 2,
            serverRevision: 1,
            server: base,
        }),
    );
    await enqueueDraftSave(SCOPE, UUID);
    await enqueueSubmit(SCOPE, UUID, '2026-10-17T10:00:00.000Z');
    await runSync({ manual: true });
}

describe('revision conflicts', () => {
    it('pauses the submission, keeps the queued submit waiting and stores the server copy', async () => {
        const backend = setup();
        await conflicted(backend);
        const row = await db.drafts.get(UUID);
        expect(row).toMatchObject({ sync: 'conflict', conflict: { revision: 2 } });
        expect(row?.conflict?.data).toEqual({ customer: 'Server' });
        expect(summarize(backend.state.log)).toEqual(['PUT update']);
        expect(await db.outbox.get(opId('submit', UUID))).toMatchObject({ state: 'pending' });

        await runSync({ manual: true }); // still paused until the user decides
        expect(backend.state.log).toHaveLength(1);
    });

    it('"Keep mine" pushes my data on top of the server revision, then submits', async () => {
        const backend = setup();
        await conflicted(backend);
        await resolveConflict(UUID, 'mine');
        expect(await db.drafts.get(UUID)).toMatchObject({ sync: 'queued', serverRevision: 2 });

        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['PUT update', 'PUT update', 'SUBMIT']);
        expect(backend.state.log[1]?.body).toMatchObject({
            revision: 2,
            data: { customer: 'Mine' },
        });
        expect(backend.state.log[2]?.body).toMatchObject({ revision: 3 });
        expect(backend.state.submissions.get(UUID)).toMatchObject({
            status: 'submitted',
            data: { customer: 'Mine' },
        });
    });

    it('"Use server" adopts the server copy without another PUT', async () => {
        const backend = setup();
        await conflicted(backend);
        await resolveConflict(UUID, 'server');
        expect(await db.drafts.get(UUID)).toMatchObject({
            sync: 'synced',
            data: { customer: 'Server' },
            serverRevision: 2,
            conflict: null,
        });
        expect(await db.outbox.get(opId('draft.save', UUID))).toBeUndefined();

        await runSync({ manual: true });

        expect(summarize(backend.state.log)).toEqual(['PUT update', 'SUBMIT']);
        expect(backend.state.log[1]?.body).toMatchObject({ revision: 2 });
    });
});

describe('blocking answers', () => {
    it('not_editable blocks the draft; "save as a new draft" moves data and photos to a new uuid', async () => {
        const backend = setup();
        backend.state.submissions.set(
            UUID,
            makeSubmission({ uuid: UUID, revision: 1, status: 'submitted', can_edit: false }),
        );
        await db.drafts.put(
            draftRow({
                uuid: UUID,
                data: { customer: 'Late edit', photos: [PHOTO] },
                serverRevision: 1,
            }),
        );
        await db.attachments.put(attachmentRow({ uuid: PHOTO, submissionUuid: UUID }));
        await enqueueDraftSave(SCOPE, UUID);
        await runSync({ manual: true });
        expect(await db.drafts.get(UUID)).toMatchObject({
            sync: 'blocked',
            blockedBy: 'not_editable',
        });

        const copy = await copyToNewDraft(UUID);

        expect(copy).not.toBe(UUID);
        expect(await db.drafts.get(UUID)).toBeUndefined();
        const row = await db.drafts.get(copy);
        expect(row).toMatchObject({ serverRevision: 0, sync: 'queued', server: null });
        const photos = (row?.data.photos ?? []) as string[];
        expect(photos).toHaveLength(1);
        expect(photos[0]).not.toBe(PHOTO);
        expect(await db.attachments.get(photos[0] ?? '')).toMatchObject({ submissionUuid: copy });
        await runSync({ manual: true });
        expect(summarize(backend.state.log)).toEqual(['PUT update', 'PUT create', 'UPLOAD']);
        expect(backend.state.submissions.get(copy)?.data).toMatchObject({ customer: 'Late edit' });
    });

    it('version_retired offers a copy into the current version', async () => {
        setup();
        server.use(
            (await import('msw')).http.put('*/api/v1/submissions/:uuid', () =>
                failWith(409, 'version_retired')(),
            ),
        );
        await db.drafts.put(draftRow({ uuid: UUID, data: { customer: 'Old version' } }));
        await enqueueDraftSave(SCOPE, UUID);
        await runSync({ manual: true });
        expect(await db.drafts.get(UUID)).toMatchObject({ blockedBy: 'version_retired' });

        const copy = await copyToNewDraft(UUID, { formId: 3, formVersionId: 9 });
        expect(await db.drafts.get(copy)).toMatchObject({
            formVersionId: 9,
            data: { customer: 'Old version' },
        });
    });

    it('"Try again" resumes a blocked draft; "discard" removes every local row', async () => {
        const backend = setup();
        backend.state.inject.set('PUT put', failWith(403, 'form_access_revoked'));
        await db.drafts.put(draftRow({ uuid: UUID, data: { a: 1 } }));
        await db.attachments.put(attachmentRow({ uuid: PHOTO, submissionUuid: UUID }));
        await enqueueDraftSave(SCOPE, UUID);
        await enqueueAttachmentUpload(SCOPE, UUID, PHOTO);
        await runSync({ manual: true });
        expect(await db.drafts.get(UUID)).toMatchObject({ blockedBy: 'form_access_revoked' });

        backend.state.inject.clear();
        await retryBlocked(UUID);
        await runSync({ manual: true });
        expect(summarize(backend.state.log)).toEqual(['PUT create', 'PUT create', 'UPLOAD']);

        await discardLocalSubmission(UUID);
        expect(await db.drafts.get(UUID)).toBeUndefined();
        expect(await db.attachments.count()).toBe(0);
        expect(await db.outbox.count()).toBe(0);
    });

    it('422 on an upload marks the photo failed and holds the submit back', async () => {
        const backend = setup();
        backend.state.inject.set('POST upload', failWith(422, 'image_too_large'));
        await db.drafts.put(draftRow({ uuid: UUID, data: { photos: [PHOTO] } }));
        await db.attachments.put(attachmentRow({ uuid: PHOTO, submissionUuid: UUID }));
        await enqueueDraftSave(SCOPE, UUID);
        await enqueueAttachmentUpload(SCOPE, UUID, PHOTO);
        await enqueueSubmit(SCOPE, UUID, '2026-10-17T10:00:00.000Z');
        const events: OutboxEvent[] = [];
        const stop = onOutboxEvent((event) => events.push(event));

        await runSync({ manual: true });
        stop();

        expect(await db.attachments.get(PHOTO)).toMatchObject({
            state: 'failed',
            error: { code: 'image_too_large' },
        });
        expect(summarize(backend.state.log)).toEqual(['PUT create', 'UPLOAD']);
        expect(await db.outbox.get(opId('submit', UUID))).toMatchObject({
            state: 'blocked',
            lastError: { code: 'attachment_failed' },
        });
        expect(events.map((event) => `${event.type}:${event.kind}`)).toEqual([
            'done:draft.save',
            'failed:attachment.upload',
            'failed:submit',
        ]);
    });

    it('422 on submit drops the submit and keeps the pointer errors on the draft', async () => {
        const backend = setup();
        backend.state.inject.set(
            'POST submit',
            failWith(422, 'validation_failed', [{ pointer: '/customer', code: 'required' }]),
        );
        await db.drafts.put(draftRow({ uuid: UUID, data: {} }));
        await enqueueDraftSave(SCOPE, UUID);
        await enqueueSubmit(SCOPE, UUID, '2026-10-17T10:00:00.000Z');
        await runSync({ manual: true });
        expect(await db.outbox.get(opId('submit', UUID))).toBeUndefined();
        expect(await db.drafts.get(UUID)).toMatchObject({
            submitErrors: [{ pointer: '/customer', code: 'required' }],
            sync: 'synced',
        });
        expect(summarize(backend.state.log)).toEqual(['PUT create', 'SUBMIT']);
    });

    it('rotates the key once after idempotency_key_reused', async () => {
        const backend = setup();
        let calls = 0;
        backend.state.inject.set('POST submit', () => {
            calls++;
            backend.state.inject.delete('POST submit');
            return failWith(409, 'idempotency_key_reused')();
        });
        await db.drafts.put(draftRow({ uuid: UUID, data: { customer: 'A' } }));
        await enqueueDraftSave(SCOPE, UUID);
        await enqueueSubmit(SCOPE, UUID, '2026-10-17T10:00:00.000Z');
        await runSync({ manual: true });
        const keys = backend.state.log.filter((e) => e.path.endsWith('/submit')).map((e) => e.key);
        expect(calls).toBe(1);
        expect(keys).toHaveLength(2);
        expect(keys[1]).not.toBe(keys[0]);
        expect(backend.state.submissions.get(UUID)?.status).toBe('submitted');
    });
});

describe('device time', () => {
    it('submits without client_submitted_at when the server refuses the device clock', async () => {
        const backend = setup();
        backend.state.inject.set('POST submit', () => {
            backend.state.inject.delete('POST submit');
            return failWith(422, 'validation_failed', [
                { pointer: '/client_submitted_at', code: 'before_or_equal' },
            ])();
        });
        await db.drafts.put(draftRow({ uuid: UUID, data: { customer: 'A' } }));
        await enqueueDraftSave(SCOPE, UUID);
        await enqueueSubmit(SCOPE, UUID, '2099-01-01T00:00:00.000Z');
        await runSync({ manual: true });
        const submits = backend.state.log.filter((entry) => entry.path.endsWith('/submit'));
        expect(submits.map((entry) => entry.body)).toEqual([
            { revision: 1, client_submitted_at: '2099-01-01T00:00:00.000Z' },
            { revision: 1 },
        ]);
        expect(submits[1]?.key).not.toBe(submits[0]?.key);
        expect(backend.state.submissions.get(UUID)?.status).toBe('submitted');
        expect(await db.drafts.get(UUID)).toBeUndefined();
    });
});

describe('queued sends', () => {
    it('blocks on send_not_allowed; the same recipient keeps its key, another one gets a new key', async () => {
        const backend = setup();
        backend.state.submissions.set(UUID, makeSubmission({ uuid: UUID, status: 'submitted' }));
        backend.state.inject.set('POST send', failWith(409, 'send_not_allowed'));
        const input = { email: 'a@example.test', name: 'A' };
        await enqueueSend(SCOPE, UUID, input);
        await runSync({ manual: true });
        const blocked = await db.outbox.get(opId('send', UUID));
        expect(blocked).toMatchObject({
            state: 'blocked',
            lastError: { code: 'send_not_allowed' },
        });

        await enqueueSend(SCOPE, UUID, input);
        const again = await db.outbox.get(opId('send', UUID));
        expect(again?.state).toBe('pending');
        const key = (op: typeof again) => (op?.kind === 'send' ? op.request.idempotencyKey : null);
        expect(key(again)).toBe(key(blocked));

        await enqueueSend(SCOPE, UUID, { email: 'b@example.test', name: 'B' });
        expect(key(await db.outbox.get(opId('send', UUID)))).not.toBe(key(blocked));
    });
});
