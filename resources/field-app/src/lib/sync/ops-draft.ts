/** `draft.save` and `attachment.upload` steps of the sync engine (ADR 0035). */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { putSubmission, uploadAttachment } from '../api/endpoints.ts';
import type { Submission } from '../api/types.ts';
import { sameJson } from '../autosave/same-json.ts';
import { serverCopy } from '../autosave/server-copy.ts';
import { db } from '../db/db.ts';
import type { AttachmentRow, DraftRow, OutboxRow } from '../db/schema.ts';
import { opId, putDraftSaveOp } from '../outbox/outbox.ts';
import { toOutboxError } from './backoff.ts';
import {
    due,
    emitDone,
    emitFailed,
    handleFailure,
    type RunContext,
    type StepResult,
} from './failures.ts';
import { applySaved, blockOp, markBlocked, markConflict } from './store.ts';

/** The draft.save op of a draft, created when missing (its backoff lives on the op). */
async function saveOpOf(draft: DraftRow): Promise<OutboxRow | undefined> {
    await putDraftSaveOp(draft, draft.uuid);
    return db.outbox.get(opId('draft.save', draft.uuid));
}

interface Sent {
    readonly data: JsonObject;
    readonly version: number;
}

/** 409 revision_conflict: our own earlier write (lost response) is adopted, anything else pauses. */
async function revisionConflict(
    op: OutboxRow,
    server: Submission,
    sent: Sent,
    error: unknown,
): Promise<StepResult> {
    const current = await db.drafts.get(op.submissionUuid);
    if (sameJson(server.data, sent.data)) {
        await applySaved(op.submissionUuid, server, sent.version);
        return 'ok';
    }
    if (current !== undefined && sameJson(server.data, current.data)) {
        await applySaved(op.submissionUuid, server, current.localVersion);
        return 'ok';
    }
    await markConflict(op.submissionUuid, server);
    emitFailed(op, error, false);
    return 'blocked';
}

/** Creates (revision 0) or updates the server draft with the row's current data. */
export async function saveDraft(ctx: RunContext, draft: DraftRow): Promise<StepResult> {
    const op = await saveOpOf(draft);
    if (op === undefined || !due(ctx, op)) {
        return 'wait';
    }
    const sent: Sent = { data: draft.data, version: draft.localVersion };
    try {
        const submission = await putSubmission(
            draft.uuid,
            {
                formVersionId: draft.formVersionId,
                data: draft.data,
                revision: draft.serverRevision,
            },
            { organizationId: draft.orgId },
        );
        await applySaved(draft.uuid, submission, sent.version);
        ctx.sent += 1;
        emitDone(op, submission);
        return 'ok';
    } catch (error) {
        const server = serverCopy(error);
        if (server !== null) {
            return revisionConflict(op, server, sent, error);
        }
        return handleFailure(ctx, op, error, async (answer) => {
            // not_editable, version_retired, version_mismatch, 403, 404, 413: the user decides.
            await markBlocked(draft.uuid, answer.code);
            emitFailed(op, answer, false);
            return 'blocked';
        });
    }
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
            await blockOp(op, answer); // attachment_conflict, 403, 404, 413
            await markAttachment(row.uuid, { error: outboxError }, null);
            emitFailed(op, answer, false);
            return 'blocked';
        });
    }
}
