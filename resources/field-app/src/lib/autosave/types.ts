import type { JsonObject } from '@form-schema-app/form-schema-js';

/**
 * Local save states (ADR 0035): `saved` means "saved on this device". The sync state of the draft
 * (queued, synced, conflict, blocked) lives on its DraftRow; `offline`, `conflict` and `locked`
 * are only shown by the screens, derived from that row.
 */
export type SaveState =
    'idle' | 'pending' | 'saving' | 'saved' | 'offline' | 'error' | 'conflict' | 'locked';

export interface SaveRequest {
    readonly uuid: string;
    readonly formVersionId: number;
    readonly data: JsonObject;
    /**
     * Only make sure the draft exists on this device (no edit yet): an existing row is left as it
     * is. Attachments and "Review" need the row before the first edit.
     */
    readonly ensureOnly?: boolean;
}

export interface SaveReceipt {
    /** Local write counter of the draft after this save (DraftRow.localVersion). */
    readonly localVersion: number;
}

/** Where drafts go. M6: the DraftRow plus a coalesced `draft.save` op (local-transport.ts). */
export interface SaveTransport {
    save(request: SaveRequest): Promise<SaveReceipt>;
}

export interface AutosaveSnapshot {
    readonly state: SaveState;
    /** 0 until the draft was written on this device. */
    readonly localVersion: number;
    readonly error: unknown;
    readonly savedAt: number | null;
}

export interface AutosaveOptions {
    readonly uuid: string;
    readonly formVersionId: number;
    readonly data: JsonObject;
    readonly transport: SaveTransport;
    /** Local version of an existing row (0: nothing written on this device yet). */
    readonly localVersion?: number;
    /** Quiet time after the last edit before the write (default 300 ms). */
    readonly debounceMs?: number;
    /** Longest an edit waits while edits keep coming (default 2 s). */
    readonly maxWaitMs?: number;
    /** First retry delay after a failed local write (doubles up to 30 s). */
    readonly retryMs?: number;
}

export interface FlushOptions {
    /** Writes the row even without an edit when the draft is not on this device yet. */
    readonly ensureCreated?: boolean;
}
