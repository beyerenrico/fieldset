/** "Ready to send" (§9.2): the submit waits in the outbox and is sent once online. */
import type { ReactNode } from 'react';
import { Alert, AlertTitle } from '../../components/ui/alert.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { OutboxError } from '../../lib/db/schema.ts';
import { retryReasonKey } from './sync-reasons.ts';

export function QueuedSubmitNotice({
    lastError,
}: {
    readonly lastError: OutboxError | null;
}): ReactNode {
    const t = useT();
    const reason = retryReasonKey(lastError);
    // "Number pending" is shown once, below the heading.
    return (
        <Alert variant="info">
            <AlertTitle>{t('sync.submitQueued.title')}</AlertTitle>
            <p>{t('sync.submitQueued.text')}</p>
            {reason === null ? null : <p className="mt-1">{t(reason)}</p>}
        </Alert>
    );
}
