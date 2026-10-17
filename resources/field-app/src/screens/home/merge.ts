/** Home "Continue drafts": server drafts merged with the drafts on this device (ADR 0035). */
import type { Submission } from '../../lib/api/types.ts';
import type { DraftSync } from '../../lib/db/schema.ts';
import { isOpenDraft, syncStateOf, type LocalDraft } from '../../hooks/useLocalDrafts.ts';

export interface DraftItem {
    /** Server copy (status, review), or the local view for drafts not on the server yet. */
    readonly submission: Submission;
    /** Sync state of the local copy; null when the server copy is all there is. */
    readonly sync: DraftSync | null;
    /** Last edit (epoch ms), local edits included. */
    readonly updatedAt: number;
}

const parse = (iso: string): number => {
    const time = Date.parse(iso);
    return Number.isNaN(time) ? 0 : time;
};

export function mergeDrafts(
    server: readonly Submission[] | undefined,
    local: readonly LocalDraft[] | undefined,
): DraftItem[] {
    const all = local ?? [];
    const open = new Map(all.filter(isOpenDraft).map((draft) => [draft.row.uuid, draft]));
    // A queued submit is no draft any more, even while the server still says so.
    const queued = new Set(all.filter((draft) => draft.pendingSubmit).map((d) => d.row.uuid));
    const items: DraftItem[] = [];
    for (const submission of server ?? []) {
        if (queued.has(submission.uuid)) {
            continue;
        }
        const mine = open.get(submission.uuid);
        open.delete(submission.uuid);
        const updatedAt = Math.max(parse(submission.updated_at), mine?.row.updatedAt ?? 0);
        items.push({ submission, sync: syncStateOf(mine), updatedAt });
    }
    for (const draft of open.values()) {
        items.push({
            submission: draft.submission,
            sync: syncStateOf(draft),
            updatedAt: draft.row.updatedAt,
        });
    }
    return items.toSorted((a, b) => b.updatedAt - a.updatedAt);
}
