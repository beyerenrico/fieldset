/**
 * The submission for the detail screen (ADR 0035): the server copy, or the copy on this device
 * while it has unsynced changes or a queued submit ("number pending"); offline the local copy is
 * enough. A queued submit that goes through in this tab replaces it with the server's answer.
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { submissionQueryOptions } from '../../hooks/queries.ts';
import { useDraftScope, useLocalSubmission } from '../../hooks/useLocalDrafts.ts';
import type { Submission } from '../../lib/api/types.ts';
import type { DraftRow, OutboxError, OutboxRow } from '../../lib/db/schema.ts';
import { localSubmission } from '../../lib/drafts/local-drafts.ts';
import { onOutboxEvent } from '../../lib/sync/events.ts';

export interface SubmissionView {
    readonly submission: Submission | null;
    /** True when `submission` is the copy on this device. */
    readonly isLocal: boolean;
    /** The row on this device (sync state, blocking code, refused fields), also when not shown. */
    readonly row: DraftRow | null;
    /** A submit waits in the outbox. */
    readonly queuedSubmit: OutboxRow | null;
    /** Why the last attempt of a queued op failed (retried automatically or blocked). */
    readonly lastError: OutboxError | null;
    readonly isPending: boolean;
    /** Server error, only when there is no local copy to show. */
    readonly error: unknown;
    readonly refetch: () => void;
}

export function useSubmissionView(uuid: string): SubmissionView {
    const scope = useDraftScope();
    const queryClient = useQueryClient();
    const query = useQuery(submissionQueryOptions(uuid));
    const local = useLocalSubmission(uuid, scope);

    useEffect(
        () =>
            onOutboxEvent((event) => {
                if (
                    event.submissionUuid !== uuid ||
                    event.type !== 'done' ||
                    event.kind !== 'submit'
                ) {
                    return;
                }
                queryClient.setQueryData(
                    submissionQueryOptions(uuid).queryKey,
                    event.result as Submission,
                );
                void queryClient.invalidateQueries({ queryKey: ['submissions'] });
            }),
        [uuid, queryClient],
    );

    const row = local?.row ?? null;
    const ops = local?.ops ?? [];
    const queuedSubmit = ops.find((op) => op.kind === 'submit') ?? null;
    const dirty = row !== null && row.localVersion > row.syncedVersion;
    const isLocal = row !== null && (queuedSubmit !== null || dirty || query.data === undefined);
    const submission = isLocal ? localSubmission(row) : (query.data ?? null);
    return {
        submission,
        isLocal,
        row,
        queuedSubmit,
        lastError: ops.find((op) => op.lastError !== null)?.lastError ?? null,
        isPending: local === undefined || (submission === null && query.isPending),
        error: submission === null ? query.error : null,
        refetch: () => void query.refetch(),
    };
}
