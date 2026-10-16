/** Approve / reject a review task: stable Idempotency-Key per decision, refresh, localized errors. */
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { MessageKey } from '../../i18n/en.ts';
import { submissionQueryOptions } from '../../hooks/queries.ts';
import {
    ApiError,
    NetworkError,
    isApiError,
    isConflict,
    validationDetails,
} from '../../lib/api/errors.ts';
import type { ReviewOutcome } from '../../lib/api/types.ts';
import { completeTask, type TaskCompletionInput } from '../../lib/api/workflow.ts';
import { reportAuthError } from '../../lib/session/auth-events.ts';
import { clearTaskIdempotencyKey, getTaskIdempotencyKey } from '../../lib/submit/idempotency.ts';

/** `done`: recorded; `closed`: the task is gone (decided elsewhere / no access); `retry`: fix + retry. */
export type CompletionResult = 'done' | 'closed' | 'retry';

export interface CompleteTaskState {
    readonly busy: boolean;
    readonly result: ReviewOutcome | null;
    readonly problem: MessageKey | null;
    readonly commentError: MessageKey | null;
    readonly complete: (taskId: number, input: TaskCompletionInput) => Promise<CompletionResult>;
    readonly clear: () => void;
    readonly clearCommentError: () => void;
}

const CLOSED: ReadonlySet<MessageKey> = new Set([
    'decisionError.task_closed',
    'decisionError.not_found',
]);

function problemOf(error: unknown): MessageKey {
    if (error instanceof ApiError && error.code === 'validation_failed') {
        return 'decisionError.validation_failed';
    }
    if (isConflict(error, 'task_closed')) {
        return 'decisionError.task_closed';
    }
    if (isApiError(error, 404)) {
        return 'decisionError.not_found';
    }
    if (isConflict(error, 'idempotency_key_reused')) {
        return 'decisionError.idempotency_key_reused';
    }
    if (error instanceof NetworkError) {
        return 'decisionError.network';
    }
    reportAuthError(error);
    return 'decisionError.unknown';
}

function commentErrorOf(error: unknown): MessageKey | null {
    return validationDetails(error).some((detail) => detail.pointer === '/comment')
        ? 'decision.commentInvalid'
        : null;
}

export function useCompleteTask(uuid: string): CompleteTaskState {
    const queryClient = useQueryClient();
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<ReviewOutcome | null>(null);
    const [problem, setProblem] = useState<MessageKey | null>(null);
    const [commentError, setCommentError] = useState<MessageKey | null>(null);

    const refresh = async (): Promise<void> => {
        await Promise.all([
            queryClient.invalidateQueries({ queryKey: submissionQueryOptions(uuid).queryKey }),
            queryClient.invalidateQueries({ queryKey: ['tasks'] }),
            queryClient.invalidateQueries({ queryKey: ['submissions'] }),
        ]);
    };

    const complete = async (
        taskId: number,
        input: TaskCompletionInput,
    ): Promise<CompletionResult> => {
        setBusy(true);
        setResult(null);
        setProblem(null);
        setCommentError(null);
        try {
            const key = getTaskIdempotencyKey(taskId, JSON.stringify(input));
            const done = await completeTask(taskId, input, key);
            clearTaskIdempotencyKey(taskId);
            queryClient.setQueryData(submissionQueryOptions(uuid).queryKey, done.submission);
            void refresh();
            setResult(input.outcome === 'approve' ? 'approved' : 'rejected');
            return 'done';
        } catch (error) {
            const message = problemOf(error);
            setProblem(message);
            setCommentError(commentErrorOf(error));
            // A changed body needs a new key; a closed task never takes this key again.
            if (message === 'decisionError.idempotency_key_reused' || CLOSED.has(message)) {
                clearTaskIdempotencyKey(taskId);
            }
            if (CLOSED.has(message)) {
                void refresh();
                return 'closed';
            }
            return 'retry';
        } finally {
            setBusy(false);
        }
    };

    return {
        busy,
        result,
        problem,
        commentError,
        complete,
        clear: () => {
            setProblem(null);
            setCommentError(null);
        },
        clearCommentError: () => {
            setCommentError(null);
        },
    };
}
