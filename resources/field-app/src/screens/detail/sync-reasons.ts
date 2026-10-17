/**
 * Localized reasons for failed outbox attempts (ADR 0035). Stored errors carry the server's code
 * and message; the UI shows only these texts, never the raw message.
 */
import type { MessageKey } from '../../i18n/en.ts';
import type { OutboxError } from '../../lib/db/schema.ts';

/** Why a queued op is retried; null when nothing needs saying (offline: the notice says so). */
export function retryReasonKey(error: OutboxError | null): MessageKey | null {
    if (error === null || error.code === 'network') {
        return null;
    }
    if (error.status === 429 || error.code === 'too_many_requests') {
        return 'sync.retry.busy';
    }
    if (error.status === 408 || error.code === 'timeout') {
        return 'sync.retry.timeout';
    }
    return 'sync.retry.temporary';
}

const SEND_REASONS: Readonly<Record<string, MessageKey>> = {
    send_not_allowed: 'sendError.send_not_allowed',
    not_submitted: 'sync.send.reason.not_submitted',
    not_found: 'sendError.not_found',
    too_many_requests: 'sendError.too_many_requests',
    validation_failed: 'sync.send.reason.invalid',
};

/** Why the office refused a queued "send to customer". */
export function sendReasonKey(error: OutboxError | null): MessageKey {
    return SEND_REASONS[error?.code ?? ''] ?? 'sync.send.reason.other';
}
