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
import {
    blockOp,
    dropOrphanSaves,
    dropOrphanUploads,
    isPaused,
    markBlocked,
    markSyncing,
} from './store.ts';

type UploadOp = Extract<OutboxRow, { kind: 'attachment.upload' }>;

const isUpload = (op: OutboxRow): op is UploadOp => op.kind === 'attachment.upload';

const isDirty = (draft: DraftRow): boolean => draft.localVersion > draft.syncedVersion;

/** Uploads every due attachment; true when none is left (blocked or waiting ones count as left). */
async function uploadAll(ctx: RunContext, ops: readonly OutboxRow[]): Promise<StepResult> {
    let left = false;
    for (const op of ops.filter(isUpload)) {
        if (!due(ctx, op)) {
            left = true;
            continue;
        }
        const result = await uploadOne(ctx, op);
        if (isFatal(result) || result === 'blocked') {
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

/**
 * The submit of a clean draft. `dirty`: edits were written since the draft steps ran; they go out
 * first, so the submit never marks unsent edits as synced.
 */
async function submitStep(ctx: RunContext, uuid: string): Promise<StepResult | 'dirty'> {
    const op = (await opsOf(uuid)).find((candidate) => candidate.kind === 'submit');
    if (op?.kind !== 'submit') {
        return 'ok';
    }
    if (!due(ctx, op)) {
        return 'wait';
    }
    const draft = await db.drafts.get(uuid);
    if (draft !== undefined && isDirty(draft)) {
        return 'dirty';
    }
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
    if (isFatal(uploads) || uploads === 'blocked') {
        return uploads;
    }
    const current = await db.drafts.get(uuid);
    if (current !== undefined && !isPaused(current.sync) && isDirty(current)) {
        const updated = await saveDraft(ctx, current);
        if (updated !== 'ok') {
            return updated;
        }
    }
    return uploads;
}

/** Draft steps, then the submit; edits written meanwhile get one more pass before the submit. */
async function draftsThenSubmit(ctx: RunContext, uuid: string): Promise<StepResult> {
    for (let pass = 0; pass < 2; pass++) {
        const drafts = await draftSteps(ctx, uuid);
        if (drafts !== 'ok') {
            return drafts;
        }
        const submitted = await submitStep(ctx, uuid);
        if (submitted !== 'dirty') {
            return submitted;
        }
    }
    return 'wait'; // still typing: the next enqueue runs the engine again
}

/**
 * A draft that was never submitted, pinned to a version the catalog marks retired (manifest
 * `retired_version_ids`), can no longer be submitted: it is blocked before any request, as the 409
 * `version_retired` would block it, so the screens offer "copy to the current version" right away.
 * A changes_requested submission is not affected: the server accepts its resubmit.
 */
async function blockRetiredVersion(draft: DraftRow, ops: readonly OutboxRow[]): Promise<boolean> {
    const neverSubmitted = draft.server === null || draft.server.status === 'draft';
    if (!neverSubmitted || (await db.formVersions.get(draft.formVersionId))?.retired !== true) {
        return false;
    }
    const error = new ApiError(409, 'version_retired', 'This form version was retired.');
    await markBlocked(draft.uuid, error.code, error);
    const op = ops.find((candidate) => candidate.kind === 'submit') ?? ops[0];
    if (op !== undefined) {
        emitFailed(op, error, false); // an interactive submit shows this answer
    }
    return true;
}

/** Orphans are dropped: draft.save ops without a draft, uploads without an attachment row. */
async function dropOrphans(uuid: string, draft: DraftRow | undefined): Promise<OutboxRow[]> {
    if (draft === undefined) {
        await dropOrphanSaves(uuid);
    }
    await dropOrphanUploads(uuid);
    return opsOf(uuid);
}

export async function processSubmission(ctx: RunContext, uuid: string): Promise<StepResult> {
    const draft = await db.drafts.get(uuid);
    if (draft !== undefined && isPaused(draft.sync)) {
        return 'blocked';
    }
    if (draft !== undefined && draft.userId !== ctx.userId) {
        return 'wait'; // never sent under another user's session
    }
    const ops = await dropOrphans(uuid, draft);
    if (draft !== undefined && (await blockRetiredVersion(draft, ops))) {
        return 'blocked';
    }
    if (draft === undefined && ops.some(isUpload)) {
        return 'wait'; // uploads need the draft: its row is written right after the file
    }
    if (!ops.some((op) => due(ctx, op))) {
        // Everything backs off or waits for the user: no request, no row write.
        return ops.some((op) => op.state === 'blocked') ? 'blocked' : 'wait';
    }
    await markSyncing(uuid);
    const submitted = await draftsThenSubmit(ctx, uuid);
    return submitted === 'ok' ? sendStep(ctx, uuid) : submitted;
}
