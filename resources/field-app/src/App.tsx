import { QueryCache, QueryClient, QueryClientProvider, MutationCache } from '@tanstack/react-query';
import { RouterProvider, type RouterHistory } from '@tanstack/react-router';
import { useEffect, useState, type ReactNode } from 'react';
import { I18nProvider } from './i18n/I18nProvider.tsx';
import { ApiError, isApiError, isAuthLost } from './lib/api/errors.ts';
import { startCatalogSync } from './lib/catalog/catalog.ts';
import { disposeDraftSessions, saveAndDisposeDraftSessions } from './lib/drafts/draft-session.ts';
import { loginUrl, onAccountEvent } from './lib/session/account.ts';
import { onAuthLost, reportAuthError } from './lib/session/auth-events.ts';
import { onLocalDataCleared, onLocalDataClearing } from './lib/session/local-data.ts';
import { onSessionRefreshed } from './lib/session/offline-start.ts';
import { handleOrganizationForbidden } from './lib/session/org-access.ts';
import { getOrganizationId } from './lib/session/org-store.ts';
import { resolveOrganization } from './lib/session/organization.ts';
import { hardReload } from './lib/session/reload.ts';
import { startSyncEngine } from './lib/sync/engine.ts';
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
 * Providers + router; session loss anywhere redirects to the login screen. Logout and wipe end open
 * drafts without saving, a lost session (reauth) saves them on this device first; every cached query
 * is dropped (the next user starts empty).
 */
export function App({ queryClient: given, history }: AppProps): ReactNode {
    const [queryClient] = useState(() => given ?? createAppQueryClient());
    const [router] = useState<AppRouter>(() => createAppRouter(queryClient, history));

    useEffect(() => {
        const stopSync = startSyncEngine();
        const stopCatalog = startCatalogSync();
        return () => {
            stopSync();
            stopCatalog();
        };
    }, []);

    useEffect(() => {
        // Deferred: clearing synchronously would cancel the request that is reporting the loss.
        const clearCache = (): void => {
            setTimeout(() => {
                queryClient.clear();
            }, 0);
        };
        // Open drafts end without saving before the tables are emptied (a late flush would refill them).
        const stopClearing = onLocalDataClearing(disposeDraftSessions);
        const stopCleared = onLocalDataCleared(() => {
            disposeDraftSessions();
            clearCache();
        });
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
            clearCache();
            void router.navigate({ to: '/login', search: notice === undefined ? {} : { notice } });
        });
        return () => {
            stopClearing();
            stopCleared();
            stopRefreshed();
            stopAccount();
            stopLost();
        };
    }, [queryClient, router]);

    return (
        <QueryClientProvider client={queryClient}>
            <I18nProvider>
                <RouterProvider router={router} />
            </I18nProvider>
        </QueryClientProvider>
    );
}
