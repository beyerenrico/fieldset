/** A "send to customer" waiting in the outbox (§9.2 "the UI says so"), or one the server refused. */
import type { ReactNode, Ref } from 'react';
import { Alert, AlertTitle } from '../../components/ui/alert.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { PanelButton } from '../review/PanelButton.tsx';
import { sendReasonKey } from './sync-reasons.ts';
import type { QueuedSend } from './useSendDocument.ts';

export interface QueuedSendNoticeProps {
    readonly queued: QueuedSend;
    /** "Cancel sending". */
    readonly onRemove: () => void;
    /** "Try again" (same recipient, same key). */
    readonly onRetry: () => void;
    readonly busy: boolean;
    /** Receives focus after the dialog closed (the page was inert while it was open). */
    readonly ref?: Ref<HTMLDivElement>;
}

const FOCUSABLE =
    'mt-3 outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring';

/** Answers a retry cannot change: the address was refused, or the submission is gone. */
const FINAL: ReadonlySet<string> = new Set(['validation_failed', 'not_found']);

export function QueuedSendNotice({
    queued,
    onRemove,
    onRetry,
    busy,
    ref,
}: QueuedSendNoticeProps): ReactNode {
    const t = useT();
    const email = queued.input.email;
    if (queued.state === 'pending') {
        return (
            <Alert ref={ref} tabIndex={-1} variant="info" className={FOCUSABLE}>
                {t('sync.send.queued', { email })}
            </Alert>
        );
    }
    const retry = !FINAL.has(queued.lastError?.code ?? '');
    return (
        <Alert ref={ref} tabIndex={-1} variant="destructive" className={FOCUSABLE}>
            <AlertTitle>{t('sync.send.blocked', { email })}</AlertTitle>
            <p>{t(sendReasonKey(queued.lastError))}</p>
            <div className="mt-3 flex flex-wrap gap-2">
                {retry ? (
                    <PanelButton busy={busy} onClick={onRetry}>
                        {t('sync.action.retry')}
                    </PanelButton>
                ) : null}
                <PanelButton variant={retry ? 'outline' : 'default'} busy={busy} onClick={onRemove}>
                    {t('sync.send.remove')}
                </PanelButton>
            </div>
        </Alert>
    );
}
