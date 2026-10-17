/**
 * meta 'sync' (ADR 0035), shared by every tab: the state the header and settings show, the last run
 * and success, and the error an op retries.
 */
import { getMeta, setMeta } from '../db/db.ts';
import type { OutboxRow, SyncMeta, SyncState } from '../db/schema.ts';
import { toOutboxError } from './backoff.ts';
import type { RunContext, StepResult } from './failures.ts';

export async function writeSyncMeta(patch: Partial<SyncMeta>): Promise<void> {
    const previous = (await getMeta('sync')) ?? {
        state: 'idle',
        lastRunAt: null,
        lastSuccessAt: null,
        lastError: null,
    };
    await setMeta('sync', { ...previous, ...patch });
}

export async function readSyncState(): Promise<SyncState> {
    return (await getMeta('sync'))?.state ?? 'idle';
}

/** The op retries a server error (5xx, 429, …); a network error means offline, not an error. */
export const isRetrying = (op: OutboxRow): boolean =>
    op.state === 'pending' && op.lastError !== null && op.lastError.code !== 'network';

/**
 * `offline` while the server could not be reached (no answer this run, ops waiting after a network
 * error), `idle` after any contact unless an op retries a server error (`error`).
 */
export function stateAfter(
    stop: StepResult | null,
    ctx: RunContext,
    left: readonly OutboxRow[],
    before: SyncState,
): SyncState {
    if (stop === 'offline' || stop === 'reauth') {
        return stop;
    }
    const contacted = ctx.contacted || ctx.sent > 0;
    if (!contacted && before === 'offline' && left.some((op) => op.lastError?.code === 'network')) {
        return 'offline';
    }
    return left.some(isRetrying) ? 'error' : 'idle';
}

/** A run failed unexpectedly: `error` with its message. Never throws (only logged). */
export async function writeRunFailure(error: unknown): Promise<void> {
    try {
        await writeSyncMeta({ state: 'error', lastError: toOutboxError(error) });
    } catch (metaError) {
        console.warn('Writing the sync state failed', metaError);
    }
}
