import { Outlet, useRouterState } from '@tanstack/react-router';
import { useEffect, type ReactNode } from 'react';
import { useI18n } from '../i18n/I18nProvider.tsx';
import { toLanguage } from '../i18n/preferences.ts';
import { useSession } from '../hooks/useSession.ts';
import { cn } from '../lib/cn.ts';
import { BottomNav } from './BottomNav.tsx';
import { NotificationBell } from './NotificationBell.tsx';
import { OnlineIndicator } from './OnlineIndicator.tsx';
import { PwaPrompts } from './pwa/PwaPrompts.tsx';
import { SyncStatusBadge } from './sync/SyncStatusBadge.tsx';

/** Authenticated layout. The window scrolls (the renderer virtualizes against it). */
export function AppShell(): ReactNode {
    const { t, setDefaultLanguage } = useI18n();
    const { me, organization } = useSession();
    const pathname = useRouterState({ select: (state) => state.location.pathname });
    const focusMode = pathname.includes('/fill/');
    const userLanguage = toLanguage(me.user.locale) ?? toLanguage(organization?.locale);

    useEffect(() => {
        setDefaultLanguage(userLanguage);
    }, [setDefaultLanguage, userLanguage]);

    return (
        <div className="min-h-dvh bg-background text-foreground">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-3 focus:text-primary-foreground"
            >
                {t('common.skipToContent')}
            </a>
            <header className="sticky top-0 z-20 border-b-2 border-border bg-background">
                <div className="mx-auto flex min-h-14 max-w-3xl items-center justify-between gap-2 px-4">
                    <span className="truncate text-lg font-bold">
                        {organization?.name ?? t('app.title')}
                    </span>
                    <span className="flex shrink-0 items-center gap-1">
                        <OnlineIndicator />
                        <SyncStatusBadge />
                        <NotificationBell />
                    </span>
                </div>
            </header>
            <PwaPrompts />
            <main
                id="main"
                tabIndex={-1}
                className={cn(
                    'mx-auto max-w-3xl px-4 py-4 outline-none',
                    focusMode ? 'pb-8' : 'pb-28',
                )}
            >
                <Outlet />
            </main>
            {focusMode ? null : <BottomNav />}
        </div>
    );
}
