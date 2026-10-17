/** Drafts and queued submissions on this device, live from the offline store (ADR 0035). */
import { useMemo } from 'react';
import { EDITABLE_STATUSES, type Submission } from '../lib/api/types.ts';
import { useLiveQuery } from '../lib/db/live.ts';
import type { DraftRow, DraftSync, OutboxError, OutboxRow, Scope } from '../lib/db/schema.ts';
import { inScope, localDraftsOf, localSubmission, opsOfScope } from '../lib/drafts/local-drafts.ts';
import { db } from '../lib/db/db.ts';
import { useSession } from './useSession.ts';

/** Organization + user of new rows and of every read (null without an organization). */
export function useDraftScope(): Scope | null {
    const { me, organization } = useSession();
    const orgId = organization?.id ?? null;
    const userId = me.user.id;
    return useMemo(() => (orgId === null ? null : { orgId, userId }), [orgId, userId]);
}

export interface LocalDraft {
    readonly row: DraftRow;
    /** Submission-shaped view (status and number of the last server copy). */
    readonly submission: Submission;
    readonly ops: readonly OutboxRow[];
    /** "Number pending": a submit waits in the outbox. */
    readonly pendingSubmit: boolean;
    /** A queued "send to customer". */
    readonly pendingSend: Extract<OutboxRow, { kind: 'send' }> | null;
    /** Error of a blocked op, or the last retry error. */
    readonly lastError: OutboxError | null;
}

export function toLocalDraft(row: DraftRow, ops: readonly OutboxRow[]): LocalDraft {
    const own = ops.filter((op) => op.submissionUuid === row.uuid);
    const send = own.find((op) => op.kind === 'send');
    return {
        row,
        submission: localSubmission(row),
        ops: own,
        pendingSubmit: own.some((op) => op.kind === 'submit'),
        pendingSend: send?.kind === 'send' ? send : null,
        lastError: own.find((op) => op.lastError !== null)?.lastError ?? null,
    };
}

/**
 * Still a draft to continue: no submit queued, and editable on the server (or not there yet) or
 * waiting for the user's decision (conflict, blocked).
 */
export function isOpenDraft(draft: LocalDraft): boolean {
    const status = draft.row.server?.status;
    const needsUser = draft.row.sync === 'conflict' || draft.row.sync === 'blocked';
    return (
        !draft.pendingSubmit &&
        (status === undefined || EDITABLE_STATUSES.includes(status) || needsUser)
    );
}

/** Not (fully) on the server yet: unsynced edits, queued ops, or waiting for the user. */
export function isUnsynced(draft: LocalDraft): boolean {
    return (
        draft.ops.length > 0 ||
        draft.row.localVersion > draft.row.syncedVersion ||
        draft.row.sync === 'conflict' ||
        draft.row.sync === 'blocked'
    );
}

/** Every local draft of the scope with its ops; undefined while loading. */
export function useLocalDrafts(scope: Scope | null): readonly LocalDraft[] | undefined {
    return useLiveQuery(async () => {
        if (scope === null) {
            return [];
        }
        const [rows, ops] = await Promise.all([localDraftsOf(scope), opsOfScope(scope)]);
        return rows.map((row) => toLocalDraft(row, ops));
    }, [scope?.orgId, scope?.userId]);
}

export interface LocalSubmissionState {
    readonly row: DraftRow | null;
    readonly ops: readonly OutboxRow[];
}

/** One submission's local row and ops (also ops without a row, e.g. a queued send); undefined while loading. */
export function useLocalSubmission(
    uuid: string,
    scope: Scope | null,
): LocalSubmissionState | undefined {
    return useLiveQuery(async () => {
        if (scope === null) {
            return { row: null, ops: [] };
        }
        const [row, ops] = await Promise.all([
            db.drafts.get(uuid),
            db.outbox.where('submissionUuid').equals(uuid).toArray(),
        ]);
        return {
            row: row !== undefined && inScope(row, scope) ? row : null,
            ops: ops.filter((op) => inScope(op, scope)).toSorted((a, b) => a.seq - b.seq),
        };
    }, [uuid, scope?.orgId, scope?.userId]);
}

/** The sync state shown next to a draft (null: nothing local, i.e. the server copy is current). */
export function syncStateOf(draft: LocalDraft | null | undefined): DraftSync | null {
    if (draft === null || draft === undefined) {
        return null;
    }
    const blockedOp = draft.ops.some((op) => op.state === 'blocked');
    return blockedOp && draft.row.sync !== 'conflict' ? 'blocked' : draft.row.sync;
}
