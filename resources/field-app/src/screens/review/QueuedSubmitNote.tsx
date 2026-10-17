/** Fill/review of a draft whose submit waits in the outbox: edits made now are sent with it. */
import type { ReactNode } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import { useT } from '../../i18n/I18nProvider.tsx';

export function QueuedSubmitNote({ editor }: { readonly editor: DraftEditor }): ReactNode {
    const t = useT();
    return (
        <div role="status">
            {editor.queuedSubmit ? (
                <Alert variant="info">{t('sync.submitQueued.editing')}</Alert>
            ) : null}
        </div>
    );
}
