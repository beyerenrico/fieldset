/**
 * The outbox (ADR 0035). Every enqueue coalesces by row id and calls requestSync('enqueue'); while
 * offline it also registers the Background Sync tag (a Chromium-only wake-up, see sync/wake.ts).
 */
import type { SendInput } from '../api/endpoints.ts';
import { db, nextSeq } from '../db/db.ts';
import type { OutboxKind, OutboxRow, Scope } from '../db/schema.ts';
import { newId } from '../ids.ts';
import { sameJson } from '../autosave/same-json.ts';
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

/** `${kind}:${submissionUuid}` (+ `:${attachmentUuid}`): the same op enqueued again coalesces. */
export function opId(kind: OutboxKind, submissionUuid: string, attachmentUuid?: string): string {
    return attachmentUuid === undefined
        ? `${kind}:${submissionUuid}`
        : `${kind}:${submissionUuid}:${attachmentUuid}`;
}

type NewOp<K extends OutboxKind> = Omit<
    Extract<OutboxRow, { kind: K }>,
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

/** Adds the op unless a row with its id exists (coalescing keeps order and backoff). */
async function addOnce<K extends OutboxKind>(scope: Scope, op: NewOp<K>): Promise<void> {
    await db.transaction('rw', db.outbox, db.meta, async () => {
        if ((await db.outbox.get(op.id)) === undefined) {
            await db.outbox.put({ ...(await baseFields(scope)), ...op } as OutboxRow);
        }
    });
}

/** Called after every enqueue: wake the engines (all tabs) and, offline, the service worker. */
export function afterEnqueue(): void {
    requestSync('enqueue');
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
        void registerBackgroundSync();
    }
}

/**
 * Queues a draft PUT; the body is read from the DraftRow when the op is sent. Must run inside a
 * transaction over drafts + outbox + meta when the row is written in the same step (autosave).
 */
export async function putDraftSaveOp(scope: Scope, submissionUuid: string): Promise<void> {
    await addOnce<'draft.save'>(scope, {
        id: opId('draft.save', submissionUuid),
        kind: 'draft.save',
        submissionUuid,
    });
}

export async function enqueueDraftSave(scope: Scope, submissionUuid: string): Promise<void> {
    await putDraftSaveOp(scope, submissionUuid);
    afterEnqueue();
}

export async function enqueueAttachmentUpload(
    scope: Scope,
    submissionUuid: string,
    attachmentUuid: string,
): Promise<void> {
    await addOnce<'attachment.upload'>(scope, {
        id: opId('attachment.upload', submissionUuid, attachmentUuid),
        kind: 'attachment.upload',
        submissionUuid,
        attachmentUuid,
    });
    afterEnqueue();
}

/**
 * Queues the submit. Submitting again (after a blocking answer was fixed) puts the op back to
 * pending; its persisted request is kept and only replaced when the revision changed (engine).
 */
export async function enqueueSubmit(
    scope: Scope,
    submissionUuid: string,
    clientSubmittedAt: string,
): Promise<void> {
    const id = opId('submit', submissionUuid);
    await db.transaction('rw', db.outbox, db.drafts, db.meta, async () => {
        const existing = await db.outbox.get(id);
        if (existing?.kind === 'submit') {
            await db.outbox.put({ ...existing, state: 'pending', nextAttemptAt: 0 });
        } else {
            await db.outbox.put({
                ...(await baseFields(scope)),
                id,
                kind: 'submit',
                submissionUuid,
                clientSubmittedAt,
                request: null,
            });
        }
        const draft = await db.drafts.get(submissionUuid);
        if (draft !== undefined) {
            await db.drafts.put({ ...draft, submitErrors: [], sync: syncAfterSubmit(draft.sync) });
        }
    });
    afterEnqueue();
}

function syncAfterSubmit(sync: string): 'queued' | 'conflict' | 'blocked' {
    return sync === 'conflict' || sync === 'blocked' ? sync : 'queued';
}

/**
 * Queues "send to customer" with a persisted `{ idempotencyKey, body }`: the same recipient again
 * keeps the key (a retry), another recipient replaces the request with a new key.
 */
export async function enqueueSend(
    scope: Scope,
    submissionUuid: string,
    input: SendInput,
): Promise<void> {
    const id = opId('send', submissionUuid);
    const body = { ...input };
    await db.transaction('rw', db.outbox, db.meta, async () => {
        const existing = await db.outbox.get(id);
        if (existing?.kind === 'send' && sameJson(existing.request.body, body)) {
            await db.outbox.put({ ...existing, state: 'pending', nextAttemptAt: 0 });
            return;
        }
        await db.outbox.put({
            ...(await baseFields(scope)),
            id,
            kind: 'send',
            submissionUuid,
            input,
            request: { idempotencyKey: newId(), body },
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
