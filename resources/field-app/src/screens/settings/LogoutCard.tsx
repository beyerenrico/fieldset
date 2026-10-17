/**
 * Logout (§9.3, ADR 0035): confirm, warn about unsynced items with "Sync now", then remove all local
 * data but the device uuid and reload to the login page.
 */
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { LogOut, RefreshCw } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useUnsyncedTotals } from '../../components/sync/useSyncStatus.ts';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { useI18n } from '../../i18n/I18nProvider.tsx';
import { rememberNotice } from '../../lib/session/auth-events.ts';
import { signOut } from '../../lib/session/sign-out.ts';
import { useSyncNow } from './useSyncNow.ts';

function UnsyncedWarning(): ReactNode {
    const { t } = useI18n();
    const totals = useUnsyncedTotals();
    const syncNow = useSyncNow(t);
    const unsynced = (totals?.pending ?? 0) + (totals?.blocked ?? 0);
    if (unsynced === 0 && syncNow.message === null) {
        return null;
    }
    return (
        <div className="mt-3 flex flex-col gap-2">
            {unsynced > 0 ? (
                <p className="font-semibold">{t('account.logoutUnsynced', { count: unsynced })}</p>
            ) : null}
            {unsynced > 0 ? (
                <Button
                    variant="default"
                    className="self-start"
                    disabled={syncNow.running}
                    onClick={() => void syncNow.run()}
                >
                    <RefreshCw aria-hidden="true" />
                    {syncNow.running ? t('account.syncing') : t('account.syncNow')}
                </Button>
            ) : null}
            <p role="status">{syncNow.message ?? ''}</p>
        </div>
    );
}

export function LogoutCard(): ReactNode {
    const { t } = useI18n();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    const confirm = async (): Promise<void> => {
        setBusy(true);
        await signOut();
        // Online the page reloads; offline (or before the reload starts) the router takes over.
        queryClient.clear();
        await navigate({ to: '/login', search: { notice: rememberNotice('logout') ?? 'logout' } });
    };

    if (!confirming) {
        return (
            <Button
                variant="outline"
                size="lg"
                onClick={() => {
                    setConfirming(true);
                }}
            >
                <LogOut aria-hidden="true" />
                {t('settings.logout')}
            </Button>
        );
    }
    return (
        <Alert variant="destructive" role="alertdialog" aria-label={t('settings.logout')}>
            <p>{t('settings.logoutConfirm')}</p>
            <UnsyncedWarning />
            <div className="mt-3 flex flex-wrap gap-2">
                <Button variant="destructive" disabled={busy} onClick={() => void confirm()}>
                    {t('settings.logoutConfirmAction')}
                </Button>
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => {
                        setConfirming(false);
                    }}
                >
                    {t('common.cancel')}
                </Button>
            </div>
        </Alert>
    );
}
