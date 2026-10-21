/**
 * Offline-sync fake of the write endpoints (agent A, ADR 0035): a request log, PUT retries that are
 * safe, idempotent uploads, Idempotency-Key replays, lost responses, offline and expired sessions.
 * Test fixture: mutable on purpose.
 */
import type { JsonObject } from '@fieldset/fieldset-js';
import { http, HttpResponse } from 'msw';
import { vi } from 'vitest';
import * as endpoints from '../../src/lib/api/endpoints.ts';
import type { AttachmentInput } from '../../src/lib/api/endpoints.ts';
import { ApiError, NetworkError, ReauthRequiredError } from '../../src/lib/api/errors.ts';
import type { AttachmentRecord, Submission } from '../../src/lib/api/types.ts';
import { fail, ok } from './backend.ts';
import { makeSubmission } from './fixtures.ts';

export interface LoggedRequest {
    readonly method: string;
    readonly path: string;
    readonly org: string | null;
    readonly key: string | null;
    readonly body: unknown;
}

export interface SyncBackendState {
    submissions: Map<string, Submission>;
    attachments: Map<string, AttachmentRecord & { submission: string }>;
    /** Idempotency-Key → stored successful answer. */
    replays: Map<string, { body: string; response: unknown }>;
    log: LoggedRequest[];
    /** Every request fails at the network level (fetch rejects). */
    offline: boolean;
    /** Session expired: tenant calls answer 401; device-status answers `deviceStatus`. */
    loggedIn: boolean;
    deviceStatus: 'ok' | 'reauth' | 'wipe';
    /** The next submit is processed, but its answer never arrives. */
    loseNextSubmit: boolean;
    /** The next PUT is processed, but its answer never arrives. */
    loseNextPut: boolean;
    /** Answers to inject before the normal handling, by "METHOD kind" (e.g. "POST submit"). */
    inject: Map<string, () => Response>;
}

const paths = {
    put: '*/api/v1/submissions/:uuid',
    submit: '*/api/v1/submissions/:uuid/submit',
    send: '*/api/v1/submissions/:uuid/send',
};

/** Logs a request that reached the server (offline attempts never do). */
function record(state: SyncBackendState, request: Request, body: unknown): void {
    if (state.offline) {
        return;
    }
    state.log.push({
        method: request.method,
        path: new URL(request.url).pathname,
        org: request.headers.get('X-Organization-Id'),
        key: request.headers.get('Idempotency-Key'),
        body,
    });
}

function nextDraft(state: SyncBackendState, uuid: string, body: Record<string, unknown>) {
    const existing = state.submissions.get(uuid);
    const revision = typeof body.revision === 'number' ? body.revision : 0;
    if (existing === undefined) {
        const created = makeSubmission({
            uuid,
            form_version_id: Number(body.form_version_id),
            data: body.data as JsonObject,
            revision: 1,
        });
        state.submissions.set(uuid, created);
        return ok({ submission: created }, { status: 201 });
    }
    // Retries are safe: the last successful save again answers 200 with the current draft. As in
    // SaveSubmissionDraft::update, revision_conflict is checked before not_editable.
    const sameData = JSON.stringify(existing.data) === JSON.stringify(body.data);
    if (existing.can_edit && sameData && revision === existing.revision - 1) {
        return ok({ submission: existing });
    }
    if (revision !== existing.revision) {
        return fail(409, 'revision_conflict', { submission: existing });
    }
    if (!existing.can_edit) {
        return fail(409, 'not_editable');
    }
    const updated = { ...existing, data: body.data as JsonObject, revision: existing.revision + 1 };
    state.submissions.set(uuid, updated);
    return ok({ submission: updated });
}

