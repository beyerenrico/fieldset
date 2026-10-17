/** "New version" and "reload this tab" banners (ADR 0035). Announced politely; focus stays put. */
import { RefreshCw } from 'lucide-react';
import { useSyncExternalStore, type ReactNode } from 'react';
import { useT } from '../../i18n/I18nProvider.tsx';
import {
    applyUpdate,
    dismissUpdate,
    getUpdateState,
    subscribeUpdate,
} from '../../lib/pwa/sw-update.ts';
import { Alert } from '../ui/alert.tsx';
import { Button } from '../ui/button.tsx';

export function useUpdateState() {
    return useSyncExternalStore(subscribeUpdate, getUpdateState);
}

export function DatabaseBanner({ reload }: { readonly reload: () => void }): ReactNode {
    const t = useT();
    const { database } = useUpdateState();
    if (database === null) {
        return null;
    }
    return (
        <Alert variant="warning" role="alert" className="flex flex-wrap items-center gap-2">
            <p className="min-w-0 flex-1">
                {t(database === 'blocked' ? 'pwa.db.blocked' : 'pwa.db.outdated')}
            </p>
            <Button variant="outline" onClick={reload}>
                <RefreshCw aria-hidden="true" />
                {t('pwa.reload')}
            </Button>
        </Alert>
    );
}

export function UpdateBanner(): ReactNode {
    const t = useT();
    const { needRefresh, updating } = useUpdateState();
    if (!needRefresh) {
        return null;
    }
    return (
        <Alert variant="info" role="status" className="flex flex-wrap items-center gap-2">
            <p className="min-w-0 flex-1">{t('pwa.update.available')}</p>
            <span className="flex gap-2">
                <Button
                    variant="outline"
                    // aria-disabled keeps focus on the button while the update runs.
                    aria-disabled={updating ? true : undefined}
                    onClick={() => void applyUpdate()}
                >
                    {t(updating ? 'pwa.update.applying' : 'pwa.update.apply')}
                </Button>
                {updating ? null : (
                    <Button variant="outline" onClick={dismissUpdate}>
                        {t('pwa.update.later')}
                    </Button>
                )}
            </span>
        </Alert>
    );
}
