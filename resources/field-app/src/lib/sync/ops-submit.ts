/** `submit` and `send` steps of the sync engine: persisted `{ idempotencyKey, body }` (ADR 0035). */
import {
    fetchSubmission,
    sendSubmissionDocument,
    submitSubmission,
    type SendInput,
    type SubmitBody,
} from '../api/endpoints.ts';
import { isApiError, isConflict, validationDetails, type ApiError } from '../api/errors.ts';
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
import { finishSubmit, isOwnSubmit, type SubmitOp } from './own-submit.ts';
import {
    blockOp,
    deleteSentOp,
    markBlocked,
    markConflict,
    persistRequest,
    updateDraft,
    type SubmitCover,
} from './store.ts';
import { refuseSubmit } from './submit-refusal.ts';

type SendOp = Extract<OutboxRow, { kind: 'send' }>;

/** A step either finished, or wants to send again right away with another request. */
type Next = { readonly again: PersistedRequest } | { readonly result: StepResult };

const WAIT: Next = { result: 'wait' };

/** Persists `request` in place of the op's current one; null when the op changed meanwhile. */
async function replaceRequest(
    op: SubmitOp | SendOp,
    request: PersistedRequest,
): Promise<PersistedRequest | null> {
    const previous = op.request?.idempotencyKey ?? null;
    return (await persistRequest(op.id, request, previous)) ? request : null;
}

/** A new key for the submit of `revision` (persisted before it is sent). */
function newSubmitRequest(op: SubmitOp, revision: number): Promise<PersistedRequest | null> {
    const body: SubmitBody = { revision, client_submitted_at: op.clientSubmittedAt };
    return replaceRequest(op, { idempotencyKey: newId(), body: { ...body } });
}

/** The stored request when it still matches the revision; otherwise a new key. */
async function submitRequest(op: SubmitOp, revision: number): Promise<PersistedRequest | null> {
    return op.request !== null && op.request.body.revision === revision
        ? op.request
        : newSubmitRequest(op, revision);
}

const again = (request: PersistedRequest | null): Next =>
    request === null ? WAIT : { again: request };

/**
 * The server refuses a device time more than 5 minutes ahead of its clock (422, pointer
 * /client_submitted_at). It is informational only: send the submit without it.
 */
function refusesDeviceTime(error: unknown): boolean {
    return validationDetails(error).some((detail) => detail.pointer === '/client_submitted_at');
}

function withoutDeviceTime(op: SubmitOp, request: PersistedRequest): Promise<Next> {
    const body = Object.fromEntries(
        Object.entries(request.body).filter(([key]) => key !== 'client_submitted_at'),
    );
    return replaceRequest(op, { idempotencyKey: newId(), body }).then(again);
}

function rotateKey(op: SubmitOp | SendOp, request: PersistedRequest): Promise<Next> {
    return replaceRequest(op, { idempotencyKey: newId(), body: request.body }).then(again);
}

const finished = async (op: SubmitOp, server: Submission, cover: SubmitCover): Promise<Next> => {
    await finishSubmit(op, server, cover);
    return { result: 'ok' };
};

/** 409 revision_conflict on submit: our own lost submit finishes; an identical copy is adopted. */
async function submitConflict(
    op: SubmitOp,
    draft: DraftRow,
    server: Submission,
    error: unknown,
): Promise<Next> {
    const cover: SubmitCover = { version: draft.syncedVersion };
    if (isOwnSubmit(op, server)) {
        return finished(op, server, cover);
    }
    if (!sameJson(server.data, draft.data)) {
        await markConflict(op.submissionUuid, server, error);
        emitFailed(op, error, false);
        return { result: 'blocked' };
    }
    if (!EDITABLE_STATUSES.includes(server.status)) {
        return finished(op, server, cover); // our submit went through, its answer was lost
    }
    await updateDraft(op.submissionUuid, (row) => ({
        ...row,
        server,
        serverRevision: server.revision,
    }));
    return again(await newSubmitRequest(op, server.revision));
}

/**
 * 409 not_editable on submit: the server copy is fetched; when our own submit produced it (lost
 * answer), the submit is finished. Otherwise (or when it cannot be fetched) the answer stands.
 */
async function submittedMeanwhile(op: SubmitOp, draft: DraftRow): Promise<boolean> {
    try {
        const server = await fetchSubmission(op.submissionUuid, { organizationId: op.orgId });
        if (!isOwnSubmit(op, server)) {
            return false;
        }
        await finishSubmit(op, server, { version: draft.syncedVersion });
        return true;
    } catch (error) {
        console.warn('The submission could not be fetched after not_editable', error);
        return false;
    }
}

async function refused(op: SubmitOp, answer: ApiError): Promise<StepResult> {
    if (answer.status === 422) {
        return refuseSubmit(op, answer);
    }
    await markBlocked(op.submissionUuid, answer.code, answer);
    emitFailed(op, answer, false);
    return 'blocked';
}

async function submitError(
    ctx: RunContext,
    op: SubmitOp & { readonly request: PersistedRequest },
    draft: DraftRow,
    error: unknown,
    first: boolean,
): Promise<Next> {
    const server = serverCopy(error);
    if (server !== null) {
        ctx.contacted = true;
        return submitConflict(op, draft, server, error);
    }
    if (first && isConflict(error, 'idempotency_key_reused')) {
        return rotateKey(op, op.request);
    }
    if (first && refusesDeviceTime(error)) {
        return withoutDeviceTime(op, op.request);
    }
    if (isConflict(error, 'not_editable') && (await submittedMeanwhile(op, draft))) {
        return { result: 'ok' };
    }
    return { result: await handleFailure(ctx, op, error, (answer) => refused(op, answer)) };
}

/**
 * Submits the draft's current server revision (the draft is clean: process.ts sends edits first);
 * at most one immediate resend (adopt, new key).
 */
export async function submitOne(
    ctx: RunContext,
    op: SubmitOp,
    draft: DraftRow | undefined,
): Promise<StepResult> {
    if (draft === undefined) {
        await deleteSentOp(op); // nothing left to submit (discarded meanwhile)
        return 'ok';
    }
    let request = await submitRequest(op, draft.serverRevision);
    for (let attempt = 0; attempt < 2 && request !== null; attempt++) {
        const sent = { ...op, request };
        try {
            const body = request.body as unknown as SubmitBody;
            const result = await submitSubmission(op.submissionUuid, body, request.idempotencyKey, {
                organizationId: op.orgId,
            });
            ctx.sent += 1;
            await finishSubmit(sent, result.submission, { version: draft.syncedVersion });
            return 'ok';
        } catch (error) {
            const next = await submitError(ctx, sent, draft, error, attempt === 0);
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
    let sent = op;
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const input = sent.request.body as unknown as SendInput;
            const send = await sendSubmissionDocument(
                op.submissionUuid,
                input,
                sent.request.idempotencyKey,
                { organizationId: op.orgId },
            );
            await deleteSentOp(sent);
            ctx.sent += 1;
            emitDone(sent, send);
            return 'ok';
        } catch (error) {
            if (attempt === 0 && isApiError(error, 409, 'idempotency_key_reused')) {
                const next = await rotateKey(sent, sent.request);
                if ('result' in next) {
                    return next.result;
                }
                sent = { ...sent, request: next.again };
                continue;
            }
            const current = sent;
            return handleFailure(ctx, current, error, async (answer) => {
                // send_not_allowed, not_submitted, validation_failed, 403, 404, 413
                await blockOp(current, answer);
                emitFailed(current, answer, false);
                return 'blocked';
            });
        }
    }
    return 'wait';
}
