/** Code-based route tree (basepath /app). */
import type { QueryClient } from '@tanstack/react-query';
import {
    createRootRouteWithContext,
    createRoute,
    createRouter,
    lazyRouteComponent,
    redirect,
    type RouterHistory,
} from '@tanstack/react-router';
import { AppShell } from '../components/AppShell.tsx';
import { RootLayout } from '../components/RootLayout.tsx';
import { RouteError } from '../components/RouteError.tsx';
import { meQueryOptions } from '../hooks/queries.ts';
import { isApiError, isAuthLost } from '../lib/api/errors.ts';
import type { Me } from '../lib/api/types.ts';
import { noticeFor, rememberNotice } from '../lib/session/auth-events.ts';
import { resolveOrganization } from '../lib/session/organization.ts';
import { flushPendingLogout, hasPendingLogout } from '../lib/session/pending-logout.ts';
import { CatalogScreen } from '../screens/CatalogScreen.tsx';
import { HomeScreen } from '../screens/HomeScreen.tsx';
import { LoginScreen } from '../screens/LoginScreen.tsx';
import { NotFoundScreen } from '../screens/NotFoundScreen.tsx';
import { NotificationsScreen } from '../screens/NotificationsScreen.tsx';
import { OrgPickerScreen } from '../screens/OrgPickerScreen.tsx';
import { SettingsScreen } from '../screens/SettingsScreen.tsx';
import { SubmissionsScreen } from '../screens/SubmissionsScreen.tsx';
import {
    parseDetailSearch,
    parseFillSearch,
    parseLoginSearch,
    parseSubmissionsSearch,
} from './search.ts';

/** Screens with the renderer (markdown, signature pad, virtualizer) load on demand. */
const FillScreen = lazyRouteComponent(() => import('../screens/FillScreen.tsx'), 'FillScreen');
const ReviewScreen = lazyRouteComponent(
    () => import('../screens/ReviewScreen.tsx'),
    'ReviewScreen',
);
const SubmissionDetailScreen = lazyRouteComponent(
    () => import('../screens/SubmissionDetailScreen.tsx'),
    'SubmissionDetailScreen',
);

export interface RouterContext {
    readonly queryClient: QueryClient;
}

/**
 * A loaded session (also a cached one while offline) never blocks navigation: a stale one is
 * revalidated in the background, and a lost session surfaces through the query cache (App.tsx).
 */
async function sessionForRoute(queryClient: QueryClient): Promise<Me> {
    const options = meQueryOptions();
    const loaded = queryClient.getQueryData(options.queryKey);
    if (loaded === undefined) {
        return queryClient.query(options);
    }
    queryClient.query(options).catch(() => undefined);
    return loaded;
}

const rootRoute = createRootRouteWithContext<RouterContext>()({
    component: RootLayout,
    notFoundComponent: NotFoundScreen,
});

const loginRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: 'login',
    validateSearch: parseLoginSearch,
    component: LoginScreen,
});

const authRoute = createRoute({
    getParentRoute: () => rootRoute,
    id: '_auth',
    beforeLoad: async ({ context, location }) => {
        if (hasPendingLogout()) {
            // Signed out on this device; the server sign-out is sent in the background.
            void flushPendingLogout();
            throw redirect({
                to: '/login',
                search: { notice: rememberNotice('logout') ?? 'logout' },
            });
        }
        try {
            const me = await sessionForRoute(context.queryClient);
            const organization = resolveOrganization(me);
            if (organization === null && !location.pathname.endsWith('/org')) {
                throw redirect({ to: '/org' });
            }
        } catch (error) {
            if (isAuthLost(error) || isApiError(error, 401) || isApiError(error, 419)) {
                const notice = rememberNotice(noticeFor(error));
                throw redirect({ to: '/login', search: notice === undefined ? {} : { notice } });
            }
            throw error;
        }
    },
    component: AppShell,
    errorComponent: RouteError,
});

const routes = [
    createRoute({ getParentRoute: () => authRoute, path: '/', component: HomeScreen }),
    createRoute({ getParentRoute: () => authRoute, path: 'org', component: OrgPickerScreen }),
    createRoute({ getParentRoute: () => authRoute, path: 'forms', component: CatalogScreen }),
    createRoute({
        getParentRoute: () => authRoute,
        path: 'fill/$uuid',
        validateSearch: parseFillSearch,
        component: FillScreen,
    }),
    createRoute({
        getParentRoute: () => authRoute,
        path: 'fill/$uuid/review',
        component: ReviewScreen,
    }),
    createRoute({
        getParentRoute: () => authRoute,
        path: 'submissions',
        validateSearch: parseSubmissionsSearch,
        component: SubmissionsScreen,
    }),
    createRoute({
        getParentRoute: () => authRoute,
        path: 'submissions/$uuid',
        validateSearch: parseDetailSearch,
        component: SubmissionDetailScreen,
    }),
    createRoute({ getParentRoute: () => authRoute, path: 'settings', component: SettingsScreen }),
    createRoute({
        getParentRoute: () => authRoute,
        path: 'notifications',
        component: NotificationsScreen,
    }),
] as const;

export const routeTree = rootRoute.addChildren([loginRoute, authRoute.addChildren(routes)]);

export function createAppRouter(queryClient: QueryClient, history?: RouterHistory) {
    return createRouter({
        routeTree,
        basepath: '/app',
        context: { queryClient },
        defaultNotFoundComponent: NotFoundScreen,
        scrollRestoration: true,
        ...(history === undefined ? {} : { history }),
    });
}

export type AppRouter = ReturnType<typeof createAppRouter>;

declare module '@tanstack/react-router' {
    interface Register {
        router: AppRouter;
    }
}
