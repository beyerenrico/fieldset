/**
 * Email-first sign-in (ADR 0036 "Home-realm discovery"): the email decides between the organization's
 * single sign-on and a password. The identity provider returns to `?sso=ok` (complete the sign-in on
 * this device) or `?sso_error=<code>` (explain and start over).
 */
import { getRouteApi } from '@tanstack/react-router';
import { useCallback, useState, type ReactNode } from 'react';
import { Alert } from '../components/ui/alert.tsx';
import { usePendingLogout } from '../hooks/useSession.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import type { DiscoveryResult, SsoErrorCode } from '../lib/api/types.ts';
import type { AuthNotice } from '../lib/session/auth-events.ts';
import { CredentialsForm } from './login/CredentialsForm.tsx';
import { EmailForm } from './login/EmailForm.tsx';
import { ForeignDataPrompt } from './login/ForeignDataPrompt.tsx';
import { SsoCompleting, SsoRedirect } from './login/SsoRedirect.tsx';
import { continueToSso } from './login/sso-redirect.ts';
import { TwoFactorForm } from './login/TwoFactorForm.tsx';
import { useLoginCompletion, useSsoReturn } from './login/use-login-completion.ts';

const route = getRouteApi('/login');

type Step =
    | { readonly kind: 'email'; readonly focus: boolean }
    | { readonly kind: 'password' }
    | { readonly kind: 'redirect'; readonly organization: string; readonly redirectUrl: string }
    | { readonly kind: 'completing' }
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

function useLoginSteps(sso: 'ok' | undefined, ssoError: SsoErrorCode | undefined) {
    const [step, setStep] = useState<Step>(
        sso === 'ok' ? { kind: 'completing' } : { kind: 'email', focus: false },
    );
    const [email, setEmail] = useState('');
    const [error, setError] = useState(ssoError);
    const onForeign = useCallback((userId: number, count: number) => {
        setStep({ kind: 'foreign', userId, count });
    }, []);
    const discovered = (address: string, result: DiscoveryResult): void => {
        setError(undefined);
        setEmail(address);
        if (result.mode === 'password') {
            setStep({ kind: 'password' });
            return;
        }
        // SSO: open drafts are saved and a pending sign-out is sent before the browser leaves.
        const redirectUrl = result.redirect_url;
        setStep({ kind: 'redirect', organization: result.organization, redirectUrl });
        void continueToSso(redirectUrl);
    };
    return { step, setStep, email, setEmail, error, onForeign, discovered };
}

interface LoginFlowProps {
    readonly notice: AuthNotice | undefined;
    readonly sso: 'ok' | undefined;
    readonly ssoError: SsoErrorCode | undefined;
}

function LoginFlow({ notice, sso, ssoError }: LoginFlowProps): ReactNode {
    const t = useT();
    const { step, setStep, email, setEmail, error, onForeign, discovered } = useLoginSteps(
        sso,
        ssoError,
    );
    const { enter, complete } = useLoginCompletion(onForeign);
    useSsoReturn(sso === 'ok', complete);

    const quiet = step.kind === 'foreign' || step.kind === 'completing' || step.kind === 'redirect';
    return (
        <main className="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-6 p-4">
            <div>
                <p className="text-lg font-semibold text-muted-foreground">{t('app.title')}</p>
                <h1 className="text-3xl font-bold">{t('auth.title')}</h1>
            </div>
            {quiet ? null : <LoginNotice notice={notice} />}
            {step.kind === 'email' ? (
                <EmailForm
                    email={email}
                    onEmailChange={setEmail}
                    onResult={discovered}
                    notice={error === undefined ? null : t(`auth.ssoError.${error}`)}
                    focusOnMount={step.focus}
                />
            ) : null}
            {step.kind === 'password' ? (
                <CredentialsForm
                    email={email}
                    onChangeEmail={() => {
                        setStep({ kind: 'email', focus: true });
                    }}
                    onDiscovered={discovered}
                    onDone={(twoFactor) => {
                        if (twoFactor) {
                            setStep({ kind: 'two-factor' });
                        } else {
                            void complete();
                        }
                    }}
                />
            ) : null}
            {step.kind === 'redirect' ? (
                <SsoRedirect
                    organization={step.organization}
                    redirectUrl={step.redirectUrl}
                    onChangeEmail={() => {
                        setStep({ kind: 'email', focus: true });
                    }}
                />
            ) : null}
            {step.kind === 'completing' ? <SsoCompleting /> : null}
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

/** A new SSO answer in the URL (or none, after a redirect back here) starts the flow afresh. */
export function LoginScreen(): ReactNode {
    const { notice, sso, sso_error: ssoError } = route.useSearch();
    return (
        <LoginFlow
            key={sso ?? ssoError ?? 'password'}
            notice={notice}
            sso={sso}
            ssoError={ssoError}
        />
    );
}
