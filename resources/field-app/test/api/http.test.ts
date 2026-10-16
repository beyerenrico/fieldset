import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import {
    ApiError,
    NetworkError,
    isConflict,
    isValidationError,
    validationDetails,
} from '../../src/lib/api/errors.ts';
import { buildUrl, rawRequest, readCookie } from '../../src/lib/api/http.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { fail, ok } from '../utils/backend.ts';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();

describe('rawRequest', () => {
    it('unwraps the envelope and returns meta', async () => {
        server.use(http.get('*/api/v1/x', () => ok({ a: 1 }, {}, { total: 3 })));
        const response = await rawRequest<{ a: number }>('/api/v1/x');
        expect(response.data).toEqual({ a: 1 });
        expect(response.meta).toEqual({ total: 3 });
        expect(response.status).toBe(200);
    });

    it('treats a body without boolean success as the payload (Fortify)', async () => {
        server.use(http.post('*/auth/login', () => HttpResponse.json({ two_factor: true })));
        const response = await rawRequest<{ two_factor: boolean }>('/auth/login', {
            method: 'POST',
            body: {},
        });
        expect(response.data).toEqual({ two_factor: true });
        expect(response.meta).toBeNull();
    });

    it('returns undefined for 204 and empty bodies', async () => {
        server.use(
            http.post('*/a', () => new HttpResponse(null, { status: 204 })),
            http.get('*/b', () => new HttpResponse('', { status: 200 })),
        );
        expect((await rawRequest('/a', { method: 'POST' })).data).toBeUndefined();
        expect((await rawRequest('/b')).data).toBeUndefined();
    });

    it('sends JSON, XSRF and organization headers on mutating tenant calls', async () => {
        setOrganizationId(42);
        let seen: Headers | null = null;
        let body: unknown = null;
        server.use(
            http.put('*/api/v1/x', async ({ request }) => {
                seen = request.headers;
                body = await request.json();
                return ok(null);
            }),
        );
        await rawRequest('/api/v1/x', { method: 'PUT', body: { v: 1 } });
        const headers = seen as unknown as Headers;
        expect(headers.get('X-XSRF-TOKEN')).toBe('xsrf=token');
        expect(headers.get('X-Organization-Id')).toBe('42');
        expect(headers.get('Content-Type')).toBe('application/json');
        expect(headers.get('Accept')).toBe('application/json');
        expect(headers.get('X-Requested-With')).toBe('XMLHttpRequest');
        expect(body).toEqual({ v: 1 });
    });

    it('omits XSRF on GET and the organization when tenant is false', async () => {
        setOrganizationId(42);
        let seen: Headers | null = null;
        server.use(
            http.get('*/api/v1/me', ({ request }) => {
                seen = request.headers;
                return ok({});
            }),
        );
        await rawRequest('/api/v1/me', { tenant: false });
        const headers = seen as unknown as Headers;
        expect(headers.get('X-XSRF-TOKEN')).toBeNull();
        expect(headers.get('X-Organization-Id')).toBeNull();
    });

    it('throws ApiError with code, message and pointer details', async () => {
        server.use(
            http.post('*/api/v1/x', () =>
                fail(422, 'validation_failed', [
                    { pointer: '/items/2/qty', code: 'required' },
                    { bad: 1 },
                ]),
            ),
        );
        const error = await rawRequest('/api/v1/x', { method: 'POST' }).catch((e: unknown) => e);
        expect(error).toBeInstanceOf(ApiError);
        expect(isValidationError(error)).toBe(true);
        expect(validationDetails(error)).toEqual([{ pointer: '/items/2/qty', code: 'required' }]);
        expect((error as ApiError).status).toBe(422);
    });

    it('maps Laravel validation errors to pointer details', async () => {
        server.use(
            http.post('*/auth/login', () =>
                HttpResponse.json(
                    { message: 'Nope', errors: { email: ['Wrong'], 'a.b': 'x' } },
                    { status: 422 },
                ),
            ),
        );
        const error = await rawRequest('/auth/login', { method: 'POST' }).catch((e: unknown) => e);
        expect(validationDetails(error)).toEqual([
            { pointer: '/email', code: 'invalid', message: 'Wrong' },
            { pointer: '/a/b', code: 'invalid', message: 'x' },
        ]);
    });

    it('maps other error bodies to http_<status> codes', async () => {
        server.use(
            http.get('*/c', () => HttpResponse.json({ message: 'Slow down' }, { status: 429 })),
            http.get('*/d', () => new HttpResponse('boom', { status: 500 })),
            http.get('*/e', () =>
                HttpResponse.json({ success: false, error: { details: 1 } }, { status: 409 }),
            ),
        );
        const c = (await rawRequest('/c').catch((e: unknown) => e)) as ApiError;
        expect([c.code, c.message]).toEqual(['http_429', 'Slow down']);
        const d = (await rawRequest('/d').catch((e: unknown) => e)) as ApiError;
        expect(d.code).toBe('http_500');
        const e = (await rawRequest('/e').catch((x: unknown) => x)) as ApiError;
        expect(isConflict(e)).toBe(true);
        expect(e.code).toBe('http_409');
    });

    it('wraps fetch failures in NetworkError', async () => {
        server.use(http.get('*/f', () => HttpResponse.error()));
        await expect(rawRequest('/f')).rejects.toBeInstanceOf(NetworkError);
    });

    it('sends multipart bodies untouched', async () => {
        let type: string | null = null;
        server.use(
            http.post('*/g', ({ request }) => {
                type = request.headers.get('Content-Type');
                return ok(null);
            }),
        );
        const form = new FormData();
        form.append('a', 'b');
        await rawRequest('/g', { method: 'POST', formData: form });
        expect(type).toMatch(/^multipart\/form-data/);
    });
});

describe('helpers', () => {
    it('builds absolute URLs and skips empty query values', () => {
        expect(buildUrl('/api/v1/x', { a: 1, b: undefined, c: '' })).toBe(
            'http://localhost:3000/api/v1/x?a=1',
        );
    });

    it('reads and decodes cookies', () => {
        expect(readCookie('XSRF-TOKEN')).toBe('xsrf=token');
        expect(readCookie('missing')).toBeNull();
    });
});
