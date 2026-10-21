/** Outbox rows (ADR 0035): coalescing ids, global order, re-queued submits, summary, wake-ups. */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../../src/lib/db/db.ts';
import {
    BACKGROUND_SYNC_TAG,
    registerBackgroundSync,
} from '../../../src/lib/outbox/background-sync.ts';
import {
    enqueueAttachmentUpload,
    enqueueDraftSave,
    enqueueSend,
    enqueueSubmit,
    opId,
    opsOfUser,
    removeOp,
    unsyncedSummary,
} from '../../../src/lib/outbox/outbox.ts';
import { onSyncRequested } from '../../../src/lib/sync/triggers.ts';
import { SCOPE, attachmentRow, draftRow, outboxIds, setupSyncScope } from '../../utils/a-store.ts';
import { setupTestServer } from '../../utils/server.ts';

setupTestServer();
setupSyncScope();

afterEach(() => {
    Reflect.deleteProperty(navigator, 'onLine');
    Reflect.deleteProperty(navigator, 'serviceWorker');
    vi.restoreAllMocks();
});

/** draft.save ops need their draft row (no orphans). */
async function seedDrafts(...rows: Parameters<typeof draftRow>[0][]): Promise<void> {
    await db.drafts.bulkPut(rows.map((row) => draftRow(row)));
}

describe('outbox', () => {
    it('coalesces by `${kind}:${user}:${uuid}[:${attachment}]` and keeps the enqueue order', async () => {
        await seedDrafts({ uuid: 's1' });
        await enqueueDraftSave(SCOPE, 's1');
        await enqueueAttachmentUpload(SCOPE, 's1', 'a1');
        await enqueueDraftSave(SCOPE, 's1');
        await enqueueAttachmentUpload(SCOPE, 's1', 'a1');
        await enqueueAttachmentUpload(SCOPE, 's1', 'a2');
        await enqueueSubmit(SCOPE, 's1', '2026-10-17T10:00:00.000Z');
        expect(await outboxIds()).toEqual([
            'draft.save:7:s1',
            'attachment.upload:7:s1:a1',
            'attachment.upload:7:s1:a2',
            'submit:7:s1',
        ]);
        expect(opId('attachment.upload', 7, 's1', 'a2')).toBe('attachment.upload:7:s1:a2');
        // The short form uses the signed-in user.
        expect(opId('attachment.upload', 's1', 'a2')).toBe('attachment.upload:7:s1:a2');
        const seqs = (await db.outbox.orderBy('seq').toArray()).map((op) => op.seq);
        expect(seqs).toEqual([...seqs].sort((a, b) => a - b));
    });

    it('requests a sync on every enqueue', async () => {
        await seedDrafts({ uuid: 's1' });
        const reasons: string[] = [];
        const stop = onSyncRequested((reason) => reasons.push(reason));
        await enqueueDraftSave(SCOPE, 's1');
        await enqueueSend(SCOPE, 's1', { email: 'a@b.de' });
        stop();
        expect(reasons).toEqual(['enqueue', 'enqueue']);
    });

    it('puts a blocked submit back to pending, keeps its request and clears the errors', async () => {
        await db.drafts.put(
            draftRow({ uuid: 's1', submitErrors: [{ pointer: '/a', code: 'required' }] }),
        );
        await enqueueSubmit(SCOPE, 's1', '2026-10-17T10:00:00.000Z');
        const request = { idempotencyKey: 'k-1', body: { revision: 2 } };
        const op = await db.outbox.get(opId('submit', 's1'));
        await db.outbox.put({ ...op!, state: 'blocked', nextAttemptAt: 99, request } as never);
        await enqueueSubmit(SCOPE, 's1', '2026-10-17T11:00:00.000Z');
        expect(await db.outbox.get(opId('submit', 's1'))).toMatchObject({
            state: 'pending',
            nextAttemptAt: 0,
            request,
            clientSubmittedAt: '2026-10-17T10:00:00.000Z',
        });
        expect(await db.drafts.get('s1')).toMatchObject({ submitErrors: [], sync: 'queued' });
    });

    it('lists the ops of one user across organizations and removes single ops', async () => {
        await seedDrafts({ uuid: 's1' }, { uuid: 's2', orgId: 2 }, { uuid: 's3', userId: 8 });
        await enqueueDraftSave(SCOPE, 's1');
        await enqueueDraftSave({ orgId: 2, userId: 7 }, 's2');
        await enqueueDraftSave({ orgId: 1, userId: 8 }, 's3');
        expect((await opsOfUser(7)).map((op) => op.submissionUuid)).toEqual(['s1', 's2']);
        await removeOp(opId('draft.save', 7, 's2'));
        expect((await opsOfUser(7)).map((op) => op.submissionUuid)).toEqual(['s1']);
    });

    it('summarizes what is not synced for the logout warning', async () => {
        await db.drafts.put(draftRow({ uuid: 's1', localVersion: 2, syncedVersion: 1 }));
        await db.drafts.put(
            draftRow({ uuid: 's2', localVersion: 1, syncedVersion: 1, sync: 'conflict' }),
        );
        await db.attachments.put(attachmentRow({ uuid: 'a1', submissionUuid: 's1' }));
        await seedDrafts({ uuid: 'other-user', userId: 8, localVersion: 1, syncedVersion: 1 });
        await enqueueDraftSave(SCOPE, 's1');
        await enqueueDraftSave({ orgId: 1, userId: 8 }, 'other-user');
        expect(await unsyncedSummary(SCOPE)).toEqual({
            submissions: 2, // s1 (draft, op, photo) and s2 (conflict)
            ops: 1,
            dirtyDrafts: 1,
            pendingAttachments: 1,
            blocked: 1,
        });
    });
});

describe('Background Sync wake-up (Chromium only)', () => {
    it('registers the tag when an op is enqueued offline', async () => {
        const register = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false });
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { getRegistration: () => Promise.resolve({ sync: { register } }) },
        });
        await seedDrafts({ uuid: 's1' });
        await enqueueDraftSave(SCOPE, 's1');
        await vi.waitFor(() => {
            expect(register).toHaveBeenCalledWith(BACKGROUND_SYNC_TAG);
        });
        expect(BACKGROUND_SYNC_TAG).toBe('fieldset-outbox');
    });

    it('does nothing without a worker or SyncManager, and never throws', async () => {
        expect(await registerBackgroundSync()).toBe(false);
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { getRegistration: () => Promise.resolve({}) },
        });
        expect(await registerBackgroundSync()).toBe(false);
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { getRegistration: () => Promise.reject(new Error('denied')) },
        });
        expect(await registerBackgroundSync()).toBe(false);
    });

    it('stays quiet online (the app syncs itself)', async () => {
        const register = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { getRegistration: () => Promise.resolve({ sync: { register } }) },
        });
        await seedDrafts({ uuid: 's1' });
        await enqueueDraftSave(SCOPE, 's1');
        await new Promise((resolve) => setTimeout(resolve, 10));
        expect(register).not.toHaveBeenCalled();
    });
});
