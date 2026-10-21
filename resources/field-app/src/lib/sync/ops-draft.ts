/** `draft.save` and `attachment.upload` steps of the sync engine (ADR 0035). */
import type { JsonObject } from '@fieldset/fieldset-js';
import { putSubmission, uploadAttachment } from '../api/endpoints.ts';
import type { Submission } from '../api/types.ts';
import { sameJson } from '../autosave/same-json.ts';
import { serverCopy } from '../autosave/server-copy.ts';
import { db } from '../db/db.ts';
import type { AttachmentRow, DraftRow, OutboxRow, SentSave } from '../db/schema.ts';
import { findOp, putDraftSaveOp } from '../outbox/outbox.ts';
import { toOutboxError } from './backoff.ts';
import { ATTACHMENT_BLOCKED } from './blocks.ts';
import {
    due,
    emitDone,
    emitFailed,
    handleFailure,
    type RunContext,
    type StepResult,
} from './failures.ts';
import { jsonHash } from './hash.ts';
import { finishLostSubmit } from './own-submit.ts';
import { applySaved, blockOp, markBlocked, markConflict, recordSentSave } from './store.ts';

/** The draft.save op of a draft, created when missing (its backoff lives on the op). */
async function saveOpOf(draft: DraftRow): Promise<OutboxRow | undefined> {
    await putDraftSaveOp(draft, draft.uuid);
    return findOp(draft.userId, draft.uuid, 'draft.save');
}

interface Sent extends SentSave {
    readonly data: JsonObject;
}

/** `again`: an earlier PUT's lost answer was adopted; the current data still has to go out. */
type SaveResult = StepResult | 'again';

/** An earlier PUT on the same base revision whose answer was lost produced this server copy. */
function lostSave(row: DraftRow, server: Submission): SentSave | undefined {
    const hash = jsonHash(server.data);
    return row.sentSaves?.find(
        (entry) => entry.revision + 1 === server.revision && entry.hash === hash,
    );
}

/**
 * 409 revision_conflict: a submit whose answer was lost is finished, our own earlier write (lost
 * answer) is adopted, the same data on the server is adopted, anything else pauses the draft.
 */
async function revisionConflict(
    op: OutboxRow,
    server: Submission,
    sent: Sent,
    error: unknown,
): Promise<SaveResult> {
    const uuid = op.submissionUuid;
    if (await finishLostSubmit(op.userId, uuid, server)) {
        return 'ok';
    }
    if (sameJson(server.data, sent.data)) {
        await applySaved(uuid, server, sent.version);
        return 'ok';
    }
    const current = await db.drafts.get(uuid);
    const lost = current === undefined ? undefined : lostSave(current, server);
    if (lost !== undefined) {
        await applySaved(uuid, server, lost.version);
        return 'again';
    }
    if (current !== undefined && sameJson(server.data, current.data)) {
        await applySaved(uuid, server, current.localVersion);
        return 'ok';
    }
    await markConflict(uuid, server, error);
    emitFailed(op, error, false);
    return 'blocked';
}

/** One PUT of the row's current data; the request is remembered first (lost answers). */
async function putOnce(ctx: RunContext, draft: DraftRow): Promise<SaveResult> {
    const op = await saveOpOf(draft);
    if (op === undefined || !due(ctx, op)) {
        return 'wait';
    }
    const sent: Sent = {
        revision: draft.serverRevision,
        version: draft.localVersion,
        hash: jsonHash(draft.data),
        data: draft.data,
    };
    await recordSentSave(draft.uuid, {
        revision: sent.revision,
        version: sent.version,
        hash: sent.hash,
    });
    try {
        const submission = await putSubmission(
            draft.uuid,
            { formVersionId: draft.formVersionId, data: draft.data, revision: sent.revision },
            { organizationId: draft.orgId },
        );
        await applySaved(draft.uuid, submission, sent.version);
        ctx.sent += 1;
        emitDone(op, submission);
        return 'ok';
    } catch (error) {
        const server = serverCopy(error);
        if (server !== null) {
            ctx.contacted = true;
            return revisionConflict(op, server, sent, error);
        }
        return handleFailure(ctx, op, error, async (answer) => {
            // not_editable, version_retired, version_mismatch, 403, 404, 413: the user decides.
            await markBlocked(draft.uuid, answer.code, answer);
            emitFailed(op, answer, false);
            return 'blocked';
        });
    }
}

/**
 * Creates (revision 0) or updates the server draft with the row's current data. After an adopted
 * lost answer the then current data is sent once more on top of the adopted revision.
 */
export async function saveDraft(ctx: RunContext, draft: DraftRow): Promise<StepResult> {
    let row: DraftRow | undefined = draft;
    for (let attempt = 0; attempt < 2; attempt++) {
        const result = await putOnce(ctx, row);
        if (result !== 'again') {
            return result;
        }
        row = await db.drafts.get(draft.uuid);
        if (row === undefined || row.localVersion <= row.syncedVersion) {
            return 'ok';
        }
    }
    return 'wait';
}

async function markAttachment(
    attachmentUuid: string,
    change: Partial<Pick<AttachmentRow, 'state' | 'record' | 'error'>>,
    deleteOpId: string | null,
): Promise<void> {
    await db.transaction('rw', db.attachments, db.outbox, async () => {
        const row = await db.attachments.get(attachmentUuid);
        if (row !== undefined) {
            await db.attachments.put({ ...row, ...change });
        }
        if (deleteOpId !== null) {
            await db.outbox.delete(deleteOpId);
        }
    });
}

/** Uploads one stored attachment through the existing endpoint (idempotent by attachment uuid). */
export async function uploadOne(
    ctx: RunContext,
    op: Extract<OutboxRow, { kind: 'attachment.upload' }>,
): Promise<StepResult> {
    const row = await db.attachments.get(op.attachmentUuid);
    if (row === undefined || row.state === 'uploaded' || row.bytes === null) {
        await db.outbox.delete(op.id);
        return 'ok';
    }
    try {
        const record = await uploadAttachment(
            op.submissionUuid,
            {
                file: new Blob([row.bytes], { type: row.mime }),
                fileName: row.fileName,
                attachmentUuid: row.uuid,
                kind: row.kind,
                elementKey: row.elementKey,
            },
            { organizationId: op.orgId },
        );
        await markAttachment(row.uuid, { state: 'uploaded', record, error: null }, op.id);
        ctx.sent += 1;
        emitDone(op, record);
        return 'ok';
    } catch (error) {
        return handleFailure(ctx, op, error, async (answer) => {
            const outboxError = toOutboxError(answer);
            if (answer.status === 422) {
                // image_too_large, file_type_not_allowed, …: the user replaces the attachment.
                await markAttachment(row.uuid, { state: 'failed', error: outboxError }, op.id);
                emitFailed(op, answer, false);
                return 'ok';
            }
            // attachment_conflict, not_editable, 403, 404, 413: the row keeps the answer, the draft
            // waits for the user (replace or remove the file, then "Try again" or submit again).
            await blockOp(op, answer);
            await markAttachment(row.uuid, { error: outboxError }, null);
            await markBlocked(op.submissionUuid, ATTACHMENT_BLOCKED, answer);
            emitFailed(op, answer, false);
            return 'blocked';
        });
    }
}
