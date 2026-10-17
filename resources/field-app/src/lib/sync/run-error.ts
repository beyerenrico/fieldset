/**
 * A sync run (or the enqueue before it) failed unexpectedly, e.g. IndexedDB refused a write. The
 * outbox keeps every op, the sync meta says `error` and the engine tries again on its next trigger.
 * `cause` holds the original error.
 */
export class SyncRunError extends Error {
    override readonly name = 'SyncRunError';

    constructor(cause: unknown) {
        super(cause instanceof Error ? cause.message : 'The sync run failed.', { cause });
    }
}
