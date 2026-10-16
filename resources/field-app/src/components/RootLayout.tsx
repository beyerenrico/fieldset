/** Root of every route: localized page title (WCAG 2.4.2) and focus on the new page's heading. */
import { Outlet, useRouterState } from '@tanstack/react-router';
import { useEffect, useRef, type ReactNode } from 'react';
import { useT, type MessageKey } from '../i18n/I18nProvider.tsx';

const TITLES: Readonly<Record<string, MessageKey>> = {
    __root__: 'notFound.title',
    '/login': 'auth.title',
    '/_auth/': 'home.title',
    '/_auth/org': 'org.title',
    '/_auth/forms': 'catalog.title',
    '/_auth/fill/$uuid': 'fill.title',
    '/_auth/fill/$uuid/review': 'review.title',
    '/_auth/submissions': 'submissions.title',
    '/_auth/submissions/$uuid': 'detail.title',
    '/_auth/settings': 'settings.title',
    '/_auth/notifications': 'notifications.title',
};

/** The heading may render later (lazy screens, loading data): poll briefly for it. */
const FOCUS_ATTEMPTS = 20;
const FOCUS_INTERVAL_MS = 50;

function focusable(element: HTMLElement): HTMLElement {
    if (!element.hasAttribute('tabindex')) {
        element.setAttribute('tabindex', '-1');
    }
    return element;
}

function useDocumentTitle(): void {
    const t = useT();
    const routeId = useRouterState({ select: (state) => state.matches.at(-1)?.routeId });
    const key = routeId === undefined ? undefined : TITLES[routeId];
    const title = key === undefined ? t('app.title') : `${t(key)} – ${t('app.title')}`;
    useEffect(() => {
        document.title = title;
    }, [title]);
}

/** After a navigation (not the first load) focus moves to the page's h1, else to <main>. */
function useFocusOnNavigation(): void {
    const pathname = useRouterState({ select: (state) => state.resolvedLocation?.pathname });
    const previous = useRef<string | undefined>(undefined);
    useEffect(() => {
        const last = previous.current;
        previous.current = pathname;
        if (last === undefined || pathname === undefined || last === pathname) {
            return undefined;
        }
        let timer: ReturnType<typeof setTimeout> | undefined;
        const attempt = (count: number): void => {
            const main = document.querySelector<HTMLElement>('main');
            const active = document.activeElement;
            if (count > 0 && active !== document.body && active !== main) {
                return; // The user moved on meanwhile.
            }
            const heading = document.querySelector<HTMLElement>('main h1');
            if (heading !== null) {
                focusable(heading).focus({ preventScroll: true });
                return;
            }
            if (count === 0 && main !== null) {
                focusable(main).focus({ preventScroll: true });
            }
            if (count + 1 < FOCUS_ATTEMPTS) {
                timer = setTimeout(() => {
                    attempt(count + 1);
                }, FOCUS_INTERVAL_MS);
            }
        };
        attempt(0);
        return () => {
            clearTimeout(timer);
        };
    }, [pathname]);
}

export function RootLayout(): ReactNode {
    useDocumentTitle();
    useFocusOnNavigation();
    return <Outlet />;
}