function submit(state: SyncBackendState, uuid: string, key: string, raw: string) {
    const stored = state.replays.get(key);
    if (stored !== undefined) {
        return stored.body === raw
            ? ok(stored.response, { headers: { 'Idempotent-Replayed': 'true' } })
            : fail(409, 'idempotency_key_reused');
    }
    const existing = state.submissions.get(uuid);
    if (existing === undefined) {
        return fail(404, 'not_found');
    }
    const body = JSON.parse(raw) as { revision: number; client_submitted_at?: string };
    // As SubmitSubmission: a repeat of the submit that consumed the revision answers 200, then
    // not_editable, then revision_conflict.
    if (!existing.can_edit && existing.status !== 'draft') {
        return body.revision === existing.revision - 1
            ? ok({ submission: existing })
            : fail(409, 'not_editable');
    }
    if (body.revision !== existing.revision) {
        return fail(409, 'revision_conflict', { submission: existing });
    }
    const missing = existing.attachments.length < countReferences(state, uuid, existing.data);
    if (missing) {
        return fail(422, 'attachments_missing');
    }
    const submitted: Submission = {
        ...existing,
        status: 'submitted',
        revision: existing.revision + 1,
        number: existing.number ?? `SR-2026-${String(state.replays.size + 1).padStart(5, '0')}`,
        submitted_at: new Date().toISOString(),
        client_submitted_at: body.client_submitted_at ?? null,
        can_edit: false,
    };
    state.submissions.set(uuid, submitted);
    const response = { submission: submitted };
    state.replays.set(key, { body: raw, response });
    return ok(response);
}

/** Attachment uuids referenced by the data that exist on this server (for attachments_missing). */
function countReferences(state: SyncBackendState, uuid: string, data: JsonObject): number {
    const json = JSON.stringify(data);
    return [...state.attachments.values()].filter(
        (attachment) => attachment.submission === uuid && json.includes(attachment.uuid),
    ).length;
}

/** Upload by attachment uuid: idempotent; a uuid of another submission → 409 attachment_conflict. */
function storeUpload(
    state: SyncBackendState,
    uuid: string,
    input: AttachmentInput,
): AttachmentRecord {
    const existing = state.attachments.get(input.attachmentUuid);
    if (existing !== undefined) {
        if (existing.submission !== uuid) {
            throw new ApiError(409, 'attachment_conflict', 'attachment_conflict');
        }
        return existing;
    }
    const draft = state.submissions.get(uuid);
    if (draft === undefined) {
        throw new ApiError(404, 'not_found', 'not_found');
    }
    const attachment = {
        uuid: input.attachmentUuid,
        kind: input.kind,
        element_key: input.elementKey,
        mime: input.file.type,
        size: input.file.size,
        url: `/api/v1/submissions/${uuid}/attachments/${input.attachmentUuid}`,
        submission: uuid,
    };
    state.attachments.set(input.attachmentUuid, attachment);
    state.submissions.set(uuid, { ...draft, attachments: [...draft.attachments, attachment] });
    return attachment;
}

/**
 * jsdom's FormData cannot travel through Node's fetch (see test/api/endpoints.test.ts), so uploads
 * are faked at the endpoint function, with the same log and failure switches as the handlers.
 */
export function fakeUploads(state: SyncBackendState) {
    return vi
        .spyOn(endpoints, 'uploadAttachment')
        .mockImplementation((uuid, input, options = {}) => {
            if (state.offline) {
                return Promise.reject(new NetworkError('offline'));
            }
            state.log.push({
                method: 'POST',
                path: `/api/v1/submissions/${uuid}/attachments`,
                org: options.organizationId === undefined ? null : String(options.organizationId),
                key: null,
                body: { attachment_uuid: input.attachmentUuid, kind: input.kind },
            });
            if (!state.loggedIn) {
                return Promise.reject(new ReauthRequiredError('Please sign in again.'));
            }
            const injected = state.inject.get('POST upload');
            if (injected !== undefined) {
                return Promise.reject(injectedError(injected()));
            }
            try {
                return Promise.resolve(storeUpload(state, uuid, input));
            } catch (error) {
                return Promise.reject(error instanceof Error ? error : new Error(String(error)));
            }
        });
}

