/** Submit flow (§9.2): wait for uploads, flush, client validation, Idempotency-Key, errors. */
import { evaluate } from '@form-schema-app/form-schema-js';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import type { MessageKey } from '../../i18n/en.ts';
import type { DraftEditor } from '../../hooks/useDraftEditor.ts';
import { submissionQueryOptions } from '../../hooks/queries.ts';
import { submitSubmission } from '../../lib/api/endpoints.ts';
import { ApiError, NetworkError, isConflict, validationDetails } from '../../lib/api/errors.ts';
import type { FormVersion } from '../../lib/api/types.ts';
import { serverCopy } from '../../lib/autosave/server-copy.ts';
import { reportAuthError } from '../../lib/session/auth-events.ts';
import { clearIdempotencyKey, getIdempotencyKey } from '../../lib/submit/idempotency.ts';
import { setSubmitErrors } from '../../lib/submit/error-store.ts';

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

    const handleError = (error: unknown, revision: number): MessageKey => {
        const details = validationDetails(error);
        if (details.length > 0) {
            setSubmitErrors(uuid, details);
            setShowErrors(true);
            return 'submitError.validation_failed';
        }
        if (isConflict(error, 'idempotency_key_reused')) {
            clearIdempotencyKey(uuid, revision);
        }
        if (error instanceof ApiError && KNOWN_CODES.includes(error.code)) {
            return `submitError.${error.code}` as MessageKey;
        }
        if (error instanceof NetworkError) {
            return 'submitError.network';
        }
        reportAuthError(error);
        return 'submitError.unknown';
    };

    const fail = (key: MessageKey): void => {
        setProblem(key);
        setBusy(false);
    };

    /** Sends the submit; an identical server copy (lost response) is adopted and sent once more. */
    const send = async (revision: number, retried: boolean): Promise<void> => {
        try {
            const result = await submitSubmission(
                uuid,
                revision,
                getIdempotencyKey(uuid, revision),
            );
            clearIdempotencyKey(uuid, revision);
            setSubmitErrors(uuid, []);
            editor.controller.acceptServer(result.submission);
            queryClient.setQueryData(submissionQueryOptions(uuid).queryKey, result.submission);
            await queryClient.invalidateQueries({ queryKey: ['submissions'] });
            await navigate({
                to: '/submissions/$uuid',
                params: { uuid },
                search: resubmit ? { submitted: true, resubmitted: true } : { submitted: true },
            });
        } catch (error) {
            const server = serverCopy(error);
            if (server !== null && !editor.controller.reportConflict(server) && !retried) {
                await send(server.revision, true);
                return;
            }
            fail(handleError(error, revision));
        }
    };

    const submit = async (): Promise<void> => {
        setBusy(true);
        setProblem(null);
        await editor.whenIdle();
        const saved = await editor.controller.flush({ ensureCreated: true });
        if (saved.state === 'conflict' || saved.state === 'locked') {
            fail(
                saved.state === 'locked'
                    ? 'submitError.not_editable'
                    : 'submitError.revision_conflict',
            );
            return;
        }
        if (saved.state === 'offline' || saved.state === 'error' || saved.revision === 0) {
            fail('review.saveFirst');
            return;
        }
        if (
            evaluate(version.definition, editor.controller.data, editor.context).errors.length > 0
        ) {
            setShowErrors(true);
            fail('review.fixErrors');
            return;
        }
        await send(saved.revision, false);
    };

    return { busy, problem, showErrors, submit };
}
