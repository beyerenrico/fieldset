/** `submit` and `send` steps of the sync engine: persisted `{ idempotencyKey, body }` (ADR 0035). */
import {
    sendSubmissionDocument,
    submitSubmission,
    type SendInput,
    type SubmitBody,
} from '../api/endpoints.ts';
import { isApiError, isConflict, validationDetails } from '../api/errors.ts';
import { EDITABLE_STATUSES, type Submission } from '../api/types.ts';
import { sameJson } from '../autosave/same-json.ts';
import { serverCopy } from '../autosave/server-copy.ts';
import type { DraftRow, OutboxRow, PersistedRequest } from '../db/schema.ts';
import { newId } from '../ids.ts';
import {
    emitDone,
    emitFailed,
    handleFailure,
    type RunContext,
    type StepResult,
} from './failures.ts';
import {
    applySubmitRefused,
    applySubmitted,
    blockOp,
    deleteOp,
    markBlocked,
    markConflict,
    persistRequest,
    updateDraft,
} from './store.ts';

type SubmitOp = Extract<OutboxRow, { kind: 'submit' }>;
type SendOp = Extract<OutboxRow, { kind: 'send' }>;

/** A step either finished, or wants to send again right away with another request. */
type Next = { readonly again: PersistedRequest } | { readonly result: StepResult };

/** The stored request when it still matches the revision; otherwise a new key (persisted first). */
async function submitRequest(op: SubmitOp, revision: number): Promise<PersistedRequest> {
    if (op.request !== null && op.request.body.revision === revision) {
        return op.request;
    }
    const body: SubmitBody = { revision, client_submitted_at: op.clientSubmittedAt };
    const request: PersistedRequest = { idempotencyKey: newId(), body: { ...body } };
    await persistRequest(op.id, request);
    return request;
}

/**
 * The server refuses a device time more than 5 minutes ahead of its clock (422, pointer
 * /client_submitted_at). It is informational only: send the submit without it.
 */
function refusesDeviceTime(error: unknown): boolean {
    return validationDetails(error).some((detail) => detail.pointer === '/client_submitted_at');
}

async function withoutDeviceTime(
    op: SubmitOp,
    request: PersistedRequest,
): Promise<PersistedRequest> {
    const body = Object.fromEntries(
        Object.entries(request.body).filter(([key]) => key !== 'client_submitted_at'),
    );
    const next: PersistedRequest = { idempotencyKey: newId(), body };
    await persistRequest(op.id, next);
    return next;
}

async function rotateKey(op: OutboxRow, request: PersistedRequest): Promise<PersistedRequest> {
    const next: PersistedRequest = { idempotencyKey: newId(), body: request.body };
    await persistRequest(op.id, next);
    return next;
}

async function finishSubmit(
    op: SubmitOp,
    submission: Submission,
): Promise<{ readonly result: StepResult }> {
    await applySubmitted(op.submissionUuid, op.id, submission);
    emitDone(op, submission);
    return { result: 'ok' };
}

/** 409 revision_conflict on submit: an identical server copy is adopted (and submitted again). */
async function submitConflict(
    op: SubmitOp,
    draft: DraftRow,
    server: Submission,
    error: unknown,
): Promise<Next> {
    if (!sameJson(server.data, draft.data)) {
        await markConflict(op.submissionUuid, server);
        emitFailed(op, error, false);
        return { result: 'blocked' };
    }
    if (!EDITABLE_STATUSES.includes(server.status)) {
        return finishSubmit(op, server); // our submit went through, its answer was lost
    }
    await updateDraft(op.submissionUuid, (row) => ({
        ...row,
        server,
        serverRevision: server.revision,
    }));
    return { again: await submitRequest({ ...op, request: null }, server.revision) };
}

async function submitError(
    ctx: RunContext,
    op: SubmitOp,
    draft: DraftRow,
    request: PersistedRequest,
    error: unknown,
    first: boolean,
): Promise<Next> {
    const server = serverCopy(error);
    if (server !== null) {
        return submitConflict(op, draft, server, error);
    }
    if (first && isConflict(error, 'idempotency_key_reused')) {
        return { again: await rotateKey(op, request) };
    }
    if (first && refusesDeviceTime(error)) {
        return { again: await withoutDeviceTime(op, request) };
    }
    const result = await handleFailure(ctx, op, error, async (answer) => {
        if (answer.status === 422) {
            // validation_failed / attachments_missing: editable again, with the pointer errors.
            await applySubmitRefused(op.submissionUuid, op.id, validationDetails(answer));
        } else {
            await markBlocked(op.submissionUuid, answer.code);
        }
        emitFailed(op, answer, false);
        return 'blocked';
    });
    return { result };
}

/** Submits the draft's current server revision; at most one immediate resend (adopt, new key). */
export async function submitOne(
    ctx: RunContext,
    op: SubmitOp,
    draft: DraftRow | undefined,
): Promise<StepResult> {
    if (draft === undefined) {
        await deleteOp(op.id); // nothing left to submit (discarded meanwhile)
        return 'ok';
    }
    let request = await submitRequest(op, draft.serverRevision);
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const body = request.body as unknown as SubmitBody;
            const result = await submitSubmission(op.submissionUuid, body, request.idempotencyKey, {
                organizationId: op.orgId,
            });
            ctx.sent += 1;
            return (await finishSubmit(op, result.submission)).result;
        } catch (error) {
            const next = await submitError(ctx, op, draft, request, error, attempt === 0);
            if ('result' in next) {
                return next.result;
            }
            request = next.again;
        }
    }
    return 'wait';
}

/** Sends the stored request; a reused key (should not happen) is rotated once. */
export async function sendOne(ctx: RunContext, op: SendOp): Promise<StepResult> {
    let request = op.request;
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const input = request.body as unknown as SendInput;
            const send = await sendSubmissionDocument(
                op.submissionUuid,
                input,
                request.idempotencyKey,
                { organizationId: op.orgId },
            );
            await deleteOp(op.id);
            ctx.sent += 1;
            emitDone(op, send);
            return 'ok';
        } catch (error) {
            if (attempt === 0 && isApiError(error, 409, 'idempotency_key_reused')) {
                request = await rotateKey(op, request);
                continue;
            }
            return handleFailure(ctx, op, error, async (answer) => {
                // send_not_allowed, not_submitted, validation_failed, 403, 404, 413
                await blockOp(op, answer);
                emitFailed(op, answer, false);
                return 'blocked';
            });
        }
    }
    return 'wait';
}
