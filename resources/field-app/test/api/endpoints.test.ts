import { http, HttpResponse } from 'msw';
import { describe, expect, it, vi } from 'vitest';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { saveDeviceRecord } from '../../src/lib/device/credentials.ts';
import {
    downloadAttachment,
    fetchFormVersion,
    fetchMe,
    fetchSubmission,
    fetchSyncManifest,
    listSubmissions,
    login,
    logout,
    putSubmission,
    submitSubmission,
    twoFactorChallenge,
    uploadAttachment,
} from '../../src/lib/api/endpoints.ts';
import { apiTransport } from '../../src/lib/autosave/api-transport.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();

describe('endpoints', () => {
    it('login refreshes CSRF first and sends remember: true', async () => {
        const order: string[] = [];
        let body: unknown = null;
        server.use(
            http.get('*/sanctum/csrf-cookie', () => {
                order.push('csrf');
                return new HttpResponse(null, { status: 204 });
            }),
            http.post('http://localhost:3000/auth/login', async ({ request }) => {
                order.push('login');
                body = await request.json();
                return HttpResponse.json({ two_factor: false });
            }),
        );
        expect(await login({ email: 'a@b.c', password: 'pw' })).toEqual({ two_factor: false });
        expect(order).toEqual(['csrf', 'login']);
        expect(body).toEqual({ email: 'a@b.c', password: 'pw', remember: true });
    });

    it('retries a Fortify call once after 419 with a fresh CSRF cookie', async () => {
        let calls = 0;
        let csrf = 0;
        server.use(
            http.get('*/sanctum/csrf-cookie', () => {
                csrf++;
                return new HttpResponse(null, { status: 204 });
            }),
            http.post('*/auth/two-factor-challenge', () => {
                calls++;
                return calls === 1
                    ? HttpResponse.json({ message: 'CSRF token mismatch.' }, { status: 419 })
                    : new HttpResponse(null, { status: 204 });
            }),
            http.post('*/auth/logout', () => new HttpResponse(null, { status: 204 })),
        );
        await twoFactorChallenge({ code: '123456' });
        expect([calls, csrf]).toEqual([2, 1]);
        await expect(logout()).resolves.toBeUndefined();
    });

    it('creates drafts without revision and updates with it', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        const created = await putSubmission('u1', {
            formVersionId: 7,
            data: { a: 1 },
            revision: 0,
        });
        expect(created.revision).toBe(1);
        await putSubmission('u1', { formVersionId: 7, data: { a: 2 }, revision: 1 });
        expect(backend.state.puts.map((put) => put.body)).toEqual([
            { form_version_id: 7, data: { a: 1 } },
            { form_version_id: 7, data: { a: 2 }, revision: 1 },
        ]);
    });

    it('forwards keepalive for the page-hide save (transport → putSubmission)', async () => {
        const spy = vi.spyOn(globalThis, 'fetch').mockImplementation(() =>
            Promise.resolve(
                Response.json({
                    success: true,
                    data: { submission: makeSubmission({ revision: 2 }) },
                    error: null,
                    meta: null,
                }),
            ),
        );
        await apiTransport.save({
            uuid: 'u1',
            formVersionId: 7,
            data: { a: 1 },
            revision: 1,
            keepalive: true,
        });
        await apiTransport.save({ uuid: 'u1', formVersionId: 7, data: { a: 1 }, revision: 2 });
        expect(spy.mock.calls.map((call) => call[1]?.keepalive)).toEqual([true, undefined]);
        spy.mockRestore();
    });

    it('uploads attachments as multipart with the client uuid', async () => {
        // jsdom's FormData is not accepted by Node's fetch: inspect the request at the fetch level.
        const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            Response.json({
                success: true,
                data: { attachment: { uuid: 'att-1' } },
                error: null,
                meta: null,
            }),
        );
        const record = await uploadAttachment('u1', {
            file: new Blob(['x'], { type: 'image/jpeg' }),
            fileName: 'a.jpg',
            attachmentUuid: 'att-1',
            kind: 'photo',
            elementKey: 'photos',
        });
        expect(record.uuid).toBe('att-1');
        const [url, init] = spy.mock.calls[0] ?? [];
        expect(url).toBe('http://localhost:3000/api/v1/submissions/u1/attachments');
        const body = init?.body as FormData;
        expect(body.get('attachment_uuid')).toBe('att-1');
        expect(body.get('kind')).toBe('photo');
        expect(body.get('element_key')).toBe('photos');
        expect(body.get('file')).toBeInstanceOf(Blob);
        spy.mockRestore();
    });

    it('submits with the Idempotency-Key header and reports replays', async () => {
        let key: string | null = null;
        server.use(
            http.post('*/api/v1/submissions/:uuid/submit', ({ request }) => {
                key = request.headers.get('Idempotency-Key');
                return ok(
                    { submission: makeSubmission({ status: 'submitted' }) },
                    { headers: { 'Idempotent-Replayed': 'true' } },
                );
            }),
        );
        const result = await submitSubmission('u1', 3, 'key-12345678');
        expect(key).toBe('key-12345678');
        expect(result.replayed).toBe(true);
        expect(result.submission.status).toBe('submitted');
    });

    it('lists submissions with filters and pagination meta', async () => {
        let url = '';
        server.use(
            http.get('*/api/v1/submissions', ({ request }) => {
                url = request.url;
                return ok([makeSubmission()], {}, { current_page: 2, last_page: 3, total: 21 });
            }),
        );
        const page = await listSubmissions({ status: 'draft', formId: 3, page: 2 });
        expect(new URL(url).search).toBe('?status=draft&form_id=3&page=2');
        expect(page.meta).toEqual({ current_page: 2, last_page: 3, total: 21 });
        server.use(http.get('*/api/v1/submissions', () => ok(null)));
        expect(await listSubmissions()).toEqual({
            items: [],
            meta: { current_page: 1, last_page: 1, total: 0 },
        });
    });

    it('reads me, versions, submissions and the manifest with fillable ids', async () => {
        const backend = createBackend({ fillable: [3] });
        backend.state.submissions.set('u9', makeSubmission({ uuid: 'u9' }));
        server.use(...backend.handlers);
        expect((await fetchMe()).user.name).toBe('Willi Worker');
        expect((await fetchFormVersion(3, 7)).definition.key).toBe('service_report');
        expect((await fetchSubmission('u9')).uuid).toBe('u9');
        const manifest = await fetchSyncManifest('c0');
        expect(manifest.fillable_form_ids).toEqual([3]);
    });

    it('downloads attachments as Blob with credentials headers and session recovery', async () => {
        setOrganizationId(1);
        await saveDeviceRecord({ deviceUuid: 'd', secret: 's' });
        const seen: (string | null)[][] = [];
        let calls = 0;
        server.use(
            http.get(
                '*/api/v1/submissions/:uuid/attachments/:attachment',
                ({ request, params }) => {
                    calls++;
                    seen.push([
                        request.headers.get('X-Organization-Id'),
                        request.headers.get('Accept'),
                        String(params.attachment),
                    ]);
                    if (calls === 1) {
                        return fail(401, 'unauthenticated');
                    }
                    return new HttpResponse(new Uint8Array([1, 2, 3]), {
                        headers: { 'Content-Type': 'image/jpeg' },
                    });
                },
            ),
            http.post('*/api/v1/auth/device-status', () => ok({ status: 'ok' })),
        );
        const blob = await downloadAttachment('u1', 'a1');
        expect(blob.size).toBe(3);
        expect(blob.type).toBe('image/jpeg');
        expect(seen).toEqual([
            ['1', '*/*', 'a1'],
            ['1', '*/*', 'a1'],
        ]);
        server.use(
            http.get('*/api/v1/submissions/:uuid/attachments/:attachment', () =>
                fail(404, 'not_found'),
            ),
        );
        await expect(downloadAttachment('u1', 'a2')).rejects.toMatchObject({ code: 'not_found' });
    });
});
