/**
 * A submit the server refused in the background (ADR 0035, §9.2): it did not go through, the user
 * fixes the form and submits again. Shown on the detail, in the lists and on fill/review.
 */
import { Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { useAttachmentReason, type AttachmentProblem } from './attachment-problems.ts';
import { PANEL_BUTTON } from './PanelButton.tsx';
import type { Refusal } from './refusal.ts';

/** The refusal in one sentence, with the next step. */
export function useRefusalText(): (refusal: Refusal, attachment?: AttachmentProblem) => string {
    const t = useT();
    const reason = useAttachmentReason();
    return (refusal, attachment) => {
        switch (refusal.kind) {
            case 'fields':
                if (refusal.count === 0) {
                    return t('sync.refused.fields.some');
                }
                return refusal.count === 1
                    ? t('sync.refused.fields.one')
                    : t('sync.refused.fields.other', { count: refusal.count });
            case 'attachment':
                return t('sync.refused.attachment');
            case 'attachmentBlocked':
                return attachment === undefined
                    ? t('sync.attachment.failed')
                    : t('sync.refused.attachmentBlocked', {
                          name: attachment.fileName,
                          reason: reason(attachment.error),
                      });
        }
    };
}

export interface RefusalNoticeProps {
    readonly uuid: string;
    readonly refusal: Refusal;
    readonly attachment?: AttachmentProblem | undefined;
    /** Link to the form (detail screen); fill and review leave it out. */
    readonly withLink?: boolean;
}

export function RefusalNotice({
    uuid,
    refusal,
    attachment,
    withLink = false,
}: RefusalNoticeProps): ReactNode {
    const t = useT();
    const text = useRefusalText()(refusal, attachment);
    return (
        <Alert variant="destructive" data-testid="refusal">
            <p>{text}</p>
            {withLink ? (
                <Button asChild variant="outline" className={`mt-2 ${PANEL_BUTTON}`}>
                    <Link to="/fill/$uuid/review" params={{ uuid }}>
                        {t('sync.refused.open')}
                    </Link>
                </Button>
            ) : null}
        </Alert>
    );
}