/** Injected upload answers are given as ApiErrors through this marker response. */
function injectedError(response: Response): ApiError {
    const status = response.status;
    const code = response.headers.get('X-Fake-Code') ?? `http_${String(status)}`;
    return new ApiError(status, code, code);
}

/** An injected failure for "METHOD kind" (uploads read its status and X-Fake-Code header). */
export function failWith(status: number, code: string, details: unknown = null): () => Response {
    return () => {
        const response = fail(status, code, details);
        response.headers.set('X-Fake-Code', code);
        return response;
    };
}

export function createSyncBackend(overrides: Partial<SyncBackendState> = {}) {
    const state: SyncBackendState = {
        submissions: new Map(),
        attachments: new Map(),
        replays: new Map(),
        log: [],
        offline: false,
        loggedIn: true,
        deviceStatus: 'reauth',
        loseNextSubmit: false,
        loseNextPut: false,
        inject: new Map(),
        ...overrides,
    };
    const gate = (kind: string, request: Request): Response | null => {
        if (state.offline) {
            return HttpResponse.error();
        }
        if (!state.loggedIn) {
            return fail(401, 'unauthenticated');
        }
        const injected = state.inject.get(`${request.method} ${kind}`);
        return injected === undefined ? null : injected();
    };
    const handlers = [
        http.post('*/api/v1/auth/device-status', () =>
            state.offline
                ? HttpResponse.error()
                : ok({ status: state.loggedIn ? 'ok' : state.deviceStatus }),
        ),
        http.post(paths.submit, async ({ request, params }) => {
            const raw = await request.text();
            record(state, request, JSON.parse(raw) as unknown);
            const blocked = gate('submit', request);
            if (blocked !== null) {
                return blocked;
            }
            const key = request.headers.get('Idempotency-Key') ?? '';
            const answer = submit(state, String(params.uuid), key, raw);
            if (state.loseNextSubmit) {
                state.loseNextSubmit = false;
                return HttpResponse.error(); // processed, then the connection dropped
            }
            return answer;
        }),
        http.post(paths.send, async ({ request }) => {
            const body = (await request.json()) as { email?: string; name?: string };
            record(state, request, body);
            const blocked = gate('send', request);
            if (blocked !== null) {
                return blocked;
            }
            const recipient = { email: body.email ?? '', name: body.name ?? null };
            return ok(
                { send: { id: state.log.length, status: 'queued', recipient } },
                { status: 202 },
            );
        }),
        http.put(paths.put, async ({ request, params }) => {
            const body = (await request.json()) as Record<string, unknown>;
            record(state, request, body);
            const answer = gate('put', request) ?? nextDraft(state, String(params.uuid), body);
            if (state.loseNextPut) {
                state.loseNextPut = false;
                return HttpResponse.error(); // processed, then the connection dropped
            }
            return answer;
        }),
    ];
    return { state, handlers };
}

/**
 * `GET /submissions/{uuid}` from the fake's state (opt-in: screen tests read through their own
 * backend). Logged as "GET".
 */
export function readHandlers(state: SyncBackendState) {
    return [
        http.get(paths.put, ({ request, params }) => {
            record(state, request, null);
            if (state.offline) {
                return HttpResponse.error();
            }
            const submission = state.submissions.get(String(params.uuid));
            return submission === undefined ? fail(404, 'not_found') : ok({ submission });
        }),
    ];
}

/** Short names of the logged requests, e.g. "PUT create", "UPLOAD a1", "SUBMIT key". */
export function summarize(log: readonly LoggedRequest[]): string[] {
    return log.map((entry) => {
        if (entry.path.endsWith('/attachments')) {
            return 'UPLOAD';
        }
        if (entry.path.endsWith('/submit')) {
            return 'SUBMIT';
        }
        if (entry.path.endsWith('/send')) {
            return 'SEND';
        }
        if (entry.method === 'GET') {
            return 'GET';
        }
        const body = entry.body as Record<string, unknown>;
        return 'revision' in body ? 'PUT update' : 'PUT create';
    });
}
