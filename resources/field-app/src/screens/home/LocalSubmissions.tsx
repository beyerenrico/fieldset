/**
 * "On this device" on My submissions (ADR 0035): drafts with unsynced changes and queued submits
 * ("number pending"), each with its sync state. Shown also offline, when the server list fails.
 */
import { Link } from '@tanstack/react-router';
import { useId, type ReactNode } from 'react';
import { StatusBadge } from '../../components/StatusBadge.tsx';
import { Card } from '../../components/ui/card.tsx';
import { useFormatters } from '../../hooks/useFormatters.ts';
import {
    isOpenDraft,
    isUnsynced,
    syncStateOf,
    useDraftScope,
    useLocalDrafts,
    type LocalDraft,
} from '../../hooks/useLocalDrafts.ts';
import { useT } from '../../i18n/I18nProvider.tsx';

const ITEM_CLASS =
    'flex min-h-14 items-center p-4 focus-visible:outline-3 focus-visible:outline-ring';

function LocalItem({
    draft,
    formName,
}: {
    readonly draft: LocalDraft;
    readonly formName: string;
}): ReactNode {
    const t = useT();
    const { dateTime } = useFormatters();
    const { uuid } = draft.row;
    const sync = syncStateOf(draft) ?? 'queued';
    const number = draft.row.server?.number ?? null;
    const body = (
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
            </span>
            <StatusBadge sync={sync} />
        </span>
    );
    return (
        <Card className="hover:bg-muted">
            {draft.pendingSubmit || !isOpenDraft(draft) ? (
                <Link to="/submissions/$uuid" params={{ uuid }} className={ITEM_CLASS}>
                    {body}
                </Link>
            ) : (
                <Link to="/fill/$uuid" params={{ uuid }} className={ITEM_CLASS}>
                    {body}
                </Link>
            )}
        </Card>
    );
}

export function LocalSubmissions({
    nameOf,
}: {
    readonly nameOf: (formId: number) => string;
}): ReactNode {
    const t = useT();
    const headingId = useId();
    const local = useLocalDrafts(useDraftScope());
    const items = (local ?? [])
        .filter(isUnsynced)
        .toSorted((a, b) => b.row.updatedAt - a.row.updatedAt);
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
                        <LocalItem draft={draft} formName={nameOf(draft.row.formId)} />
                    </li>
                ))}
            </ul>
        </section>
    );
}
