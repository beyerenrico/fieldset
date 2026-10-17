/**
 * Fill/review: a conflict, a blocking answer or a refused submit that arrives in the background is
 * announced once through this status line; focus stays where the user is (WCAG 2.2, 3.2.1/4.1.3).
 */
import type { ReactNode } from 'react';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { AttachmentProblem } from './attachment-problems.ts';
import { refusalOf } from './refusal.ts';
import { useRefusalText } from './RefusalNotice.tsx';

export interface DecisionStatusProps {
    readonly editor: DraftEditor;
    readonly attachment: AttachmentProblem | undefined;
}

export function DecisionStatus({ editor, attachment }: DecisionStatusProps): ReactNode {
    const t = useT();
    const refusalText = useRefusalText();
    const refusal = refusalOf(editor.row);
    let text: string | null = null;
    if (editor.conflict !== null) {
        text = t('conflict.title');
    } else if (refusal !== null) {
        text = refusalText(refusal, attachment);
    } else if (editor.blockedBy !== null) {
        text = t('sync.blocked.title');
    }
    return (
        <p role="status" className="sr-only">
            {text}
        </p>
    );
}
