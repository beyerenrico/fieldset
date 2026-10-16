/** Header bell (§8.9): unread count in the accessible name and a badge; polls while visible. */
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Bell } from 'lucide-react';
import type { ReactNode } from 'react';
import { notificationSummaryQueryOptions } from '../hooks/queries.ts';
import { useOrganizationId } from '../hooks/useSession.ts';
import { useT } from '../i18n/I18nProvider.tsx';

const MAX_BADGE = 99;

export function NotificationBell(): ReactNode {
    const t = useT();
    const org = useOrganizationId();
    const summary = useQuery(notificationSummaryQueryOptions(org));
    const unread = summary.data?.unread ?? 0;
    const label =
        unread > 0 ? t('notifications.bellUnread', { count: unread }) : t('notifications.title');
    return (
        <>
            <Link
                to="/notifications"
                aria-label={label}
                title={label}
                activeProps={{ 'aria-current': 'page' }}
                className="relative inline-flex size-11 shrink-0 items-center justify-center rounded-md hover:bg-muted focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <Bell aria-hidden="true" className="size-6" />
                {unread > 0 ? (
                    <span
                        aria-hidden="true"
                        className="absolute right-0 top-0 min-w-5 rounded-full border-2 border-background bg-destructive px-1 text-center text-xs font-bold leading-4 text-destructive-foreground"
                    >
                        {unread > MAX_BADGE ? `${String(MAX_BADGE)}+` : String(unread)}
                    </span>
                ) : null}
            </Link>
            {/* Mounted with the header: a changed count is announced politely (WCAG 4.1.3). */}
            <span role="status" className="sr-only">
                {unread > 0 ? label : ''}
            </span>
        </>
    );
}
