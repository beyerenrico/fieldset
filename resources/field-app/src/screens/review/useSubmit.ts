/**
 * Submit flow (§9.2, ADR 0035): wait for uploads, write locally, client validation, then the
 * submit goes through the outbox (persisted Idempotency-Key and body). Online the screen shows the
 * answer; offline the submission is queued and the detail says "number pending".
 */
import { evaluate } from '@form-schema-app/form-schema-js';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useCallback, useState } from 'react';
import { isStorageFull } from '../../components/SaveIndicator.tsx';
import type { MessageKey } from '../../i18n/en.ts';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import { submissionQueryOptions } from '../../hooks/queries.ts';
import { ApiError, isValidationError } from '../../lib/api/errors.ts';
import type { FormVersion, Submission } from '../../lib/api/types.ts';
import type { AutosaveSnapshot } from '../../lib/autosave/types.ts';
import { enqueueSubmit, opId } from '../../lib/outbox/outbox.ts';
import { enqueueAndRun, type QueuedOutcome } from '../../lib/submit/queue.ts';
import { retryBlocked } from '../../lib/sync/conflicts.ts';
import { isFixable } from './refusal.ts';

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
    /** The answer opened a decision panel: the screen moves focus there once it shows. */
    readonly focusPanel: boolean;
    readonly panelFocused: () => void;
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

/** A conflict or a blocking answer (not a 422, which the user fixes in the form). */
function opensPanel(outcome: QueuedOutcome): boolean {
    return (
        outcome.status === 'failed' &&
        !outcome.retrying &&
        outcome.error instanceof ApiError &&
        outcome.error.status !== 422
    );
}

/** Problems before the submit is queued: the draft must be written and valid. */
function precheck(
    editor: DraftEditor,
    saved: AutosaveSnapshot,
    invalid: boolean,
): MessageKey | null {
    if (saved.state === 'error') {
        return isStorageFull(saved.error) ? 'save.storageFull' : 'review.saveFirst';
    }
    if (editor.conflict !== null) {
        return 'submitError.revision_conflict';
    }
    if (editor.blockedBy !== null && !isFixable(editor.blockedBy)) {
        return KNOWN_CODES.includes(editor.blockedBy)
            ? (`submitError.${editor.blockedBy}` as MessageKey)
            : 'submitError.unknown';
    }
    return invalid ? 'review.fixErrors' : null;
}

/** A local failure that kept the submit from being queued (e.g. the device storage is full). */
function thrownProblem(error: unknown): MessageKey {
    console.error('Submitting failed', error);
    return isStorageFull(error) ? 'save.storageFull' : 'submitError.unknown';
}

/** Queues the submit; a refused one (fixed in the form since) is unblocked and sent again. */
function queueSubmit(editor: DraftEditor, uuid: string): Promise<QueuedOutcome> {
    const resend = isFixable(editor.blockedBy);
    const id = opId('submit', editor.scope.userId, uuid);
    return enqueueAndRun({ id, submissionUuid: uuid }, async () => {
        await enqueueSubmit(editor.scope, uuid, new Date().toISOString());
        if (resend) {
            await retryBlocked(uuid);
        }
    });
}

/** After the server took the submit (or it was queued): the detail, which says which one. */
function useShowSubmitted(uuid: string, resubmit: boolean) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    return async (submission: Submission | null): Promise<void> => {
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
}

/** `resubmit`: the submission had changes requested (the detail then says "Resubmitted"). */
export function useSubmit(
    uuid: string,
    version: FormVersion,
    editor: DraftEditor,
    resubmit = false,
): SubmitState {
    const done = useShowSubmitted(uuid, resubmit);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<MessageKey | null>(null);
    const [showErrors, setShowErrors] = useState(false);
    const [focusPanel, setFocusPanel] = useState(false);
    const panelFocused = useCallback(() => {
        setFocusPanel(false);
    }, []);

    const attempt = async (): Promise<void> => {
        await editor.whenIdle();
        const saved = await editor.controller.flush({ ensureCreated: true });
        const invalid =
            evaluate(version.definition, editor.controller.data, editor.context).errors.length > 0;
        const early = precheck(editor, saved, invalid);
        if (early !== null) {
            setShowErrors(early === 'review.fixErrors');
            setProblem(early);
            return;
        }
        const outcome = await queueSubmit(editor, uuid);
        const refused = submitProblem(outcome);
        if (refused !== null) {
            setShowErrors(refused === 'submitError.validation_failed');
            setProblem(refused);
            setFocusPanel(opensPanel(outcome));
            return;
        }
        await done(outcome.status === 'done' ? (outcome.result as Submission) : null);
    };

    const submit = async (): Promise<void> => {
        setBusy(true);
        setProblem(null);
        setFocusPanel(false);
        try {
            await attempt();
        } catch (error: unknown) {
            setProblem(thrownProblem(error));
        } finally {
            setBusy(false); // always: a thrown error must not leave the button busy
        }
    };

    return { busy, problem, showErrors, focusPanel, panelFocused, submit };
}
