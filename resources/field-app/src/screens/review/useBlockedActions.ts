/** The user's ways out of a blocked draft (ADR 0035): retry, discard, or copy into a new draft. */
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { isStorageFull } from '../../components/SaveIndicator.tsx';
import { formsQueryOptions } from '../../hooks/queries.ts';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import type { MessageKey } from '../../i18n/en.ts';
import { endDraftSession } from '../../lib/drafts/draft-session.ts';
import { copyToNewDraft, discardLocalSubmission, retryBlocked } from '../../lib/sync/conflicts.ts';
import { flashState } from './flash.tsx';
import { canRetry } from './refusal.ts';

export interface BlockedActions {
    readonly busy: boolean;
    /** Why the last action failed (e.g. the device storage is full). */
    readonly problem: MessageKey | null;
    /** Only where a retry can succeed (access restored, a smaller draft, …). */
    readonly retry: (() => Promise<void>) | null;
    readonly discard: () => Promise<void>;
    /** Into the same form version (not_editable, version_mismatch, not_found) or the current one. */
    readonly copy: (() => Promise<void>) | null;
}

type Target = { readonly formId: number; readonly formVersionId: number } | undefined;

const SAME_VERSION: ReadonlySet<string> = new Set([
    'not_editable',
    'version_mismatch',
    'not_found',
]);

/** undefined: copy within the same version; null: no copy offered for this answer. */
function copyTarget(
    code: string | null,
    formId: number,
    current: number | undefined,
): Target | null {
    if (code !== null && SAME_VERSION.has(code)) {
        return undefined;
    }
    if (code === 'version_retired' && current !== undefined) {
        return { formId, formVersionId: current };
    }
    return null;
}

export function useBlockedActions(editor: DraftEditor, formId: number): BlockedActions {
    const navigate = useNavigate();
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<MessageKey | null>(null);
    const forms = useQuery(formsQueryOptions(editor.scope.orgId));
    const current = forms.data?.find((form) => form.id === formId)?.version.id;
    const code = editor.blockedBy;
    const target = copyTarget(code, formId, current);

    const run = async (task: () => Promise<void>): Promise<void> => {
        setBusy(true);
        setProblem(null);
        try {
            await task();
        } catch (error: unknown) {
            setProblem(isStorageFull(error) ? 'save.storageFull' : 'error.generic');
        } finally {
            setBusy(false);
        }
    };

    return {
        busy,
        problem,
        retry: code !== null && canRetry(code) ? () => run(() => retryBlocked(editor.uuid)) : null,
        discard: () =>
            run(async () => {
                endDraftSession(editor.uuid); // no write may bring the row back
                await discardLocalSubmission(editor.uuid);
                await navigate({ to: '/', state: flashState('discarded') });
            }),
        copy:
            target === null
                ? null
                : () =>
                      run(async () => {
                          await editor.whenIdle();
                          await editor.controller.flush();
                          const uuid = await copyToNewDraft(editor.uuid, target);
                          endDraftSession(editor.uuid);
                          await navigate({
                              to: '/fill/$uuid',
                              params: { uuid },
                              state: flashState('copied'),
                          });
                      }),
    };
}
