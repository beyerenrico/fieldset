/**
 * A form version that could not be loaded (fill, review, detail). Offline without a cached copy the
 * catalog raises NotCachedError (ADR 0035): say so instead of the generic error. Both offer a retry.
 */
import type { ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';
import { NotCachedError } from '../lib/catalog/catalog.ts';
import { ErrorState } from './RouteError.tsx';
import { Alert } from './ui/alert.tsx';
import { Button } from './ui/button.tsx';

export function VersionLoadError({
    error,
    onRetry,
}: {
    readonly error: unknown;
    readonly onRetry: () => void;
}): ReactNode {
    const t = useT();
    if (!(error instanceof NotCachedError)) {
        return <ErrorState error={error} onRetry={onRetry} />;
    }
    return (
        <Alert variant="warning" role="alert">
            <p>{t('catalog.versionNotCached')}</p>
            <Button variant="outline" className="mt-2" onClick={onRetry}>
                {t('common.retry')}
            </Button>
        </Alert>
    );
}
