/**
 * The detail's sync situation (ADR 0035): a refused submit to fix, a conflict or blocking answer to
 * resolve (both link to the review screen, where the panels are), or a submit ready to send.
 * Always mounted: a change that arrives while the screen is open is announced politely.
 */
import { Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { Alert, AlertTitle } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { DraftRow, OutboxError } from '../../lib/db/schema.ts';
import { useAttachmentProblems } from '../review/attachment-problems.ts';
import { PANEL_BUTTON } from '../review/PanelButton.tsx';
import { RefusalNotice } from '../review/RefusalNotice.tsx';
import { blockedTextKey, needsDecision, refusalOf } from '../review/refusal.ts';
import { QueuedSubmitNotice } from './QueuedSubmitNotice.tsx';

const NO_UUIDS: readonly string[] = [];

function DecisionNotice({ row }: { readonly row: DraftRow }): ReactNode {
    const t = useT();
    const text =
        row.sync === 'conflict'
            ? t('sync.blocked.conflict')
            : t(blockedTextKey(row.blockedBy ?? 'unknown'));
    return (
        <Alert variant="destructive">
            <AlertTitle>{t('sync.blocked.title')}</AlertTitle>
            <p>{text}</p>
            <Button asChild variant="outline" className={`mt-2 ${PANEL_BUTTON}`}>
                <Link to="/fill/$uuid/review" params={{ uuid: row.uuid }}>
                    {t('sync.decision.resolve')}
                </Link>
            </Button>
        </Alert>
    );
}

export interface SyncNoticeProps {
    readonly row: DraftRow | null;
    /** A submit waits in the outbox. */
    readonly queued: boolean;
    readonly lastError: OutboxError | null;
}

export function SyncNotice({ row, queued, lastError }: SyncNoticeProps): ReactNode {
    const refusal = refusalOf(row);
    const blockedUpload = refusal?.kind === 'attachmentBlocked' && row !== null;
    const problems = useAttachmentProblems(blockedUpload ? [row.uuid] : NO_UUIDS, row);
    let notice: ReactNode = null;
    if (row !== null && refusal !== null) {
        notice = (
            <RefusalNotice
                uuid={row.uuid}
                refusal={refusal}
                attachment={problems.get(row.uuid)}
                withLink
            />
        );
    } else if (row !== null && needsDecision(row)) {
        notice = <DecisionNotice row={row} />;
    } else if (queued) {
        notice = <QueuedSubmitNotice lastError={lastError} />;
    }
    // Empty, the region stays in the accessibility tree but takes no space in the layout.
    return (
        <div role="status" className={notice === null ? 'sr-only' : undefined}>
            {notice}
        </div>
    );
}
