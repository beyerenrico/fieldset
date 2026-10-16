/** The submission's PDF (§9.1 "Document (PDF) view"): polls while it is rendered, keeps the link fresh. */
import { useQuery, type QueryState, type UseQueryResult } from '@tanstack/react-query';
import { useRef } from 'react';
import { fetchSubmissionDocument } from '../lib/api/endpoints.ts';
import { isApiError, type ApiError } from '../lib/api/errors.ts';
import type { SubmissionDocument } from '../lib/api/types.ts';

/** Backoff between checks while the PDF is rendered (~1.5 min), then until "Check again". */
export const POLL_DELAYS_MS: readonly number[] = [2_000, 4_000, 8_000, 15_000, 30_000, 30_000];
/** Fetch a new signed link this long before the current one expires. */
const LINK_REFRESH_MARGIN_MS = 60_000;
const MIN_LINK_REFRESH_MS = 10_000;

export type DocumentQueryState = Pick<
    QueryState<SubmissionDocument>,
    'status' | 'data' | 'error' | 'errorUpdateCount'
>;

export function isDocumentNotReady(error: unknown): boolean {
    return isApiError(error, 404, 'document_not_ready');
}

/** Rendering failed permanently (409 `document_failed`); the office is notified and can re-render. */
export function isDocumentFailed(error: unknown): error is ApiError {
    return isApiError(error, 409, 'document_failed');
}

/** The server's user-facing text for a permanent failure, or `null` when it sent none. */
export function documentFailureMessage(error: unknown): string | null {
    if (!isDocumentFailed(error)) {
        return null;
    }
    const text = error.message.trim();
    return text === '' || text === error.code ? null : text;
}

/**
 * Next automatic refetch in ms, or `false` to stop. A permanent failure stops (only a manual check
 * asks again). With a link (also after another failed refresh): shortly before it expires. Without
 * one: backoff by `polls`, the "not ready" answers since mount or the last manual check.
 */
export function documentRefetchDelay(
    state: DocumentQueryState,
    polls: number,
    now: number = Date.now(),
): number | false {
    if (isDocumentFailed(state.error)) {
        return false;
    }
    if (state.data !== undefined) {
        const expires = Date.parse(state.data.expires_at);
        if (Number.isNaN(expires)) {
            return false;
        }
        return Math.max(expires - now - LINK_REFRESH_MARGIN_MS, MIN_LINK_REFRESH_MS);
    }
    if (state.status === 'error' && isDocumentNotReady(state.error)) {
        return POLL_DELAYS_MS[polls - 1] ?? false;
    }
    return false;
}

/** True when the link expires within the refresh margin (or already has). */
export function linkNeedsRefresh(state: DocumentQueryState, now: number = Date.now()): boolean {
    if (state.data === undefined) {
        return false;
    }
    const expires = Date.parse(state.data.expires_at);
    return !Number.isNaN(expires) && expires - now < LINK_REFRESH_MARGIN_MS;
}

export interface SubmissionDocumentState {
    readonly query: UseQueryResult<SubmissionDocument>;
    /** Manual check; a "not ready" answer restarts the backoff schedule. */
    readonly checkAgain: () => void;
}

/** Polling stops on unmount (observer gone); `gcTime: 0` drops signed links with the screen. */
export function useSubmissionDocument(uuid: string): SubmissionDocumentState {
    const pollBase = useRef(0);
    const query = useQuery({
        queryKey: ['submission-document', uuid],
        queryFn: () => fetchSubmissionDocument(uuid),
        gcTime: 0,
        refetchInterval: (current) =>
            documentRefetchDelay(current.state, current.state.errorUpdateCount - pollBase.current),
        // Timers do not run for hidden tabs: coming back with an (almost) expired link refreshes it.
        refetchOnWindowFocus: (current) => linkNeedsRefresh(current.state),
    });
    const checkAgain = (): void => {
        pollBase.current = query.errorUpdateCount;
        void query.refetch();
    };
    return { query, checkAgain };
}
