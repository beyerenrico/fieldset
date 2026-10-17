/**
 * "Send to customer" (§9.2, ADR 0035): the request is queued in the outbox with a persisted
 * Idempotency-Key (the same recipient keeps its key, another one gets a new key) and sent right
 * away when online. Offline it goes out after sync; answers that arrive later are shown too.
 */
import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import type { MessageKey } from '../../i18n/en.ts';
import { ApiError, NetworkError, validationDetails } from '../../lib/api/errors.ts';
import type { DocumentSend } from '../../lib/api/types.ts';
import type { SendInput } from '../../lib/api/endpoints.ts';
import type { OutboxRow, Scope } from '../../lib/db/schema.ts';
import { enqueueSend, opId, removeOp } from '../../lib/outbox/outbox.ts';
import { toSendInput, type RecipientDraft } from '../../lib/send/recipient.ts';
import { reportAuthError } from '../../lib/session/auth-events.ts';
import { enqueueAndRun, type QueuedOutcome } from '../../lib/submit/queue.ts';
import { onOutboxEvent } from '../../lib/sync/events.ts';
import { useLocalSubmission } from '../../hooks/useLocalDrafts.ts';

export type RecipientField = 'email' | 'name';
export type FieldErrors = Readonly<Partial<Record<RecipientField, MessageKey>>>;

const KNOWN_CODES: readonly string[] = [
    'send_not_allowed',
    'not_submitted',
    'not_found',
    'idempotency_key_reused',
    'too_many_requests',
];

const FIELD_MESSAGES: Readonly<Record<string, readonly [RecipientField, MessageKey]>> = {
    '/email': ['email', 'send.emailInvalid'],
    '/name': ['name', 'send.nameInvalid'],
};

export type QueuedSend = Extract<OutboxRow, { kind: 'send' }>;

export interface SendState {
    readonly busy: boolean;
    readonly result: DocumentSend | null;
    readonly problem: MessageKey | null;
    readonly fieldErrors: FieldErrors;
    /** The send waiting in the outbox (pending or blocked), live. */
    readonly queued: QueuedSend | null;
    /**
     * Resolves `true` once the server accepted the send or it is queued for later. `onAccepted`
     * runs in the same tick as the outcome's state updates (one render: result and dialog close).
     */
    readonly send: (recipient: RecipientDraft, onAccepted?: () => void) => Promise<boolean>;
    readonly clearProblem: () => void;
    /** "Cancel sending": drops the queued send. */
    readonly removeQueued: () => Promise<void>;
    /** "Try again" on a refused send: the same recipient again (same key). */
    readonly retryQueued: () => Promise<void>;
}

function fieldErrorsOf(error: unknown): FieldErrors {
    const errors: Partial<Record<RecipientField, MessageKey>> = {};
    for (const detail of validationDetails(error)) {
        const mapped = FIELD_MESSAGES[detail.pointer];
        if (mapped !== undefined) {
            errors[mapped[0]] = mapped[1];
        }
    }
    return errors;
}

/** Localized text for a send error code (also for stored outbox errors). */
export function sendErrorKey(code: string): MessageKey {
    if (code === 'validation_failed') {
        return 'sendError.validation_failed';
    }
    if (code === 'network') {
        return 'sendError.network';
    }
    return KNOWN_CODES.includes(code) ? (`sendError.${code}` as MessageKey) : 'sendError.unknown';
}

function problemOf(error: unknown): MessageKey {
    if (error instanceof ApiError) {
        return sendErrorKey(error.code);
    }
    if (error instanceof NetworkError) {
        return 'sendError.network';
    }
    reportAuthError(error);
    return 'sendError.unknown';
}

/** Answers of a send that went out later (after reconnecting, or after the dialog was closed). */
function useLateOutcomes(
    uuid: string,
    sending: RefObject<boolean>,
    apply: (outcome: QueuedOutcome) => boolean,
): void {
    useEffect(
        () =>
            onOutboxEvent((event) => {
                if (event.id !== opId('send', uuid) || sending.current) {
                    return;
                }
                apply(
                    event.type === 'done'
                        ? { status: 'done', result: event.result }
                        : { status: 'failed', error: event.error, retrying: event.retrying },
                );
            }),
        [uuid, sending, apply],
    );
}

export function useSendDocument(uuid: string, scope: Scope | null): SendState {
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<DocumentSend | null>(null);
    const [problem, setProblem] = useState<MessageKey | null>(null);
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
    /** While send() waits for its own answer, it applies it itself (together with closing). */
    const sending = useRef(false);
    const local = useLocalSubmission(uuid, scope);
    const op = local?.ops.find((candidate) => candidate.kind === 'send');
    const queued = op?.kind === 'send' ? op : null;

    const apply = useCallback((outcome: QueuedOutcome): boolean => {
        if (outcome.status === 'done') {
            setResult(outcome.result as DocumentSend);
            setProblem(null);
            return true;
        }
        if (outcome.status === 'queued' || outcome.error instanceof NetworkError) {
            return true; // goes out after sync; the queued notice says so
        }
        setFieldErrors(fieldErrorsOf(outcome.error));
        setProblem(problemOf(outcome.error));
        return false;
    }, []);

    useLateOutcomes(uuid, sending, apply);

    const run = async (input: SendInput, onAccepted?: () => void): Promise<boolean> => {
        if (scope === null) {
            return false;
        }
        setBusy(true);
        setResult(null);
        setProblem(null);
        setFieldErrors({});
        sending.current = true;
        try {
            const outcome = await enqueueAndRun(
                { id: opId('send', uuid), submissionUuid: uuid },
                () => enqueueSend(scope, uuid, input),
            );
            const accepted = apply(outcome);
            if (accepted) {
                onAccepted?.();
            }
            return accepted;
        } finally {
            sending.current = false;
            setBusy(false);
        }
    };

    const send = (recipient: RecipientDraft, onAccepted?: () => void): Promise<boolean> =>
        run(toSendInput(recipient), onAccepted);

    return {
        busy,
        result,
        problem,
        fieldErrors,
        queued,
        send,
        clearProblem: () => {
            setProblem(null);
            setFieldErrors({});
        },
        removeQueued: () => removeOp(opId('send', uuid)),
        retryQueued: async () => {
            if (queued !== null) {
                await run(queued.input);
            }
        },
    };
}
