/**
 * Step 1 of the sign-in (ADR 0036 "Home-realm discovery"): the email decides between single sign-on and
 * a password. Errors are announced (role=alert) and described on the field.
 */
import { useId, useLayoutEffect, useRef, type ReactNode, type SubmitEvent } from 'react';
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
}

export function EmailForm({
    email,
    onEmailChange,
    onResult,
    notice = null,
    focusOnMount = false,
}: EmailFormProps): ReactNode {
    const t = useT();
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const { busy, error, run } = useDiscovery(onResult);
    const message = error ?? notice;

    useLayoutEffect(() => {
        if (focusOnMount) {
            input.current?.focus();
        }
    }, [focusOnMount]);

    const submit = (event: SubmitEvent): void => {
        event.preventDefault();
        if (!busy && email.trim() !== '') {
            void run(email);
        }
    };

    return (
        <form className="flex flex-col gap-4" onSubmit={submit} noValidate aria-busy={busy}>
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
                    aria-invalid={message !== null}
                    aria-describedby={message === null ? `${id}-hint` : `${id}-hint ${id}-error`}
                    onChange={(event) => {
                        onEmailChange(event.target.value);
                    }}
                />
                <p id={`${id}-hint`} className="text-sm text-muted-foreground">
                    {t('auth.emailHint')}
                </p>
            </div>
            <Button type="submit" size="lg" disabled={busy || email.trim() === ''}>
                {busy ? t('auth.checking') : t('auth.continue')}
            </Button>
        </form>
    );
}
