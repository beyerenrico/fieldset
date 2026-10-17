/**
 * Pending-sync indicator next to the online state (ADR 0035, M6-T07): the number of submissions not
 * synced (queued ops, unsynced drafts, attachments not uploaded), items that need a decision, or
 * "sign in again". Hidden when everything is synced. The full text is the link's accessible name.
 * The pending count waits while online (usePendingVisible.ts), so autosaves do not make it flicker;
 * "needs your decision" shows at once.
 */
import { Link } from '@tanstack/react-router';
import { CloudUpload, KeyRound, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '../../i18n/I18nProvider.tsx';
import { cn } from '../../lib/cn.ts';
import { usePendingVisible } from './usePendingVisible.ts';
import { useSyncMeta, useUnsyncedTotals } from './useSyncStatus.ts';

const MAX_BADGE = 99;
const BASE =
    'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1 rounded-full px-2 text-sm font-bold focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring';

function count(value: number): string {
    return value > MAX_BADGE ? `${String(MAX_BADGE)}+` : String(value);
}

interface BadgeProps {
    readonly to: '/settings' | '/submissions';
    readonly label: string;
    readonly className: string;
    readonly icon: ReactNode;
    readonly value?: number;
}

function Badge({ to, label, className, icon, value }: BadgeProps): ReactNode {
    return (
        <Link to={to} aria-label={label} title={label} className={cn(BASE, className)}>
            {icon}
            {value === undefined ? null : <span aria-hidden="true">{count(value)}</span>}
        </Link>
    );
}

export function SyncStatusBadge(): ReactNode {
    const t = useT();
    const totals = useUnsyncedTotals();
    const sync = useSyncMeta();
    const showPending = usePendingVisible(totals?.submissions ?? 0);

    if (sync?.state === 'reauth') {
        return (
            <Badge
                to="/settings"
                label={t('account.badgeReauth')}
                className="bg-warning text-warning-foreground"
                icon={<KeyRound aria-hidden="true" className="size-5" />}
            />
        );
    }
    if (totals !== undefined && totals.blocked > 0) {
        return (
            <Badge
                to="/submissions"
                label={t('account.badgeBlocked', { count: totals.blocked })}
                className="bg-destructive text-destructive-foreground"
                icon={<TriangleAlert aria-hidden="true" className="size-5" />}
                value={totals.blocked}
            />
        );
    }
    if (totals !== undefined && showPending) {
        return (
            <Badge
                to="/settings"
                label={t('account.badgePending', { count: totals.submissions })}
                className="border-2 border-border bg-background text-foreground"
                icon={<CloudUpload aria-hidden="true" className="size-5" />}
                value={totals.submissions}
            />
        );
    }
    return null;
}
