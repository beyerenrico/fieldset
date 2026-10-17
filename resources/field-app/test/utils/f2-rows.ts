/**
 * Outbox rows and offline write blockers for the F2 screen tests (paused submissions, ADR 0035).
 * The rows follow F1's contract: a paused submission's submit/send ops are `blocked`.
 */
import { http, HttpResponse } from 'msw';
import { db } from '../../src/lib/db/db.ts';
import type { OutboxError, OutboxRow } from '../../src/lib/db/schema.ts';
import { opId } from '../../src/lib/outbox/outbox.ts';
import { SCOPE } from './a-store.ts';
import { server } from './server.ts';

/** Far ahead: an automatic run (app start) leaves the op alone. */
const LATER = Date.now() + 3_600_000;

export const outboxError = (status: number | null, code: string, message = code): OutboxError => ({
    status,
    code,
    message,
});

export function submitOp(uuid: string, overrides: Partial<OutboxRow> = {}): OutboxRow {
    return {
        ...SCOPE,
        id: opId('submit', SCOPE.userId, uuid),
        kind: 'submit',
        submissionUuid: uuid,
        seq: 2,
        state: 'pending',
        attempts: 0,
        nextAttemptAt: LATER,
        lastError: null,
        createdAt: 1,
        clientSubmittedAt: '2026-10-17T09:00:00.000Z',
        request: null,
        ...overrides,
    } as OutboxRow;
}

export function sendOp(uuid: string, overrides: Partial<OutboxRow> = {}): OutboxRow {
    const input = { email: 'erika@example.test' };
    return {
        ...SCOPE,
        id: opId('send', SCOPE.userId, uuid),
        kind: 'send',
        submissionUuid: uuid,
        seq: 3,
        state: 'pending',
        attempts: 0,
        nextAttemptAt: LATER,
        lastError: null,
        createdAt: 1,
        input,
        request: { idempotencyKey: '0192f1c4-0000-7000-8000-00000000c001', body: input },
        ...overrides,
    } as OutboxRow;
}

export async function putOps(...ops: OutboxRow[]): Promise<void> {
    await db.outbox.bulkPut(ops);
}

/** Every write request fails like a dropped connection (reads stay as the backend answers). */
export function writesOffline(): void {
    server.use(
        http.put('*/api/v1/submissions/:uuid', () => HttpResponse.error()),
        http.post('*/api/v1/submissions/:uuid/submit', () => HttpResponse.error()),
        http.post('*/api/v1/submissions/:uuid/send', () => HttpResponse.error()),
        http.post('*/api/v1/submissions/:uuid/attachments', () => HttpResponse.error()),
    );
}
