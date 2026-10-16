/** TanStack Query options for the API (org id in the keys: switching orgs never mixes caches). */
import { queryOptions } from '@tanstack/react-query';
import {
    fetchForms,
    fetchFormVersion,
    fetchMe,
    fetchSubmission,
    listSubmissions,
} from '../lib/api/endpoints.ts';
import type { SubmissionStatus } from '../lib/api/types.ts';

const MINUTE = 60_000;

export const meQueryOptions = () =>
    queryOptions({ queryKey: ['me'], queryFn: fetchMe, staleTime: 5 * MINUTE });

export const formsQueryOptions = (org: number | null) =>
    queryOptions({ queryKey: ['forms', org], queryFn: fetchForms, staleTime: MINUTE });

export const formVersionQueryOptions = (formId: number, versionId: number) =>
    queryOptions({
        queryKey: ['form-version', formId, versionId],
        queryFn: () => fetchFormVersion(formId, versionId),
        staleTime: Infinity,
    });

export const submissionQueryOptions = (uuid: string) =>
    queryOptions({ queryKey: ['submission', uuid], queryFn: () => fetchSubmission(uuid) });

export const draftsQueryOptions = (org: number | null) =>
    queryOptions({
        queryKey: ['submissions', org, 'drafts'],
        queryFn: async () => {
            const statuses: readonly SubmissionStatus[] = ['draft', 'changes_requested'];
            const pages = await Promise.all(statuses.map((status) => listSubmissions({ status })));
            return pages
                .flatMap((page) => page.items)
                .toSorted((a, b) => b.updated_at.localeCompare(a.updated_at));
        },
    });
