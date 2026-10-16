/** Typed functions for every contract endpoint. */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { apiRequest } from './client.ts';
import { ApiError } from './errors.ts';
import { rawRequest, type ApiResponse, type RequestOptions } from './http.ts';
import { refreshCsrfCookie } from './device-status.ts';
import { paths } from './paths.ts';
import type {
    AttachmentKind,
    AttachmentRecord,
    DeviceRegistration,
    FormSummary,
    FormVersion,
    LoginResult,
    Me,
    Page,
    PaginationMeta,
    Submission,
    SubmissionStatus,
    SyncManifest,
} from './types.ts';

export { refreshCsrfCookie };

export interface Credentials {
    readonly email: string;
    readonly password: string;
}

/** Fortify calls: on 419 (CSRF mismatch) refresh the cookie and retry once; no device-status flow. */
async function authRequest<T>(path: string, options: RequestOptions): Promise<ApiResponse<T>> {
    const request = { ...options, tenant: false, recover: false };
    try {
        return await rawRequest<T>(path, request);
    } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 419) {
            throw error;
        }
    }
    await refreshCsrfCookie();
    return rawRequest<T>(path, request);
}

export async function login(credentials: Credentials): Promise<LoginResult> {
    await refreshCsrfCookie();
    const response = await authRequest<LoginResult | undefined>(paths.login, {
        method: 'POST',
        body: { ...credentials, remember: true },
    });
    return { two_factor: response.data?.two_factor === true };
}

export type TwoFactorInput = { readonly code: string } | { readonly recovery_code: string };

export async function twoFactorChallenge(input: TwoFactorInput): Promise<void> {
    await authRequest<undefined>(paths.twoFactorChallenge, { method: 'POST', body: input });
}

export async function logout(): Promise<void> {
    await authRequest<undefined>(paths.logout, { method: 'POST' });
}

export async function fetchMe(): Promise<Me> {
    return (await apiRequest<Me>(paths.me, { tenant: false })).data;
}

export interface DeviceInput {
    readonly device_uuid: string;
    readonly name: string;
    readonly platform: string;
}

export async function registerDevice(input: DeviceInput): Promise<DeviceRegistration> {
    const response = await apiRequest<DeviceRegistration>(paths.devices, {
        method: 'POST',
        tenant: false,
        body: input,
    });
    return response.data;
}

export async function fetchForms(): Promise<readonly FormSummary[]> {
    return (await apiRequest<readonly FormSummary[]>(paths.forms)).data;
}

export async function fetchFormVersion(formId: number, versionId: number): Promise<FormVersion> {
    return (await apiRequest<FormVersion>(paths.formVersion(formId, versionId))).data;
}

export async function fetchSyncManifest(since?: string): Promise<SyncManifest> {
    const query = since === undefined ? {} : { since };
    return (await apiRequest<SyncManifest>(paths.syncManifest, { query })).data;
}

export interface DraftInput {
    readonly formVersionId: number;
    readonly data: JsonObject;
    /** Omitted (or 0) to create the draft. */
    readonly revision: number;
}

export async function putSubmission(uuid: string, input: DraftInput): Promise<Submission> {
    const body: Record<string, unknown> = {
        form_version_id: input.formVersionId,
        data: input.data,
    };
    if (input.revision > 0) {
        body.revision = input.revision;
    }
    const response = await apiRequest<{ submission: Submission }>(paths.submission(uuid), {
        method: 'PUT',
        body,
    });
    return response.data.submission;
}

export interface AttachmentInput {
    readonly file: Blob;
    readonly fileName: string;
    readonly attachmentUuid: string;
    readonly kind: AttachmentKind;
    readonly elementKey: string;
}

export async function uploadAttachment(
    uuid: string,
    input: AttachmentInput,
): Promise<AttachmentRecord> {
    const formData = new FormData();
    formData.append('file', input.file, input.fileName);
    formData.append('attachment_uuid', input.attachmentUuid);
    formData.append('kind', input.kind);
    formData.append('element_key', input.elementKey);
    const response = await apiRequest<{ attachment: AttachmentRecord }>(paths.attachments(uuid), {
        method: 'POST',
        formData,
    });
    return response.data.attachment;
}

/** Private file download (credentials, `X-Organization-Id` and session recovery apply). */
export async function downloadAttachment(uuid: string, attachmentUuid: string): Promise<Blob> {
    const response = await apiRequest<Blob>(paths.attachment(uuid, attachmentUuid), {
        responseType: 'blob',
    });
    return response.data;
}

export interface SubmitResult {
    readonly submission: Submission;
    /** `Idempotent-Replayed: true` — the server returned the original response. */
    readonly replayed: boolean;
}

export async function submitSubmission(
    uuid: string,
    revision: number,
    idempotencyKey: string,
): Promise<SubmitResult> {
    const response = await apiRequest<{ submission: Submission }>(paths.submit(uuid), {
        method: 'POST',
        body: { revision },
        headers: { 'Idempotency-Key': idempotencyKey },
    });
    return {
        submission: response.data.submission,
        replayed: response.headers.get('Idempotent-Replayed') === 'true',
    };
}

export interface SubmissionFilter {
    readonly status?: SubmissionStatus | undefined;
    readonly formId?: number | undefined;
    readonly page?: number | undefined;
}

const EMPTY_META: PaginationMeta = { current_page: 1, last_page: 1, total: 0 };

function toMeta(meta: Readonly<Record<string, unknown>> | null, count: number): PaginationMeta {
    const read = (key: keyof PaginationMeta, fallback: number): number => {
        const value = meta?.[key];
        return typeof value === 'number' ? value : fallback;
    };
    return {
        current_page: read('current_page', EMPTY_META.current_page),
        last_page: read('last_page', EMPTY_META.last_page),
        total: read('total', count),
    };
}

export async function listSubmissions(filter: SubmissionFilter = {}): Promise<Page<Submission>> {
    const response = await apiRequest<readonly Submission[]>(paths.submissions, {
        query: { status: filter.status, form_id: filter.formId, page: filter.page },
    });
    const items = Array.isArray(response.data) ? response.data : [];
    return { items, meta: toMeta(response.meta, items.length) };
}

export async function fetchSubmission(uuid: string): Promise<Submission> {
    return (await apiRequest<{ submission: Submission }>(paths.submission(uuid))).data.submission;
}
