/** Transactional row updates of the sync engine (ADR 0035); every read-modify-write is atomic. */
import type { ValidationDetail } from '../api/errors.ts';
import { EDITABLE_STATUSES, type Submission } from '../api/types.ts';
import { db } from '../db/db.ts';
import type {
    DraftRow,
    DraftSync,
    OutboxError,
    OutboxRow,
    PersistedRequest,
    SentSave,
} from '../db/schema.ts';
import { opsOf } from '../outbox/outbox.ts';
import { backoffDelay, toOutboxError } from './backoff.ts';

export const isPaused = (sync: DraftSync): boolean => sync === 'conflict' || sync === 'blocked';

/** Unconfirmed PUTs kept per draft (only the ones on the current base revision). */
const MAX_SENT_SAVES = 10;

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

/** The Idempotency-Key a submit or send carries (null for the other kinds or before the first send). */
function keyOf(op: OutboxRow): string | null {
    if (op.kind === 'submit') {
        return op.request?.idempotencyKey ?? null;
    }
    return op.kind === 'send' ? op.request.idempotencyKey : null;
}

/**
 * The stored op while it is still the request that was sent; a submit or send re-queued with
 * another request (new recipient, new key) meanwhile is left for the next run. Call inside a
 * transaction over the outbox.
 */
async function storedIfSent(sent: OutboxRow): Promise<OutboxRow | undefined> {
    const current = await db.outbox.get(sent.id);
    return current !== undefined && keyOf(current) === keyOf(sent) ? current : undefined;
}

/** Deletes the draft.save op(s) of a submission (inside the caller's transaction). */
async function deleteSaveOps(uuid: string): Promise<void> {
    await db.outbox
        .where('submissionUuid')
        .equals(uuid)
        .filter((op) => op.kind === 'draft.save')
        .delete();
}

/** Puts the queued submit and send of a submission on hold with the answer that paused it. */
async function blockWaitingOps(uuid: string, lastError: OutboxError): Promise<void> {
    const waiting = (await opsOf(uuid)).filter((op) => op.kind === 'submit' || op.kind === 'send');
    await db.outbox.bulkPut(waiting.map((op) => ({ ...op, state: 'blocked' as const, lastError })));
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
            sentSaves: [],
        });
        if (row.localVersion <= syncedVersion) {
            await deleteSaveOps(uuid);
        }
    });
}

/** Remembers a PUT before it leaves (a lost answer is recognised by the next 409). */
export async function recordSentSave(uuid: string, sent: SentSave): Promise<void> {
    await updateDraft(uuid, (row) => {
        const kept = (row.sentSaves ?? []).filter(
            (entry) => entry.revision === sent.revision && entry.hash !== sent.hash,
        );
        return { ...row, sentSaves: [...kept, sent].slice(-MAX_SENT_SAVES) };
    });
}

/** Pauses the draft and puts its queued submit and send on hold with the answer (`error`). */
async function pauseDraft(
    uuid: string,
    change: (row: DraftRow) => DraftRow,
    error: unknown,
): Promise<void> {
    await db.transaction('rw', db.drafts, db.outbox, async () => {
        const row = await db.drafts.get(uuid);
        if (row !== undefined) {
            await db.drafts.put(change(row));
            await blockWaitingOps(uuid, toOutboxError(error));
        }
    });
}

export async function markConflict(
    uuid: string,
    server: Submission,
    error: unknown,
): Promise<void> {
    await pauseDraft(uuid, (row) => ({ ...row, sync: 'conflict', conflict: server }), error);
}

export async function markBlocked(uuid: string, code: string, error: unknown): Promise<void> {
    await pauseDraft(uuid, (row) => ({ ...row, sync: 'blocked', blockedBy: code }), error);
}

/** What a finished submit covers. */
export interface SubmitCover {
    /** Local version the submitted data contains; edits after it stay dirty and queued. */
    readonly version: number;
    /**
     * Finished from a server copy after the user edited again (lost answer): those edits can no
     * longer reach the submitted submission, so the row takes the server data and leaves the device.
     */
    readonly dropLaterEdits?: boolean;
}

