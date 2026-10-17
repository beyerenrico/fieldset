/** Typed functions for every contract endpoint. */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { apiRequest } from './client.ts';
import { ApiError } from './errors.ts';
import { rawRequest, type ApiResponse, type RequestOptions } from './http.ts';
import { refreshCsrfCookie } from './device-status.ts';
import { toMeta } from './pagination.ts';
import { paths } from './paths.ts';
import type {
    AttachmentKind,
    AttachmentRecord,
    DeviceRegistration,
    DiscoveryResult,
    DocumentSend,
    FormSummary,
    FormVersion,
    LoginResult,
    Me,
    Page,
    Submission,
    SubmissionDocument,
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

/** Only http(s) targets are followed; a relative URL is resolved against this origin. */
function ssoTarget(value: unknown): string | null {
    if (typeof value !== 'string' || value === '') {
        return null;
    }
    try {
        const url = new URL(value, window.location.origin);
        return url.protocol === 'https:' || url.protocol === 'http:' ? url.toString() : null;
    } catch {
        return null;
    }
}

/** Narrows the untrusted discovery answer; anything but a usable SSO answer means password. */
export function parseDiscovery(data: unknown): DiscoveryResult {
    const body = typeof data === 'object' && data !== null ? (data as Record<string, unknown>) : {};
    if (body.mode !== 'sso') {
        return { mode: 'password' };
    }
    const redirectUrl = ssoTarget(body.redirect_url);
    if (redirectUrl === null) {
        throw new ApiError(502, 'invalid_discovery', 'The SSO answer has no usable redirect URL');
    }
    const organization = typeof body.organization === 'string' ? body.organization.trim() : '';
    return {
        mode: 'sso',
        redirect_url: redirectUrl,
        organization,
        enforced: body.enforced === true,
    };
}

/** Home-realm discovery (ADR 0036): needs the network; it never says whether the user exists. */
export async function discover(email: string): Promise<DiscoveryResult> {
    await refreshCsrfCookie();
    const response = await authRequest<unknown>(paths.ssoDiscover, {
        method: 'POST',
        body: { email },
    });
    return parseDiscovery(response.data);
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

/** Per-request options of the write endpoints (the outbox sends each row's organization). */
export interface WriteOptions {
    readonly organizationId?: number;
    readonly keepalive?: boolean;
}

function writeOptions(options: WriteOptions): Pick<RequestOptions, 'organizationId' | 'keepalive'> {
    return {
        ...(options.organizationId === undefined ? {} : { organizationId: options.organizationId }),
        ...(options.keepalive === true ? { keepalive: true } : {}),
    };
}

export async function putSubmission(
    uuid: string,
    input: DraftInput,
    options: WriteOptions = {},
): Promise<Submission> {
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
        ...writeOptions(options),
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
    options: WriteOptions = {},
): Promise<AttachmentRecord> {
    const formData = new FormData();
    formData.append('file', input.file, input.fileName);
    formData.append('attachment_uuid', input.attachmentUuid);
    formData.append('kind', input.kind);
    formData.append('element_key', input.elementKey);
    const response = await apiRequest<{ attachment: AttachmentRecord }>(paths.attachments(uuid), {
        method: 'POST',
        formData,
        ...writeOptions(options),
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

/** Body of `POST /submissions/{uuid}/submit`; persisted with its key so a retry sends the same bytes. */
export interface SubmitBody {
    readonly revision: number;
    /** Device time of the submit (ISO 8601); number, period and `$today` stay on server time. */
    readonly client_submitted_at?: string;
}

export async function submitSubmission(
    uuid: string,
    body: SubmitBody,
    idempotencyKey: string,
    options: WriteOptions = {},
): Promise<SubmitResult> {
    const response = await apiRequest<{ submission: Submission }>(paths.submit(uuid), {
        method: 'POST',
        body,
        headers: { 'Idempotency-Key': idempotencyKey },
        ...writeOptions(options),
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

export async function listSubmissions(filter: SubmissionFilter = {}): Promise<Page<Submission>> {
    const response = await apiRequest<readonly Submission[]>(paths.submissions, {
        query: { status: filter.status, form_id: filter.formId, page: filter.page },
    });
    const items = Array.isArray(response.data) ? response.data : [];
    return { items, meta: toMeta(response.meta, items.length) };
}

/** One submission; the outbox passes its row's organization (`organizationId`). */
export async function fetchSubmission(
    uuid: string,
    options: Pick<WriteOptions, 'organizationId'> = {},
): Promise<Submission> {
    const response = await apiRequest<{ submission: Submission }>(paths.submission(uuid), {
        ...writeOptions(options),
    });
    return response.data.submission;
}

/** Latest rendition with a signed link; 404 `document_not_ready` while the PDF is rendered. */
export async function fetchSubmissionDocument(uuid: string): Promise<SubmissionDocument> {
    const response = await apiRequest<{ document: SubmissionDocument }>(paths.document(uuid));
    return response.data.document;
}

export interface SendInput {
    readonly email: string;
    /** Omitted: the server uses the `customer.name` element's value; `""` means "no name". */
    readonly name?: string;
}

/** "Send to customer" (§9.2): 202 with the send record; same idempotency rules as submit. */
export async function sendSubmissionDocument(
    uuid: string,
    input: SendInput,
    idempotencyKey: string,
    options: WriteOptions = {},
): Promise<DocumentSend> {
    const response = await apiRequest<{ send: DocumentSend }>(paths.send(uuid), {
        method: 'POST',
        body: input,
        headers: { 'Idempotency-Key': idempotencyKey },
        ...writeOptions(options),
    });
    return response.data.send;
}
