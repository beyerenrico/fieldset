/** "Send to customer" request (§9.2): stable Idempotency-Key per recipient, localized errors. */
import { useState } from 'react';
import type { MessageKey } from '../../i18n/en.ts';
import { sendSubmissionDocument } from '../../lib/api/endpoints.ts';
import { ApiError, NetworkError, isConflict, validationDetails } from '../../lib/api/errors.ts';
import type { DocumentSend } from '../../lib/api/types.ts';
import { toSendInput, type RecipientDraft } from '../../lib/send/recipient.ts';
import { reportAuthError } from '../../lib/session/auth-events.ts';
import { clearSendIdempotencyKey, getSendIdempotencyKey } from '../../lib/submit/idempotency.ts';

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

export interface SendState {
    readonly busy: boolean;
    readonly result: DocumentSend | null;
    readonly problem: MessageKey | null;
    readonly fieldErrors: FieldErrors;
    /** Resolves `true` once the server accepted the send (202). */
    readonly send: (recipient: RecipientDraft) => Promise<boolean>;
    readonly clearProblem: () => void;
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

function problemOf(error: unknown, uuid: string): MessageKey {
    if (isConflict(error, 'idempotency_key_reused')) {
        clearSendIdempotencyKey(uuid);
    }
    if (error instanceof ApiError && error.code === 'validation_failed') {
        return 'sendError.validation_failed';
    }
    if (error instanceof ApiError && KNOWN_CODES.includes(error.code)) {
        return `sendError.${error.code}` as MessageKey;
    }
    if (error instanceof NetworkError) {
        return 'sendError.network';
    }
    reportAuthError(error);
    return 'sendError.unknown';
}

export function useSendDocument(uuid: string): SendState {
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<DocumentSend | null>(null);
    const [problem, setProblem] = useState<MessageKey | null>(null);
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

    const send = async (recipient: RecipientDraft): Promise<boolean> => {
        const input = toSendInput(recipient);
        setBusy(true);
        setResult(null);
        setProblem(null);
        setFieldErrors({});
        try {
            const key = getSendIdempotencyKey(uuid, JSON.stringify(input));
            const accepted = await sendSubmissionDocument(uuid, input, key);
            clearSendIdempotencyKey(uuid);
            setResult(accepted);
            return true;
        } catch (error) {
            setFieldErrors(fieldErrorsOf(error));
            setProblem(problemOf(error, uuid));
            return false;
        } finally {
            setBusy(false);
        }
    };

    return {
        busy,
        result,
        problem,
        fieldErrors,
        send,
        clearProblem: () => {
            setProblem(null);
            setFieldErrors({});
        },
    };
}
