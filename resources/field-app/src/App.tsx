import { QueryCache, QueryClient, QueryClientProvider, MutationCache } from '@tanstack/react-query';
import { RouterProvider, type RouterHistory } from '@tanstack/react-router';
import { useEffect, useState, type ReactNode } from 'react';
import { I18nProvider } from './i18n/I18nProvider.tsx';
import { ApiError, isAuthLost } from './lib/api/errors.ts';
import { disposeDraftSessions } from './lib/drafts/draft-session.ts';
import { onAuthLost, reportAuthError } from './lib/session/auth-events.ts';
import { onLocalDataCleared } from './lib/session/local-data.ts';
import { createAppRouter, type AppRouter } from './routes/router.tsx';

const MAX_RETRIES = 2;

/** No retries for 4xx answers or a lost session; transient failures retry twice. */
export function shouldRetry(failureCount: number, error: unknown): boolean {
    if (isAuthLost(error) || (error instanceof ApiError && error.status < 500)) {
        return false;
    }
    return failureCount < MAX_RETRIES;
}

export function createAppQueryClient(): QueryClient {
    return new QueryClient({
        queryCache: new QueryCache({ onError: (error) => void reportAuthError(error) }),
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
 * Providers + router; session loss anywhere redirects to the login screen. Logout, wipe and a lost
 * session end open drafts without saving and drop every cached query (the next user starts empty).
 */
export function App({ queryClient: given, history }: AppProps): ReactNode {
    const [queryClient] = useState(() => given ?? createAppQueryClient());
    const [router] = useState<AppRouter>(() => createAppRouter(queryClient, history));

    useEffect(() => {
        // Deferred: clearing synchronously would cancel the request that is reporting the loss.
        const clearCache = (): void => {
            setTimeout(() => {
                queryClient.clear();
            }, 0);
        };
        const stopCleared = onLocalDataCleared(() => {
            disposeDraftSessions();
            clearCache();
        });
        const stopLost = onAuthLost((notice) => {
            disposeDraftSessions();
            queryClient.removeQueries({ queryKey: ['me'] });
            clearCache();
            void router.navigate({ to: '/login', search: notice === undefined ? {} : { notice } });
        });
        return () => {
            stopCleared();
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
