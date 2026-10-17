/**
 * Paused submissions (ADR 0035): which ones the user fixes in the form (a refused submit, a missing
 * or refused attachment) and which ones need a decision (a conflict, any other blocking answer).
 */
import type { MessageKey } from '../../i18n/en.ts';
import type { DraftRow } from '../../lib/db/schema.ts';

/** Block codes the user resolves by fixing the form and submitting again (no decision panel). */
const FIXABLE: ReadonlySet<string> = new Set([
    'submit_refused',
    'attachments_missing',
    'attachment_blocked',
]);

/** Blocking answers with their own explanation in the decision panel. */
const EXPLAINED: ReadonlySet<string> = new Set([
    'not_editable',
    'version_retired',
    'version_mismatch',
    'form_access_revoked',
    'forbidden',
    'not_found',
    'payload_too_large',
]);

/** Answers where "Try again" cannot help: the entries move to a new draft or are discarded. */
const NO_RETRY: ReadonlySet<string> = new Set([
    'not_editable',
    'version_retired',
    'version_mismatch',
    'not_found',
]);

type SyncFields = Pick<DraftRow, 'sync' | 'blockedBy' | 'submitErrors'>;

export type Refusal =
    | { readonly kind: 'fields'; readonly count: number }
    | { readonly kind: 'attachment' }
    | { readonly kind: 'attachmentBlocked' };

export function isFixable(code: string | null): boolean {
    return code !== null && FIXABLE.has(code);
}

/** A retry can succeed once something outside the app changed (access restored, …). */
export function canRetry(code: string): boolean {
    return !NO_RETRY.has(code);
}

export function blockedTextKey(code: string): MessageKey {
    return EXPLAINED.has(code) ? (`sync.blocked.${code}` as MessageKey) : 'sync.blocked.other';
}

/** What the user has to fix before submitting again; null when nothing was refused. */
export function refusalOf(row: SyncFields | null | undefined): Refusal | null {
    if (row?.sync !== 'blocked') {
        return null;
    }
    switch (row.blockedBy) {
        case 'submit_refused':
            return { kind: 'fields', count: row.submitErrors.length };
        case 'attachments_missing':
            return { kind: 'attachment' };
        case 'attachment_blocked':
            return { kind: 'attachmentBlocked' };
        default:
            return null;
    }
}

/** Waits for the user's decision: a conflict, or a blocking answer the form cannot fix. */
export function needsDecision(row: SyncFields | null | undefined): boolean {
    return row?.sync === 'conflict' || (row?.sync === 'blocked' && !isFixable(row.blockedBy));
}

/** Needs the user (a decision or a fix): lists link such items to the review screen. */
export function needsAttention(row: SyncFields | null | undefined): boolean {
    return row?.sync === 'conflict' || row?.sync === 'blocked';
}
