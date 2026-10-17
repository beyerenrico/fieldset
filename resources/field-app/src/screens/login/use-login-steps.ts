/**
 * The steps of the email-first sign-in (ADR 0036 "Home-realm discovery"). Discovery answers "password"
 * (the password step) or "sso": an organization that enforces SSO is left for at once; one that does not
 * offers its central login next to a password. Leaving can be cancelled until the browser goes.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { DiscoveryResult, SsoErrorCode } from '../../lib/api/types.ts';
import { continueToSso } from './sso-redirect.ts';

export interface SsoTarget {
    readonly organization: string;
    readonly redirectUrl: string;
}

export type Step =
    | { readonly kind: 'email'; readonly focus: boolean }
    | { readonly kind: 'password' }
    | ({ readonly kind: 'choice' } & SsoTarget)
    | ({ readonly kind: 'redirect' } & SsoTarget)
    | { readonly kind: 'completing' }
    | { readonly kind: 'two-factor' }
    | { readonly kind: 'foreign'; readonly userId: number; readonly count: number };

/** An "sso" answer offers the choice, unless SSO is enforced or the user already asked for it. */
export type SsoEntry = 'offer' | 'leave';

/** SSO returns after which a password may still work (the organization's IdP failed, not the user). */
const PASSWORD_FALLBACK: ReadonlySet<SsoErrorCode> = new Set([
    'sso_failed',
    'provider_unavailable',
]);

export interface LoginSteps {
    readonly step: Step;
    readonly email: string;
    readonly setEmail: (email: string) => void;
    /** The refused SSO login this page was opened with, until the next attempt. */
    readonly error: SsoErrorCode | undefined;
    /** Offer "Sign in with a password" on the email step (after a failed SSO login). */
    readonly offerPassword: boolean;
    readonly discovered: (email: string, result: DiscoveryResult, entry?: SsoEntry) => void;
    readonly continueWith: (target: SsoTarget) => void;
    readonly choosePassword: (email: string) => void;
    readonly changeEmail: () => void;
    readonly toStep: (step: Step) => void;
    readonly onForeign: (userId: number, count: number) => void;
}

/** Sends the browser to the IdP; a newer redirect, cancel() or unmounting stops the pending one. */
function useLeaving(): { readonly leave: (url: string) => void; readonly cancel: () => void } {
    const pending = useRef<AbortController | null>(null);
    const cancel = useCallback(() => {
        pending.current?.abort();
        pending.current = null;
    }, []);
    useEffect(() => cancel, [cancel]);
    const leave = useCallback(
        (url: string) => {
            cancel();
            const controller = new AbortController();
            pending.current = controller;
            void continueToSso(url, controller.signal);
        },
        [cancel],
    );
    return { leave, cancel };
}

interface StepState {
    readonly step: Step;
    readonly toStep: (step: Step) => void;
    readonly continueWith: (target: SsoTarget) => void;
    readonly onForeign: (userId: number, count: number) => void;
}

/** The current step; moving to another step cancels a redirect that has not left yet. */
function useStep(initial: Step): StepState {
    const [step, setStep] = useState<Step>(initial);
    const { leave, cancel } = useLeaving();
    const toStep = useCallback(
        (next: Step) => {
            cancel();
            setStep(next);
        },
        [cancel],
    );
    const continueWith = useCallback(
        (target: SsoTarget) => {
            const { organization, redirectUrl } = target;
            setStep({ kind: 'redirect', organization, redirectUrl });
            // Open drafts are saved and a pending sign-out is sent before the browser leaves.
            leave(redirectUrl);
        },
        [leave],
    );
    const onForeign = useCallback(
        (userId: number, count: number) => {
            toStep({ kind: 'foreign', userId, count });
        },
        [toStep],
    );
    return { step, toStep, continueWith, onForeign };
}

export function useLoginSteps(
    sso: 'ok' | undefined,
    ssoError: SsoErrorCode | undefined,
): LoginSteps {
    const { step, toStep, continueWith, onForeign } = useStep(
        sso === 'ok' ? { kind: 'completing' } : { kind: 'email', focus: false },
    );
    const [email, setEmail] = useState('');
    const [error, setError] = useState(ssoError);
    const choosePassword = (address: string): void => {
        setError(undefined);
        setEmail(address);
        toStep({ kind: 'password' });
    };
    const discovered = (address: string, result: DiscoveryResult, entry: SsoEntry = 'offer') => {
        if (result.mode === 'password') {
            choosePassword(address);
            return;
        }
        setError(undefined);
        setEmail(address);
        const target = { organization: result.organization, redirectUrl: result.redirect_url };
        if (result.enforced || entry === 'leave') {
            continueWith(target);
        } else {
            toStep({ kind: 'choice', ...target });
        }
    };
    return {
        step,
        email,
        setEmail,
        error,
        offerPassword: error !== undefined && PASSWORD_FALLBACK.has(error),
        discovered,
        continueWith,
        choosePassword,
        changeEmail: () => {
            toStep({ kind: 'email', focus: true });
        },
        toStep,
        onForeign,
    };
}
