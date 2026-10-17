/**
 * The outbox (ADR 0035). Every enqueue coalesces by op (kind, user, submission, attachment) and
 * calls requestSync('enqueue'); while offline it also registers the Background Sync tag (a
 * Chromium-only wake-up, see background-sync.ts).
 */
import type { SendInput } from '../api/endpoints.ts';
import { sameJson } from '../autosave/same-json.ts';
import { db, nextSeq } from '../db/db.ts';
import type { DraftRow, OutboxError, OutboxKind, OutboxRow, Scope } from '../db/schema.ts';
import { getCurrentUserId } from '../db/scope.ts';
import { newId } from '../ids.ts';
import { LIFTED_BY_SUBMIT, pausedError } from '../sync/blocks.ts';
import { requestSync } from '../sync/triggers.ts';
import { registerBackgroundSync } from './background-sync.ts';

export interface UnsyncedSummary {
    /**
     * Distinct submissions with queued ops, unsynced or paused drafts, or attachments not uploaded:
     * the number users see (one offline draft is one submission, not its op plus its draft).
     */
    readonly submissions: number;
    readonly ops: number;
    readonly dirtyDrafts: number;
    readonly pendingAttachments: number;
    readonly blocked: number;
}

/**
 * `${kind}:${userId}:${submissionUuid}` (+ `:${attachmentUuid}`): the same op enqueued again
 * coalesces, and two users never share an op. The short form (no user id) uses the signed-in user;
 * without one it returns an id that matches no row.
 */
export function opId(
    kind: OutboxKind,
    userId: number,
    submissionUuid: string,
    attachmentUuid?: string,
): string;
export function opId(kind: OutboxKind, submissionUuid: string, attachmentUuid?: string): string;
export function opId(
    kind: OutboxKind,
    first: number | string,
    second?: string,
    third?: string,
): string {
    const [userId, submissionUuid, attachmentUuid] =
        typeof first === 'number'
            ? [first, second ?? '', third]
            : [getCurrentUserId() ?? 0, first, second];
    const id = `${kind}:${String(userId)}:${submissionUuid}`;
    return attachmentUuid === undefined ? id : `${id}:${attachmentUuid}`;
}

type OpOf<K extends OutboxKind> = Extract<OutboxRow, { kind: K }>;

function isKind<K extends OutboxKind>(op: OutboxRow, kind: K): op is OpOf<K> {
    return op.kind === kind;
}

/**
 * The op of one user's submission by kind (and attachment). Every lookup goes through here, so a
 * row is found whatever its id spelling.
 */
export async function findOp<K extends OutboxKind>(
    userId: number,
    submissionUuid: string,
    kind: K,
    attachmentUuid?: string,
): Promise<OpOf<K> | undefined> {
    const ops = await db.outbox.where('submissionUuid').equals(submissionUuid).toArray();
    const sameAttachment = (op: OutboxRow): boolean =>
        op.kind !== 'attachment.upload' || op.attachmentUuid === attachmentUuid;
    return ops.find(
        (op): op is OpOf<K> => op.userId === userId && isKind(op, kind) && sameAttachment(op),
    );
}

type NewOp<K extends OutboxKind> = Omit<
    OpOf<K>,
    keyof Scope | 'seq' | 'state' | 'attempts' | 'nextAttemptAt' | 'lastError' | 'createdAt'
>;

async function baseFields(scope: Scope) {
    return {
        orgId: scope.orgId,
        userId: scope.userId,
        seq: await nextSeq(),
        state: 'pending' as const,
        attempts: 0,
        nextAttemptAt: 0,
        lastError: null,
        createdAt: Date.now(),
    };
}

/** Called after every enqueue: wake the engines (all tabs) and, offline, the service worker. */
export function afterEnqueue(): void {
    requestSync('enqueue');
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
        void registerBackgroundSync();
    }
}

/**
 * Queues a draft PUT unless one is queued; the body is read from the DraftRow when the op is sent.
 * The row must exist (checked in the same transaction): a draft discarded or wiped meanwhile never
 * leaves an orphan op. Must run inside a transaction over drafts + outbox + meta when the row is
 * written in the same step (autosave). Resolves false when the draft is missing.
 */
export async function putDraftSaveOp(scope: Scope, submissionUuid: string): Promise<boolean> {
    return db.transaction('rw', db.drafts, db.outbox, db.meta, async () => {
        const draft = await db.drafts.get(submissionUuid);
        if (draft?.userId !== scope.userId) {
            return false;
        }
        if ((await findOp(scope.userId, submissionUuid, 'draft.save')) === undefined) {
            const op: NewOp<'draft.save'> = {
                id: opId('draft.save', scope.userId, submissionUuid),
                kind: 'draft.save',
                submissionUuid,
            };
            await db.outbox.put({ ...(await baseFields(scope)), ...op });
        }
        return true;
    });
}

export async function enqueueDraftSave(scope: Scope, submissionUuid: string): Promise<void> {
    if (await putDraftSaveOp(scope, submissionUuid)) {
        afterEnqueue();
    }
}

export async function enqueueAttachmentUpload(
    scope: Scope,
    submissionUuid: string,
    attachmentUuid: string,
): Promise<void> {
    await db.transaction('rw', db.outbox, db.meta, async () => {
        const found = await findOp(
            scope.userId,
            submissionUuid,
            'attachment.upload',
            attachmentUuid,
        );
        if (found === undefined) {
            const op: NewOp<'attachment.upload'> = {
                id: opId('attachment.upload', scope.userId, submissionUuid, attachmentUuid),
                kind: 'attachment.upload',
                submissionUuid,
                attachmentUuid,
            };
            await db.outbox.put({ ...(await baseFields(scope)), ...op });
        }
    });
    afterEnqueue();
}

