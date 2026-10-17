/**
 * Submit flow (§9.2, ADR 0035): wait for uploads, write locally, client validation, then the
 * submit goes through the outbox (persisted Idempotency-Key and body). Online the screen shows the
 * answer; offline the submission is queued and the detail says "number pending".
 */
import { evaluate } from '@form-schema-app/form-schema-js';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import type { MessageKey } from '../../i18n/en.ts';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import { submissionQueryOptions } from '../../hooks/queries.ts';
import { ApiError, isValidationError } from '../../lib/api/errors.ts';
import type { FormVersion, Submission } from '../../lib/api/types.ts';
import { enqueueSubmit, opId } from '../../lib/outbox/outbox.ts';
import { enqueueAndRun, type QueuedOutcome } from '../../lib/submit/queue.ts';

const KNOWN_CODES: readonly string[] = [
    'version_retired',
    'form_access_revoked',
    'attachments_missing',
    'not_editable',
    'idempotency_key_reused',
    'revision_conflict',
];

export interface SubmitState {
    readonly busy: boolean;
    readonly problem: MessageKey | null;
    readonly showErrors: boolean;
    readonly submit: () => Promise<void>;
}

/** Localized text of a refused submit; null when it stays queued (retried automatically). */
export function submitProblem(outcome: QueuedOutcome): MessageKey | null {
    if (outcome.status !== 'failed' || outcome.retrying) {
        return null;
    }
    const { error } = outcome;
    if (isValidationError(error)) {
        return 'submitError.validation_failed';
    }
    if (error instanceof ApiError && error.code === 'attachment_failed') {
        return 'sync.attachment.failed';
    }
    if (error instanceof ApiError && KNOWN_CODES.includes(error.code)) {
        return `submitError.${error.code}` as MessageKey;
    }
    return 'submitError.unknown';
}

/** Problems before the submit is queued: the draft must be written and valid. */
function precheck(editor: DraftEditor, state: string, invalid: boolean): MessageKey | null {
    if (state === 'error') {
        return 'review.saveFirst';
    }
    if (editor.conflict !== null) {
        return 'submitError.revision_conflict';
    }
    if (editor.blockedBy !== null) {
        return KNOWN_CODES.includes(editor.blockedBy)
            ? (`submitError.${editor.blockedBy}` as MessageKey)
            : 'submitError.unknown';
    }
    return invalid ? 'review.fixErrors' : null;
}

/** `resubmit`: the submission had changes requested (the detail then says "Resubmitted"). */
export function useSubmit(
    uuid: string,
    version: FormVersion,
    editor: DraftEditor,
    resubmit = false,
): SubmitState {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<MessageKey | null>(null);
    const [showErrors, setShowErrors] = useState(false);

    const fail = (key: MessageKey): void => {
        setProblem(key);
        setBusy(false);
    };

    const done = async (submission: Submission | null): Promise<void> => {
        if (submission !== null) {
            queryClient.setQueryData(submissionQueryOptions(uuid).queryKey, submission);
        }
        await queryClient.invalidateQueries({ queryKey: ['submissions'] });
        await navigate({
            to: '/submissions/$uuid',
            params: { uuid },
            search: resubmit ? { submitted: true, resubmitted: true } : { submitted: true },
        });
    };

    const submit = async (): Promise<void> => {
        setBusy(true);
        setProblem(null);
        await editor.whenIdle();
        const saved = await editor.controller.flush({ ensureCreated: true });
        const invalid =
            evaluate(version.definition, editor.controller.data, editor.context).errors.length > 0;
        const early = precheck(editor, saved.state, invalid);
        if (early !== null) {
            setShowErrors(early === 'review.fixErrors');
            fail(early);
            return;
        }
        const outcome = await enqueueAndRun(
            { id: opId('submit', uuid), submissionUuid: uuid },
            () => enqueueSubmit(editor.scope, uuid, new Date().toISOString()),
        );
        const refused = submitProblem(outcome);
        if (refused !== null) {
            setShowErrors(refused === 'submitError.validation_failed');
            fail(refused);
            return;
        }
        await done(outcome.status === 'done' ? (outcome.result as Submission) : null);
    };

    return { busy, problem, showErrors, submit };
}
