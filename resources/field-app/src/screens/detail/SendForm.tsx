/** Recipient form inside the "Send to customer" dialog: errors next to the field plus a summary. */
import {
    useEffect,
    useId,
    useRef,
    useState,
    type ComponentProps,
    type ReactNode,
    type SubmitEvent,
} from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Input } from '../../components/ui/input.tsx';
import { Label } from '../../components/ui/label.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { MessageKey } from '../../i18n/en.ts';
import { isValidEmail, type RecipientDraft } from '../../lib/send/recipient.ts';
import type { FieldErrors } from './useSendDocument.ts';

export interface SendFormProps {
    readonly defaults: RecipientDraft;
    readonly busy: boolean;
    readonly problem: MessageKey | null;
    readonly serverErrors: FieldErrors;
    readonly onSubmit: (recipient: RecipientDraft) => void;
    readonly onCancel: () => void;
}

interface FieldProps extends ComponentProps<'input'> {
    readonly id: string;
    readonly label: string;
    readonly message: string | undefined;
}

function Field({ id, label, message, ...input }: FieldProps): ReactNode {
    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                autoComplete="off"
                aria-invalid={message !== undefined}
                aria-describedby={message === undefined ? undefined : `${id}-error`}
                {...input}
            />
            {message === undefined ? null : (
                <p id={`${id}-error`} className="font-semibold text-destructive">
                    {message}
                </p>
            )}
        </div>
    );
}

function emailError(value: string): MessageKey | undefined {
    const email = value.trim();
    if (email === '') {
        return 'send.emailRequired';
    }
    return isValidEmail(email) ? undefined : 'send.emailInvalid';
}

export function SendForm({
    defaults,
    busy,
    problem,
    serverErrors,
    onSubmit,
    onCancel,
}: SendFormProps): ReactNode {
    const t = useT();
    const id = useId();
    const emailInput = useRef<HTMLInputElement>(null);
    const [email, setEmail] = useState(defaults.email);
    const [name, setName] = useState(defaults.name);
    const [clientError, setClientError] = useState<MessageKey | undefined>(undefined);
    const emailMessage = clientError ?? serverErrors.email;

    // A rejected address from the server: take the user to the field, like the client check does.
    useEffect(() => {
        if (serverErrors.email !== undefined) {
            emailInput.current?.focus();
        }
    }, [serverErrors]);

    const submit = (event: SubmitEvent<HTMLFormElement>): void => {
        event.preventDefault();
        if (busy) {
            return;
        }
        const error = emailError(email);
        setClientError(error);
        if (error !== undefined) {
            emailInput.current?.focus();
            return;
        }
        onSubmit({ email, name });
    };

    return (
        <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
            {problem === null ? null : (
                <Alert variant="destructive" role="alert">
                    {t(problem)}
                </Alert>
            )}
            <Field
                ref={emailInput}
                id={`${id}-email`}
                label={t('send.email')}
                message={emailMessage === undefined ? undefined : t(emailMessage)}
                type="email"
                inputMode="email"
                autoCapitalize="none"
                spellCheck={false}
                required
                value={email}
                onChange={(event) => {
                    setEmail(event.target.value);
                    setClientError(undefined);
                }}
            />
            <Field
                id={`${id}-name`}
                label={t('send.name')}
                message={serverErrors.name === undefined ? undefined : t(serverErrors.name)}
                value={name}
                onChange={(event) => {
                    setName(event.target.value);
                }}
            />
            <div className="flex flex-wrap justify-end gap-2">
                <Button type="button" variant="outline" onClick={onCancel}>
                    {t('common.cancel')}
                </Button>
                {/* aria-disabled, not disabled: the focused button must keep focus inside the dialog. */}
                <Button type="submit" aria-disabled={busy}>
                    {busy ? t('send.sending') : t('send.submit')}
                </Button>
            </div>
        </form>
    );
}
