/**
 * "On this device" on My submissions (ADR 0035): drafts with unsynced changes and queued submits
 * ("number pending"), each with its sync state. Shown also offline, when the server list fails.
 * Paused items (a decision, a refused submit to fix) open the review screen.
 */
import { useId, type ReactNode } from 'react';
import { StatusBadge } from '../../components/StatusBadge.tsx';
import { Card } from '../../components/ui/card.tsx';
import { useFormatters } from '../../hooks/useFormatters.ts';
import {
    isOpenDraft,
    syncStateOf,
    useDraftScope,
    type LocalDraft,
} from '../../hooks/useLocalDrafts.ts';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { SubmissionStatus } from '../../lib/api/types.ts';
import { useAttachmentProblems, type AttachmentProblem } from '../review/attachment-problems.ts';
import { needsAttention, refusalOf } from '../review/refusal.ts';
import { useRefusalText } from '../review/RefusalNotice.tsx';
import { ItemLink, type ItemTarget } from './ItemLink.tsx';

const ITEM_CLASS =
    'flex min-h-14 items-center p-4 focus-visible:outline-3 focus-visible:outline-ring';

function targetOf(draft: LocalDraft): ItemTarget {
    if (needsAttention(draft.row)) {
        return 'review';
    }
    return draft.pendingSubmit || !isOpenDraft(draft) ? 'detail' : 'fill';
}

/** The status the item counts as for the filter: a queued submit is on its way to "submitted". */
export function localStatus(draft: LocalDraft): SubmissionStatus {
    return draft.pendingSubmit && !needsAttention(draft.row)
        ? 'submitted'
        : draft.submission.status;
}

interface LocalItemProps {
    readonly draft: LocalDraft;
    readonly formName: string;
    readonly attachment: AttachmentProblem | undefined;
}

function LocalItem({ draft, formName, attachment }: LocalItemProps): ReactNode {
    const t = useT();
    const refusalText = useRefusalText();
    const { dateTime } = useFormatters();
    const sync = syncStateOf(draft) ?? 'queued';
    const number = draft.row.server?.number ?? null;
    const refusal = refusalOf(draft.row);
    return (
        <Card className="hover:bg-muted">
            <ItemLink uuid={draft.row.uuid} target={targetOf(draft)} className={ITEM_CLASS}>
                <span className="flex w-full items-center justify-between gap-2">
                    <span className="flex min-w-0 flex-col">
                        <span className="truncate font-semibold">{formName}</span>
                        <span className="text-sm text-muted-foreground">
                            {number ??
                                (draft.pendingSubmit
                                    ? t('sync.list.numberPending')
                                    : t('submissions.noNumber'))}{' '}
                            ·{' '}
                            {t('submissions.updated', {
                                date: dateTime(new Date(draft.row.updatedAt).toISOString()),
                            })}
                        </span>
                        {refusal === null ? null : (
                            <span className="text-sm font-semibold text-destructive">
                                {refusalText(refusal, attachment)}
                            </span>
                        )}
                    </span>
                    <StatusBadge sync={sync} />
                </span>
            </ItemLink>
        </Card>
    );
}

export interface LocalSubmissionsProps {
    /** Unsynced items on this device (already filtered and sorted by the screen). */
    readonly items: readonly LocalDraft[];
    readonly nameOf: (formId: number) => string;
}

export function LocalSubmissions({ items, nameOf }: LocalSubmissionsProps): ReactNode {
    const t = useT();
    const headingId = useId();
    const blockedUploads = items
        .filter((draft) => draft.row.blockedBy === 'attachment_blocked')
        .map((draft) => draft.row.uuid);
    const problems = useAttachmentProblems(blockedUploads, useDraftScope());
    if (items.length === 0) {
        return null;
    }
    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-2">
            <h2 id={headingId} className="text-lg font-bold">
                {t('sync.list.title')}
            </h2>
            <ul className="flex flex-col gap-2">
                {items.map((draft) => (
                    <li key={draft.row.uuid}>
                        <LocalItem
                            draft={draft}
                            formName={nameOf(draft.row.formId)}
                            attachment={problems.get(draft.row.uuid)}
                        />
                    </li>
                ))}
            </ul>
        </section>
    );
}
