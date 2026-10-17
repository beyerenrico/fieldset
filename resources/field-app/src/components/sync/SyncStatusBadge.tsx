/**
 * Pending-sync indicator next to the online state (ADR 0035, M6-T07): the number of submissions not
 * synced (queued ops, unsynced drafts, attachments not uploaded), items that need a decision, or
 * "sign in again". Hidden when everything is synced. The full text is the link's accessible name.
 */
import { Link } from '@tanstack/react-router';
import { CloudUpload, KeyRound, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '../../i18n/I18nProvider.tsx';
import { cn } from '../../lib/cn.ts';
import { useSyncMeta, useUnsyncedTotals } from './useSyncStatus.ts';

const MAX_BADGE = 99;
const BASE =
    'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1 rounded-full px-2 text-sm font-bold focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring';

function count(value: number): string {
    return value > MAX_BADGE ? `${String(MAX_BADGE)}+` : String(value);
}

export function SyncStatusBadge(): ReactNode {
    const t = useT();
    const totals = useUnsyncedTotals();
    const sync = useSyncMeta();

    if (sync?.state === 'reauth') {
        const label = t('account.badgeReauth');
        return (
            <Link
                to="/settings"
                aria-label={label}
                title={label}
                className={cn(BASE, 'bg-warning text-warning-foreground')}
            >
                <KeyRound aria-hidden="true" className="size-5" />
            </Link>
        );
    }
    if (totals === undefined) {
        return null;
    }
    if (totals.blocked > 0) {
        const label = t('account.badgeBlocked', { count: totals.blocked });
        return (
            <Link
                to="/submissions"
                aria-label={label}
                title={label}
                className={cn(BASE, 'bg-destructive text-destructive-foreground')}
            >
                <TriangleAlert aria-hidden="true" className="size-5" />
                <span aria-hidden="true">{count(totals.blocked)}</span>
            </Link>
        );
    }
    if (totals.submissions > 0) {
        const label = t('account.badgePending', { count: totals.submissions });
        return (
            <Link
                to="/settings"
                aria-label={label}
                title={label}
                className={cn(BASE, 'border-2 border-border bg-background text-foreground')}
            >
                <CloudUpload aria-hidden="true" className="size-5" />
                <span aria-hidden="true">{count(totals.submissions)}</span>
            </Link>
        );
    }
    return null;
}
