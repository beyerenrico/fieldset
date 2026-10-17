/** Shared failure handling of the outbox steps (ADR 0035). */
import type { ApiError } from '../api/errors.ts';
import type { OutboxRow } from '../db/schema.ts';
import { handleOrganizationForbidden, type OrganizationAccess } from '../session/org-access.ts';
import { classify } from './backoff.ts';
import { emitOutboxEvent } from './events.ts';
import { retryLater } from './store.ts';

/** Per-run state of the engine; `sent` counts ops that the server accepted. */
export interface RunContext {
    readonly manual: boolean;
    readonly now: number;
    readonly userId: number;
    sent: number;
}

/**
 * `ok`: done, continue with the next step. `wait`: retried later or not due. `blocked`: needs the
 * user. `offline`/`reauth`/`wiped`: stop the whole run.
 */
export type StepResult = 'ok' | 'wait' | 'blocked' | 'offline' | 'reauth' | 'wiped';

export const isFatal = (result: StepResult): boolean =>
    result === 'offline' || result === 'reauth' || result === 'wiped';

export function due(ctx: RunContext, op: OutboxRow): boolean {
    return op.state === 'pending' && (ctx.manual || op.nextAttemptAt <= ctx.now);
}

export function emitDone(op: OutboxRow, result: unknown): void {
    emitOutboxEvent({
        type: 'done',
        id: op.id,
        kind: op.kind,
        submissionUuid: op.submissionUuid,
        result,
    });
}

export function emitFailed(op: OutboxRow, error: unknown, retrying: boolean): void {
    emitOutboxEvent({
        type: 'failed',
        id: op.id,
        kind: op.kind,
        submissionUuid: op.submissionUuid,
        error,
        retrying,
    });
}

/** The membership check of org-access.ts; a lost session stops the run, anything else is unknown. */
async function checkAccess(orgId: number): Promise<OrganizationAccess | 'reauth' | 'unknown'> {
    try {
        return await handleOrganizationForbidden(orgId);
    } catch (failure) {
        const kind = classify(failure).kind;
        return kind === 'reauth' || kind === 'wiped' ? kind : 'unknown';
    }
}

/**
 * 403 organization_forbidden (shared with the query cache, session/org-access.ts): `/me` again; a
 * lost organization's rows are purged (this op with them), no organization left → device-status
 * (`wipe` clears the device). A member (e.g. a role change) or an unknown answer retries later.
 */
async function organizationForbidden(
    ctx: RunContext,
    op: OutboxRow,
    error: unknown,
): Promise<StepResult> {
    const access = await checkAccess(op.orgId);
    if (access === 'reauth' || access === 'wiped') {
        return access;
    }
    if (access === 'purged') {
        emitFailed(op, error, false);
        return 'wait'; // nothing of this submission is left on the device
    }
    await retryLater(op, error, ctx.manual, null);
    emitFailed(op, error, true);
    return 'wait';
}

/** Retryable failures back off; server answers go to `answer` (the step decides what blocks). */
export async function handleFailure(
    ctx: RunContext,
    op: OutboxRow,
    error: unknown,
    answer: (error: ApiError) => Promise<StepResult>,
): Promise<StepResult> {
    const failure = classify(error);
    switch (failure.kind) {
        case 'reauth':
        case 'wiped':
            return failure.kind; // the outbox stays untouched
        case 'offline':
            await retryLater(op, error, ctx.manual, null);
            emitFailed(op, error, true);
            return 'offline';
        case 'retry':
        case 'unknown':
            await retryLater(
                op,
                error,
                ctx.manual,
                failure.kind === 'retry' ? failure.retryAfterMs : null,
            );
            emitFailed(op, error, true);
            return 'wait';
        case 'answer':
            if (failure.error.status === 403 && failure.error.code === 'organization_forbidden') {
                return organizationForbidden(ctx, op, error);
            }
            return answer(failure.error);
    }
}
