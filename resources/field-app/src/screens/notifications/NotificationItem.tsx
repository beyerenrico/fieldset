/**
 * One notification: title (a link to its target), body, time, organization and unread state. The
 * list spans all of the user's organizations: an app link of another one switches to it first; an
 * organization the user left cannot be opened at all (its pages would only answer "not found").
 */
import { useRouter } from '@tanstack/react-router';
import { Building2, ExternalLink } from 'lucide-react';
import { useId, useRef, type MouseEvent, type ReactNode } from 'react';
import { Badge } from '../../components/ui/badge.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Card } from '../../components/ui/card.tsx';
import { useFormatters } from '../../hooks/useFormatters.ts';
import { useSwitchOrganization } from '../../hooks/useSwitchOrganization.ts';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { AppNotification, Organization } from '../../lib/api/types.ts';
import { cn } from '../../lib/cn.ts';
import { notificationTarget, type NotificationTarget } from '../../lib/notifications/target.ts';

const LINK =
    'inline-flex min-h-11 items-center gap-1 underline underline-offset-4 focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring';

/** `member`: one of the user's organizations; `gone`: no longer a member; `none`: not tied to one. */
export type NotificationScope =
    | { readonly kind: 'none' }
    | { readonly kind: 'member'; readonly organization: Organization }
    | { readonly kind: 'gone' };

export function scopeOf(
    item: AppNotification,
    organizations: readonly Organization[],
): NotificationScope {
    if (item.organization_id === null || item.organization_id === undefined) {
        return { kind: 'none' };
    }
    const organization = organizations.find((candidate) => candidate.id === item.organization_id);
    return organization === undefined ? { kind: 'gone' } : { kind: 'member', organization };
}

/** A plain left click stays in the app; modifier clicks keep the browser's behaviour. */
function isPlainClick(event: MouseEvent<HTMLAnchorElement>): boolean {
    return (
        event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey
    );
}

interface TitleLinkProps {
    readonly target: NotificationTarget;
    readonly title: string;
    /** Organization to switch to before an app link opens (new tabs read the stored one too). */
    readonly organizationId: number | null;
    readonly onOpen: () => void;
}

function TitleLink({ target, title, organizationId, onOpen }: TitleLinkProps): ReactNode {
    const t = useT();
    const router = useRouter();
    const switchOrganization = useSwitchOrganization();
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
        if (organizationId !== null) {
            switchOrganization(organizationId);
        }
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

interface TitleProps {
    readonly item: AppNotification;
    readonly scope: NotificationScope;
    readonly onOpen: () => void;
}

function Title({ item, scope, onOpen }: TitleProps): ReactNode {
    const target = scope.kind === 'gone' ? null : notificationTarget(item.url);
    if (target === null) {
        return item.title;
    }
    return (
        <TitleLink
            target={target}
            title={item.title}
            organizationId={scope.kind === 'member' ? scope.organization.id : null}
            onOpen={onOpen}
        />
    );
}

export interface NotificationItemProps {
    readonly item: AppNotification;
    readonly scope: NotificationScope;
    /** The user belongs to several organizations: name the item's one. */
    readonly showOrganization: boolean;
    readonly onMarkRead: (id: string) => Promise<boolean>;
}

export function NotificationItem({
    item,
    scope,
    showOrganization,
    onMarkRead,
}: NotificationItemProps): ReactNode {
    const t = useT();
    const { dateTime } = useFormatters();
    const titleId = useId();
    const heading = useRef<HTMLHeadingElement>(null);
    const unread = item.read_at === null;
    const markRead = (): void => {
        // The button disappears at once (optimistic): keep focus on this notification.
        heading.current?.focus();
        void onMarkRead(item.id);
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
                    <Title
                        item={item}
                        scope={scope}
                        onOpen={() => {
                            if (unread) {
                                void onMarkRead(item.id);
                            }
                        }}
                    />
                </h2>
                {unread ? <Badge className="shrink-0">{t('notifications.new')}</Badge> : null}
            </div>
            {item.body === null || item.body === '' ? null : (
                <p className="whitespace-pre-line break-words">{item.body}</p>
            )}
            {showOrganization && scope.kind === 'member' ? (
                <p className="flex items-center gap-1 text-sm text-muted-foreground">
                    <Building2 aria-hidden="true" className="size-4 shrink-0" />
                    {t('notifications.organization', { name: scope.organization.name })}
                </p>
            ) : null}
            {scope.kind === 'gone' ? (
                <p className="text-sm font-semibold">{t('notifications.orgGone')}</p>
            ) : null}
            <time dateTime={item.created_at} className="text-sm text-muted-foreground">
                {dateTime(item.created_at)}
            </time>
            {unread ? (
                <Button
                    variant="ghost"
                    className="self-start"
                    aria-describedby={titleId}
                    onClick={markRead}
                >
                    {t('notifications.markRead')}
                </Button>
            ) : null}
        </Card>
    );
}
