/** Install button (Chromium) or Add-to-Home-Screen instructions and data warning (iOS). */
import { Download, Share } from 'lucide-react';
import { useState, useSyncExternalStore, type ReactNode } from 'react';
import { useOrganizationId } from '../../hooks/useSession.ts';
import { useT } from '../../i18n/I18nProvider.tsx';
import { useLiveQuery } from '../../lib/db/live.ts';
import { currentScope } from '../../lib/db/scope.ts';
import { unsyncedSummary } from '../../lib/outbox/outbox.ts';
import {
    chooseInstallOffer,
    dismissInstall,
    hideIosWarning,
    isInstallDismissed,
    isIos,
    isIosWarningHidden,
    isStandalone,
    needsIosDataWarning,
    readPlatform,
    type PlatformInfo,
} from '../../lib/pwa/install.ts';
import {
    getInstallPrompt,
    promptInstall,
    subscribeInstallPrompt,
} from '../../lib/pwa/install-prompt.ts';
import { Alert } from '../ui/alert.tsx';
import { Button } from '../ui/button.tsx';

/** Submissions not synced of the signed-in user; only queried where the iOS warning can apply. */
function useUnsynced(enabled: boolean): number {
    const org = useOrganizationId();
    const scope = currentScope();
    return (
        useLiveQuery(
            async () =>
                enabled && scope !== null ? (await unsyncedSummary(scope)).submissions : 0,
            [enabled, org, scope?.userId],
        ) ?? 0
    );
}

function IosDataWarning({
    count,
    onHide,
}: {
    readonly count: number;
    readonly onHide: () => void;
}): ReactNode {
    const t = useT();
    return (
        <Alert variant="warning" role="status" className="flex flex-col gap-2">
            <p>{t('pwa.ios.warning', { count })}</p>
            <p className="flex items-start gap-2">
                <Share aria-hidden="true" className="mt-0.5 size-5 shrink-0" />
                {t('pwa.install.ios')}
            </p>
            <Button variant="outline" className="self-start" onClick={onHide}>
                {t('pwa.ios.hide')}
            </Button>
        </Alert>
    );
}

function InstallOffer({
    ios,
    onDismiss,
}: {
    readonly ios: boolean;
    readonly onDismiss: () => void;
}): ReactNode {
    const t = useT();
    return (
        <Alert variant="info" role="status" className="flex flex-wrap items-center gap-2">
            <p className="flex min-w-0 flex-1 items-start gap-2">
                {ios ? <Share aria-hidden="true" className="mt-0.5 size-5 shrink-0" /> : null}
                {t(ios ? 'pwa.install.ios' : 'pwa.install.text')}
            </p>
            <span className="flex gap-2">
                {ios ? null : (
                    <Button onClick={() => void promptInstall()}>
                        <Download aria-hidden="true" />
                        {t('pwa.install')}
                    </Button>
                )}
                <Button variant="outline" onClick={onDismiss}>
                    {t('pwa.install.dismiss')}
                </Button>
            </span>
        </Alert>
    );
}

export function InstallPrompt({ platform }: { readonly platform?: PlatformInfo }): ReactNode {
    const [info] = useState(() => platform ?? readPlatform());
    const deferred = useSyncExternalStore(subscribeInstallPrompt, getInstallPrompt);
    const [dismissed, setDismissed] = useState(isInstallDismissed);
    const [warningHidden, setWarningHidden] = useState(isIosWarningHidden);
    const unsynced = useUnsynced(isIos(info) && !isStandalone(info) && !warningHidden);
    const offer = chooseInstallOffer({ platform: info, canPrompt: deferred !== null, dismissed });

    if (!warningHidden && needsIosDataWarning(info, unsynced)) {
        return (
            <IosDataWarning
                count={unsynced}
                onHide={() => {
                    hideIosWarning();
                    setWarningHidden(true);
                }}
            />
        );
    }
    if (offer === 'none') {
        return null;
    }
    return (
        <InstallOffer
            ios={offer === 'ios'}
            onDismiss={() => {
                dismissInstall();
                setDismissed(true);
            }}
        />
    );
}
