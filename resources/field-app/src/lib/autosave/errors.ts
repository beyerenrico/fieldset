/**
 * Typed failures of a local draft write (ADR 0035), shown by the screens through the autosave
 * snapshot's `error` and its stable `code`.
 */
export type LocalWriteCode = 'storage_full' | 'session_ended' | 'draft_locked';

export class LocalWriteError extends Error {
    override readonly name: string = 'LocalWriteError';
    readonly code: LocalWriteCode;

    constructor(code: LocalWriteCode, message: string, cause?: unknown) {
        super(message, cause === undefined ? undefined : { cause });
        this.code = code;
    }
}

/** IndexedDB refused the write: the device storage (or the browser's quota) is full. */
export class StorageFullError extends LocalWriteError {
    override readonly name = 'StorageFullError';

    constructor(cause?: unknown) {
        super('storage_full', 'The device storage is full.', cause);
    }
}

/** The session ended (logout or wipe, maybe in another tab, or another user signed in). */
export class SessionEndedError extends LocalWriteError {
    override readonly name = 'SessionEndedError';

    constructor() {
        super('session_ended', 'The session ended; this draft is no longer saved on this device.');
    }
}

/** Another tab holds the draft open for editing; this tab shows it read-only. */
export class DraftLockedError extends LocalWriteError {
    override readonly name = 'DraftLockedError';

    constructor() {
        super('draft_locked', 'This draft is open for editing in another tab.');
    }
}

function nameOf(value: unknown): string | null {
    return typeof value === 'object' &&
        value !== null &&
        'name' in value &&
        typeof value.name === 'string'
        ? value.name
        : null;
}

/**
 * A quota error, also wrapped: Dexie names its own `QuotaExceededError` and keeps the DOMException
 * in `inner`; an aborted transaction may carry it as `cause` or `inner` of an AbortError.
 */
export function isQuotaExceeded(error: unknown): boolean {
    if (nameOf(error) === 'QuotaExceededError') {
        return true;
    }
    if (typeof error !== 'object' || error === null) {
        return false;
    }
    const nested = [
        'inner' in error ? error.inner : undefined,
        'cause' in error ? error.cause : undefined,
    ];
    return nested.some((value) => nameOf(value) === 'QuotaExceededError');
}