function submittedRow(row: DraftRow, submission: Submission, cover: SubmitCover): DraftRow {
    const drop = cover.dropLaterEdits === true;
    return {
        ...row,
        data: drop ? submission.data : row.data,
        server: submission,
        serverRevision: submission.revision,
        syncedVersion: drop ? row.localVersion : Math.max(row.syncedVersion, cover.version),
        sync: isPaused(row.sync) ? 'queued' : row.sync,
        submitErrors: [],
        missingAttachments: [],
        sentSaves: [],
        blockedBy: null,
        conflict: null,
    };
}

/** The submit went through (or a lost answer was adopted); `sent` is the submit op as it was sent. */
export async function applySubmitted(
    sent: OutboxRow,
    submission: Submission,
    cover: SubmitCover,
): Promise<void> {
    await db.transaction('rw', db.drafts, db.outbox, async () => {
        if ((await storedIfSent(sent)) !== undefined) {
            await db.outbox.delete(sent.id);
        }
        const row = await db.drafts.get(sent.submissionUuid);
        if (row !== undefined) {
            const next = submittedRow(row, submission, cover);
            await db.drafts.put(next);
            if (next.localVersion <= next.syncedVersion) {
                await deleteSaveOps(row.uuid);
            }
        }
    });
}

/** A 422 on submit: its pointer errors, and for a background submit the block that shows it. */
export interface SubmitRefusal {
    readonly errors: readonly ValidationDetail[];
    /** SUBMIT_REFUSED / ATTACHMENTS_MISSING for a background submit; null for an interactive one. */
    readonly blockedBy: string | null;
    readonly missing: readonly string[];
    readonly error: unknown;
}

/** 422 on submit: the op is dropped and the draft is editable again with the pointer errors. */
export async function applySubmitRefused(sent: OutboxRow, refusal: SubmitRefusal): Promise<void> {
    await db.transaction('rw', db.drafts, db.outbox, async () => {
        if ((await storedIfSent(sent)) === undefined) {
            return; // submitted again with another request meanwhile: this answer is stale
        }
        await db.outbox.delete(sent.id);
        const row = await db.drafts.get(sent.submissionUuid);
        if (row === undefined) {
            return;
        }
        const { blockedBy } = refusal;
        await db.drafts.put({
            ...row,
            submitErrors: refusal.errors,
            missingAttachments: refusal.missing,
            ...(blockedBy === null ? {} : { sync: 'blocked' as const, blockedBy }),
        });
        if (blockedBy !== null) {
            await blockWaitingOps(row.uuid, toOutboxError(refusal.error));
        }
    });
}

/** Deletes the op once it went through, unless it was re-queued with another request meanwhile. */
export async function deleteSentOp(sent: OutboxRow): Promise<void> {
    await db.transaction('rw', db.outbox, async () => {
        if ((await storedIfSent(sent)) !== undefined) {
            await db.outbox.delete(sent.id);
        }
    });
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
        const current = await storedIfSent(op);
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
        const current = await storedIfSent(op);
        if (current !== undefined) {
            await db.outbox.put({ ...current, state: 'blocked', lastError: toOutboxError(error) });
        }
    });
}

/**
 * Stores the request of a submit/send before it is sent (a retry sends the same bytes). Only while
 * the stored request is still `previousKey`'s: false when the op was re-queued or removed meanwhile.
 */
export async function persistRequest(
    id: string,
    request: PersistedRequest,
    previousKey: string | null,
): Promise<boolean> {
    return db.transaction('rw', db.outbox, async () => {
        const current = await db.outbox.get(id);
        if (
            (current?.kind !== 'submit' && current?.kind !== 'send') ||
            keyOf(current) !== previousKey
        ) {
            return false;
        }
        await db.outbox.put({ ...current, request });
        return true;
    });
}

/** Marks the submit op: its local attachments were queued again once (attachments_missing). */
export async function markReuploaded(sent: OutboxRow): Promise<void> {
    await db.transaction('rw', db.outbox, async () => {
        const current = await storedIfSent(sent);
        if (current?.kind === 'submit') {
            await db.outbox.put({ ...current, reuploaded: true });
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

/** draft.save ops whose draft is gone (discarded or wiped from another tab) are dropped. */
export async function dropOrphanSaves(submissionUuid: string): Promise<void> {
    await db.transaction('rw', db.drafts, db.outbox, async () => {
        if ((await db.drafts.get(submissionUuid)) === undefined) {
            await deleteSaveOps(submissionUuid);
        }
    });
}
