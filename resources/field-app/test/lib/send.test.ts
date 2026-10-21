import type { FormDefinition } from '@fieldset/fieldset-js';
import { describe, expect, it } from 'vitest';
import {
    documentAnonymizedMessage,
    documentFailureMessage,
    documentRefetchDelay,
    isDocumentAnonymized,
    isDocumentFailed,
    linkNeedsRefresh,
    POLL_DELAYS_MS,
} from '../../src/hooks/useSubmissionDocument.ts';
import { ApiError, NetworkError } from '../../src/lib/api/errors.ts';
import { db } from '../../src/lib/db/db.ts';
import { enqueueSend, opId } from '../../src/lib/outbox/outbox.ts';
import { customerDefaults, isValidEmail, toSendInput } from '../../src/lib/send/recipient.ts';
import { SCOPE } from '../utils/a-store.ts';
import { makeDocument } from '../utils/fixtures.ts';
import { setupTestServer } from '../utils/server.ts';

setupTestServer();

const ROLE_DEFINITION: FormDefinition = {
    specVersion: '1.0.0',
    key: 'roles',
    title: 'Roles',
    locale: 'en-US',
    pages: [
        {
            key: 'p1',
            title: 'P1',
            elements: [
                {
                    id: '00000000-0000-4000-8000-000000000011',
                    type: 'section',
                    key: 'customer_section',
                    label: 'Customer',
                    elements: [
                        {
                            id: '00000000-0000-4000-8000-000000000012',
                            type: 'text',
                            key: 'customer_name',
                            label: 'Name',
                            role: 'customer.name',
                        },
                    ],
                },
                {
                    id: '00000000-0000-4000-8000-000000000013',
                    type: 'columns',
                    key: 'cols',
                    columns: [
                        {
                            elements: [
                                {
                                    id: '00000000-0000-4000-8000-000000000014',
                                    type: 'email',
                                    key: 'customer_mail',
                                    label: 'Email',
                                    role: 'customer.email',
                                },
                            ],
                        },
                    ],
                },
            ],
        },
    ],
};

describe('send recipient defaults', () => {
    it('reads the submitted values of the customer roles (sections and columns included)', () => {
        expect(
            customerDefaults(ROLE_DEFINITION, {
                customer_name: '  Erika Kundin ',
                customer_mail: 'erika@example.test',
            }),
        ).toEqual({ email: 'erika@example.test', name: 'Erika Kundin' });
    });

    it('falls back to empty values for missing roles, non-strings and broken definitions', () => {
        expect(customerDefaults(ROLE_DEFINITION, { customer_name: 42 })).toEqual({
            email: '',
            name: '',
        });
        expect(customerDefaults(undefined, { customer_mail: 'x@y.z' })).toEqual({
            email: '',
            name: '',
        });
        const odd = { pages: 'nope' } as unknown as FormDefinition;
        expect(customerDefaults(odd, {})).toEqual({ email: '', name: '' });
        const broken = 'not a definition' as unknown as FormDefinition;
        expect(customerDefaults(broken, {})).toEqual({ email: '', name: '' });
    });

    it('validates email addresses on the client', () => {
        expect(isValidEmail('kunde@example.test')).toBe(true);
        expect(isValidEmail('kunde@example')).toBe(false);
        expect(isValidEmail('kunde example@test.de')).toBe(false);
        expect(isValidEmail(`${'a'.repeat(250)}@b.de`)).toBe(false);
    });

    it('trims the request body and sends an empty name as "" (no role fallback)', () => {
        expect(toSendInput({ email: ' a@b.de ', name: '  ' })).toEqual({
            email: 'a@b.de',
            name: '',
        });
        expect(toSendInput({ email: 'a@b.de', name: ' Ana ' })).toEqual({
            email: 'a@b.de',
            name: 'Ana',
        });
    });
});

describe('send idempotency keys (persisted with the queued request, ADR 0035)', () => {
    const keyOf = async (uuid: string): Promise<string | null> => {
        const op = await db.outbox.get(opId('send', SCOPE.userId, uuid));
        return op?.kind === 'send' ? op.request.idempotencyKey : null;
    };

    it('keeps one key per submission and recipient, a new one for another recipient', async () => {
        await enqueueSend(SCOPE, 's1', { email: 'a@b.de', name: 'Ana' });
        const first = await keyOf('s1');
        expect(first).toMatch(/^[0-9a-f-]{36}$/);
        await enqueueSend(SCOPE, 's1', { email: 'a@b.de', name: 'Ana' });
        expect(await keyOf('s1')).toBe(first);
        await enqueueSend(SCOPE, 's1', { email: 'c@d.de', name: '' });
        const other = await keyOf('s1');
        expect(other).not.toBe(first);
        await enqueueSend(SCOPE, 's2', { email: 'c@d.de', name: '' });
        expect(await keyOf('s2')).not.toBe(other);
        expect(sessionStorage.length).toBe(0);
    });

    it('stores the body the key belongs to', async () => {
        await enqueueSend(SCOPE, 's3', { email: 'x@y.de' });
        const op = await db.outbox.get(opId('send', SCOPE.userId, 's3'));
        expect(op).toMatchObject({
            kind: 'send',
            input: { email: 'x@y.de' },
            request: { body: { email: 'x@y.de' } },
        });
    });
});

