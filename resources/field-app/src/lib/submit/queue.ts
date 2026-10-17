/**
 * Interactive submit and "send to customer" (ADR 0035): the request always goes through the outbox
 * (persisted key and body); the screen runs the engine right away and shows the first answer, or
 * "queued" when there was none (offline, another tab syncs, a lost session).
 */
import { awaitOp, onOutboxEvent, type OutboxEvent } from '../sync/events.ts';
import { runSync } from '../sync/engine.ts';
import { SyncRunError } from '../sync/run-error.ts';

export type QueuedOutcome =
    | { readonly status: 'done'; readonly result: unknown }
    | { readonly status: 'failed'; readonly error: unknown; readonly retrying: boolean }
    | { readonly status: 'queued' };

export interface QueuedTarget {
    /** Outbox row id of the op (opId()). */
    readonly id: string;
    readonly submissionUuid: string;
}

/** The op's own answer, or a blocking answer to a step before it (conflict, retired version, …). */
function outcomeOf(target: QueuedTarget, event: OutboxEvent): QueuedOutcome | null {
    if (event.submissionUuid !== target.submissionUuid) {
        return null;
    }
    if (event.type === 'done') {
        return event.id === target.id ? { status: 'done', result: event.result } : null;
    }
    if (event.id === target.id || !event.retrying) {
        return { status: 'failed', error: event.error, retrying: event.retrying };
    }
    return null;
}

/**
 * Enqueues the op and runs the engine while the op counts as awaited (a refusal is answered here,
 * not by blocking the draft).
 *
 * @throws {SyncRunError} when the enqueue or the run failed unexpectedly (IndexedDB refused a
 * write) before the server answered: the op stays queued; screens leave their busy state in a
 * `finally`.
 */
export async function enqueueAndRun(
    target: QueuedTarget,
    enqueue: () => Promise<void>,
): Promise<QueuedOutcome> {
    const first: { outcome: QueuedOutcome | null } = { outcome: null };
    const stop = onOutboxEvent((event) => {
        first.outcome ??= outcomeOf(target, event);
    });
    const release = awaitOp(target.id);
    try {
        await enqueue();
        await runSync({ manual: true });
        return first.outcome ?? { status: 'queued' };
    } catch (error) {
        if (first.outcome !== null) {
            return first.outcome; // the server answered before the run failed
        }
        throw error instanceof SyncRunError ? error : new SyncRunError(error);
    } finally {
        release();
        stop();
    }
}
