import { useId, useState, type SubmitEvent, type ReactNode } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Input } from '../../components/ui/input.tsx';
import { Label } from '../../components/ui/label.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { twoFactorChallenge } from '../../lib/api/endpoints.ts';
import { authErrorMessage } from './errors.ts';

export function TwoFactorForm({ onDone }: { readonly onDone: () => void }): ReactNode {
    const t = useT();
    const id = useId();
    const [recovery, setRecovery] = useState(false);
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: SubmitEvent): Promise<void> => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const value = code.trim();
            await twoFactorChallenge(recovery ? { recovery_code: value } : { code: value });
            onDone();
        } catch (caught) {
            setError(authErrorMessage(caught, t, 'code'));
            setBusy(false);
        }
    };

    return (
        <form className="flex flex-col gap-4" onSubmit={(event) => void submit(event)} noValidate>
            <h2 className="text-xl font-bold">{t('auth.twoFactorTitle')}</h2>
            <p id={`${id}-hint`}>{recovery ? t('auth.recoveryHint') : t('auth.twoFactorHint')}</p>
            {error === null ? null : (
                <Alert variant="destructive" role="alert">
                    {error}
                </Alert>
            )}
            <div className="flex flex-col gap-2">
                <Label htmlFor={`${id}-code`}>
                    {recovery ? t('auth.recoveryCode') : t('auth.code')}
                </Label>
                <Input
                    id={`${id}-code`}
                    autoComplete="one-time-code"
                    inputMode={recovery ? 'text' : 'numeric'}
                    aria-describedby={`${id}-hint`}
                    aria-invalid={error !== null}
                    value={code}
                    onChange={(event) => {
                        setCode(event.target.value);
                    }}
                />
            </div>
            <Button type="submit" size="lg" disabled={busy || code.trim() === ''}>
                {t('auth.verify')}
            </Button>
            <Button
                type="button"
                variant="link"
                onClick={() => {
                    setRecovery(!recovery);
                    setCode('');
                    setError(null);
                }}
            >
                {recovery ? t('auth.useCode') : t('auth.useRecovery')}
            </Button>
        </form>
    );
}
