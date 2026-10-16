import { Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { Button } from '../components/ui/button.tsx';
import { useT } from '../i18n/I18nProvider.tsx';

export function NotFoundScreen(): ReactNode {
    const t = useT();
    return (
        <main className="mx-auto flex max-w-md flex-col gap-4 p-4">
            <h1 className="text-2xl font-bold">{t('notFound.title')}</h1>
            <Button asChild>
                <Link to="/">{t('notFound.home')}</Link>
            </Button>
        </main>
    );
}
