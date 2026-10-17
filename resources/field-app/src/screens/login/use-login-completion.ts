/**
 * Finishing a sign-in, for passwords and for the return from SSO alike (ADR 0035 "Reauth", ADR 0036):
 * completeLogin() registers the device and loads `me`; unsynced rows of another user ask first; then the
 * app opens and the sync resumes (a reauth keeps the outbox).
 */
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useCallback, useEffect, useRef } from 'react';
import { requestSync } from '../../lib/sync/triggers.ts';
import { completeLogin } from './complete-login.ts';

export type ForeignHandler = (userId: number, count: number) => void;

export interface LoginCompletion {
    /** Opens the app and asks for a sync. */
    readonly enter: () => Promise<void>;
    /** Runs after the credentials (and 2FA) or the SSO login were accepted. */
    readonly complete: () => Promise<void>;
}

export function useLoginCompletion(onForeign: ForeignHandler): LoginCompletion {
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    const enter = useCallback(async (): Promise<void> => {
        requestSync('login');
        await navigate({ to: '/' });
    }, [navigate]);

    const complete = useCallback(async (): Promise<void> => {
        const outcome = await completeLogin(queryClient);
        if (outcome.userId !== null && outcome.foreignSubmissions > 0) {
            onForeign(outcome.userId, outcome.foreignSubmissions);
            return;
        }
        await enter();
    }, [enter, onForeign, queryClient]);

    return { enter, complete };
}

/** `?sso=ok`: the identity provider signed the user in; complete it once (StrictMode runs effects twice). */
export function useSsoReturn(active: boolean, complete: () => Promise<void>): void {
    const started = useRef(false);
    useEffect(() => {
        if (!active || started.current) {
            return;
        }
        started.current = true;
        void complete();
    }, [active, complete]);
}
