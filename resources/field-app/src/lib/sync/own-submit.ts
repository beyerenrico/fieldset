/**
 * Lost submit answers (ADR 0035): the submit reached the server, its answer did not. A server copy
 * that this device's queued submit produced finishes that submit instead of opening a conflict or
 * blocking with `not_editable` (and offering a duplicate).
 */
import { EDITABLE_STATUSES, type Submission } from '../api/types.ts';
import { db } from '../db/db.ts';
import type { OutboxRow } from '../db/schema.ts';
import { findOp } from '../outbox/outbox.ts';
import { emitDone } from './failures.ts';
import { applySubmitted, type SubmitCover } from './store.ts';

export type SubmitOp = Extract<OutboxRow, { kind: 'submit' }>;

/** The server stores the device time with second precision. */
function sameInstant(stored: string | null, sent: string): boolean {
    if (stored === null) {
        return false;
    }
    const difference = Date.parse(stored) - Date.parse(sent);
    return Number.isFinite(difference) && Math.abs(difference) < 1000;
}

/**
 * The server copy is submitted (not a draft, not changes_requested, not void) by this submit: it
 * was sent (its request is persisted) and the server kept the device time the request carried.
 * Only the creator can submit, and the draft is this user's, so the submitter is the current user.
 */
export function isOwnSubmit(op: SubmitOp, server: Submission): boolean {
    if (
        op.request === null ||
        EDITABLE_STATUSES.includes(server.status) ||
        server.status === 'void' ||
        server.submitted_at === null
    ) {
        return false;
    }
    const sentAt = op.request.body.client_submitted_at;
    return typeof sentAt === 'string'
        ? sameInstant(server.client_submitted_at, sentAt)
        : server.client_submitted_at === null;
}

/** Applies the submitted copy and tells a waiting screen. */
export async function finishSubmit(
    op: SubmitOp,
    submission: Submission,
    cover: SubmitCover,
): Promise<void> {
    await applySubmitted(op, submission, cover);
    emitDone(op, submission);
}

/**
 * A 409 to a draft PUT whose server copy is our submitted submission (the submit's answer was lost,
 * then the user edited): the submit is finished and the later edits are dropped with their PUT.
 */
export async function finishLostSubmit(
    userId: number,
    uuid: string,
    server: Submission,
): Promise<boolean> {
    const op = await findOp(userId, uuid, 'submit');
    if (op === undefined || !isOwnSubmit(op, server)) {
        return false;
    }
    const row = await db.drafts.get(uuid);
    await finishSubmit(op, server, { version: row?.syncedVersion ?? 0, dropLaterEdits: true });
    return true;
}
