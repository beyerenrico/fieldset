/** "Send to customer" (§9.2): button, dialog and the outcome of the last send. */
import type { FormDefinition, JsonObject } from '@form-schema-app/form-schema-js';
import { Send } from 'lucide-react';
import { useMemo, useRef, useState, type ReactNode } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Dialog } from '../../components/ui/dialog.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { DocumentSend } from '../../lib/api/types.ts';
import { customerDefaults, type RecipientDraft } from '../../lib/send/recipient.ts';
import { SendForm } from './SendForm.tsx';
import { useSendDocument } from './useSendDocument.ts';

export interface SendToCustomerProps {
    readonly uuid: string;
    /** Pinned version definition (roles `customer.email` / `customer.name`); may still load. */
    readonly definition: FormDefinition | undefined;
    readonly data: JsonObject;
}

function SendResult({ result }: { readonly result: DocumentSend }): ReactNode {
    const t = useT();
    const email = result.recipient.email;
    return (
        <Alert variant="success" className="mt-3">
            {result.status === 'queued'
                ? t('send.queued', { email })
                : t('send.waiting', { email })}
        </Alert>
    );
}

export function SendToCustomer({ uuid, definition, data }: SendToCustomerProps): ReactNode {
    const t = useT();
    const state = useSendDocument(uuid);
    const [open, setOpen] = useState(false);
    const defaults = useMemo(() => customerDefaults(definition, data), [definition, data]);
    /** Counts dialog openings: a late answer must not close a dialog reopened meanwhile. */
    const opened = useRef(0);

    const submit = async (recipient: RecipientDraft): Promise<void> => {
        const opening = opened.current;
        if ((await state.send(recipient)) && opened.current === opening) {
            setOpen(false);
        }
    };

    return (
        <div className="flex flex-col">
            <Button
                size="lg"
                onClick={() => {
                    opened.current += 1;
                    state.clearProblem();
                    setOpen(true);
                }}
            >
                <Send aria-hidden="true" />
                {t('send.open')}
            </Button>
            {/* Always mounted, so screen readers announce the outcome. */}
            <div role="status">
                {state.result === null ? null : <SendResult result={state.result} />}
            </div>
            {/* A send that fails after the dialog was closed must not vanish silently. */}
            {!open && state.problem !== null ? (
                <Alert variant="destructive" role="alert" className="mt-3">
                    {t(state.problem)}
                </Alert>
            ) : null}
            <Dialog
                open={open}
                onClose={() => {
                    setOpen(false);
                }}
                title={t('send.title')}
                description={t('send.description')}
            >
                <SendForm
                    defaults={defaults}
                    busy={state.busy}
                    problem={state.problem}
                    serverErrors={state.fieldErrors}
                    onSubmit={(recipient) => void submit(recipient)}
                    onCancel={() => {
                        setOpen(false);
                    }}
                />
            </Dialog>
        </div>
    );
}
