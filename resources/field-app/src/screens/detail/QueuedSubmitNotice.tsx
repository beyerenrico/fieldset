/** "Number pending" (§9.2): the submit waits in the outbox and is sent once online. */
import type { ReactNode } from 'react';
import { Alert, AlertTitle } from '../../components/ui/alert.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { OutboxError } from '../../lib/db/schema.ts';

export function QueuedSubmitNotice({
    lastError,
}: {
    readonly lastError: OutboxError | null;
}): ReactNode {
    const t = useT();
    return (
        <Alert variant="info" role="status">
            <AlertTitle>{t('sync.submitQueued.title')}</AlertTitle>
            <p>{t('sync.submitQueued.text')}</p>
            <p className="font-semibold">{t('submitted.numberPending')}</p>
            {lastError === null || lastError.code === 'network' ? null : (
                <p className="mt-1">
                    {t('sync.submitQueued.retry', { message: lastError.message })}
                </p>
            )}
        </Alert>
    );
}
