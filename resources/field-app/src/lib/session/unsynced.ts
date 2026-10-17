/**
 * Unsynced work on this device (ADR 0035): what logout would lose, and rows of other users that a
 * new sign-in keeps hidden and unsent until someone deletes them (QUESTIONS #15).
 */
import { db } from '../db/db.ts';
import { unsyncedSummary, type UnsyncedSummary } from '../outbox/outbox.ts';

export interface UnsyncedTotals extends UnsyncedSummary {
    /** Queued ops + unsynced drafts + attachments not uploaded (the pending count shown to users). */
    readonly pending: number;
}

export const EMPTY_TOTALS: UnsyncedTotals = {
    ops: 0,
    dirtyDrafts: 0,
    pendingAttachments: 0,
    blocked: 0,
    pending: 0,
};

export function withPending(summary: UnsyncedSummary): UnsyncedTotals {
    return { ...summary, pending: summary.ops + summary.dirtyDrafts + summary.pendingAttachments };
}

function add(a: UnsyncedSummary, b: UnsyncedSummary): UnsyncedSummary {
    return {
        ops: a.ops + b.ops,
        dirtyDrafts: a.dirtyDrafts + b.dirtyDrafts,
        pendingAttachments: a.pendingAttachments + b.pendingAttachments,
        blocked: a.blocked + b.blocked,
    };
}

/** Unsynced items of one user over the given organizations (logout clears all of them). */
export async function unsyncedForUser(
    userId: number,
    orgIds: readonly number[],
): Promise<UnsyncedTotals> {
    let total: UnsyncedSummary = EMPTY_TOTALS;
    for (const orgId of new Set(orgIds)) {
        total = add(total, await unsyncedSummary({ orgId, userId }));
    }
    return withPending(total);
}

/** Drafts, outbox ops and attachments stored for users other than `userId`. */
export async function countForeignRows(userId: number): Promise<number> {
    const other = (row: { readonly userId: number }): boolean => row.userId !== userId;
    const [drafts, ops, attachments] = await Promise.all([
        db.drafts.filter(other).count(),
        db.outbox.filter(other).count(),
        db.attachments.filter(other).count(),
    ]);
    return drafts + ops + attachments;
}

/** Deletes every draft, outbox op and attachment that does not belong to `userId`. */
export async function deleteForeignRows(userId: number): Promise<void> {
    const other = (row: { readonly userId: number }): boolean => row.userId !== userId;
    await db.transaction('rw', db.drafts, db.outbox, db.attachments, async () => {
        await db.drafts.filter(other).delete();
        await db.outbox.filter(other).delete();
        await db.attachments.filter(other).delete();
    });
}
