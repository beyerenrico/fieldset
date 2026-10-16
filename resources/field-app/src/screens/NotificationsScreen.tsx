/** Notification centre (§8.9): all organizations, newest first, unread in words, mark read, open. */
import { useInfiniteQuery } from '@tanstack/react-query';
import { CheckCheck } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { notificationListQueryOptions } from '../hooks/queries.ts';
import { useMarkRead } from '../hooks/useMarkRead.ts';
import { useOrganizationId, useSession } from '../hooks/useSession.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import { NotificationItem, scopeOf } from './notifications/NotificationItem.tsx';

export function NotificationsScreen(): ReactNode {
    const t = useT();
    const org = useOrganizationId();
    const { me } = useSession();
    const listId = useId();
    const list = useInfiniteQuery(notificationListQueryOptions(org));
    const { busy, problem, markRead } = useMarkRead();
    const [allRead, setAllRead] = useState(false);
    const unread = list.data?.pages[0]?.unread ?? 0;
    const items = list.data?.pages.flatMap((page) => page.items) ?? [];

    const markAll = async (): Promise<void> => {
        if (unread === 0 || busy) {
            return;
        }
        setAllRead(false);
        setAllRead(await markRead());
    };

    return (
        <div className="flex flex-col gap-4">
            <h1 id={listId} className="text-2xl font-bold">
                {t('notifications.title')}
            </h1>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-muted-foreground">
                    {t('notifications.unreadCount', { count: unread })}
                </p>
                <Button
                    variant="outline"
                    aria-disabled={unread === 0 || busy}
                    onClick={() => void markAll()}
                >
                    <CheckCheck aria-hidden="true" />
                    {t('notifications.markAll')}
                </Button>
            </div>
            <div role="status">
                {allRead && unread === 0 ? <p>{t('notifications.allRead')}</p> : null}
            </div>
            {problem === null ? null : (
                <Alert variant="destructive" role="alert">
                    {t(problem)}
                </Alert>
            )}
            {list.isPending ? <Loading /> : null}
            {list.isError ? (
                <ErrorState error={list.error} onRetry={() => void list.refetch()} />
            ) : null}
            {list.isSuccess && items.length === 0 ? (
                <p className="text-muted-foreground">{t('notifications.empty')}</p>
            ) : null}
            {items.length === 0 ? null : (
                <ul aria-labelledby={listId} className="flex flex-col gap-2">
                    {items.map((item) => (
                        <li key={item.id}>
                            <NotificationItem
                                item={item}
                                scope={scopeOf(item, me.organizations)}
                                showOrganization={me.organizations.length > 1}
                                onMarkRead={(id) => markRead([id])}
                            />
                        </li>
                    ))}
                </ul>
            )}
            {list.hasNextPage ? (
                <Button
                    variant="outline"
                    disabled={list.isFetchingNextPage}
                    onClick={() => void list.fetchNextPage()}
                >
                    {t('submissions.loadMore')}
                </Button>
            ) : null}
        </div>
    );
}
