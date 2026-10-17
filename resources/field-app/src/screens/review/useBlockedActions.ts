/** The user's ways out of a blocked draft (ADR 0035): retry, discard, or copy into a new draft. */
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { formsQueryOptions } from '../../hooks/queries.ts';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import { endDraftSession } from '../../lib/drafts/draft-session.ts';
import { copyToNewDraft, discardLocalSubmission, retryBlocked } from '../../lib/sync/conflicts.ts';

export interface BlockedActions {
    readonly busy: boolean;
    readonly retry: () => Promise<void>;
    readonly discard: () => Promise<void>;
    /** Same form version (not_editable) or the form's current one (version_retired). */
    readonly copy: (() => Promise<void>) | null;
}

type Target = { readonly formId: number; readonly formVersionId: number } | undefined;

/** undefined: copy within the same version; null: no copy offered for this answer. */
function copyTarget(
    code: string | null,
    formId: number,
    current: number | undefined,
): Target | null {
    if (code === 'not_editable') {
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
    const forms = useQuery(formsQueryOptions(editor.scope.orgId));
    const current = forms.data?.find((form) => form.id === formId)?.version.id;
    const target = copyTarget(editor.blockedBy, formId, current);

    const run = async (task: () => Promise<void>): Promise<void> => {
        setBusy(true);
        try {
            await task();
        } finally {
            setBusy(false);
        }
    };

    return {
        busy,
        retry: () => run(() => retryBlocked(editor.uuid)),
        discard: () =>
            run(async () => {
                endDraftSession(editor.uuid); // no write may bring the row back
                await discardLocalSubmission(editor.uuid);
                await navigate({ to: '/' });
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
                          await navigate({ to: '/fill/$uuid', params: { uuid } });
                      }),
    };
}
