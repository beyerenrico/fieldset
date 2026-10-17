/** Settings: last sync, unsynced items and "Sync now" (§9.1, ADR 0035). */
import { Link } from '@tanstack/react-router';
import { RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useSyncMeta, useUnsyncedTotals } from '../../components/sync/useSyncStatus.ts';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card.tsx';
import { useFormatters } from '../../hooks/useFormatters.ts';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { UnsyncedTotals } from '../../lib/session/unsynced.ts';
import { useSyncNow } from './useSyncNow.ts';

function Counts({ totals }: { readonly totals: UnsyncedTotals }): ReactNode {
    const t = useT();
    if (totals.pending === 0 && totals.blocked === 0) {
        return <p>{t('account.allSynced')}</p>;
    }
    const rows = [
        [t('account.countDrafts'), totals.dirtyDrafts],
        [t('account.countOps'), totals.ops],
        [t('account.countAttachments'), totals.pendingAttachments],
        [t('account.countBlocked'), totals.blocked],
    ] as const;
    return (
        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1">
            {rows.map(([label, value]) => (
                <div key={label} className="contents">
                    <dt>{label}</dt>
                    <dd className="text-right font-semibold tabular-nums">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function SyncCard(): ReactNode {
    const t = useT();
    const { dateTime } = useFormatters();
    const totals = useUnsyncedTotals();
    const sync = useSyncMeta();
    const syncNow = useSyncNow(t);
    const lastSuccessAt = sync?.lastSuccessAt ?? null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('account.syncTitle')}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {sync?.state === 'reauth' ? (
                    <Alert variant="warning">
                        <p>{t('account.reauthHint')}</p>
                        <Link
                            to="/login"
                            search={{ notice: 'reauth' }}
                            className="mt-2 inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4"
                        >
                            {t('account.signInAgain')}
                        </Link>
                    </Alert>
                ) : null}
                <p>
                    {lastSuccessAt === null
                        ? t('account.neverSynced')
                        : t('account.lastSync', {
                              time: dateTime(new Date(lastSuccessAt).toISOString()),
                          })}
                </p>
                {totals === undefined ? null : <Counts totals={totals} />}
                <Button
                    variant="outline"
                    size="lg"
                    disabled={syncNow.running}
                    onClick={() => void syncNow.run()}
                >
                    <RefreshCw
                        aria-hidden="true"
                        className={syncNow.running ? 'animate-spin' : undefined}
                    />
                    {syncNow.running ? t('account.syncing') : t('account.syncNow')}
                </Button>
                <p role="status" className="min-h-6">
                    {syncNow.message ?? ''}
                </p>
            </CardContent>
        </Card>
    );
}
