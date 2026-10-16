/** Notification centre (§8.9): newest first, unread state in words, mark read, open the target. */
import { useInfiniteQuery } from '@tanstack/react-query';
import { useRouter } from '@tanstack/react-router';
import { CheckCheck, ExternalLink } from 'lucide-react';
import { useId, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { Badge } from '../components/ui/badge.tsx';
import { Button } from '../components/ui/button.tsx';
import { Card } from '../components/ui/card.tsx';
import { notificationListQueryOptions } from '../hooks/queries.ts';
import { useFormatters } from '../hooks/useFormatters.ts';
import { useMarkRead } from '../hooks/useMarkRead.ts';
import { useOrganizationId } from '../hooks/useSession.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import type { AppNotification } from '../lib/api/types.ts';
import { notificationTarget, type NotificationTarget } from '../lib/notifications/target.ts';
import { cn } from '../lib/cn.ts';

const LINK =
    'inline-flex min-h-11 items-center gap-1 underline underline-offset-4 focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring';

/** A plain left click stays in the app; modifier clicks keep the browser's behaviour. */
function isPlainClick(event: MouseEvent<HTMLAnchorElement>): boolean {
    return (
        event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey
    );
}

interface TitleLinkProps {
    readonly target: NotificationTarget;
    readonly title: string;
    readonly onOpen: () => void;
}

function TitleLink({ target, title, onOpen }: TitleLinkProps): ReactNode {
    const t = useT();
    const router = useRouter();
    if (target.kind === 'external') {
        return (
            <a
                href={target.href}
                target="_blank"
                rel="noopener noreferrer"
                className={LINK}
                onClick={onOpen}
            >
                {title} <span className="sr-only">{t('common.newTab')}</span>
                <ExternalLink aria-hidden="true" className="size-4 shrink-0" />
            </a>
        );
    }
    const open = (event: MouseEvent<HTMLAnchorElement>): void => {
        onOpen();
        if (isPlainClick(event)) {
            event.preventDefault();
            router.history.push(target.href);
        }
    };
    return (
        <a href={target.href} className={LINK} onClick={open}>
            {title}
        </a>
    );
}

interface ItemProps {
    readonly item: AppNotification;
    readonly onMarkRead: (id: string) => Promise<boolean>;
}

function NotificationItem({ item, onMarkRead }: ItemProps): ReactNode {
    const t = useT();
    const { dateTime } = useFormatters();
    const titleId = useId();
    const heading = useRef<HTMLHeadingElement>(null);
    const target = notificationTarget(item.url);
    const unread = item.read_at === null;
    const markRead = async (): Promise<void> => {
        // The button disappears once read: keep focus on this notification.
        if (await onMarkRead(item.id)) {
            heading.current?.focus();
        }
    };
    return (
        <Card className={cn('flex flex-col gap-1 p-4', unread ? 'border-primary' : undefined)}>
            <div className="flex items-start justify-between gap-2">
                <h2
                    ref={heading}
                    id={titleId}
                    tabIndex={-1}
                    className={cn(
                        'text-base outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring',
                        unread ? 'font-bold' : 'font-semibold',
                    )}
                >
                    {target === null ? (
                        item.title
                    ) : (
                        <TitleLink
                            target={target}
                            title={item.title}
                            onOpen={() => {
                                if (unread) {
                                    void onMarkRead(item.id);
                                }
                            }}
                        />
                    )}
                </h2>
                {unread ? <Badge className="shrink-0">{t('notifications.new')}</Badge> : null}
            </div>
            {item.body === null || item.body === '' ? null : (
                <p className="whitespace-pre-line break-words">{item.body}</p>
            )}
            <time dateTime={item.created_at} className="text-sm text-muted-foreground">
                {dateTime(item.created_at)}
            </time>
            {unread ? (
                <Button
                    variant="ghost"
                    className="self-start"
                    aria-describedby={titleId}
                    onClick={() => void markRead()}
                >
                    {t('notifications.markRead')}
                </Button>
            ) : null}
        </Card>
    );
}

export function NotificationsScreen(): ReactNode {
    const t = useT();
    const org = useOrganizationId();
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
                            <NotificationItem item={item} onMarkRead={(id) => markRead([id])} />
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
