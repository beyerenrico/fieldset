/**
 * Marks notifications as read, optimistically: polls in flight are cancelled first (a late answer
 * with the old state would undo the change), the bell and the list update at once, a failure rolls
 * back, and both are refetched once the request has settled.
 */
import { useQueryClient, type InfiniteData, type QueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { MessageKey } from '../i18n/en.ts';
import { NetworkError } from '../lib/api/errors.ts';
import type { AppNotification, NotificationPage } from '../lib/api/types.ts';
import { markNotificationsRead } from '../lib/api/workflow.ts';
import { reportAuthError } from '../lib/session/auth-events.ts';
import { notificationListQueryOptions, notificationSummaryQueryOptions } from './queries.ts';
import { useOrganizationId } from './useSession.ts';

export interface MarkReadState {
    readonly busy: boolean;
    readonly problem: MessageKey | null;
    /** `ids` omitted: all. Resolves `true` once the server confirmed. */
    readonly markRead: (ids?: readonly string[]) => Promise<boolean>;
}

/** Every notification query (bell and list, any organization key). */
const NOTIFICATIONS = { queryKey: ['notifications'] } as const;

type ListData = InfiniteData<NotificationPage, number>;

interface Snapshot {
    readonly summary: NotificationPage | undefined;
    readonly list: ListData | undefined;
}

interface Change {
    readonly ids: readonly string[] | undefined;
    readonly now: string;
    /** Number of the targeted notifications that were still unread (counted once). */
    readonly newlyRead: number;
}

const isTarget = (item: AppNotification, ids: readonly string[] | undefined): boolean =>
    item.read_at === null && (ids === undefined || ids.includes(item.id));

function countNewlyRead(snapshot: Snapshot, ids: readonly string[] | undefined): number {
    const pages = [
        ...(snapshot.list?.pages ?? []),
        ...(snapshot.summary ? [snapshot.summary] : []),
    ];
    const unreadIds = pages.flatMap((page) => page.items.filter((item) => isTarget(item, ids)));
    return new Set(unreadIds.map((item) => item.id)).size;
}

/** `unread`: the server's count once known; before that the count is reduced locally. */
function readPage(page: NotificationPage, change: Change, unread?: number): NotificationPage {
    const items = page.items.map((item): AppNotification =>
        isTarget(item, change.ids) ? { ...item, read_at: change.now } : item,
    );
    const local = change.ids === undefined ? 0 : Math.max(0, page.unread - change.newlyRead);
    return { ...page, items, unread: unread ?? local };
}

function writeAll(
    queryClient: QueryClient,
    org: number | null,
    update: (page: NotificationPage) => NotificationPage,
): void {
    queryClient.setQueryData(notificationSummaryQueryOptions(org).queryKey, (page) =>
        page === undefined ? page : update(page),
    );
    queryClient.setQueryData(notificationListQueryOptions(org).queryKey, (data) =>
        data === undefined ? data : { ...data, pages: data.pages.map(update) },
    );
}

export function useMarkRead(): MarkReadState {
    const queryClient = useQueryClient();
    const org = useOrganizationId();
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<MessageKey | null>(null);

    const markRead = async (ids?: readonly string[]): Promise<boolean> => {
        setBusy(true);
        setProblem(null);
        await queryClient.cancelQueries(NOTIFICATIONS);
        const summaryKey = notificationSummaryQueryOptions(org).queryKey;
        const listKey = notificationListQueryOptions(org).queryKey;
        const snapshot: Snapshot = {
            summary: queryClient.getQueryData(summaryKey),
            list: queryClient.getQueryData(listKey),
        };
        const change: Change = {
            ids,
            now: new Date().toISOString(),
            newlyRead: countNewlyRead(snapshot, ids),
        };
        writeAll(queryClient, org, (page) => readPage(page, change));
        try {
            const unread = await markNotificationsRead(ids);
            writeAll(queryClient, org, (page) => readPage(page, change, unread));
            return true;
        } catch (error) {
            queryClient.setQueryData(summaryKey, snapshot.summary);
            queryClient.setQueryData(listKey, snapshot.list);
            if (!reportAuthError(error)) {
                setProblem(
                    error instanceof NetworkError ? 'error.offline' : 'notifications.markFailed',
                );
            }
            return false;
        } finally {
            setBusy(false);
            void queryClient.invalidateQueries(NOTIFICATIONS);
        }
    };

    return { busy, problem, markRead };
}
