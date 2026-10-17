/** A "send to customer" waiting in the outbox (§9.2 "the UI says so"), or one the server refused. */
import type { ReactNode, Ref } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { sendErrorKey, type QueuedSend } from './useSendDocument.ts';

export interface QueuedSendNoticeProps {
    readonly queued: QueuedSend;
    readonly onRemove: () => void;
    /** Receives focus after the dialog closed (the page was inert while it was open). */
    readonly ref?: Ref<HTMLDivElement>;
}

const FOCUSABLE =
    'mt-3 outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring';

export function QueuedSendNotice({ queued, onRemove, ref }: QueuedSendNoticeProps): ReactNode {
    const t = useT();
    const email = queued.input.email;
    if (queued.state === 'pending') {
        return (
            <Alert ref={ref} tabIndex={-1} variant="info" className={FOCUSABLE}>
                {t('sync.send.queued', { email })}
            </Alert>
        );
    }
    const message = t(sendErrorKey(queued.lastError?.code ?? 'unknown'));
    return (
        <Alert
            ref={ref}
            tabIndex={-1}
            variant="destructive"
            className={`${FOCUSABLE} flex flex-col gap-2`}
        >
            <p>{t('sync.send.blocked', { email, message })}</p>
            <Button variant="outline" className="self-start" onClick={onRemove}>
                {t('sync.send.remove')}
            </Button>
        </Alert>
    );
}
