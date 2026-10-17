/** Home "Continue drafts": server drafts merged with the drafts on this device (ADR 0035). */
import type { Submission } from '../../lib/api/types.ts';
import type { DraftSync } from '../../lib/db/schema.ts';
import { isOpenDraft, syncStateOf, type LocalDraft } from '../../hooks/useLocalDrafts.ts';
import { needsAttention, refusalOf, type Refusal } from '../review/refusal.ts';

export interface DraftItem {
    /** Server copy (status, review), or the local view for drafts not on the server yet. */
    readonly submission: Submission;
    /** Sync state of the local copy; null when the server copy is all there is. */
    readonly sync: DraftSync | null;
    /** Last edit (epoch ms), local edits included. */
    readonly updatedAt: number;
    /** Waits for the user (decision or fix): the item opens the review screen. */
    readonly attention: boolean;
    /** A submit the server refused (fields or attachments to fix). */
    readonly refusal: Refusal | null;
}

const parse = (iso: string): number => {
    const time = Date.parse(iso);
    return Number.isNaN(time) ? 0 : time;
};

/** A draft again: open, or waiting for the user even though its submit is still queued. */
const isDraft = (draft: LocalDraft): boolean => isOpenDraft(draft) || needsAttention(draft.row);

function localState(mine: LocalDraft | undefined): Pick<DraftItem, 'attention' | 'refusal'> {
    return { attention: needsAttention(mine?.row), refusal: refusalOf(mine?.row) };
}

export function mergeDrafts(
    server: readonly Submission[] | undefined,
    local: readonly LocalDraft[] | undefined,
): DraftItem[] {
    const all = local ?? [];
    const open = new Map(all.filter(isDraft).map((draft) => [draft.row.uuid, draft]));
    // A queued submit is no draft any more, even while the server still says so.
    const queued = new Set(
        all.filter((d) => d.pendingSubmit && !isDraft(d)).map((d) => d.row.uuid),
    );
    const items: DraftItem[] = [];
    for (const submission of server ?? []) {
        if (queued.has(submission.uuid)) {
            continue;
        }
        const mine = open.get(submission.uuid);
        open.delete(submission.uuid);
        const updatedAt = Math.max(parse(submission.updated_at), mine?.row.updatedAt ?? 0);
        items.push({ submission, sync: syncStateOf(mine), updatedAt, ...localState(mine) });
    }
    for (const draft of open.values()) {
        items.push({
            submission: draft.submission,
            sync: syncStateOf(draft),
            updatedAt: draft.row.updatedAt,
            ...localState(draft),
        });
    }
    return items.toSorted((a, b) => b.updatedAt - a.updatedAt);
}
