import type { JsonObject } from '@form-schema-app/form-schema-js';
import type { Submission } from '../api/types.ts';

export type SaveState =
    'idle' | 'pending' | 'saving' | 'saved' | 'offline' | 'error' | 'conflict' | 'locked';

export interface SaveRequest {
    readonly uuid: string;
    readonly formVersionId: number;
    readonly data: JsonObject;
    /** 0 creates the draft. */
    readonly revision: number;
}

/** Where drafts go. M2: `PUT /submissions/{uuid}`; M6: a Dexie outbox that syncs later. */
export interface SaveTransport {
    save(request: SaveRequest): Promise<Submission>;
}

export interface AutosaveSnapshot {
    readonly state: SaveState;
    readonly revision: number;
    /** Last server copy returned by a save. */
    readonly submission: Submission | null;
    /** Server copy after a `revision_conflict`. */
    readonly conflict: Submission | null;
    readonly error: unknown;
    readonly savedAt: number | null;
}

export interface AutosaveOptions {
    readonly uuid: string;
    readonly formVersionId: number;
    /** Current server revision; 0 when the draft does not exist yet. */
    readonly revision: number;
    readonly data: JsonObject;
    readonly transport: SaveTransport;
    readonly debounceMs?: number;
    readonly retryMs?: number;
    readonly onAuthLost?: (error: unknown) => void;
}
