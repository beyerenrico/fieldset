import { QueryCache, QueryClient, QueryClientProvider, MutationCache } from '@tanstack/react-query';
import { RouterProvider, type RouterHistory } from '@tanstack/react-router';
import { useEffect, useState, type ReactNode } from 'react';
import { I18nProvider } from './i18n/I18nProvider.tsx';
import { ApiError, isApiError, isAuthLost } from './lib/api/errors.ts';
import { startCatalogSync } from './lib/catalog/catalog.ts';
import { disposeDraftSessions, saveAndDisposeDraftSessions } from './lib/drafts/draft-session.ts';
import { setCurrentUserId } from './lib/db/scope.ts';
import { loginUrl, onAccountEvent } from './lib/session/account.ts';
import { onAuthLost, reportAuthError } from './lib/session/auth-events.ts';
import {
    onLocalDataCleared,
    onLocalDataClearing,
    onSessionEnding,
} from './lib/session/local-data.ts';
import { onSessionRefreshed } from './lib/session/offline-start.ts';
import { handleOrganizationForbidden } from './lib/session/org-access.ts';
import { getOrganizationId } from './lib/session/org-store.ts';
import { resolveOrganization } from './lib/session/organization.ts';
import { startPendingLogoutSync } from './lib/session/pending-logout.ts';
import { hardReload } from './lib/session/reload.ts';
import { startSyncEngine, stopSyncEngine } from './lib/sync/engine.ts';
import { createAppRouter, type AppRouter } from './routes/router.tsx';

const MAX_RETRIES = 2;

/** No retries for 4xx answers or a lost session; transient failures retry twice. */
export function shouldRetry(failureCount: number, error: unknown): boolean {
    if (isAuthLost(error) || (error instanceof ApiError && error.status < 500)) {
        return false;
    }
    return failureCount < MAX_RETRIES;
}

/**
 * 403 `organization_forbidden` on a query: the membership may be gone (ADR 0035). Refetch `/me`,
 * purge that organization's rows, and wipe when no organization is left and device-status says so.
 */
function checkOrganizationAccess(error: unknown): void {
    const orgId = getOrganizationId();
    if (orgId === null || !isApiError(error, 403, 'organization_forbidden')) {
        return;
    }
    handleOrganizationForbidden(orgId).catch((failure: unknown) => {
        if (!reportAuthError(failure)) {
            console.warn('Checking the organization access failed', failure);
        }
    });
}

function onQueryError(error: unknown): void {
    if (!reportAuthError(error)) {
        checkOrganizationAccess(error);
    }
}

export function createAppQueryClient(): QueryClient {
    return new QueryClient({
        queryCache: new QueryCache({ onError: onQueryError }),
        mutationCache: new MutationCache({ onError: (error) => void reportAuthError(error) }),
        defaultOptions: {
            queries: { retry: shouldRetry, refetchOnWindowFocus: false },
            mutations: { retry: false },
        },
    });
}

export interface AppProps {
    readonly queryClient?: QueryClient;
    readonly history?: RouterHistory;
}

/**
 * Logout and wipe stop the engine without waiting for its run: a wipe often starts inside that run
 * (device-status), which would then wait for itself. The run stops before its next submission, and
 * without a current user the offline store refuses its writes.
 */
function stopEngine(): void {
    void stopSyncEngine();
}

/**
 * Sync engine, catalog refresh and a pending server sign-out. Logout and wipe stop this tab's engine
 * before anything is removed; it starts again (with no user, so idle) once the data is gone, ready
 * for the next sign-in without a reload.
 */
function useBackgroundWork(): void {
    useEffect(() => {
        let stopSync = startSyncEngine();
        const stopCatalog = startCatalogSync();
        const stopLogout = startPendingLogoutSync();
        const stopEnding = onSessionEnding(stopEngine);
        const stopRestart = onLocalDataCleared(() => {
            stopSync = startSyncEngine();
        });
        return () => {
            stopEnding();
            stopRestart();
            stopSync();
            stopCatalog();
            stopLogout();
        };
    }, []);
}

/** Deferred: clearing synchronously would cancel the request that is reporting the loss. */
function clearLater(queryClient: QueryClient): void {
    setTimeout(() => {
        queryClient.clear();
    }, 0);
}

/** Open drafts end without saving before the tables are emptied (a late flush would refill them). */
function useLocalDataEvents(queryClient: QueryClient): void {
    useEffect(() => {
        const stopClearing = onLocalDataClearing(disposeDraftSessions);
        const stopCleared = onLocalDataCleared(() => {
            disposeDraftSessions();
            clearLater(queryClient);
        });
        return () => {
            stopClearing();
            stopCleared();
        };
    }, [queryClient]);
}

function useSessionEvents(queryClient: QueryClient, router: AppRouter): void {
    useEffect(() => {
        // A refreshed session can lack the selected organization (membership deactivated).
        const stopRefreshed = onSessionRefreshed((me) => {
            queryClient.setQueryData(['me'], me);
            const previous = getOrganizationId();
            const organization = resolveOrganization(me);
            if (organization?.id !== previous) {
                queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'me' });
                void router.navigate({ to: organization === null ? '/org' : '/' });
            }
        });
        // Another tab signed out or wiped this device: drop what this tab holds and start over.
        const stopAccount = onAccountEvent((event) => {
            setCurrentUserId(null); // the offline store refuses this tab's writes from now on
            disposeDraftSessions();
            queryClient.clear();
            hardReload(loginUrl(event === 'wiped' ? 'wiped' : 'logout'));
        });
        const stopLost = onAuthLost((notice) => {
            // Expiry writes pending edits on this device first; a wipe ends them without writing.
            if (notice === 'reauth') {
                void saveAndDisposeDraftSessions();
            } else {
                disposeDraftSessions();
            }
            queryClient.removeQueries({ queryKey: ['me'] });
            clearLater(queryClient);
            void router.navigate({ to: '/login', search: notice === undefined ? {} : { notice } });
        });
        return () => {
            stopRefreshed();
            stopAccount();
            stopLost();
        };
    }, [queryClient, router]);
}

/**
 * Providers + router; session loss anywhere redirects to the login screen. Logout and wipe end open
 * drafts without saving, a lost session (reauth) saves them on this device first; every cached query
 * is dropped (the next user starts empty).
 */
export function App({ queryClient: given, history }: AppProps): ReactNode {
    const [queryClient] = useState(() => given ?? createAppQueryClient());
    const [router] = useState<AppRouter>(() => createAppRouter(queryClient, history));
    useBackgroundWork();
    useLocalDataEvents(queryClient);
    useSessionEvents(queryClient, router);

    return (
        <QueryClientProvider client={queryClient}>
            <I18nProvider>
                <RouterProvider router={router} />
            </I18nProvider>
        </QueryClientProvider>
    );
}
