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
import { useSyncNow, type SyncNow } from './useSyncNow.ts';

const LINK =
    'inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4';

/** The two counts of the header badge, plus photos still to upload when there are any. */
function Counts({ totals }: { readonly totals: UnsyncedTotals }): ReactNode {
    const t = useT();
    if (totals.pending === 0 && totals.blocked === 0) {
        return <p>{t('account.allSynced')}</p>;
    }
    return (
        <dl className="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1">
            <dt>{t('account.countSubmissions')}</dt>
            <dd className="text-right font-semibold tabular-nums">{totals.submissions}</dd>
            <dt>
                {totals.blocked > 0 ? (
                    <Link to="/submissions" className={LINK}>
                        {t('account.countBlocked')}
                    </Link>
                ) : (
                    t('account.countBlocked')
                )}
            </dt>
            <dd className="text-right font-semibold tabular-nums">{totals.blocked}</dd>
            {totals.pendingAttachments > 0 ? (
                <>
                    <dt>{t('account.countAttachments')}</dt>
                    <dd className="text-right font-semibold tabular-nums">
                        {totals.pendingAttachments}
                    </dd>
                </>
            ) : null}
        </dl>
    );
}

function ReauthHint(): ReactNode {
    const t = useT();
    return (
        <Alert variant="warning">
            <p>{t('account.reauthHint')}</p>
            <Link to="/login" search={{ notice: 'reauth' }} className={`mt-2 ${LINK}`}>
                {t('account.signInAgain')}
            </Link>
        </Alert>
    );
}

/** aria-disabled (not disabled) keeps focus on the button while the run is busy. */
function SyncNowButton({ syncNow }: { readonly syncNow: SyncNow }): ReactNode {
    const t = useT();
    return (
        <Button
            variant="outline"
            size="lg"
            aria-disabled={syncNow.running ? true : undefined}
            onClick={() => {
                if (!syncNow.running) {
                    void syncNow.run();
                }
            }}
        >
            <RefreshCw
                aria-hidden="true"
                className={syncNow.running ? 'animate-spin' : undefined}
            />
            {syncNow.running ? t('account.syncing') : t('account.syncNow')}
        </Button>
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
                {sync?.state === 'reauth' ? <ReauthHint /> : null}
                <p>
                    {lastSuccessAt === null
                        ? t('account.neverSynced')
                        : t('account.lastSync', {
                              time: dateTime(new Date(lastSuccessAt).toISOString()),
                          })}
                </p>
                {totals === undefined ? null : <Counts totals={totals} />}
                <SyncNowButton syncNow={syncNow} />
                <p role="status" className="min-h-6">
                    {syncNow.message ?? ''}
                </p>
            </CardContent>
        </Card>
    );
}
