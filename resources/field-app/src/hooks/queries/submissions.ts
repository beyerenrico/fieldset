/** Submission queries; the screens merge them with the drafts on this device (useLocalDrafts). */
import { queryOptions } from '@tanstack/react-query';
import { fetchSubmission, listSubmissions } from '../../lib/api/endpoints.ts';
import { ApiError, NetworkError, isAuthLost } from '../../lib/api/errors.ts';
import type { SubmissionStatus } from '../../lib/api/types.ts';

const MAX_RETRIES = 2;

/** Offline the copy on this device is shown at once: no retries for network errors (or 4xx). */
export function retryUnlessOffline(failureCount: number, error: unknown): boolean {
    if (error instanceof NetworkError || isAuthLost(error)) {
        return false;
    }
    return !(error instanceof ApiError && error.status < 500) && failureCount < MAX_RETRIES;
}

/**
 * `networkMode: 'always'`: offline the request fails at once (NetworkError) instead of waiting
 * paused, so the screens fall back to the copy on this device (ADR 0035).
 */
export const submissionQueryOptions = (uuid: string) =>
    queryOptions({
        queryKey: ['submission', uuid],
        queryFn: () => fetchSubmission(uuid),
        networkMode: 'always',
        retry: retryUnlessOffline,
    });

/**
 * Home "Continue drafts": drafts and changes-requested submissions the user may edit now. The list
 * endpoint also returns submissions the user can only see (group grants, reviewers): `can_edit` is
 * true only for their creator.
 */
export const draftsQueryOptions = (org: number | null) =>
    queryOptions({
        queryKey: ['submissions', org, 'drafts'],
        networkMode: 'always',
        retry: retryUnlessOffline,
        queryFn: async () => {
            const statuses: readonly SubmissionStatus[] = ['draft', 'changes_requested'];
            const pages = await Promise.all(statuses.map((status) => listSubmissions({ status })));
            return pages
                .flatMap((page) => page.items)
                .filter((submission) => submission.can_edit)
                .toSorted((a, b) => b.updated_at.localeCompare(a.updated_at));
        },
    });
