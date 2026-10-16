import { Link } from '@tanstack/react-router';
import { ClipboardList, FileText, House, Settings } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';

const ITEM =
    'flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 px-1 text-sm font-semibold text-muted-foreground focus-visible:outline-3 focus-visible:-outline-offset-3 focus-visible:outline-ring';
const ACTIVE = { className: 'text-primary', 'aria-current': 'page' as const };

export function BottomNav(): ReactNode {
    const t = useT();
    return (
        <nav
            aria-label={t('nav.label')}
            className="fixed inset-x-0 bottom-0 z-20 border-t-2 border-border bg-background pb-[env(safe-area-inset-bottom)]"
        >
            <div className="mx-auto flex max-w-3xl">
                <Link to="/" className={ITEM} activeProps={ACTIVE} activeOptions={{ exact: true }}>
                    <House aria-hidden="true" className="size-6" />
                    {t('nav.home')}
                </Link>
                <Link to="/forms" className={ITEM} activeProps={ACTIVE}>
                    <FileText aria-hidden="true" className="size-6" />
                    {t('nav.forms')}
                </Link>
                <Link to="/submissions" className={ITEM} activeProps={ACTIVE}>
                    <ClipboardList aria-hidden="true" className="size-6" />
                    {t('nav.submissions')}
                </Link>
                <Link to="/settings" className={ITEM} activeProps={ACTIVE}>
                    <Settings aria-hidden="true" className="size-6" />
                    {t('nav.settings')}
                </Link>
            </div>
        </nav>
    );
}
