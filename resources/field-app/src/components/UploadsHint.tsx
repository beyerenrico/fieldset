import type { ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';

/** Why the primary action is disabled while uploads run (announced politely). */
export function UploadsHint({ visible }: { readonly visible: boolean }): ReactNode {
    const t = useT();
    return (
        <p
            role="status"
            className={visible ? 'text-sm font-semibold text-muted-foreground' : 'sr-only'}
        >
            {visible ? t('upload.inProgress') : null}
        </p>
    );
}
