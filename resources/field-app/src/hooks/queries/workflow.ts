/** Tasks and notifications queries (polled). */
import { infiniteQueryOptions, queryOptions } from '@tanstack/react-query';
import { nextPage } from '../../lib/api/pagination.ts';
import {
    fetchNotificationPreferences,
    listNotifications,
    listTasks,
} from '../../lib/api/workflow.ts';
import { polling } from './shared.ts';

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
