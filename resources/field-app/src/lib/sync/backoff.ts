/**
 * Retry policy of the outbox (ADR 0035): min(15 min, 5 s·2^(n−1)) plus up to 10 % jitter for
 * network errors, 5xx, 408 and 429; `Retry-After` wins when the server sent one.
 */
import { ApiError, DeviceWipedError, NetworkError, ReauthRequiredError } from '../api/errors.ts';
import type { OutboxError } from '../db/schema.ts';

export const BASE_DELAY_MS = 5_000;
export const MAX_DELAY_MS = 15 * 60_000;
const JITTER = 0.1;

export function backoffDelay(attempts: number, random: () => number = Math.random): number {
    const exponent = Math.max(0, attempts - 1);
    const delay = Math.min(MAX_DELAY_MS, BASE_DELAY_MS * 2 ** exponent);
    return Math.round(delay + delay * JITTER * random());
}

/** How the engine reacts to a failed request. */
export type Failure =
    | { readonly kind: 'offline' }
    | { readonly kind: 'retry'; readonly retryAfterMs: number | null }
    | { readonly kind: 'reauth' }
    | { readonly kind: 'wiped' }
    | { readonly kind: 'answer'; readonly error: ApiError }
    | { readonly kind: 'unknown' };

const RETRY_STATUSES = new Set([408, 429]);

export function classify(error: unknown): Failure {
    if (error instanceof ReauthRequiredError) {
        return { kind: 'reauth' };
    }
    if (error instanceof DeviceWipedError) {
        return { kind: 'wiped' };
    }
    if (error instanceof NetworkError) {
        return { kind: 'offline' };
    }
    if (error instanceof ApiError) {
        if (error.status >= 500 || RETRY_STATUSES.has(error.status)) {
            return { kind: 'retry', retryAfterMs: error.retryAfterMs };
        }
        return { kind: 'answer', error };
    }
    return { kind: 'unknown' };
}

export function toOutboxError(error: unknown): OutboxError {
    if (error instanceof ApiError) {
        return { status: error.status, code: error.code, message: error.message };
    }
    if (error instanceof NetworkError) {
        return { status: null, code: 'network', message: error.message };
    }
    const message = error instanceof Error ? error.message : 'Unexpected error';
    return { status: null, code: 'unknown', message };
}