describe('document refetch delay', () => {
    const notReady = new ApiError(404, 'document_not_ready', 'not ready');
    const failed = new ApiError(409, 'document_failed', 'Too large.');
    const base = { data: undefined, error: null, errorUpdateCount: 0 };
    const waiting = { ...base, status: 'error' as const, error: notReady };

    it('polls with backoff while the PDF is rendered and then stops', () => {
        POLL_DELAYS_MS.forEach((delay, index) => {
            expect(documentRefetchDelay(waiting, index + 1)).toBe(delay);
        });
        expect(documentRefetchDelay(waiting, POLL_DELAYS_MS.length + 1)).toBe(false);
    });

    it('does not poll after other errors or while loading', () => {
        expect(
            documentRefetchDelay({ ...base, status: 'error', error: new NetworkError('x') }, 1),
        ).toBe(false);
        expect(documentRefetchDelay({ ...base, status: 'error', error: failed }, 1)).toBe(false);
        expect(documentRefetchDelay({ ...base, status: 'pending' }, 0)).toBe(false);
    });

    it('refreshes the signed link a minute before it expires, also after a failed refresh', () => {
        const now = Date.parse('2026-10-16T10:00:00Z');
        const at = (iso: string, status: 'success' | 'error' = 'success') =>
            documentRefetchDelay(
                { ...base, status, data: makeDocument({ expires_at: iso }) },
                0,
                now,
            );
        expect(at('2026-10-16T10:05:00Z')).toBe(4 * 60_000);
        expect(at('2026-10-16T10:00:30Z')).toBe(10_000);
        expect(at('2026-10-16T09:59:00Z', 'error')).toBe(10_000);
        expect(at('not a date')).toBe(false);
    });

    it('never polls or refreshes once the submission was anonymized (410 document_anonymized)', () => {
        const gone = new ApiError(410, 'document_anonymized', 'The PDF files were deleted.');
        expect(documentRefetchDelay({ ...base, status: 'error', error: gone }, 1)).toBe(false);
        const expired = makeDocument({ expires_at: new Date(Date.now() - 1_000).toISOString() });
        const withOldLink = { ...base, status: 'error' as const, error: gone, data: expired };
        expect(documentRefetchDelay(withOldLink, 0)).toBe(false);
        expect(linkNeedsRefresh(withOldLink)).toBe(false);
    });

    it('stops refreshing a link once rendering failed permanently', () => {
        expect(
            documentRefetchDelay(
                { ...base, status: 'error', error: failed, data: makeDocument() },
                0,
            ),
        ).toBe(false);
    });

    it("reads the server's text for a permanent failure, if it sent one", () => {
        expect(isDocumentFailed(failed)).toBe(true);
        expect(isDocumentFailed(notReady)).toBe(false);
        expect(documentFailureMessage(failed)).toBe('Too large.');
        expect(
            documentFailureMessage(new ApiError(409, 'document_failed', 'document_failed')),
        ).toBeNull();
        expect(documentFailureMessage(new ApiError(409, 'document_failed', '  '))).toBeNull();
        expect(documentFailureMessage(notReady)).toBeNull();
    });

    it("reads the server's text for an anonymized submission, if it sent one", () => {
        const gone = new ApiError(410, 'document_anonymized', 'The PDF files were deleted.');
        expect(isDocumentAnonymized(gone)).toBe(true);
        expect(isDocumentAnonymized(new ApiError(410, 'gone', 'Gone'))).toBe(false);
        expect(isDocumentAnonymized(failed)).toBe(false);
        expect(documentAnonymizedMessage(gone)).toBe('The PDF files were deleted.');
        expect(
            documentAnonymizedMessage(
                new ApiError(410, 'document_anonymized', 'document_anonymized'),
            ),
        ).toBeNull();
        expect(documentAnonymizedMessage(failed)).toBeNull();
    });

    it('knows when a link needs a refresh (tab comes back to the foreground)', () => {
        const now = Date.parse('2026-10-16T10:00:00Z');
        const state = (iso: string) => ({
            ...base,
            status: 'success' as const,
            data: makeDocument({ expires_at: iso }),
        });
        expect(linkNeedsRefresh(state('2026-10-16T10:00:59Z'), now)).toBe(true);
        expect(linkNeedsRefresh(state('2026-10-16T10:02:00Z'), now)).toBe(false);
        expect(linkNeedsRefresh(state('not a date'), now)).toBe(false);
        expect(linkNeedsRefresh({ ...base, status: 'pending' }, now)).toBe(false);
    });
});
