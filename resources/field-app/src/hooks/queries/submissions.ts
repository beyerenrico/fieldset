/** Submission queries (ADR 0035: agent A merges local drafts). */
import { queryOptions } from '@tanstack/react-query';
import { fetchSubmission, listSubmissions } from '../../lib/api/endpoints.ts';
import type { SubmissionStatus } from '../../lib/api/types.ts';

export const submissionQueryOptions = (uuid: string) =>
    queryOptions({ queryKey: ['submission', uuid], queryFn: () => fetchSubmission(uuid) });

/**
 * Home "Continue drafts": drafts and changes-requested submissions the user may edit now. The list
 * endpoint also returns submissions the user can only see (group grants, reviewers): `can_edit` is
 * true only for their creator.
 */
export const draftsQueryOptions = (org: number | null) =>
    queryOptions({
        queryKey: ['submissions', org, 'drafts'],
        queryFn: async () => {
            const statuses: readonly SubmissionStatus[] = ['draft', 'changes_requested'];
            const pages = await Promise.all(statuses.map((status) => listSubmissions({ status })));
            return pages
                .flatMap((page) => page.items)
                .filter((submission) => submission.can_edit)
                .toSorted((a, b) => b.updated_at.localeCompare(a.updated_at));
        },
    });
