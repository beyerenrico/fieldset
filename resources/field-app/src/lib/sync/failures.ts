/** Shared failure handling of the outbox steps (ADR 0035). */
import { fetchMe } from '../api/endpoints.ts';
import { adoptSession } from '../session/offline-start.ts';
import type { ApiError } from '../api/errors.ts';
import type { OutboxRow } from '../db/schema.ts';
import { classify } from './backoff.ts';
import { emitOutboxEvent } from './events.ts';
import { purgeOrganization, retryLater } from './store.ts';

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

/** 403 organization_forbidden: purge the organization's rows when the membership is gone. */
async function organizationForbidden(
    ctx: RunContext,
    op: OutboxRow,
    error: unknown,
): Promise<void> {
    try {
        const me = await fetchMe();
        await adoptSession(me);
        if (!me.organizations.some((organization) => organization.id === op.orgId)) {
            await purgeOrganization(op.orgId, op.userId);
            return;
        }
    } catch {
        // Unknown membership: keep everything and ask again later.
    }
    await retryLater(op, error, ctx.manual, null);
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
                await organizationForbidden(ctx, op, error);
                emitFailed(op, error, true);
                return 'wait';
            }
            return answer(failure.error);
    }
}
