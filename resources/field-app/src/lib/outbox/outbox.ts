/**
 * The outbox (ADR 0035, agent A). Every enqueue coalesces by row id and calls requestSync('enqueue').
 * The enqueue functions are stubs until agent A implements them; unsyncedSummary is final.
 */
import type { SendInput } from '../api/endpoints.ts';
import { db } from '../db/db.ts';
import type { Scope } from '../db/schema.ts';

export interface UnsyncedSummary {
    readonly ops: number;
    readonly dirtyDrafts: number;
    readonly pendingAttachments: number;
    readonly blocked: number;
}

function notImplemented(name: string): Promise<never> {
    return Promise.reject(new Error(`outbox.${name} is not implemented yet (M6-T03)`));
}

export const enqueueDraftSave: (scope: Scope, submissionUuid: string) => Promise<void> = () =>
    notImplemented('enqueueDraftSave');

export const enqueueAttachmentUpload: (
    scope: Scope,
    submissionUuid: string,
    attachmentUuid: string,
) => Promise<void> = () => notImplemented('enqueueAttachmentUpload');

export const enqueueSubmit: (
    scope: Scope,
    submissionUuid: string,
    clientSubmittedAt: string,
) => Promise<void> = () => notImplemented('enqueueSubmit');

export const enqueueSend: (
    scope: Scope,
    submissionUuid: string,
    input: SendInput,
) => Promise<void> = () => notImplemented('enqueueSend');

/** What would be lost on logout: queued ops, unsynced drafts, attachments not uploaded, blocked items. */
export async function unsyncedSummary(scope: Scope): Promise<UnsyncedSummary> {
    const key: [number, number] = [scope.orgId, scope.userId];
    const ops = await db.outbox.where('[orgId+userId]').equals(key).toArray();
    const drafts = await db.drafts.where('[orgId+userId]').equals(key).toArray();
    const attachments = await db.attachments
        .where('state')
        .anyOf('pending', 'failed')
        .filter((row) => row.orgId === scope.orgId && row.userId === scope.userId)
        .count();
    return {
        ops: ops.length,
        dirtyDrafts: drafts.filter((draft) => draft.localVersion > draft.syncedVersion).length,
        pendingAttachments: attachments,
        blocked:
            ops.filter((op) => op.state === 'blocked').length +
            drafts.filter((draft) => draft.sync === 'conflict' || draft.sync === 'blocked').length,
    };
}
