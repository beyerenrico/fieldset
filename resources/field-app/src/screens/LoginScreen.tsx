import { useQueryClient } from '@tanstack/react-query';
import { getRouteApi, useNavigate } from '@tanstack/react-router';
import { useState, type ReactNode } from 'react';
import { Alert } from '../components/ui/alert.tsx';
import { useT } from '../i18n/I18nProvider.tsx';
import { ensureDeviceRegistered } from '../lib/device/register.ts';
import { disposeDraftSessions } from '../lib/drafts/draft-session.ts';
import { resetAuthNotice } from '../lib/session/auth-events.ts';
import { CredentialsForm } from './login/CredentialsForm.tsx';
import { TwoFactorForm } from './login/TwoFactorForm.tsx';

const route = getRouteApi('/login');

export function LoginScreen(): ReactNode {
    const t = useT();
    const { notice } = route.useSearch();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [step, setStep] = useState<'credentials' | 'two-factor'>('credentials');

    const complete = async (): Promise<void> => {
        try {
            await ensureDeviceRegistered();
        } catch (error) {
            // Login still works; device-status recovery is unavailable until the next login.
            console.warn('Device registration failed', error);
        }
        // Nothing cached or open from a previous session may reach this user.
        resetAuthNotice();
        disposeDraftSessions();
        queryClient.clear();
        await navigate({ to: '/' });
    };

    return (
        <main className="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-6 p-4">
            <div>
                <p className="text-lg font-semibold text-muted-foreground">{t('app.title')}</p>
                <h1 className="text-3xl font-bold">{t('auth.title')}</h1>
            </div>
            {notice === undefined ? null : (
                <Alert variant={notice === 'wiped' ? 'destructive' : 'info'} role="status">
                    {t(`notice.${notice}`)}
                </Alert>
            )}
            {step === 'credentials' ? (
                <CredentialsForm
                    onDone={(twoFactor) => {
                        if (twoFactor) {
                            setStep('two-factor');
                        } else {
                            void complete();
                        }
                    }}
                />
            ) : (
                <TwoFactorForm onDone={() => void complete()} />
            )}
        </main>
    );
}
