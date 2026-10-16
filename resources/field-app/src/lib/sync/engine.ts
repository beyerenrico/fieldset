/** The sync engine (ADR 0035, agent A). Stub with the final signatures until M6-T03 lands. */
import type { SyncState } from '../db/schema.ts';

export interface SyncRunResult {
    readonly ran: boolean;
    readonly sent: number;
    readonly blocked: number;
    readonly remaining: number;
    readonly state: SyncState;
}

/** Wires the triggers (onSyncRequested, interval) and returns a stop function. */
export function startSyncEngine(): () => void {
    return () => undefined;
}

export const runSync: (options?: { readonly manual?: boolean }) => Promise<SyncRunResult> = () =>
    Promise.resolve({ ran: false, sent: 0, blocked: 0, remaining: 0, state: 'idle' });

export const resolveConflict: (
    submissionUuid: string,
    choice: 'mine' | 'server',
) => Promise<void> = () =>
    Promise.reject(new Error('resolveConflict is not implemented yet (M6-T05)'));

export const discardLocalSubmission: (submissionUuid: string) => Promise<void> = () =>
    Promise.reject(new Error('discardLocalSubmission is not implemented yet (M6-T05)'));
