/**
 * Why a draft waits for the user (ADR 0035). `blockedBy` holds the server's error code, or one of
 * the codes below for answers to background work that the screens explain themselves.
 */
import type { DraftRow, OutboxError } from '../db/schema.ts';

/** A background submit was refused with 422 `validation_failed` (pointer errors in submitErrors). */
export const SUBMIT_REFUSED = 'submit_refused';
/** A background submit was refused with 422 `attachments_missing` (uuids in missingAttachments). */
export const ATTACHMENTS_MISSING = 'attachments_missing';
/** An upload was refused (413, 403, 404, 409 attachment_conflict / not_editable); see the row's error. */
export const ATTACHMENT_BLOCKED = 'attachment_blocked';

/** Blocks a new submit lifts: the user fixed the data or the attachments and submits again. */
export const LIFTED_BY_SUBMIT: ReadonlySet<string> = new Set([
    SUBMIT_REFUSED,
    ATTACHMENTS_MISSING,
    ATTACHMENT_BLOCKED,
]);

/** The error a queued submit or send of a paused draft carries; null while the draft may sync. */
export function pausedError(row: DraftRow): OutboxError | null {
    if (row.sync === 'conflict') {
        return {
            status: 409,
            code: 'revision_conflict',
            message: 'The draft was changed on the server.',
        };
    }
    if (row.sync === 'blocked') {
        const code = row.blockedBy ?? 'blocked';
        return { status: null, code, message: `The submission is blocked (${code}).` };
    }
    return null;
}
