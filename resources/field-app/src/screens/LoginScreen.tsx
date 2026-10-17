/**
 * Email-first sign-in (ADR 0036 "Home-realm discovery"): the email decides between the organization's
 * central login and a password. An organization that enforces SSO is left for at once; otherwise the user
 * chooses. The identity provider returns to `?sso=ok` (complete the sign-in on this device) or
 * `?sso_error=<code>` (explain and start over; a password is offered when the IdP failed).
 */
import { getRouteApi } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { Alert } from '../components/ui/alert.tsx';
import { usePendingLogout } from '../hooks/useSession.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import type { SsoErrorCode } from '../lib/api/types.ts';
import type { AuthNotice } from '../lib/session/auth-events.ts';
import { CredentialsForm } from './login/CredentialsForm.tsx';
import { EmailForm } from './login/EmailForm.tsx';
import { ForeignDataPrompt } from './login/ForeignDataPrompt.tsx';
import { SsoChoice } from './login/SsoChoice.tsx';
import { SsoCompleting, SsoRedirect } from './login/SsoRedirect.tsx';
import { TwoFactorForm } from './login/TwoFactorForm.tsx';
import { useLoginCompletion, useSsoReturn } from './login/use-login-completion.ts';
import { useLoginSteps, type LoginSteps } from './login/use-login-steps.ts';

const route = getRouteApi('/login');

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

interface StepProps {
    readonly steps: LoginSteps;
    /** Runs after the credentials (and 2FA) or the SSO login were accepted. */
    readonly complete: () => Promise<void>;
    /** Opens the app (after the question about another user's data). */
    readonly enter: () => Promise<void>;
}

function EmailStep({
    steps,
    focus,
}: {
    readonly steps: LoginSteps;
    readonly focus: boolean;
}): ReactNode {
    const t = useT();
    return (
        <EmailForm
            email={steps.email}
            onEmailChange={steps.setEmail}
            onResult={steps.discovered}
            notice={steps.error === undefined ? null : t(`auth.ssoError.${steps.error}`)}
            focusOnMount={focus}
            onPassword={steps.offerPassword ? steps.choosePassword : undefined}
        />
    );
}

function PasswordStep({ steps, complete }: Omit<StepProps, 'enter'>): ReactNode {
    return (
        <CredentialsForm
            email={steps.email}
            onChangeEmail={steps.changeEmail}
            // "Continue with central login" after `sso_required`: the user asked for it, so leave.
            onDiscovered={(address, result) => {
                steps.discovered(address, result, 'leave');
            }}
            onDone={(twoFactor) => {
                if (twoFactor) {
                    steps.toStep({ kind: 'two-factor' });
                } else {
                    void complete();
                }
            }}
        />
    );
}

function LoginStep({ steps, complete, enter }: StepProps): ReactNode {
    const { step } = steps;
    switch (step.kind) {
        case 'email':
            return <EmailStep steps={steps} focus={step.focus} />;
        case 'password':
            return <PasswordStep steps={steps} complete={complete} />;
        case 'choice':
            return (
                <SsoChoice
                    email={steps.email}
                    organization={step.organization}
                    onContinue={() => {
                        steps.continueWith(step);
                    }}
                    onPassword={() => {
                        steps.choosePassword(steps.email);
                    }}
                    onChangeEmail={steps.changeEmail}
                />
            );
        case 'redirect':
            return (
                <SsoRedirect
                    organization={step.organization}
                    redirectUrl={step.redirectUrl}
                    onChangeEmail={steps.changeEmail}
                />
            );
        case 'completing':
            return <SsoCompleting />;
        case 'two-factor':
            return <TwoFactorForm onDone={() => void complete()} />;
        case 'foreign':
            return (
                <ForeignDataPrompt
                    userId={step.userId}
                    count={step.count}
                    onDone={() => void enter()}
                />
            );
    }
}

interface LoginFlowProps {
    readonly notice: AuthNotice | undefined;
    readonly sso: 'ok' | undefined;
    readonly ssoError: SsoErrorCode | undefined;
}

function LoginFlow({ notice, sso, ssoError }: LoginFlowProps): ReactNode {
    const t = useT();
    const steps = useLoginSteps(sso, ssoError);
    const { enter, complete } = useLoginCompletion(steps.onForeign);
    useSsoReturn(sso === 'ok', complete);

    const kind = steps.step.kind;
    const quiet = kind === 'foreign' || kind === 'completing' || kind === 'redirect';
    return (
        <main className="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-6 p-4">
            <div>
                <p className="text-lg font-semibold text-muted-foreground">{t('app.title')}</p>
                <h1 className="text-3xl font-bold">{t('auth.title')}</h1>
            </div>
            {quiet ? null : <LoginNotice notice={notice} />}
            <LoginStep steps={steps} complete={complete} enter={enter} />
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
