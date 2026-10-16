/** TanStack Query options for the API (org id in the keys: switching orgs never mixes caches). */
import { infiniteQueryOptions, queryOptions } from '@tanstack/react-query';
import {
    fetchForms,
    fetchFormVersion,
    fetchMe,
    fetchSubmission,
    listSubmissions,
} from '../lib/api/endpoints.ts';
import { nextPage } from '../lib/api/pagination.ts';
import type { SubmissionStatus } from '../lib/api/types.ts';
import { fetchNotificationPreferences, listNotifications, listTasks } from '../lib/api/workflow.ts';

const MINUTE = 60_000;
/** Polling fallback without Reverb (§8.9): every minute while the tab is visible, plus on focus. */
export const POLL_INTERVAL_MS = MINUTE;
const polling = {
    refetchInterval: POLL_INTERVAL_MS,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: true,
} as const;

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

export const openTasksQueryOptions = (org: number | null) =>
    queryOptions({
        queryKey: ['tasks', org, 'open'],
        queryFn: async () => (await listTasks({ status: 'open' })).items,
        ...polling,
    });

/** First page of notifications: the bell's unread count. */
export const notificationSummaryQueryOptions = (org: number | null) =>
    queryOptions({
        queryKey: ['notifications', org, 'summary'],
        queryFn: () => listNotifications(1),
        ...polling,
    });

/** The notification centre's list (pages of 'GET /notifications'). */
export const notificationListQueryOptions = (org: number | null) =>
    infiniteQueryOptions({
        queryKey: ['notifications', org, 'list'],
        queryFn: ({ pageParam }) => listNotifications(pageParam),
        initialPageParam: 1,
        getNextPageParam: (last) => nextPage(last.meta),
        ...polling,
    });

export const notificationPreferencesQueryOptions = () =>
    queryOptions({
        queryKey: ['notification-preferences'],
        queryFn: fetchNotificationPreferences,
    });
