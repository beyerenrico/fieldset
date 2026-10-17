/**
 * Step 1 of the sign-in (ADR 0036 "Home-realm discovery"): the email decides between the organization's
 * central login and a password. Errors are announced (role=alert) and described on the field; the field
 * takes the focus while one is shown, so a refusal from the identity provider is read out on return.
 */
import {
    useId,
    useLayoutEffect,
    useRef,
    type ReactNode,
    type RefObject,
    type SubmitEvent,
} from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Input } from '../../components/ui/input.tsx';
import { Label } from '../../components/ui/label.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { useDiscovery, type DiscoveryHandler } from './use-discovery.ts';

export interface EmailFormProps {
    readonly email: string;
    readonly onEmailChange: (email: string) => void;
    readonly onResult: DiscoveryHandler;
    /** A message to show before the first attempt (e.g. a refused SSO login). */
    readonly notice?: string | null;
    /** Move focus to the email field (back from the password step). */
    readonly focusOnMount?: boolean;
    /** Offers "Sign in with a password" for the typed email (after a failed SSO login). */
    readonly onPassword?: ((email: string) => void) | undefined;
}

interface EmailFieldProps {
    readonly id: string;
    readonly input: RefObject<HTMLInputElement | null>;
    readonly email: string;
    readonly onEmailChange: (email: string) => void;
    /** The error (or notice) shown above the field and described on it. */
    readonly message: string | null;
}

function EmailField({ id, input, email, onEmailChange, message }: EmailFieldProps): ReactNode {
    const t = useT();
    const invalid = message !== null;
    return (
        <>
            {message === null ? null : (
                <Alert variant="destructive" role="alert" id={`${id}-error`}>
                    {message}
                </Alert>
            )}
            <div className="flex flex-col gap-2">
                <Label htmlFor={`${id}-email`}>{t('auth.email')}</Label>
                <Input
                    ref={input}
                    id={`${id}-email`}
                    type="email"
                    autoComplete="username"
                    inputMode="email"
                    required
                    value={email}
                    aria-invalid={invalid}
                    // The error comes first: it is what a screen reader must say on focus.
                    aria-describedby={invalid ? `${id}-error ${id}-hint` : `${id}-hint`}
                    onChange={(event) => {
                        onEmailChange(event.target.value);
                    }}
                />
                <p id={`${id}-hint`} className="text-sm text-muted-foreground">
                    {t('auth.emailHint')}
                </p>
            </div>
        </>
    );
}

interface PasswordOptionProps {
    readonly email: string;
    readonly disabled: boolean;
    readonly onPassword: (email: string) => void;
}

function PasswordOption({ email, disabled, onPassword }: PasswordOptionProps): ReactNode {
    const t = useT();
    return (
        <Button
            type="button"
            variant="outline"
            size="lg"
            disabled={disabled}
            onClick={() => {
                onPassword(email.trim());
            }}
        >
            {t('auth.usePassword')}
        </Button>
    );
}

export function EmailForm({
    email,
    onEmailChange,
    onResult,
    notice = null,
    focusOnMount = false,
    onPassword,
}: EmailFormProps): ReactNode {
    const t = useT();
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const { busy, error, run } = useDiscovery(onResult);
    const message = error ?? notice;
    const blank = email.trim() === '';

    useLayoutEffect(() => {
        if (focusOnMount || message !== null) {
            input.current?.focus();
        }
    }, [focusOnMount, message]);

    const submit = (event: SubmitEvent): void => {
        event.preventDefault();
        if (!busy && !blank) {
            void run(email);
        }
    };

    return (
        <form className="flex flex-col gap-4" onSubmit={submit} noValidate aria-busy={busy}>
            <EmailField
                id={id}
                input={input}
                email={email}
                onEmailChange={onEmailChange}
                message={message}
            />
            <Button type="submit" size="lg" disabled={busy || blank}>
                {busy ? t('auth.checking') : t('auth.continue')}
            </Button>
            {onPassword === undefined ? null : (
                <PasswordOption email={email} disabled={busy || blank} onPassword={onPassword} />
            )}
        </form>
    );
}
