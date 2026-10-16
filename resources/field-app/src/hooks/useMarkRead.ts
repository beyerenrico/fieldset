/** Marks notifications as read and updates the bell and the list caches with the server's count. */
import { useQueryClient } from '@tanstack/react-query';
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

function readPage(
    page: NotificationPage,
    ids: readonly string[] | undefined,
    unread: number,
    now: string,
): NotificationPage {
    const items = page.items.map((item): AppNotification =>
        item.read_at === null && (ids === undefined || ids.includes(item.id))
            ? { ...item, read_at: now }
            : item,
    );
    return { ...page, items, unread };
}

export function useMarkRead(): MarkReadState {
    const queryClient = useQueryClient();
    const org = useOrganizationId();
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<MessageKey | null>(null);

    const markRead = async (ids?: readonly string[]): Promise<boolean> => {
        setBusy(true);
        setProblem(null);
        try {
            const unread = await markNotificationsRead(ids);
            const now = new Date().toISOString();
            queryClient.setQueryData(notificationSummaryQueryOptions(org).queryKey, (page) =>
                page === undefined ? page : readPage(page, ids, unread, now),
            );
            queryClient.setQueryData(notificationListQueryOptions(org).queryKey, (data) =>
                data === undefined
                    ? data
                    : {
                          ...data,
                          pages: data.pages.map((page) => readPage(page, ids, unread, now)),
                      },
            );
            return true;
        } catch (error) {
            if (!reportAuthError(error)) {
                setProblem(
                    error instanceof NetworkError ? 'error.offline' : 'notifications.markFailed',
                );
            }
            return false;
        } finally {
            setBusy(false);
        }
    };

    return { busy, problem, markRead };
}
