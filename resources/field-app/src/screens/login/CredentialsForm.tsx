/**
 * Step 2 of the sign-in: the password for the email from step 1 (kept as a read-only username field
 * for password managers). An organization that enforces SSO refuses it (422 `sso_required`); the user
 * can then run discovery again and continue with single sign-on.
 */
import { useId, useLayoutEffect, useRef, useState, type ReactNode, type SubmitEvent } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Input } from '../../components/ui/input.tsx';
import { Label } from '../../components/ui/label.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { login } from '../../lib/api/endpoints.ts';
import { flushPendingLogout } from '../../lib/session/pending-logout.ts';
import { authErrorMessage, isSsoRequired } from './errors.ts';
import { useDiscovery, type DiscoveryHandler } from './use-discovery.ts';

export interface CredentialsFormProps {
    readonly email: string;
    readonly onDone: (twoFactor: boolean) => void;
    /** Back to step 1 to type another email. */
    readonly onChangeEmail: () => void;
    /** Discovery ran again after `sso_required`. */
    readonly onDiscovered: DiscoveryHandler;
}

type Failure = { readonly kind: 'message'; readonly text: string } | { readonly kind: 'sso' };

function SsoRequired({
    email,
    onDiscovered,
}: Pick<CredentialsFormProps, 'email' | 'onDiscovered'>): ReactNode {
    const t = useT();
    const { busy, error, run } = useDiscovery(onDiscovered);
    return (
        <Alert variant="destructive" role="alert" className="flex flex-col gap-3">
            <span>{t('auth.ssoRequired')}</span>
            {error === null ? null : <span>{error}</span>}
            <Button
                type="button"
                variant="outline"
                aria-disabled={busy ? true : undefined}
                onClick={() => {
                    if (!busy) {
                        void run(email);
                    }
                }}
            >
                {busy ? t('auth.checking') : t('auth.ssoContinueGeneric')}
            </Button>
        </Alert>
    );
}

export function CredentialsForm({
    email,
    onDone,
    onChangeEmail,
    onDiscovered,
}: CredentialsFormProps): ReactNode {
    const t = useT();
    const id = useId();
    const passwordInput = useRef<HTMLInputElement>(null);
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState<Failure | null>(null);

    useLayoutEffect(() => {
        passwordInput.current?.focus();
    }, []);

    const submit = async (event: SubmitEvent): Promise<void> => {
        event.preventDefault();
        setBusy(true);
        setFailure(null);
        try {
            // An unconfirmed sign-out ends the old session first (it could block the new login).
            await flushPendingLogout();
            const result = await login({ email, password });
            onDone(result.two_factor);
        } catch (caught) {
            setFailure(
                isSsoRequired(caught)
                    ? { kind: 'sso' }
                    : { kind: 'message', text: authErrorMessage(caught, t, 'credentials') },
            );
            setBusy(false);
        }
    };

    return (
        <form className="flex flex-col gap-4" onSubmit={(event) => void submit(event)} noValidate>
            {failure?.kind === 'sso' ? (
                <SsoRequired email={email} onDiscovered={onDiscovered} />
            ) : null}
            {failure?.kind === 'message' ? (
                <Alert variant="destructive" role="alert" id={`${id}-error`}>
                    {failure.text}
                </Alert>
            ) : null}
            <div className="flex flex-col gap-2">
                <Label htmlFor={`${id}-email`}>{t('auth.email')}</Label>
                <Input
                    id={`${id}-email`}
                    type="email"
                    autoComplete="username"
                    value={email}
                    readOnly
                    className="bg-muted"
                />
                <Button
                    type="button"
                    variant="link"
                    className="self-start px-0"
                    onClick={onChangeEmail}
                >
                    {t('auth.changeEmail')}
                </Button>
            </div>
            <div className="flex flex-col gap-2">
                <Label htmlFor={`${id}-password`}>{t('auth.password')}</Label>
                <Input
                    ref={passwordInput}
                    id={`${id}-password`}
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    aria-invalid={failure?.kind === 'message'}
                    aria-describedby={failure?.kind === 'message' ? `${id}-error` : undefined}
                    onChange={(event) => {
                        setPassword(event.target.value);
                    }}
                />
            </div>
            <Button type="submit" size="lg" disabled={busy || password === ''}>
                {busy ? t('auth.signingIn') : t('auth.submit')}
            </Button>
        </form>
    );
}
