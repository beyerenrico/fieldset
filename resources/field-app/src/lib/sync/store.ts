/** Transactional row updates of the sync engine (ADR 0035); every read-modify-write is atomic. */
import type { ValidationDetail } from '../api/errors.ts';
import { EDITABLE_STATUSES, type Submission } from '../api/types.ts';
import { db } from '../db/db.ts';
import type { DraftRow, DraftSync, OutboxRow, PersistedRequest } from '../db/schema.ts';
import { opId, opsOf } from '../outbox/outbox.ts';
import { backoffDelay, toOutboxError } from './backoff.ts';

export const isPaused = (sync: DraftSync): boolean => sync === 'conflict' || sync === 'blocked';

/** Applies `change` to the draft row atomically; a missing row stays missing. */
export async function updateDraft(
    uuid: string,
    change: (row: DraftRow) => DraftRow,
): Promise<DraftRow | undefined> {
    return db.transaction('rw', db.drafts, async () => {
        const row = await db.drafts.get(uuid);
        if (row === undefined) {
            return undefined;
        }
        const next = change(row);
        await db.drafts.put(next);
        return next;
    });
}

/** A successful (or adopted) PUT: the server copy covers local writes up to `sentVersion`. */
export async function applySaved(
    uuid: string,
    submission: Submission,
    sentVersion: number,
): Promise<void> {
    await db.transaction('rw', db.drafts, db.outbox, async () => {
        const row = await db.drafts.get(uuid);
        if (row === undefined) {
            return;
        }
        const syncedVersion = Math.max(row.syncedVersion, sentVersion);
        await db.drafts.put({
            ...row,
            serverRevision: submission.revision,
            server: submission,
            syncedVersion,
            conflict: null,
            blockedBy: null,
        });
        if (row.localVersion <= syncedVersion) {
            await db.outbox.delete(opId('draft.save', uuid));
        }
    });
}

export async function markConflict(uuid: string, server: Submission): Promise<void> {
    await updateDraft(uuid, (row) => ({ ...row, sync: 'conflict', conflict: server }));
}

export async function markBlocked(uuid: string, code: string): Promise<void> {
    await updateDraft(uuid, (row) => ({ ...row, sync: 'blocked', blockedBy: code }));
}

/** The submit went through (or a lost response was adopted). */
export async function applySubmitted(
    uuid: string,
    id: string,
    submission: Submission,
): Promise<void> {
    await db.transaction('rw', db.drafts, db.outbox, async () => {
        await db.outbox.delete(id);
        const row = await db.drafts.get(uuid);
        if (row !== undefined) {
            await db.drafts.put({
                ...row,
                server: submission,
                serverRevision: submission.revision,
                syncedVersion: row.localVersion,
                submitErrors: [],
                blockedBy: null,
                conflict: null,
            });
        }
    });
}

/** 422 on submit: the op is dropped, the draft is editable again with the pointer errors. */
export async function applySubmitRefused(
    uuid: string,
    id: string,
    errors: readonly ValidationDetail[],
): Promise<void> {
    await db.transaction('rw', db.drafts, db.outbox, async () => {
        await db.outbox.delete(id);
        const row = await db.drafts.get(uuid);
        if (row !== undefined) {
            await db.drafts.put({ ...row, submitErrors: errors });
        }
    });
}

export async function deleteOp(id: string): Promise<void> {
    await db.outbox.delete(id);
}

/** Network error, 5xx, 408, 429: try again later; a manual run starts the backoff over. */
export async function retryLater(
    op: OutboxRow,
    error: unknown,
    manual: boolean,
    retryAfterMs: number | null,
): Promise<void> {
    const attempts = (manual ? 0 : op.attempts) + 1;
    const delay = retryAfterMs ?? backoffDelay(attempts);
    await db.transaction('rw', db.outbox, async () => {
        const current = await db.outbox.get(op.id);
        if (current !== undefined) {
            await db.outbox.put({
                ...current,
                attempts,
                nextAttemptAt: Date.now() + delay,
                lastError: toOutboxError(error),
            });
        }
    });
}

export async function blockOp(op: OutboxRow, error: unknown): Promise<void> {
    await db.transaction('rw', db.outbox, async () => {
        const current = await db.outbox.get(op.id);
        if (current !== undefined) {
            await db.outbox.put({ ...current, state: 'blocked', lastError: toOutboxError(error) });
        }
    });
}

/** Stores the request of a submit/send before it is sent (a retry sends the same bytes). */
export async function persistRequest(id: string, request: PersistedRequest): Promise<void> {
    await db.transaction('rw', db.outbox, async () => {
        const current = await db.outbox.get(id);
        if (current?.kind === 'submit' || current?.kind === 'send') {
            await db.outbox.put({ ...current, request });
        }
    });
}

/**
 * After a run touched a submission: the sync state follows its rows, and a submitted, fully synced
 * submission leaves the device (its draft row and attachment bytes are pruned).
 */
export async function settleSubmission(uuid: string): Promise<void> {
    await db.transaction('rw', db.drafts, db.outbox, db.attachments, async () => {
        const row = await db.drafts.get(uuid);
        if (row === undefined || isPaused(row.sync)) {
            return;
        }
        const ops = await opsOf(uuid);
        const dirty = row.localVersion > row.syncedVersion;
        const sync: DraftSync = dirty || ops.length > 0 ? 'queued' : 'synced';
        const done = row.server !== null && !EDITABLE_STATUSES.includes(row.server.status);
        if (sync === 'synced' && done) {
            await db.drafts.delete(uuid);
            await db.attachments.where('submissionUuid').equals(uuid).delete();
            return;
        }
        if (row.sync !== sync) {
            await db.drafts.put({ ...row, sync });
        }
    });
}

export async function markSyncing(uuid: string): Promise<void> {
    await db.transaction('rw', db.drafts, async () => {
        const row = await db.drafts.get(uuid);
        if (row !== undefined && !isPaused(row.sync) && row.sync !== 'syncing') {
            await db.drafts.put({ ...row, sync: 'syncing' });
        }
    });
}

/**
 * A run starts (inside the sync lock): rows a killed tab left at `syncing` are queued again, since
 * no run of this user is in flight now.
 */
export async function resetStaleSyncing(userId: number): Promise<void> {
    await db.transaction('rw', db.drafts, async () => {
        const stale = await db.drafts
            .filter((row) => row.userId === userId && row.sync === 'syncing')
            .toArray();
        await db.drafts.bulkPut(stale.map((row) => ({ ...row, sync: 'queued' as const })));
    });
}

/**
 * Upload ops whose attachment row is gone (the user removed the file before it was uploaded): they
 * are dropped, so they hold back neither the draft update nor the submit. The row is always written
 * before its op (attachments/local-store.ts), so a missing row never means "not stored yet".
 */
export async function dropOrphanUploads(submissionUuid: string): Promise<void> {
    await db.transaction('rw', db.outbox, db.attachments, async () => {
        const ops = await db.outbox.where('submissionUuid').equals(submissionUuid).toArray();
        for (const op of ops) {
            if (op.kind !== 'attachment.upload') {
                continue;
            }
            if ((await db.attachments.get(op.attachmentUuid)) === undefined) {
                await db.outbox.delete(op.id);
            }
        }
    });
}
