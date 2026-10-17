/**
 * One submission's outbox in the order of ADR 0035: create the draft → upload every attachment →
 * update the draft (only when dirty) → submit → send. A step that is not done stops the rest.
 */
import { ApiError } from '../api/errors.ts';
import { db } from '../db/db.ts';
import type { DraftRow, OutboxRow } from '../db/schema.ts';
import { opsOf } from '../outbox/outbox.ts';
import { due, emitFailed, isFatal, type RunContext, type StepResult } from './failures.ts';
import { saveDraft, uploadOne } from './ops-draft.ts';
import { sendOne, submitOne } from './ops-submit.ts';
import { blockOp, isPaused, markSyncing } from './store.ts';

type UploadOp = Extract<OutboxRow, { kind: 'attachment.upload' }>;

const isUpload = (op: OutboxRow): op is UploadOp => op.kind === 'attachment.upload';

/** Uploads every due attachment; true when none is left (blocked or waiting ones count as left). */
async function uploadAll(ctx: RunContext, ops: readonly OutboxRow[]): Promise<StepResult> {
    let left = false;
    for (const op of ops.filter(isUpload)) {
        if (!due(ctx, op)) {
            left = true;
            continue;
        }
        const result = await uploadOne(ctx, op);
        if (isFatal(result)) {
            return result;
        }
        left ||= result !== 'ok';
    }
    return left ? 'wait' : 'ok';
}

/** A failed (422) attachment that the data still references: the submit would be refused. */
async function referencesFailedAttachment(draft: DraftRow): Promise<boolean> {
    const failed = await db.attachments
        .where('submissionUuid')
        .equals(draft.uuid)
        .filter((row) => row.state === 'failed')
        .toArray();
    const data = JSON.stringify(draft.data);
    return failed.some((row) => data.includes(row.uuid));
}

async function submitStep(ctx: RunContext, uuid: string): Promise<StepResult> {
    const op = (await opsOf(uuid)).find((candidate) => candidate.kind === 'submit');
    if (op?.kind !== 'submit') {
        return 'ok';
    }
    if (!due(ctx, op)) {
        return 'wait';
    }
    const draft = await db.drafts.get(uuid);
    if (draft !== undefined && (await referencesFailedAttachment(draft))) {
        const error = new ApiError(422, 'attachment_failed', 'An attachment must be replaced.');
        await blockOp(op, error);
        emitFailed(op, error, false);
        return 'blocked';
    }
    return submitOne(ctx, op, draft);
}

async function sendStep(ctx: RunContext, uuid: string): Promise<StepResult> {
    const ops = await opsOf(uuid);
    const op = ops.find((candidate) => candidate.kind === 'send');
    if (op?.kind !== 'send') {
        return 'ok';
    }
    const draft = await db.drafts.get(uuid);
    const submitted =
        draft === undefined || (draft.server !== null && draft.server.status !== 'draft');
    if (!submitted || ops.some((candidate) => candidate.kind === 'submit') || !due(ctx, op)) {
        return 'wait'; // goes out after the submit (number and PDF exist then)
    }
    return sendOne(ctx, op);
}

/** Draft steps: create first (uploads need an editable draft), then uploads, then the update. */
async function draftSteps(ctx: RunContext, uuid: string): Promise<StepResult> {
    const draft = await db.drafts.get(uuid);
    if (draft !== undefined && draft.serverRevision === 0) {
        const created = await saveDraft(ctx, draft);
        if (created !== 'ok') {
            return created;
        }
    }
    const uploads = await uploadAll(ctx, await opsOf(uuid));
    if (isFatal(uploads)) {
        return uploads;
    }
    const current = await db.drafts.get(uuid);
    if (
        current !== undefined &&
        !isPaused(current.sync) &&
        current.localVersion > current.syncedVersion
    ) {
        const updated = await saveDraft(ctx, current);
        if (updated !== 'ok') {
            return updated;
        }
    }
    return uploads;
}

export async function processSubmission(ctx: RunContext, uuid: string): Promise<StepResult> {
    const draft = await db.drafts.get(uuid);
    if (draft !== undefined && isPaused(draft.sync)) {
        return 'blocked';
    }
    if (draft !== undefined && draft.userId !== ctx.userId) {
        return 'wait'; // never sent under another user's session
    }
    const ops = await opsOf(uuid);
    if (draft === undefined && ops.some(isUpload)) {
        return 'wait'; // uploads need the draft: its row is written right after the file
    }
    if (!ops.some((op) => due(ctx, op))) {
        // Everything backs off or waits for the user: no request, no row write.
        return ops.some((op) => op.state === 'blocked') ? 'blocked' : 'wait';
    }
    await markSyncing(uuid);
    const drafts = await draftSteps(ctx, uuid);
    if (drafts !== 'ok') {
        return drafts;
    }
    const submitted = await submitStep(ctx, uuid);
    if (submitted !== 'ok') {
        return submitted;
    }
    return sendStep(ctx, uuid);
}
