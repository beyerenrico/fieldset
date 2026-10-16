import type { FormDefinition } from '@form-schema-app/form-schema-js';
import { describe, expect, it } from 'vitest';
import {
    documentRefetchDelay,
    linkNeedsRefresh,
    POLL_DELAYS_MS,
} from '../../src/hooks/useSubmissionDocument.ts';
import { ApiError, NetworkError } from '../../src/lib/api/errors.ts';
import { customerDefaults, isValidEmail, toSendInput } from '../../src/lib/send/recipient.ts';
import {
    clearSendIdempotencyKey,
    getSendIdempotencyKey,
} from '../../src/lib/submit/idempotency.ts';
import { makeDocument } from '../utils/fixtures.ts';

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

    it('trims the request body and omits an empty name', () => {
        expect(toSendInput({ email: ' a@b.de ', name: '  ' })).toEqual({ email: 'a@b.de' });
        expect(toSendInput({ email: 'a@b.de', name: ' Ana ' })).toEqual({
            email: 'a@b.de',
            name: 'Ana',
        });
    });
});

describe('send idempotency keys', () => {
    it('keeps one key per submission and recipient until cleared', () => {
        const first = getSendIdempotencyKey('s1', 'a@b.de|Ana');
        expect(getSendIdempotencyKey('s1', 'a@b.de|Ana')).toBe(first);
        const other = getSendIdempotencyKey('s1', 'c@d.de|');
        expect(other).not.toBe(first);
        expect(getSendIdempotencyKey('s2', 'c@d.de|')).not.toBe(other);
        clearSendIdempotencyKey('s1');
        expect(getSendIdempotencyKey('s1', 'c@d.de|')).not.toBe(other);
    });

    it('ignores a corrupt stored entry', () => {
        sessionStorage.setItem('fsa.idem.send.s3', '"broken"');
        expect(getSendIdempotencyKey('s3', 'x')).toMatch(/^[0-9a-f-]{36}$/);
    });
});

describe('document refetch delay', () => {
    const notReady = new ApiError(404, 'document_not_ready', 'not ready');
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
