/** In-memory fake of the Field App API for MSW (test fixture: mutable on purpose). */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { http, HttpResponse, type JsonBodyType } from 'msw';
import type { FormSummary, Me, Submission, SubmissionDocument } from '../../src/lib/api/types.ts';
import { FORMS, makeMe, makeSubmission, makeVersion } from './fixtures.ts';

export const ok = (data: unknown, init: ResponseInit = {}, meta: unknown = null) =>
    HttpResponse.json({ success: true, data, error: null, meta } as JsonBodyType, init);

export const fail = (status: number, code: string, details: unknown = null, message = code) =>
    HttpResponse.json(
        {
            success: false,
            data: null,
            error: { code, message, details },
            meta: null,
        } as JsonBodyType,
        { status },
    );

export interface BackendState {
    me: Me;
    forms: readonly FormSummary[];
    fillable: readonly number[] | null;
    submissions: Map<string, Submission>;
    puts: { uuid: string; body: Record<string, unknown> }[];
    submits: { uuid: string; key: string | null; body: unknown }[];
    /** Rendered PDFs per submission uuid (missing: 404 `document_not_ready`). */
    documents: Map<string, SubmissionDocument>;
    documentCalls: number;
    sends: { uuid: string; key: string | null; body: unknown }[];
    loggedIn: boolean;
    twoFactor: boolean;
    deviceSecret: string;
}

export function createBackend(overrides: Partial<BackendState> = {}) {
    const state: BackendState = {
        me: makeMe(),
        forms: FORMS,
        fillable: null,
        submissions: new Map(),
        puts: [],
        submits: [],
        documents: new Map(),
        documentCalls: 0,
        sends: [],
        loggedIn: true,
        twoFactor: false,
        deviceSecret: 'secret-1',
        ...overrides,
    };
    const guard = () => (state.loggedIn ? null : fail(401, 'unauthenticated'));
    const handlers = [
        http.get('*/sanctum/csrf-cookie', () => new HttpResponse(null, { status: 204 })),
        http.post('*/auth/login', () => {
            state.loggedIn = !state.twoFactor;
            return HttpResponse.json({ two_factor: state.twoFactor });
        }),
        http.post('*/auth/two-factor-challenge', async ({ request }) => {
            const body = (await request.json()) as Record<string, string>;
            if (body.code !== '123456' && body.recovery_code !== 'abc-def') {
                return HttpResponse.json(
                    { message: 'Invalid', errors: { code: ['Invalid code'] } },
                    { status: 422 },
                );
            }
            state.loggedIn = true;
            return new HttpResponse(null, { status: 204 });
        }),
        http.post('*/auth/logout', () => {
            state.loggedIn = false;
            return new HttpResponse(null, { status: 204 });
        }),
        http.post('*/api/v1/auth/device-status', () =>
            ok({ status: state.loggedIn ? 'ok' : 'reauth' }),
        ),
        http.get('*/api/v1/me', () => guard() ?? ok(state.me)),
        http.post('*/api/v1/devices', async ({ request }) => {
            const body = (await request.json()) as Record<string, string>;
            return ok(
                { device_uuid: body.device_uuid, secret: state.deviceSecret },
                { status: 201 },
            );
        }),
        http.get('*/api/v1/forms', () => guard() ?? ok(state.forms)),
        http.get('*/api/v1/sync/manifest', () =>
            ok({
                cursor: 'c1',
                forms: state.forms,
                fillable_form_ids: state.fillable ?? state.forms.map((form) => form.id),
                revoked_form_ids: [],
                tasks: [],
            }),
        ),
        http.get('*/api/v1/forms/:form/versions/:version', ({ params }) =>
            ok(makeVersion(Number(params.version), Number(params.form))),
        ),
        http.get('*/api/v1/submissions/:uuid/document', ({ params }) => {
            state.documentCalls++;
            const found = state.documents.get(String(params.uuid));
            return found === undefined ? fail(404, 'document_not_ready') : ok({ document: found });
        }),
        http.post('*/api/v1/submissions/:uuid/send', async ({ params, request }) => {
            const uuid = String(params.uuid);
            const body = (await request.json()) as { email?: string; name?: string };
            state.sends.push({ uuid, key: request.headers.get('Idempotency-Key'), body });
            const status = state.documents.has(uuid) ? 'queued' : 'waiting_for_document';
            const recipient = { email: body.email ?? '', name: body.name ?? null };
            return ok({ send: { id: state.sends.length, status, recipient } }, { status: 202 });
        }),
        http.get('*/api/v1/submissions/:uuid', ({ params }) => {
            const found = state.submissions.get(String(params.uuid));
            return found === undefined ? fail(404, 'not_found') : ok({ submission: found });
        }),
        http.get('*/api/v1/submissions', ({ request }) => {
            const status = new URL(request.url).searchParams.get('status');
            const items = [...state.submissions.values()].filter(
                (submission) => status === null || submission.status === status,
            );
            return ok(items, {}, { current_page: 1, last_page: 1, total: items.length });
        }),
        http.put('*/api/v1/submissions/:uuid', async ({ params, request }) => {
            const uuid = String(params.uuid);
            const body = (await request.json()) as Record<string, unknown>;
            state.puts.push({ uuid, body });
            const existing = state.submissions.get(uuid);
            const revision = typeof body.revision === 'number' ? body.revision : 0;
            if (existing !== undefined && existing.revision !== revision) {
                return fail(409, 'revision_conflict', { submission: existing });
            }
            const next = makeSubmission({
                ...existing,
                uuid,
                form_version_id: Number(body.form_version_id),
                data: body.data as JsonObject,
                revision: (existing?.revision ?? 0) + 1,
            });
            state.submissions.set(uuid, next);
            return ok({ submission: next }, { status: existing === undefined ? 201 : 200 });
        }),
        http.post('*/api/v1/submissions/:uuid/submit', async ({ params, request }) => {
            const uuid = String(params.uuid);
            const body = (await request.json()) as { revision: number };
            state.submits.push({ uuid, key: request.headers.get('Idempotency-Key'), body });
            const existing = state.submissions.get(uuid);
            if (existing === undefined) {
                return fail(404, 'not_found');
            }
            const submitted = {
                ...existing,
                status: 'submitted' as const,
                number: 'SR-2026-00012',
                can_edit: false,
            };
            state.submissions.set(uuid, submitted);
            return ok({ submission: submitted });
        }),
    ];
    return { state, handlers };
}