/** A new submit: blocks a refused submit or a blocked upload lifts; conflicts and the rest stay. */
function resubmitted(row: DraftRow): DraftRow {
    const lifted = row.sync === 'blocked' && LIFTED_BY_SUBMIT.has(row.blockedBy ?? '');
    const paused = !lifted && (row.sync === 'conflict' || row.sync === 'blocked');
    return {
        ...row,
        submitErrors: [],
        missingAttachments: [],
        sync: paused ? row.sync : 'queued',
        blockedBy: lifted ? null : row.blockedBy,
    };
}

/** Blocked uploads of the submission get another try (the user replaced or kept the file). */
async function wakeBlockedUploads(userId: number, submissionUuid: string): Promise<void> {
    const ops = await db.outbox.where('submissionUuid').equals(submissionUuid).toArray();
    const blocked = ops.filter(
        (op) => op.userId === userId && op.kind === 'attachment.upload' && op.state === 'blocked',
    );
    await db.outbox.bulkPut(
        blocked.map((op) => ({ ...op, state: 'pending' as const, nextAttemptAt: 0, attempts: 0 })),
    );
}

const stateOf = (paused: OutboxError | null) => (paused === null ? 'pending' : 'blocked');

/**
 * Queues the submit. Submitting again (after a blocking answer was fixed) puts the op back to
 * pending; its persisted request is kept and only replaced when the revision changed (engine). A
 * draft that still waits for the user (conflict, not_editable, …) keeps the op blocked.
 */
export async function enqueueSubmit(
    scope: Scope,
    submissionUuid: string,
    clientSubmittedAt: string,
): Promise<void> {
    await db.transaction('rw', db.outbox, db.drafts, db.meta, async () => {
        const draft = await db.drafts.get(submissionUuid);
        const next = draft === undefined ? undefined : resubmitted(draft);
        const paused = next === undefined ? null : pausedError(next);
        const existing = await findOp(scope.userId, submissionUuid, 'submit');
        const state = stateOf(paused);
        await db.outbox.put(
            existing === undefined
                ? {
                      ...(await baseFields(scope)),
                      id: opId('submit', scope.userId, submissionUuid),
                      kind: 'submit',
                      submissionUuid,
                      clientSubmittedAt,
                      request: null,
                      state,
                      lastError: paused,
                  }
                : { ...existing, state, nextAttemptAt: 0, lastError: paused, reuploaded: false },
        );
        if (next !== undefined) {
            await db.drafts.put(next);
        }
        await wakeBlockedUploads(scope.userId, submissionUuid);
    });
    afterEnqueue();
}

/**
 * Queues "send to customer" with a persisted `{ idempotencyKey, body }`: the same recipient again
 * keeps the key (a retry), another recipient replaces the request with a new key. Only the user's
 * own op is reused.
 */
export async function enqueueSend(
    scope: Scope,
    submissionUuid: string,
    input: SendInput,
): Promise<void> {
    const body = { ...input };
    await db.transaction('rw', db.outbox, db.drafts, db.meta, async () => {
        const draft = await db.drafts.get(submissionUuid);
        const paused = draft === undefined ? null : pausedError(draft);
        const existing = await findOp(scope.userId, submissionUuid, 'send');
        const state = stateOf(paused);
        if (existing?.userId === scope.userId && sameJson(existing.request.body, body)) {
            await db.outbox.put({ ...existing, state, nextAttemptAt: 0, lastError: paused });
            return;
        }
        if (existing !== undefined) {
            await db.outbox.delete(existing.id);
        }
        await db.outbox.put({
            ...(await baseFields(scope)),
            id: opId('send', scope.userId, submissionUuid),
            kind: 'send',
            submissionUuid,
            input,
            request: { idempotencyKey: newId(), body },
            state,
            lastError: paused,
        });
    });
    afterEnqueue();
}

/** Drops a queued send (the user gave up on a blocked one). */
export async function removeOp(id: string): Promise<void> {
    await db.outbox.delete(id);
}

/** All ops of one user (every organization), in enqueue order. */
export async function opsOfUser(userId: number): Promise<OutboxRow[]> {
    const ops = await db.outbox.orderBy('seq').toArray();
    return ops.filter((op) => op.userId === userId);
}

export async function opsOf(submissionUuid: string): Promise<OutboxRow[]> {
    const ops = await db.outbox.where('submissionUuid').equals(submissionUuid).toArray();
    return ops.toSorted((a, b) => a.seq - b.seq);
}

/** What would be lost on logout: queued ops, unsynced drafts, attachments not uploaded, blocked items. */
export async function unsyncedSummary(scope: Scope): Promise<UnsyncedSummary> {
    const key: [number, number] = [scope.orgId, scope.userId];
    const ops = await db.outbox.where('[orgId+userId]').equals(key).toArray();
    const drafts = await db.drafts.where('[orgId+userId]').equals(key).toArray();
    const attachments = await db.attachments
        .where('state')
        .anyOf('pending', 'failed')
        .filter((row) => row.orgId === scope.orgId && row.userId === scope.userId)
        .toArray();
    const dirty = drafts.filter((draft) => draft.localVersion > draft.syncedVersion);
    const paused = drafts.filter((draft) => draft.sync === 'conflict' || draft.sync === 'blocked');
    const submissions = new Set([
        ...ops.map((op) => op.submissionUuid),
        ...[...dirty, ...paused].map((draft) => draft.uuid),
        ...attachments.map((row) => row.submissionUuid),
    ]);
    return {
        submissions: submissions.size,
        ops: ops.length,
        dirtyDrafts: dirty.length,
        pendingAttachments: attachments.length,
        blocked: ops.filter((op) => op.state === 'blocked').length + paused.length,
    };
}
