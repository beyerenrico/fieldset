/** Settings: offline storage usage and whether the browser keeps it (§9.1, §9.2, ADR 0035). */
import { HardDrive } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card.tsx';
import { useI18n } from '../../i18n/I18nProvider.tsx';
import {
    estimate,
    formatBytes,
    isPersisted,
    type StorageEstimate,
} from '../../lib/storage/persistence.ts';

interface StorageState {
    readonly usage: StorageEstimate | null;
    readonly persisted: boolean | null;
}

export function StorageCard(): ReactNode {
    const { t, language } = useI18n();
    const [state, setState] = useState<StorageState | undefined>(undefined);

    useEffect(() => {
        let active = true;
        void Promise.all([estimate(), isPersisted()]).then(([usage, persisted]) => {
            if (active) {
                setState({ usage, persisted });
            }
        });
        return () => {
            active = false;
        };
    }, []);

    const persistedText =
        state?.persisted === true
            ? t('account.persisted')
            : state?.persisted === false
              ? t('account.notPersisted')
              : t('account.persistUnknown');

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
                        <p>
                            {state.usage === null
                                ? t('account.usageUnknown')
                                : t('account.usage', {
                                      used: formatBytes(state.usage.usage, language),
                                      quota: formatBytes(state.usage.quota, language),
                                  })}
                        </p>
                        <p className="text-muted-foreground">{persistedText}</p>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
