/** "Send to customer" (§9.2): button, dialog and the outcome of the last send. */
import type { FormDefinition, JsonObject } from '@fieldset/fieldset-js';
import { Send } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode, type Ref } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Dialog } from '../../components/ui/dialog.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { DocumentSend } from '../../lib/api/types.ts';
import { useDraftScope } from '../../hooks/useLocalDrafts.ts';
import { customerDefaults, type RecipientDraft } from '../../lib/send/recipient.ts';
import { QueuedSendNotice } from './QueuedSendNotice.tsx';
import { SendForm } from './SendForm.tsx';
import { useSendDocument } from './useSendDocument.ts';

export interface SendToCustomerProps {
    readonly uuid: string;
    /** Pinned version definition (roles `customer.email` / `customer.name`); may still load. */
    readonly definition: FormDefinition | undefined;
    readonly data: JsonObject;
}

interface SendResultProps {
    readonly result: DocumentSend;
    readonly ref: Ref<HTMLDivElement>;
}

function SendResult({ result, ref }: SendResultProps): ReactNode {
    const t = useT();
    const email = result.recipient.email;
    return (
        <Alert
            ref={ref}
            tabIndex={-1}
            variant="success"
            className="mt-3 outline-none focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
            {result.status === 'queued'
                ? t('send.queued', { email })
                : t('send.waiting', { email })}
        </Alert>
    );
}

export function SendToCustomer({ uuid, definition, data }: SendToCustomerProps): ReactNode {
    const t = useT();
    const state = useSendDocument(uuid, useDraftScope());
    const [open, setOpen] = useState(false);
    const defaults = useMemo(() => customerDefaults(definition, data), [definition, data]);
    /** Changes on every open and close: a late answer only acts on the dialog it was sent from. */
    const dialogRun = useRef(0);
    const resultMessage = useRef<HTMLDivElement>(null);
    const queuedMessage = useRef<HTMLDivElement>(null);
    const focusResult = useRef(false);

    const openDialog = (): void => {
        dialogRun.current += 1;
        state.clearProblem();
        setOpen(true);
    };
    const closeDialog = (): void => {
        dialogRun.current += 1;
        setOpen(false);
    };

    const submit = async (recipient: RecipientDraft): Promise<void> => {
        const run = dialogRun.current;
        await state.send(recipient, () => {
            if (dialogRun.current === run) {
                focusResult.current = true;
                closeDialog();
            }
        });
    };

    // The page is inert while the dialog is open, so the status region cannot speak then. Once the
    // dialog has closed (its cleanup ran first: page live again), focus carries the outcome to AT. The
    // outcome may render after the dialog closed (the send settles later): keep the request until then.
    useEffect(() => {
        if (open || !focusResult.current) {
            return;
        }
        const target = resultMessage.current ?? queuedMessage.current;
        if (target !== null) {
            focusResult.current = false;
            target.focus();
        }
    }, [open, state.result, state.queued]);

    return (
        <div className="flex flex-col">
            <Button size="lg" onClick={openDialog}>
                <Send aria-hidden="true" />
                {t('send.open')}
            </Button>
            {/* Always mounted: announces an outcome that arrives after the dialog was closed. */}
            <div role="status">
                {state.result === null ? null : (
                    <SendResult ref={resultMessage} result={state.result} />
                )}
                {state.queued === null || state.result !== null ? null : (
                    <QueuedSendNotice
                        ref={queuedMessage}
                        queued={state.queued}
                        busy={state.busy}
                        onRemove={() => void state.removeQueued()}
                        onRetry={() => void state.retryQueued()}
                    />
                )}
            </div>
            {/* A send that fails after the dialog was closed must not vanish silently. */}
            {!open && state.problem !== null ? (
                <Alert variant="destructive" role="alert" className="mt-3">
                    {t(state.problem)}
                </Alert>
            ) : null}
            <Dialog
                open={open}
                onClose={closeDialog}
                title={t('send.title')}
                description={t('send.description')}
            >
                <SendForm
                    defaults={defaults}
                    busy={state.busy}
                    problem={state.problem}
                    serverErrors={state.fieldErrors}
                    onSubmit={(recipient) => void submit(recipient)}
                    onCancel={closeDialog}
                />
            </Dialog>
        </div>
    );
}
