/**
 * 422 answers to a submit (ADR 0035). A screen waiting for the answer (interactive submit) shows it
 * and the draft stays editable, as before. A background submit (queued offline, retried) blocks the
 * draft, so the lists show it: `submit_refused` with the pointer errors, or `attachments_missing`
 * with the missing uuids after the local copies were queued again once.
 */
import type { ApiError } from '../api/errors.ts';
import { validationDetails } from '../api/errors.ts';
import { db } from '../db/db.ts';
import { enqueueAttachmentUpload } from '../outbox/outbox.ts';
import { ATTACHMENTS_MISSING, SUBMIT_REFUSED } from './blocks.ts';
import { emitFailed, type StepResult } from './failures.ts';
import { isAwaited } from './events.ts';
import type { SubmitOp } from './own-submit.ts';
import { applySubmitRefused, markReuploaded } from './store.ts';

/** `details` of 422 attachments_missing: the attachment uuids the data references. */
function missingUuids(answer: ApiError): string[] {
    const details: unknown = answer.details;
    return Array.isArray(details)
        ? details.filter((value): value is string => typeof value === 'string')
        : [];
}

/** Queues the upload of every missing attachment that still has its bytes here; their count. */
async function requeueUploads(op: SubmitOp, missing: readonly string[]): Promise<number> {
    const local = await db.transaction('rw', db.attachments, async () => {
        const rows = await db.attachments.bulkGet([...missing]);
        const usable = rows.filter(
            (row) => row?.submissionUuid === op.submissionUuid && row.bytes !== null,
        );
        await db.attachments.bulkPut(
            usable.flatMap((row) =>
                row === undefined ? [] : [{ ...row, state: 'pending' as const, error: null }],
            ),
        );
        return usable.flatMap((row) => (row === undefined ? [] : [row.uuid]));
    });
    for (const uuid of local) {
        await enqueueAttachmentUpload(op, op.submissionUuid, uuid);
    }
    return local.length;
}

/** Answers a 422 on submit; the op is dropped unless its attachments are uploaded again first. */
export async function refuseSubmit(op: SubmitOp, answer: ApiError): Promise<StepResult> {
    const interactive = isAwaited(op.id);
    const missing = answer.code === ATTACHMENTS_MISSING ? missingUuids(answer) : [];
    const retry = !interactive && op.reuploaded !== true;
    if (missing.length > 0 && (interactive || retry)) {
        const queued = await requeueUploads(op, missing);
        if (queued > 0 && retry) {
            await markReuploaded(op); // submitted again after the uploads, once
            emitFailed(op, answer, true);
            return 'wait';
        }
    }
    const blockedBy = answer.code === ATTACHMENTS_MISSING ? ATTACHMENTS_MISSING : SUBMIT_REFUSED;
    await applySubmitRefused(op, {
        errors: validationDetails(answer),
        blockedBy: interactive ? null : blockedBy,
        missing: interactive ? [] : missing,
        error: answer,
    });
    emitFailed(op, answer, false);
    return 'blocked';
}
