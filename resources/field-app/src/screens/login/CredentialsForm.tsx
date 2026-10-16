import { useId, useState, type SubmitEvent, type ReactNode } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Input } from '../../components/ui/input.tsx';
import { Label } from '../../components/ui/label.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { login } from '../../lib/api/endpoints.ts';
import { authErrorMessage } from './errors.ts';

export function CredentialsForm({
    onDone,
}: {
    readonly onDone: (twoFactor: boolean) => void;
}): ReactNode {
    const t = useT();
    const id = useId();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: SubmitEvent): Promise<void> => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const result = await login({ email, password });
            onDone(result.two_factor);
        } catch (caught) {
            setError(authErrorMessage(caught, t, 'credentials'));
            setBusy(false);
        }
    };

    return (
        <form className="flex flex-col gap-4" onSubmit={(event) => void submit(event)} noValidate>
            {error === null ? null : (
                <Alert variant="destructive" role="alert" id={`${id}-error`}>
                    {error}
                </Alert>
            )}
            <div className="flex flex-col gap-2">
                <Label htmlFor={`${id}-email`}>{t('auth.email')}</Label>
                <Input
                    id={`${id}-email`}
                    type="email"
                    autoComplete="username"
                    inputMode="email"
                    required
                    value={email}
                    aria-invalid={error !== null}
                    onChange={(event) => {
                        setEmail(event.target.value);
                    }}
                />
            </div>
            <div className="flex flex-col gap-2">
                <Label htmlFor={`${id}-password`}>{t('auth.password')}</Label>
                <Input
                    id={`${id}-password`}
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    aria-invalid={error !== null}
                    onChange={(event) => {
                        setPassword(event.target.value);
                    }}
                />
            </div>
            <Button type="submit" size="lg" disabled={busy || email === '' || password === ''}>
                {busy ? t('auth.signingIn') : t('auth.submit')}
            </Button>
        </form>
    );
}
