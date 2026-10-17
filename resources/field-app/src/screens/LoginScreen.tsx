import { useQueryClient } from '@tanstack/react-query';
import { getRouteApi, useNavigate } from '@tanstack/react-router';
import { useState, type ReactNode } from 'react';
import { Alert } from '../components/ui/alert.tsx';
import { usePendingLogout } from '../hooks/useSession.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import type { AuthNotice } from '../lib/session/auth-events.ts';
import { requestSync } from '../lib/sync/triggers.ts';
import { completeLogin } from './login/complete-login.ts';
import { CredentialsForm } from './login/CredentialsForm.tsx';
import { ForeignDataPrompt } from './login/ForeignDataPrompt.tsx';
import { TwoFactorForm } from './login/TwoFactorForm.tsx';

const route = getRouteApi('/login');

type Step =
    | { readonly kind: 'credentials' }
    | { readonly kind: 'two-factor' }
    | { readonly kind: 'foreign'; readonly userId: number; readonly count: number };

/** The reason for this page (logout, reauth, wipe) and a server sign-out that is still pending. */
function LoginNotice({ notice }: { readonly notice: AuthNotice | undefined }): ReactNode {
    const t = useT();
    const logoutPending = usePendingLogout();
    if (notice === undefined && !logoutPending) {
        return null;
    }
    return (
        <Alert
            variant={notice === 'wiped' ? 'destructive' : 'info'}
            role="status"
            className="flex flex-col gap-2"
        >
            {notice === undefined ? null : t(`notice.${notice}`)}
            {/* The server still holds the session: say so until it is ended. */}
            {logoutPending ? <span>{t('account.logoutPending')}</span> : null}
        </Alert>
    );
}

export function LoginScreen(): ReactNode {
    const t = useT();
    const { notice } = route.useSearch();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [step, setStep] = useState<Step>({ kind: 'credentials' });

    // Reauth keeps the outbox: the sync resumes under the new session (ADR 0035).
    const enter = async (): Promise<void> => {
        requestSync('login');
        await navigate({ to: '/' });
    };

    const complete = async (): Promise<void> => {
        const outcome = await completeLogin(queryClient);
        if (outcome.userId !== null && outcome.foreignSubmissions > 0) {
            setStep({ kind: 'foreign', userId: outcome.userId, count: outcome.foreignSubmissions });
            return;
        }
        await enter();
    };

    return (
        <main className="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-6 p-4">
            <div>
                <p className="text-lg font-semibold text-muted-foreground">{t('app.title')}</p>
                <h1 className="text-3xl font-bold">{t('auth.title')}</h1>
            </div>
            {step.kind === 'foreign' ? null : <LoginNotice notice={notice} />}
            {step.kind === 'credentials' ? (
                <CredentialsForm
                    onDone={(twoFactor) => {
                        if (twoFactor) {
                            setStep({ kind: 'two-factor' });
                        } else {
                            void complete();
                        }
                    }}
                />
            ) : null}
            {step.kind === 'two-factor' ? <TwoFactorForm onDone={() => void complete()} /> : null}
            {step.kind === 'foreign' ? (
                <ForeignDataPrompt
                    userId={step.userId}
                    count={step.count}
                    onDone={() => void enter()}
                />
            ) : null}
        </main>
    );
}
