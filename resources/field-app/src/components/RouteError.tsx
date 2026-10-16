import { useRouter, type ErrorComponentProps } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';
import { NetworkError } from '../lib/api/errors.ts';
import { Alert, AlertTitle } from './ui/alert.tsx';
import { Button } from './ui/button.tsx';

export function ErrorState({
    error,
    onRetry,
}: {
    readonly error: unknown;
    readonly onRetry?: () => void;
}): ReactNode {
    const t = useT();
    return (
        <Alert variant="destructive" role="alert">
            <AlertTitle>
                {error instanceof NetworkError ? t('error.offline') : t('error.generic')}
            </AlertTitle>
            {onRetry === undefined ? null : (
                <Button variant="outline" className="mt-2" onClick={onRetry}>
                    {t('common.retry')}
                </Button>
            )}
        </Alert>
    );
}

export function RouteError({ error, reset }: ErrorComponentProps): ReactNode {
    const router = useRouter();
    return (
        <div className="mx-auto max-w-3xl p-4">
            <ErrorState
                error={error}
                onRetry={() => {
                    reset();
                    void router.invalidate();
                }}
            />
        </div>
    );
}

export function Loading(): ReactNode {
    const t = useT();
    return (
        <p role="status" className="py-8 text-center text-muted-foreground">
            {t('common.loading')}
        </p>
    );
}
