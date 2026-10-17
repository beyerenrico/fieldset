/**
 * Settings: offline storage usage and whether the browser keeps it (§9.1, §9.2, ADR 0035). It is
 * read again when the app becomes visible and after every sync run; above STORAGE_WARN_RATIO it
 * suggests syncing, and while storage is not persistent it offers to ask the browser to keep it.
 */
import { HardDrive } from 'lucide-react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { useSyncMeta } from '../../components/sync/useSyncStatus.ts';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card.tsx';
import { useI18n, type MessageKey } from '../../i18n/I18nProvider.tsx';
import {
    estimate,
    formatBytes,
    isPersisted,
    persist,
    type StorageEstimate,
} from '../../lib/storage/persistence.ts';

export const STORAGE_WARN_RATIO = 0.8;

interface StorageState {
    readonly usage: StorageEstimate | null;
    readonly persisted: boolean | null;
}

const readStorage = async (): Promise<StorageState> => {
    const [usage, persisted] = await Promise.all([estimate(), isPersisted()]);
    return { usage, persisted };
};

/** The storage state, re-read on visibility and whenever a sync run starts or ends. */
function useStorageState(): readonly [StorageState | undefined, () => void] {
    const [state, setState] = useState<StorageState | undefined>(undefined);
    const sync = useSyncMeta();
    const runKey = `${String(sync?.state)}:${String(sync?.lastRunAt)}`;
    const refresh = useCallback((): void => {
        void readStorage().then(setState);
    }, []);

    useEffect(() => {
        let active = true;
        void readStorage().then((next) => {
            if (active) {
                setState(next);
            }
        });
        return () => {
            active = false;
        };
    }, [runKey]);

    useEffect(() => {
        const onVisibility = (): void => {
            if (document.visibilityState === 'visible') {
                refresh();
            }
        };
        document.addEventListener('visibilitychange', onVisibility);
        return () => {
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [refresh]);

    return [state, refresh];
}

function persistedKey(persisted: boolean | null): MessageKey {
    if (persisted === true) {
        return 'account.persisted';
    }
    return persisted === false ? 'account.notPersisted' : 'account.persistUnknown';
}

function isAlmostFull(usage: StorageEstimate | null): boolean {
    return usage !== null && usage.quota > 0 && usage.usage / usage.quota > STORAGE_WARN_RATIO;
}

/** "Keep data on this device": asks the browser (persist()), then shows the answer. */
function KeepDataButton({ onDone }: { readonly onDone: () => void }): ReactNode {
    const { t } = useI18n();
    const [busy, setBusy] = useState(false);
    const [denied, setDenied] = useState(false);
    const keep = async (): Promise<void> => {
        setBusy(true);
        const granted = await persist();
        setBusy(false);
        setDenied(granted !== true);
        onDone();
    };
    return (
        <>
            <Button
                variant="outline"
                className="self-start"
                aria-disabled={busy ? true : undefined}
                onClick={() => {
                    if (!busy) {
                        void keep();
                    }
                }}
            >
                {t('account.keepData')}
            </Button>
            <p role="status" className="min-h-6">
                {denied ? t('account.persistDenied') : ''}
            </p>
        </>
    );
}

export function StorageCard(): ReactNode {
    const { t, language } = useI18n();
    const [state, refresh] = useStorageState();
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <HardDrive aria-hidden="true" className="size-5" />
                    {t('account.storage')}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {state === undefined ? null : (
                    <>
                        {isAlmostFull(state.usage) ? (
                            <Alert variant="warning" role="status">
                                {t('account.storageAlmostFull')}
                            </Alert>
                        ) : null}
                        <p>
                            {state.usage === null
                                ? t('account.usageUnknown')
                                : t('account.usage', {
                                      used: formatBytes(state.usage.usage, language),
                                      quota: formatBytes(state.usage.quota, language),
                                  })}
                        </p>
                        <p className="text-muted-foreground">{t(persistedKey(state.persisted))}</p>
                        {state.persisted === false ? <KeepDataButton onDone={refresh} /> : null}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
